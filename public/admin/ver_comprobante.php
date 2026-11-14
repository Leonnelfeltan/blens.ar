<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

if (empty($_SESSION['ok'])) { http_response_code(403); exit('Acceso denegado.'); }

$tipo = (string)($_GET['tipo'] ?? '');
if (!in_array($tipo, ['cobro', 'gasto'], true)) { http_response_code(400); exit('Solicitud inválida.'); }
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Solicitud inválida.'); }

$tabla = $tipo === 'gasto' ? 'gastos' : 'trabajos';
$nombre = '';
try {
    $pdo = blens_db();
    $s = $pdo->prepare("SELECT comprobante FROM `$tabla` WHERE id=?");
    $s->execute([$id]);
    $nombre = (string)($s->fetchColumn() ?: '');
} catch (Throwable $x) {
    error_log('[blens comprobante] ' . $x->getMessage());
    http_response_code(404); exit('Comprobante no encontrado.');
}

$nombre = basename($nombre);
if ($nombre === '' || $nombre === '.' || $nombre === '..') { http_response_code(404); exit('Comprobante no encontrado.'); }
$path = COMPROBANTES_DIR . '/' . $nombre;
if (!is_file($path)) { http_response_code(404); exit('Comprobante no encontrado.'); }

$mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
$mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!isset($mimes[$mime])) { http_response_code(415); exit('Formato no permitido.'); }

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="comprobante-' . $id . '.' . $mimes[$mime] . '"');
readfile($path);
exit;
