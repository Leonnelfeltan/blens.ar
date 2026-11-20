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
    return $keys = [
        'publicKey' => (string)($data['publicKey'] ?? ''),
        'privateKey' => $private,
        'subject' => 'mailto:hola@blens.ar',
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

function blens_push_host(string $endpoint): string {
    return strtolower(trim((string)(parse_url($endpoint, PHP_URL_HOST) ?? '')));
}
function blens_push_debug_log(string $tipo, string $host, int $status, string $detalle = ''): void {
    $path = dirname(__DIR__, 2) . '/blens-private/push-debug.log';
    $linea = date('Y-m-d H:i:s') . ' | ' . $tipo . ' | ' . ($host !== '' ? $host : '-') . ' | ' . $status;
    if ($detalle !== '') $linea .= ' | ' . preg_replace('/\s+/', ' ', substr($detalle, 0, 200));
    $lineas = is_file($path) ? (@file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
    $lineas[] = $linea;
    @file_put_contents($path, implode("\n", array_slice($lineas, -20)) . "\n", LOCK_EX);
}
function blens_push_hash(string $tipo, ?int $clienteId, string $endpoint): string {
    return hash('sha256', $tipo . '|' . ($clienteId ?? '') . '|' . $endpoint);
}
function blens_push_origin(string $endpoint): string {
    $u = parse_url($endpoint);
    if (!is_array($u) || strtolower((string)($u['scheme'] ?? '')) !== 'https') return '';
    $host = strtolower(trim((string)($u['host'] ?? '')));
    if ($host === '') return '';
    $port = (int)($u['port'] ?? 443);
    return 'https://' . $host . ($port > 0 && $port !== 443 ? ':' . $port : '');
}

function blens_push_endpoint_valido(string $endpoint): bool {
    $host = strtolower((string)parse_url(blens_push_origin($endpoint), PHP_URL_HOST));
    if ($host === '') return false;
    if (in_array($host, ['fcm.googleapis.com', 'web.push.apple.com'], true)) return true;
    return (bool)preg_match('/^updates\d*\.push\.services\.mozilla\.com$/', $host);
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
    $n = ord($der[$pos++] ?? "\x00");
    if (($n & 0x80) === 0) return $n;
    $bytes = $n & 0x7f;
    if ($bytes < 1 || $bytes > 4) return -1;
    $len = 0;
    for ($i = 0; $i < $bytes; $i++) {
        if ($pos >= strlen($der)) return -1;
        $len = ($len << 8) | ord($der[$pos++]);
    }
    return $len;
}

function blens_der_integer(string $der, int &$pos, int $size): string {
    if ($pos >= strlen($der) || ord($der[$pos++]) !== 0x02) return '';
    $len = blens_der_length($der, $pos);
    if ($len < 1 || $len > $size + 1 || $pos + $len > strlen($der)) return '';
    $v = substr($der, $pos, $len);
    $pos += $len;
    if (strlen($v) === $size + 1) {
        if ($v[0] !== "\x00" || (ord($v[1]) & 0x80) === 0) return '';
        $v = substr($v, 1);
    }
    $v = ltrim($v, "\x00");
    if ($v === '' || strlen($v) > $size) return '';
    return str_pad($v, $size, "\x00", STR_PAD_LEFT);
}

function blens_ecdsa_der_to_raw(string $der, int $size = 32): string {
    $pos = 0; $len = strlen($der);
    if ($len < 8 || ord($der[$pos++]) !== 0x30) return '';
    $seqLen = blens_der_length($der, $pos);
    if ($seqLen < 0 || $seqLen !== $len - $pos) return '';
    $r = blens_der_integer($der, $pos, $size);
    $s = blens_der_integer($der, $pos, $size);
    if ($r === '' || $s === '' || $pos !== $len) return '';
    return $r . $s;
}

function blens_vapid_jwt(string $endpoint): string {
    $origin = blens_push_origin($endpoint);
    $keys = blens_vapid_keys();
    $private = openssl_pkey_get_private($keys['privateKey']);
    if ($origin === '' || $private === false || $keys['publicKey'] === '') return '';
    $header = blens_b64url_encode((string)json_encode(['alg' => 'ES256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $payload = blens_b64url_encode((string)json_encode(['aud' => $origin, 'exp' => time() + 43200, 'sub' => $keys['subject']], JSON_UNESCAPED_SLASHES));
    $data = $header . '.' . $payload;
    if (!openssl_sign($data, $der, $private, OPENSSL_ALGO_SHA256)) return '';
    $raw = blens_ecdsa_der_to_raw($der);
    if (strlen($raw) !== 64) return '';
    return $data . '.' . blens_b64url_encode($raw);
}

function blens_push_encrypt(string $plain, string $p256dh, string $auth, ?string $ephemeralPem = null): array {
    if (strlen($plain) > 4079) return [];
    $uaPublic = blens_b64url_decode($p256dh);
    $authSecret = blens_b64url_decode($auth);
    if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) !== 16) return [];
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
    if ($asPublic === '' || strlen($asPublic) !== 65) return [];
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

function blens_push_send(array $sub, array $payload, string $tipo = 'admin'): array {
    $endpoint = trim((string)($sub['endpoint'] ?? ''));
    $p256dh = trim((string)($sub['p256dh'] ?? ''));
    $auth = trim((string)($sub['auth'] ?? ''));
    $host = blens_push_host($endpoint);
    if (!blens_push_endpoint_valido($endpoint) || strlen($endpoint) > 2000 || $p256dh === '' || $auth === '') {
        blens_push_debug_log($tipo, $host, 0, 'Endpoint inválido.');
        return ['ok' => false, 'status' => 0, 'delete' => false, 'error' => 'Endpoint inválido.', 'response' => '', 'host' => $host];
    }
    $jwt = blens_vapid_jwt($endpoint);
    if ($jwt === '') {
        blens_push_debug_log($tipo, $host, 0, 'JWT VAPID inválido.');
        return ['ok' => false, 'status' => 0, 'delete' => false, 'error' => 'JWT VAPID inválido.', 'response' => '', 'host' => $host];
    }
    $plain = (string)json_encode($payload, JSON_UNESCAPED_UNICODE);
    $enc = blens_push_encrypt($plain, $p256dh, $auth);
    if (!isset($enc['body'])) {
        blens_push_debug_log($tipo, $host, 0, 'No se pudo cifrar el payload.');
        return ['ok' => false, 'status' => 0, 'delete' => false, 'error' => 'No se pudo cifrar el payload.', 'response' => '', 'host' => $host];
    }

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
    $response = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($status >= 400 || $status === 0) {
        $detalle = trim(substr($response, 0, 200) . ' ' . $error);
        error_log('[blens push] HTTP ' . $status . ' body=' . substr($response, 0, 500) . ' error=' . $error);
        blens_push_debug_log($tipo, $host, $status, $detalle);
    } else {
        blens_push_debug_log($tipo, $host, $status);
    }
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'delete' => in_array($status, [404, 410], true),
        'error' => $error,
        'response' => substr($response, 0, 500),
        'host' => $host,
    ];
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
function blens_push_send_target(PDO $pdo, string $tipo, ?int $clienteId, string $endpoint, array $payload): array {
    $endpoint = trim($endpoint);
    $host = blens_push_host($endpoint);
    if (!blens_push_endpoint_valido($endpoint) || strlen($endpoint) > 2000) {
        blens_push_debug_log($tipo, $host, 0, 'Endpoint inválido.');
        return ['ok' => false, 'status' => 0, 'delete' => false, 'error' => 'Endpoint de notificaciones no permitido.', 'response' => '', 'host' => $host];
    }
    $sql = 'SELECT id,endpoint,p256dh,auth FROM push_subscriptions WHERE endpoint_hash=? AND tipo=?';
    $args = [blens_push_hash($tipo, $clienteId, $endpoint), $tipo];
    if ($clienteId !== null) { $sql .= ' AND cliente_id=?'; $args[] = $clienteId; }
    $s = $pdo->prepare($sql);
    $s->execute($args);
    $sub = $s->fetch();
    if (!$sub) {
        blens_push_debug_log($tipo, $host, 0, 'Suscripción no encontrada.');
        return ['ok' => false, 'status' => 0, 'delete' => false, 'error' => 'Suscripción no encontrada. Volvé a sincronizar.', 'response' => '', 'host' => $host];
    }
    $res = blens_push_send($sub, $payload, $tipo);
    if (!empty($res['delete'])) blens_push_delete($pdo, (int)$sub['id']);
    return $res;
}

function blens_push_notify_detailed(PDO $pdo, string $tipo, ?int $clienteId, array $payload): array {
    $res = ['ok' => false, 'intentados' => 0, 'enviados' => 0, 'eliminados' => 0, 'estados' => [], 'errores' => []];
    if (blens_vapid_public() === '') {
        $res['errores'][] = 'Falta configuración VAPID.';
        return $res;
    }
    foreach (blens_push_subscriptions($pdo, $tipo, $clienteId) as $sub) {
        try {
            $envio = blens_push_send($sub, $payload, $tipo);
            $res['intentados']++;
            $res['estados'][] = $envio['status'];
            if ($envio['delete']) {
                blens_push_delete($pdo, (int)$sub['id']);
                $res['eliminados']++;
            }
            if ($envio['ok']) $res['enviados']++;
            else $res['errores'][] = 'HTTP ' . $envio['status'] . ' ' . trim(($envio['response'] ?? '') . ' ' . ($envio['error'] ?? ''));
        } catch (Throwable $x) {
            $res['errores'][] = $x->getMessage();
            error_log('[blens push] ' . $x->getMessage());
        }
    }
    $res['ok'] = $res['enviados'] > 0;
    return $res;
}

function blens_push_notify(PDO $pdo, string $tipo, ?int $clienteId, array $payload): void {
    blens_push_notify_detailed($pdo, $tipo, $clienteId, $payload);
}
