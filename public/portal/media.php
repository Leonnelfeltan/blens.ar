<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
session_name('blens_cliente'); session_start();
$cid = (int)($_SESSION['cliente_id'] ?? $_SESSION['cid'] ?? 0);
if (!$cid || !empty($_SESSION['forzar'])) { http_response_code(403); exit; }
$db = blens_db();
try { $s = $db->prepare('SELECT e.id,e.titulo,e.preview,e.poster,e.link_hd,e.link_4k,e.portada_drive_id FROM entregas e JOIN clientes c ON c.id=e.cliente_id WHERE e.id=? AND e.cliente_id=? AND c.activo=1'); $s->execute([(int)($_GET['e'] ?? 0), $cid]); }
catch (Throwable $x) { $s = $db->prepare('SELECT e.id,e.titulo,e.preview,e.poster,e.link_hd,e.link_4k FROM entregas e JOIN clientes c ON c.id=e.cliente_id WHERE e.id=? AND e.cliente_id=? AND c.activo=1'); $s->execute([(int)($_GET['e'] ?? 0), $cid]); }
$r = $s->fetch();
$t = ($_GET['t'] ?? 'v') === 'p' ? 'p' : 'v';
if (!$r) { http_response_code(404); exit; }
session_write_close();
if ($t === 'p') {
    $path = blens_entrega_portada_origen($db, $r);
    if (!$path || !is_file($path)) { http_response_code(404); exit; }
    $mime = (string)(mime_content_type($path) ?: 'image/jpeg');
    if (!str_starts_with($mime, 'image/')) $mime = 'image/jpeg';
    blens_stream_archivo($path, $mime);
    exit;
}
$f = (string)($r['preview'] ?? '');
$path = $f !== '' ? BLENS_ENTREGAS_DIR . '/' . basename(str_replace('\\', '/', $f)) : '';
if ($path === '' || !is_file($path)) { http_response_code(404); exit; }
blens_stream_archivo($path, 'video/mp4');
