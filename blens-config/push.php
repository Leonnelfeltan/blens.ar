<?php
declare(strict_types=1);

function blens_b64url_encode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function blens_b64url_decode(string $txt): string {
    $txt = strtr($txt, '-_', '+/');
    $bin = base64_decode(str_pad($txt, (4 - strlen($txt) % 4) % 4, '=', STR_PAD_RIGHT), true);
    return $bin === false ? '' : $bin;
}

function blens_vapid_keys(): array {
    static $keys = null;
    if ($keys !== null) return $keys;
    $path = __DIR__ . '/vapid.json';
    $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    if (!is_array($data)) $data = [];
    $cfg = function_exists('blens_cfg') ? blens_cfg() : (is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : []);
    $secret = hash('sha256', (string)($cfg['db_pass'] ?? '') . '|blens-vapid|aes-256-gcm', true);
    $private = (string)($data['privateKey'] ?? '');
    $raw = base64_decode((string)($data['privateKeyEnc'] ?? ''), true);
    if ($raw !== false && strlen($raw) > 28) {
        $plain = openssl_decrypt(substr($raw, 12, -16), 'aes-256-gcm', $secret, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, -16));
        if ($plain !== false) $private = $plain;
    }
    $mail = trim((string)($cfg['mail_to'] ?? ''));
    return $keys = [
        'publicKey' => (string)($data['publicKey'] ?? ''),
        'privateKey' => $private,
        'subject' => (string)($data['subject'] ?? ($mail !== '' && str_contains($mail, '@') ? 'mailto:' . $mail : 'mailto:admin@blens.ar')),
    ];
}

function blens_vapid_public(): string {
    return (string)(blens_vapid_keys()['publicKey'] ?? '');
}

function blens_ec_public_point(OpenSSLAsymmetricKey $key): string {
    $d = openssl_pkey_get_details($key);
    if (!is_array($d) || !isset($d['ec']['x'], $d['ec']['y'])) return '';
    return "\x04" . $d['ec']['x'] . $d['ec']['y'];
}

