<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
require __DIR__ . '/../blens-config/bootstrap.php';
try {
    $db = blens_db(); blens_migrar($db);
    try {
        $rows = $db->query("SELECT p.id,p.titulo,p.cliente,p.categoria,p.descripcion,p.video_mp4,p.video_webm,p.video_1080,p.video_480,p.poster,p.fuente_drive,p.video_drive_id,p.ancho,p.formato,p.mostrar_cliente,e.titulo AS entrega_titulo,e.tipo AS entrega_tipo,e.link_hd AS entrega_link,e.link_4k AS entrega_link4,e.poster AS entrega_poster,e.portada_drive_id AS entrega_portada_drive_id,e.creado AS entrega_creado FROM proyectos p LEFT JOIN entregas e ON e.id=p.entrega_id WHERE p.visible=1 ORDER BY p.orden ASC, p.id DESC")->fetchAll();
    } catch (Throwable $x) {
        $rows = $db->query('SELECT id,titulo,cliente,categoria,descripcion,video_mp4,video_webm,poster,ancho,formato FROM proyectos WHERE visible=1 ORDER BY orden ASC, id DESC')->fetchAll();
    }
    $rows = array_values(array_filter($rows, 'blens_proyecto_tiene_media'));
    $videoUrl = function (array $r): string {
        $local = blens_video_local($r);
        if ($local !== '') return blens_url_versionada($local);
        $id = blens_drive_id((string)($r['video_drive_id'] ?? '')) ?: blens_drive_id((string)($r['fuente_drive'] ?? ''))
            ?: blens_drive_id((string)($r['entrega_link'] ?? '')) ?: blens_drive_id((string)($r['entrega_link4'] ?? ''));
        return $id !== '' ? 'media-proyecto.php?id=' . (int)$r['id'] : '';
    };
    $posterUrl = function (array $r): string {
        $raw = trim((string)($r['poster'] ?? ''));
        if ($raw !== '' && blens_drive_id($raw) === '' && !preg_match('~^https?://~i', $raw)) {
            $local = blens_webp($raw);
            if ($local !== '' && blens_ruta_publica($local) !== '') return blens_url_versionada($local);
        }
        $id = blens_drive_id((string)($r['video_drive_id'] ?? '')) ?: blens_drive_id((string)($r['fuente_drive'] ?? ''))
            ?: blens_drive_id((string)($r['entrega_link'] ?? '')) ?: blens_drive_id((string)($r['entrega_link4'] ?? ''));
        if ($raw !== '' || !empty($r['entrega_poster']) || !empty($r['entrega_portada_drive_id']) || $id !== '') return 'media-proyecto.php?id=' . (int)$r['id'] . '&t=p';
        return '';
    };
    $permitidas = array_flip(['id', 'titulo', 'cliente', 'categoria', 'descripcion', 'ancho', 'formato', 'entrega_titulo', 'entrega_tipo', 'entrega_creado', 'video_mp4', 'poster']);
    $limpiar = function (array $r) use ($videoUrl, $posterUrl, $permitidas): array {
        $original = $r;
        $ocultarCliente = array_key_exists('mostrar_cliente', $r) && (int)$r['mostrar_cliente'] === 0;
        $r = array_intersect_key($r, $permitidas);
        $r['video_mp4'] = $videoUrl($original);
        $r['poster'] = $posterUrl($original);
        $r['formato'] = blens_formato((string)($r['formato'] ?? ''));
        if ($ocultarCliente) $r['cliente'] = '';
        return $r;
    };
    $show = null; $list = [];
    foreach ($rows as $r) { if (($r['categoria'] ?? '') === 'showreel') { $show ??= $r; } else { $list[] = $r; } }
    if ($show) $show = $limpiar($show);
    $base = ['reels' => 'Reels', 'cobertura' => 'Cobertura', 'estrategia' => 'Estrategia'];
    $cats = $base;
    foreach ($list as &$r) {
        $c = (string)($r['categoria'] ?? '');
        if ($c === '' || $c === 'auto') $r['categoria'] = $c = 'reels';
        if (!isset($cats[$c])) $cats[$c] = ucfirst(str_replace('-', ' ', $c));
        $r = $limpiar($r);
        $r['categoria'] = $c;
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
