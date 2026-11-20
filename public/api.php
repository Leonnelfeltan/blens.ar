<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
require __DIR__ . '/../blens-config/bootstrap.php';
try {
    $db = blens_db(); blens_migrar($db);
    try {
        $rows = $db->query("SELECT p.id,p.titulo,p.cliente,p.categoria,p.descripcion,p.video_mp4,p.video_webm,p.video_1080,p.video_480,p.poster,p.ancho,p.cliente_id,p.entrega_id,p.mostrar_cliente,e.titulo AS entrega_titulo,e.descripcion AS entrega_descripcion,e.tipo AS entrega_tipo,e.link_hd AS entrega_link,e.link_4k AS entrega_link4,e.creado AS entrega_creado FROM proyectos p LEFT JOIN entregas e ON e.id=p.entrega_id WHERE p.visible=1 AND (p.estado='listo' OR p.video_mp4 IS NOT NULL OR p.entrega_id IS NOT NULL) ORDER BY p.orden ASC, p.id DESC")->fetchAll();
    } catch (Throwable $x) {
        $rows = $db->query('SELECT id,titulo,cliente,categoria,descripcion,video_mp4,video_webm,poster,ancho FROM proyectos WHERE visible=1 ORDER BY orden ASC, id DESC')->fetchAll();
    }
    $mejor = function (array $r): string {
        return (string)($r['video_1080'] ?? '') ?: (string)($r['video_mp4'] ?? '') ?: (string)($r['video_480'] ?? '');
    };
    $show = null; $list = [];
    foreach ($rows as $r) { if (($r['categoria'] ?? '') === 'showreel') { $show ??= $r; } else { $list[] = $r; } }
    if ($show) {
        $show['poster'] = blens_webp((string)($show['poster'] ?? ''));
        $show['video_mp4'] = $mejor($show);
        unset($show['video_1080'], $show['video_480'], $show['video_webm']);
    }
    $base = ['reels' => 'Reels', 'cobertura' => 'Cobertura', 'estrategia' => 'Estrategia'];
    $cats = $base;
    foreach ($list as &$r) {
        $c = (string)($r['categoria'] ?? '');
        if ($c === '' || $c === 'auto') $r['categoria'] = $c = 'reels';
        if (!isset($cats[$c])) $cats[$c] = ucfirst(str_replace('-', ' ', $c));
        $r['poster'] = blens_webp((string)($r['poster'] ?? ''));
        $r['video_mp4'] = $mejor($r);
        unset($r['video_1080'], $r['video_480'], $r['video_webm']);
        if (array_key_exists('mostrar_cliente', $r) && (int)$r['mostrar_cliente'] === 0) $r['cliente'] = '';
        $link = (string)($r['entrega_link'] ?? '') ?: (string)($r['entrega_link4'] ?? '');
        $did = $link !== '' ? blens_drive_id($link) : '';
        if ($did !== '') {
            $r['drive_id'] = $did;
            if (!$r['poster']) $r['poster_remote'] = 'https://drive.google.com/thumbnail?id=' . $did . '&sz=w800';
        }
    }
    unset($r);
    $cli = [];
    try { $cli = $db->query("SELECT COALESCE(NULLIF(empresa,''),nombre) AS nombre, logo FROM clientes WHERE logo IS NOT NULL AND logo<>'' AND COALESCE(logo_web,1)=1 ORDER BY activo DESC, empresa, nombre")->fetchAll(); }
    catch (Throwable $x) { try { $cli = $db->query("SELECT COALESCE(NULLIF(empresa,''),nombre) AS nombre, logo FROM clientes WHERE logo IS NOT NULL AND logo<>'' ORDER BY activo DESC, empresa, nombre")->fetchAll(); } catch (Throwable $x2) { } }
    foreach ($cli as &$c) {
        $logo = trim((string)($c['logo'] ?? ''));
        if ($logo === '' || preg_match('~^https?://~i', $logo)) { $c['logo'] = ''; continue; }
        $rel = blens_webp('media/logos/' . basename(str_replace('\\', '/', $logo)));
        $c['logo'] = is_file(__DIR__ . '/' . $rel) ? $rel : '';
    }
    unset($c);
    $wa = blens_wa_numero();
    echo json_encode(['showreel' => $show, 'proyectos' => $list, 'clientes' => $cli, 'categorias' => $cats, 'wa' => $wa], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[blens api] ' . $e->getMessage()); http_response_code(500);
    echo '{"showreel":null,"proyectos":[],"clientes":[]}';
}
