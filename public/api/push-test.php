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

$pdo = blens_db();
blens_migrar($pdo);
$res = blens_push_notify_detailed($pdo, 'admin', null, [
    'title' => 'Push de prueba · blens.ar',
    'body' => 'Si ves esto, las notificaciones nativas funcionan correctamente.',
    'url' => '/admin/',
    'tag' => 'blens-push-test-' . time(),
]);

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
