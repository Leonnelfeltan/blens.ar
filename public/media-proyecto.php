<?php
declare(strict_types=1);
require __DIR__ . '/../blens-config/bootstrap.php';
header('X-Content-Type-Options: nosniff');
$id = (int)($_GET['id'] ?? 0);
$tipo = ($_GET['t'] ?? 'v') === 'p' ? 'p' : 'v';
if ($id <= 0) { http_response_code(404); exit; }
try {
    $db = blens_db(); blens_migrar($db);
    try {
        $s = $db->prepare('SELECT p.*, e.link_hd AS entrega_link, e.link_4k AS entrega_link4, e.poster AS entrega_poster, e.portada_drive_id AS entrega_portada_drive_id FROM proyectos p LEFT JOIN entregas e ON e.id=p.entrega_id WHERE p.id=? AND p.visible=1');
        $s->execute([$id]);
    } catch (Throwable $x) {
        $s = $db->prepare('SELECT p.*, e.link_hd AS entrega_link, e.link_4k AS entrega_link4, e.poster AS entrega_poster FROM proyectos p LEFT JOIN entregas e ON e.id=p.entrega_id WHERE p.id=? AND p.visible=1');
        $s->execute([$id]);
    }
    $r = $s->fetch();
} catch (Throwable $x) { error_log('[blens media proyecto] ' . $x->getMessage()); http_response_code(500); exit; }
if (!$r) { http_response_code(404); exit; }

if ($tipo === 'p') {
    $poster = blens_webp(trim((string)($r['poster'] ?? '')));
    $abs = $poster !== '' ? blens_ruta_publica($poster) : '';
    if ($abs === '' || !is_file($abs)) {
        $entrega = [
            'id' => (int)($r['entrega_id'] ?? 0),
            'poster' => (string)($r['entrega_poster'] ?? ''),
            'portada_drive_id' => (string)($r['entrega_portada_drive_id'] ?? ''),
            'link_hd' => (string)($r['entrega_link'] ?? $r['fuente_drive'] ?? ''),
            'link_4k' => (string)($r['entrega_link4'] ?? ''),
        ];
        $rel = (string)blens_publicar_portada($db, $entrega, $id);
        $poster2 = blens_webp($rel);
        $abs = $poster2 !== '' ? blens_ruta_publica($poster2) : ($rel !== '' && is_file($rel) ? $rel : '');
    }
    if ($abs === '' || !is_file($abs)) {
        $driveId = blens_proyecto_drive_id($db, $id);
        if ($driveId !== '') {
            $abs = blens_uploads_proyectos() . '/proyecto-' . $id . '-portada.jpg';
            if (!is_file($abs)) @blens_portada_drive_cachear($driveId, $abs);
        }
    }
    if ($abs === '' || !is_file($abs)) { http_response_code(404); exit; }
    $mime = (string)(mime_content_type($abs) ?: 'image/jpeg');
    if (!str_starts_with($mime, 'image/')) $mime = 'image/jpeg';
    blens_stream_archivo($abs, $mime);
    exit;
}

$video = blens_video_local($r);
if ($video !== '') { blens_stream_archivo(blens_ruta_publica($video), 'video/mp4'); exit; }
$driveId = blens_proyecto_drive_id($db, $id);
if ($driveId !== '') {
    blens_lanzar_cachear_drive($id);
    if (blens_stream_drive('https://drive.google.com/file/d/' . $driveId . '/view')) exit;
}
http_response_code(503);
