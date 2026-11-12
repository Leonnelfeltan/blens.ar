<?php
declare(strict_types=1);
require __DIR__ . '/../blens-config/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$sitio = rtrim((string)(blens_cfg()['sitio'] ?? 'https://blens.ar'), '/');
$urls = [['loc' => $sitio . '/', 'lastmod' => date('Y-m-d')]];
try {
    $db = blens_db();
    try {
        $rows = $db->query("SELECT id,creado FROM proyectos WHERE visible=1 AND (estado='listo' OR video_mp4 IS NOT NULL) ORDER BY orden ASC, id DESC")->fetchAll();
    } catch (Throwable $x) {
        $rows = $db->query('SELECT id,creado FROM proyectos WHERE visible=1 ORDER BY orden ASC, id DESC')->fetchAll();
    }
    foreach ($rows as $r) {
        $urls[] = [
            'loc' => $sitio . '/proyecto.php?id=' . (int)$r['id'],
            'lastmod' => !empty($r['creado']) ? substr((string)$r['creado'], 0, 10) : null,
        ];
    }
} catch (Throwable $x) {
    error_log('[blens sitemap] ' . $x->getMessage());
}
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo '  <url><loc>' . htmlspecialchars($u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
    if (!empty($u['lastmod'])) echo '<lastmod>' . htmlspecialchars($u['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</lastmod>';
    echo '</url>' . "\n";
}
echo '</urlset>' . "\n";