function blens_ec_pem_from_point(string $point): string {
    if (strlen($point) !== 65 || $point[0] !== "\x04") return '';
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode((string)$der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function blens_hkdf_extract(string $salt, string $ikm): string {
    return hash_hmac('sha256', $ikm, $salt, true);
}

function blens_hkdf_expand(string $prk, string $info, int $length): string {
    $out = ''; $block = ''; $i = 1;
    while (strlen($out) < $length) {
        $block = hash_hmac('sha256', $block . $info . chr($i), $prk, true);
        $out .= $block;
        $i++;
    }
    return substr($out, 0, $length);
}

function blens_der_length(string $der, int &$pos): int {
    $n = ord($der[$pos++]);
    if ($n < 128) return $n;
    $bytes = $n & 0x7f; $len = 0;
    for ($i = 0; $i < $bytes; $i++) $len = ($len << 8) | ord($der[$pos++]);
    return $len;
}

function blens_der_integer(string $der, int &$pos): string {
    if (ord($der[$pos++]) !== 0x02) return '';
    $len = blens_der_length($der, $pos);
    $v = substr($der, $pos, $len);
    $pos += $len;
    return ltrim($v, "\x00");
}

function blens_ecdsa_der_to_raw(string $der, int $size = 32): string {
    $pos = 0;
    if (ord($der[$pos++] ?? "\x00") !== 0x30) return '';
    blens_der_length($der, $pos);
    $r = blens_der_integer($der, $pos);
    $s = blens_der_integer($der, $pos);
    if ($r === '' || $s === '') return '';
    return str_pad(substr($r, -$size), $size, "\x00", STR_PAD_LEFT) . str_pad(substr($s, -$size), $size, "\x00", STR_PAD_LEFT);
}

function blens_vapid_jwt(string $endpoint): string {
    $keys = blens_vapid_keys();
    $private = openssl_pkey_get_private($keys['privateKey']);
    if ($private === false || $keys['publicKey'] === '') return '';
    $aud = preg_match('#^https://([^/]+)#', $endpoint, $m) ? 'https://' . $m[1] : 'https://fcm.googleapis.com';
    $header = blens_b64url_encode((string)json_encode(['alg' => 'ES256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $payload = blens_b64url_encode((string)json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => $keys['subject']], JSON_UNESCAPED_SLASHES));
    $data = $header . '.' . $payload;
    if (!openssl_sign($data, $der, $private, OPENSSL_ALGO_SHA256)) return '';
    return $data . '.' . blens_b64url_encode(blens_ecdsa_der_to_raw($der));
}

function blens_push_encrypt(string $plain, string $p256dh, string $auth, ?string $ephemeralPem = null): array {
    $uaPublic = blens_b64url_decode($p256dh);
    $authSecret = blens_b64url_decode($auth);
    if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) return [];
    $uaPem = blens_ec_pem_from_point($uaPublic);
    $uaKey = $uaPem !== '' ? openssl_pkey_get_public($uaPem) : false;
    if ($uaKey === false) return [];

    if ($ephemeralPem === null) {
        $ephemeral = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($ephemeral === false) return [];
        openssl_pkey_export($ephemeral, $ephemeralPem);
    } else {
        $ephemeral = openssl_pkey_get_private($ephemeralPem);
        if ($ephemeral === false) return [];
    }
    $asPublic = blens_ec_public_point($ephemeral);
    if ($asPublic === '') return [];
    $shared = openssl_pkey_derive($uaKey, $ephemeral, 32);
    if ($shared === false || strlen($shared) !== 32) return [];

    $ikm = blens_hkdf_expand(blens_hkdf_extract($authSecret, $shared), "WebPush: info\x00" . $uaPublic . $asPublic, 32);
    $salt = random_bytes(16);
    $prk = blens_hkdf_extract($salt, $ikm);
    $cek = blens_hkdf_expand($prk, 'Content-Encoding: aes128gcm', 16);
    $nonce = blens_hkdf_expand($prk, 'Content-Encoding: nonce', 12);
    $cipher = openssl_encrypt($plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) return [];

    $body = $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
    return ['body' => $body, 'salt' => $salt, 'cek' => $cek, 'nonce' => $nonce, 'ephemeralPublic' => $asPublic];
}

function blens_push_send(array $sub, array $payload): array {
    $endpoint = trim((string)($sub['endpoint'] ?? ''));
    $p256dh = trim((string)($sub['p256dh'] ?? ''));
    $auth = trim((string)($sub['auth'] ?? ''));
    if (!str_starts_with($endpoint, 'https://') || $p256dh === '' || $auth === '') return ['ok' => false, 'status' => 0, 'delete' => false];
    $jwt = blens_vapid_jwt($endpoint);
    if ($jwt === '') return ['ok' => false, 'status' => 0, 'delete' => false];
    $enc = blens_push_encrypt((string)json_encode($payload, JSON_UNESCAPED_UNICODE), $p256dh, $auth);
    if (!isset($enc['body'])) return ['ok' => false, 'status' => 0, 'delete' => false];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $enc['body'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER => [
            'Authorization: vapid t=' . $jwt . ', k=' . blens_vapid_public(),
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
            'Urgency: high',
        ],
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'delete' => in_array($status, [404, 410], true), 'error' => $error];
}

function blens_push_subscriptions(PDO $pdo, string $tipo, ?int $clienteId): array {
    $sql = 'SELECT id,endpoint,p256dh,auth FROM push_subscriptions WHERE tipo=?';
    $args = [$tipo];
    if ($clienteId !== null) { $sql .= ' AND cliente_id=?'; $args[] = $clienteId; }
    $s = $pdo->prepare($sql);
    $s->execute($args);
    return $s->fetchAll();
}

function blens_push_delete(PDO $pdo, int $id): void {
    $pdo->prepare('DELETE FROM push_subscriptions WHERE id=?')->execute([$id]);
}

function blens_push_notify(PDO $pdo, string $tipo, ?int $clienteId, array $payload): void {
    if (blens_vapid_public() === '') return;
    foreach (blens_push_subscriptions($pdo, $tipo, $clienteId) as $sub) {
        try {
            $res = blens_push_send($sub, $payload);
            if ($res['delete']) blens_push_delete($pdo, (int)$sub['id']);
            elseif (!$res['ok']) error_log('[blens push] HTTP ' . $res['status'] . ' ' . ($res['error'] ?? ''));
        } catch (Throwable $x) { error_log('[blens push] ' . $x->getMessage()); }
    }
}
