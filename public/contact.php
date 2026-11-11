<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ---- Configuración: vive FUERA de la carpeta pública (ver config.example.php) ----
$cfg = require __DIR__ . '/../blens-config/config.php';
define('DB_HOST', $cfg['db_host']); define('DB_NAME', $cfg['db_name']);
define('DB_USER', $cfg['db_user']); define('DB_PASS', $cfg['db_pass']);
define('MAIL_TO', $cfg['mail_to']); define('MAX_POR_HORA', $cfg['max_por_hora']);

function out(bool $ok, string $msg, int $code = 200): never {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function clean(string $k, int $max): string {
    $v = trim((string)($_POST[$k] ?? ''));
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v) ?? '';
    return mb_substr($v, 0, $max);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(false, 'Método no permitido.', 405);

// Solo aceptamos envíos desde el propio sitio
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) out(false, 'Origen no permitido.', 403);

// Anti-spam: honeypot + tiempo mínimo de llenado
if (clean('website', 50) !== '') out(true, 'Consulta enviada.');           // bots: respuesta falsa
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

    // Límite por IP
    $q = $pdo->prepare('SELECT COUNT(*) FROM consultas WHERE ip = ? AND creado > (NOW() - INTERVAL 1 HOUR)');
    $q->execute([$ip]);
    if ((int)$q->fetchColumn() >= MAX_POR_HORA) out(false, 'Recibimos varias consultas tuyas. Esperá un rato e intentá otra vez.', 429);

    $ins = $pdo->prepare('INSERT INTO consultas (nombre, empresa, email, telefono, servicio, mensaje, ip) VALUES (?,?,?,?,?,?,?)');
    $ins->execute([$nombre, $empresa, $email, $telefono, $servicio, $mensaje, $ip]);
} catch (Throwable $e) {
    error_log('[blens contact] ' . $e->getMessage());
    out(false, 'No pudimos guardar tu consulta. Escribinos a ' . MAIL_TO . '.', 500);
}

// Notificación por email (requiere MTA local: msmtp o postfix). Si falla, la consulta ya está guardada.
$hdr = str_replace(["\r", "\n"], ' ', $email);
$body = "Nombre: $nombre\nEmpresa: $empresa\nEmail: $email\nTeléfono: $telefono\nServicio: $servicio\n\n$mensaje\n";
@mail(MAIL_TO, '=?UTF-8?B?' . base64_encode('Nueva consulta en blens.ar') . '?=', $body,
      ['From' => 'web@blens.ar', 'Reply-To' => $hdr, 'Content-Type' => 'text/plain; charset=UTF-8']);

out(true, 'Gracias, recibimos tu consulta. Te respondemos a la brevedad.');
