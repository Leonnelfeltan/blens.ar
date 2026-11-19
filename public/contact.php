<?php
declare(strict_types=1);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
require __DIR__ . '/../blens-config/bootstrap.php';
$cfg = blens_cfg();
define('DB_HOST', $cfg['db_host']); define('DB_NAME', $cfg['db_name']);
define('DB_USER', $cfg['db_user']); define('DB_PASS', $cfg['db_pass']);
define('MAIL_TO', $cfg['mail_to']); define('MAX_POR_HORA', $cfg['max_por_hora']);
$wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') || str_contains((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest');

function out(bool $ok, string $msg, int $code = 200): never {
    global $wantsJson;
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode(['ok' => $ok, 'message' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($ok) { header('Location: /?enviado=1#contacto', true, 303); exit; }
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>No pudimos enviar la consulta | blens.ar</title><link rel="stylesheet" href="/styles.css?v=20"></head><body><main class="err404"><h1>No pudimos enviar la consulta.</h1><p class="lead">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p><p><a class="btn" href="/#contacto">Volver al formulario</a></p></main></body></html>';
    exit;
}
function clean(string $k, int $max): string {
    $v = trim((string)($_POST[$k] ?? ''));
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v) ?? '';
    return mb_substr($v, 0, $max);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(false, 'Método no permitido.', 405);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) out(false, 'Origen no permitido.', 403);

if (clean('website', 50) !== '') out(true, 'Consulta enviada.');
$ts = (int)($_POST['ts'] ?? 0);
if ($ts > 0 && (time() * 1000 - $ts) < 3000) out(false, 'Enviaste el formulario demasiado rápido. Probá de nuevo.', 429);

$nombre   = clean('nombre', 80);
$empresa  = clean('empresa', 80);
$email    = clean('email', 120);
$telefono = clean('telefono', 30);
$servicio = clean('servicio', 60);
$mensaje  = clean('mensaje', 2000);

if ($nombre === '' || $mensaje === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    out(false, 'Completá nombre, email válido y mensaje.', 422);
}
$ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    blens_migrar($pdo);
    $q = $pdo->prepare('SELECT COUNT(*) FROM consultas WHERE ip = ? AND creado > (NOW() - INTERVAL 1 HOUR)');
    $q->execute([$ip]);
    if ((int)$q->fetchColumn() >= MAX_POR_HORA) out(false, 'Recibimos varias consultas tuyas. Esperá un rato e intentá otra vez.', 429);

    $ins = $pdo->prepare('INSERT INTO consultas (nombre, empresa, email, telefono, servicio, mensaje, ip) VALUES (?,?,?,?,?,?,?)');
    $ins->execute([$nombre, $empresa, $email, $telefono, $servicio, $mensaje, $ip]);
    $consultaId = (int)$pdo->lastInsertId();
    blens_push_notify($pdo, 'admin', null, [
        'title' => 'Nueva consulta · blens.ar',
        'body' => trim(($empresa !== '' ? $empresa : $nombre) . ($servicio !== '' ? ' · ' . $servicio : '') . ': ' . mb_substr($mensaje, 0, 140)),
        'url' => '/admin/consultas.php',
        'tag' => 'blens-consulta-' . $consultaId,
    ]);
} catch (Throwable $e) {
    error_log('[blens contact] ' . $e->getMessage());
    out(false, 'No pudimos guardar tu consulta. Escribinos a ' . MAIL_TO . '.', 500);
}

$hdr = str_replace(["\r", "\n"], ' ', $email);
$body = "Nombre: $nombre\nEmpresa: $empresa\nEmail: $email\nTeléfono: $telefono\nServicio: $servicio\n\n$mensaje\n";
@mail(MAIL_TO, '=?UTF-8?B?' . base64_encode('Nueva consulta en blens.ar') . '?=', $body,
      ['From' => 'web@blens.ar', 'Reply-To' => $hdr, 'Content-Type' => 'text/plain; charset=UTF-8']);

out(true, 'Gracias, recibimos tu consulta. Te respondemos a la brevedad.');
