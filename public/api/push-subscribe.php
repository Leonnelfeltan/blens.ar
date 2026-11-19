<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/../../blens-config/bootstrap.php';

function blens_push_out(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') blens_push_out(405, ['ok' => false, 'error' => 'Método no permitido.']);

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) blens_push_out(400, ['ok' => false, 'error' => 'JSON inválido.']);
$tipo = (string)($body['tipo'] ?? '');
if (!in_array($tipo, ['admin', 'cliente'], true)) blens_push_out(400, ['ok' => false, 'error' => 'Tipo inválido.']);

session_name($tipo === 'admin' ? 'PHPSESSID' : 'blens_cliente');
session_start();
if (empty($_SESSION['t']) || !hash_equals($_SESSION['t'], (string)($body['token'] ?? ''))) blens_push_out(403, ['ok' => false, 'error' => 'Sesión vencida.']);

$clienteId = null;
if ($tipo === 'admin') {
    if (empty($_SESSION['ok'])) blens_push_out(401, ['ok' => false, 'error' => 'No autorizado.']);
} else {
    $clienteId = (int)($_SESSION['cid'] ?? $_SESSION['cliente_id'] ?? 0);
    if ($clienteId <= 0) blens_push_out(401, ['ok' => false, 'error' => 'No autorizado.']);
}

$pdo = blens_db();
blens_migrar($pdo);
$sub = is_array($body['subscription'] ?? null) ? $body['subscription'] : [];
$endpoint = trim((string)($sub['endpoint'] ?? ''));
$p256dh = trim((string)($sub['keys']['p256dh'] ?? $sub['p256dh'] ?? ''));
$auth = trim((string)($sub['keys']['auth'] ?? $sub['auth'] ?? ''));
if (!blens_push_endpoint_valido($endpoint) || strlen($endpoint) > 2000) blens_push_out(400, ['ok' => false, 'error' => 'Endpoint de notificaciones no permitido.']);
if (strlen(blens_b64url_decode($p256dh)) !== 65 || strlen(blens_b64url_decode($auth)) !== 16) blens_push_out(400, ['ok' => false, 'error' => 'Claves de suscripción inválidas.']);

$hash = hash('sha256', $tipo . '|' . ($clienteId ?? '') . '|' . $endpoint);
$action = (string)($body['action'] ?? 'subscribe');
if ($action === 'unsubscribe') {
    $s = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash=? AND tipo=?');
    $s->execute([$hash, $tipo]);
    blens_push_out(200, ['ok' => true, 'active' => false]);
}
if ($action !== 'subscribe') blens_push_out(400, ['ok' => false, 'error' => 'Acción inválida.']);

$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$pdo->prepare('DELETE FROM push_subscriptions WHERE tipo=? AND endpoint=? AND endpoint_hash<>?')->execute([$tipo, $endpoint, $hash]);
$s = $pdo->prepare('INSERT INTO push_subscriptions (tipo,cliente_id,endpoint,p256dh,auth,user_agent,endpoint_hash)
    VALUES (?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE p256dh=VALUES(p256dh), auth=VALUES(auth), user_agent=VALUES(user_agent), cliente_id=VALUES(cliente_id)');
$s->execute([$tipo, $clienteId, $endpoint, $p256dh, $auth, $ua, $hash]);
blens_push_out(200, ['ok' => true, 'active' => true]);
