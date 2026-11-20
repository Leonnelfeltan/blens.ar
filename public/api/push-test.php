<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/../../blens-config/bootstrap.php';

function blens_push_test_out(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') blens_push_test_out(405, ['ok' => false, 'error' => 'Método no permitido.']);
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) blens_push_test_out(400, ['ok' => false, 'error' => 'JSON inválido.']);

session_name('PHPSESSID');
session_start();
if (empty($_SESSION['ok'])) blens_push_test_out(401, ['ok' => false, 'error' => 'No autorizado.']);
if (empty($_SESSION['t']) || !hash_equals($_SESSION['t'], (string)($body['token'] ?? ''))) blens_push_test_out(403, ['ok' => false, 'error' => 'Sesión vencida.']);

$delay = max(0, min(10, (int)($body['delay'] ?? 0)));
if ($delay > 0) {
    session_write_close();
    if (function_exists('set_time_limit')) set_time_limit($delay + 20);
    sleep($delay);
}
$pdo = blens_db();
blens_migrar($pdo);
$endpoint = trim((string)($body['endpoint'] ?? ''));
$payload = [
    'title' => 'Push de prueba · blens.ar',
    'body' => 'Si ves esto, las notificaciones nativas funcionan correctamente.',
    'url' => '/admin/',
    'tag' => 'blens-push-test-' . time(),
];
if ($endpoint !== '') {
    $res = blens_push_send_target($pdo, 'admin', null, $endpoint, $payload);
    $detalle = trim((string)($res['response'] ?? '') . ' ' . (string)($res['error'] ?? ''));
    $message = $res['ok']
        ? 'Enviado (HTTP ' . (int)$res['status'] . ')'
        : 'No se pudo enviar: HTTP ' . (int)$res['status'] . ($detalle !== '' ? ' · ' . $detalle : '');
    blens_push_test_out($res['ok'] ? 200 : 502, [
        'ok' => $res['ok'],
        'message' => $message,
        'host' => $res['host'] ?? blens_push_host($endpoint),
        'status' => (int)$res['status'],
        'response' => (string)($res['response'] ?? ''),
        'error' => (string)($res['error'] ?? ''),
        'intentados' => 1,
        'enviados' => $res['ok'] ? 1 : 0,
    ]);
}
$res = blens_push_notify_detailed($pdo, 'admin', null, $payload);
if ($res['enviados'] > 0) {
    $message = 'Enviado (HTTP ' . implode(', ', array_slice($res['estados'], 0, 3)) . ')';
} elseif ($res['intentados'] === 0) {
    $message = 'No hay suscripciones admin activas. Activá las notificaciones en este celu.';
} else {
    $message = 'No se pudo enviar: ' . implode(' | ', array_slice($res['errores'], 0, 2));
}
blens_push_test_out($res['ok'] ? 200 : 502, [
    'ok' => $res['ok'],
    'message' => $message,
    'intentados' => $res['intentados'],
    'enviados' => $res['enviados'],
    'eliminados' => $res['eliminados'],
    'estados' => $res['estados'],
    'errores' => $res['errores'],
]);
