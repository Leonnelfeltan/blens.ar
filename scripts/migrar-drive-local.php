<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/blens-config/bootstrap.php';
set_time_limit(0);
$pdo = blens_db(); blens_migrar($pdo);
$locked = (bool)$pdo->query("SELECT GET_LOCK('blens_migrar_drive_local', 0)")->fetchColumn();
if (!$locked) { echo "Otra migración de Drive está en curso.\n"; exit(0); }
try {
    $s = $pdo->query("SELECT p.id,p.titulo,p.poster,p.video_drive_id,p.fuente_drive,e.link_hd,e.link_4k,e.poster AS entrega_poster,e.portada_drive_id
        FROM proyectos p LEFT JOIN entregas e ON e.id=p.entrega_id
        WHERE p.visible=1 AND p.categoria<>'showreel'
          AND COALESCE(p.video_1080,'')='' AND COALESCE(p.video_mp4,'')='' AND COALESCE(p.video_480,'')=''
          AND ((p.fuente_drive IS NOT NULL AND p.fuente_drive<>'') OR (e.link_hd IS NOT NULL AND e.link_hd<>'') OR (e.link_4k IS NOT NULL AND e.link_4k<>''))
        ORDER BY p.id");
    $pendientes = $s->fetchAll();
    echo 'Proyectos pendientes: ' . count($pendientes) . "\n";
    foreach ($pendientes as $r) {
        $pid = (int)$r['id'];
        if (trim((string)$r['poster']) === '') {
            $portada = blens_publicar_portada($pdo, $r, $pid);
            if ($portada !== '') { $pdo->prepare('UPDATE proyectos SET poster=?,poster_auto=0 WHERE id=?')->execute([$portada, $pid]); echo "  portada $pid $portada\n"; }
        }
        $rel = blens_drive_cachear_video($pdo, $pid);
        echo ($rel ? '  OK ' : '  FAIL ') . $pid . ' ' . mb_substr((string)$r['titulo'], 0, 60) . ($rel ? ' ' . $rel : '') . "\n";
    }
    echo "Fin de migración de Drive.\n";
} finally { $pdo->query("SELECT RELEASE_LOCK('blens_migrar_drive_local')"); }
