<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
require __DIR__ . '/../blens-config/bootstrap.php';
try {
    $db = blens_db();
    try {
        $rows = $db->query("SELECT id,titulo,cliente,categoria,descripcion,video_mp4,video_webm,video_1080,video_480,poster,ancho FROM proyectos WHERE visible=1 AND (estado='listo' OR video_mp4 IS NOT NULL) ORDER BY orden ASC, id DESC")->fetchAll();
    } catch (Throwable $x) {
        // la base todavía no tiene las columnas nuevas: se usa la consulta anterior
        $rows = $db->query('SELECT id,titulo,cliente,categoria,descripcion,video_mp4,video_webm,poster,ancho FROM proyectos WHERE visible=1 ORDER BY orden ASC, id DESC')->fetchAll();
    }
    $show = null; $list = [];
    foreach ($rows as $r) { if ($r['categoria'] === 'showreel') { $show ??= $r; } else { $list[] = $r; } }
    $cli = [];
    try { $cli = $db->query("SELECT COALESCE(NULLIF(empresa,''),nombre) AS nombre, logo FROM clientes WHERE activo=1 AND logo IS NOT NULL AND logo<>'' AND COALESCE(logo_web,1)=1 ORDER BY empresa,nombre")->fetchAll(); }
    catch (Throwable $x) { try { $cli = $db->query("SELECT COALESCE(NULLIF(empresa,''),nombre) AS nombre, logo FROM clientes WHERE activo=1 AND logo IS NOT NULL AND logo<>'' ORDER BY empresa,nombre")->fetchAll(); } catch (Throwable $x2) { } }
    $wa = preg_replace('/\D+/', '', (string)(blens_cfg()['wa_negocio'] ?? '')) ?? '';
    echo json_encode(['showreel' => $show, 'proyectos' => $list, 'clientes' => $cli, 'wa' => $wa], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[blens api] ' . $e->getMessage()); http_response_code(500);
    echo '{"showreel":null,"proyectos":[],"clientes":[]}';
}
