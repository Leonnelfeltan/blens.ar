<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
session_name('blens_cliente'); session_start();
$cid = (int)($_SESSION['cid'] ?? $_SESSION['cliente_id'] ?? 0);
if (!$cid || !empty($_SESSION['forzar'])) { http_response_code(403); exit; }
if (($_GET['tipo'] ?? '') !== 'portada') { http_response_code(400); exit; }
$eid = (int)($_GET['entrega_id'] ?? 0);
if ($eid <= 0) { http_response_code(404); exit; }
try {
    $db = blens_db(); blens_migrar($db);
    $s = $db->prepare('SELECT e.* FROM entregas e JOIN clientes c ON c.id=e.cliente_id WHERE e.id=? AND e.cliente_id=? AND c.activo=1');
    $s->execute([$eid, $cid]); $r = $s->fetch();
} catch (Throwable $x) { error_log('[blens descargar portada] ' . $x->getMessage()); http_response_code(500); exit; }
if (!$r) { http_response_code(404); exit; }
$path = blens_portada_para_descarga($db, $r);
if (!$path && !empty($r['proyecto_id'])) {
    try {
        $q = $db->prepare('SELECT poster FROM proyectos WHERE id=?'); $q->execute([(int)$r['proyecto_id']]);
        $pp = blens_webp((string)($q->fetchColumn() ?? ''));
        $abs = $pp !== '' ? blens_ruta_publica($pp) : '';
        if ($abs !== '' && is_file($abs)) {
            $dir = BLENS_ENTREGAS_DIR . '/descargas';
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            $dest = $dir . '/portada-' . $eid . '.jpg';
            if (blens_imagen_convertir($abs, $dest, 'jpg')) $path = $dest;
        }
    } catch (Throwable $x) { error_log('[blens descargar portada proyecto] ' . $x->getMessage()); }
}
if (!$path || !is_file($path)) { http_response_code(404); exit; }
$slug = blens_slug((string)($r['titulo'] ?? '')) ?: (string)$eid;
session_write_close();
blens_stream_archivo($path, 'image/jpeg', 'portada-' . $slug . '.jpg');
