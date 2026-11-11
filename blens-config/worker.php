<?php
declare(strict_types=1);
// Procesa el video de un proyecto en segundo plano. Solo se ejecuta por línea de comandos.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
set_time_limit(0);
const COLA = '/var/www/blens-private/cola';
$id = (int)($argv[1] ?? 0);
$pub = realpath(__DIR__ . '/../public') ?: '/var/www/blens.ar/public';
$dirMedia = $pub . '/media/uploads';
if (!is_dir(COLA)) @mkdir(COLA, 0750, true);
if (!is_dir($dirMedia)) @mkdir($dirMedia, 0755, true);
$pdo = blens_db();
$s = $pdo->prepare('SELECT * FROM proyectos WHERE id=?'); $s->execute([$id]); $p = $s->fetch();
if (!$p) exit(1);

function borrar_pub(?string $rel, string $pub): void { if ($rel && str_starts_with($rel, 'media/uploads/')) @unlink($pub . '/' . $rel); }

function descargar_drive(string $url, string $dest): void {
    if (!preg_match('#/d/([A-Za-z0-9_-]{10,})#', $url, $m) && !preg_match('#[?&]id=([A-Za-z0-9_-]{10,})#', $url, $m)) throw new RuntimeException('No pude leer el ID del link de Drive.');
    $id = $m[1]; $jar = $dest . '.jar'; $u = 'https://drive.google.com/uc?export=download&id=' . $id; $ct = '';
    for ($i = 0; $i < 4; $i++) {
        $o = [];
        exec('curl -sSL --max-time 7200 -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar) . ' -A ' . escapeshellarg('Mozilla/5.0') . ' -o ' . escapeshellarg($dest) . ' -w ' . escapeshellarg('%{content_type}') . ' ' . escapeshellarg($u) . ' 2>&1', $o, $rc);
        $ct = trim(implode('', $o));
        if ($rc !== 0) { @unlink($dest); @unlink($jar); throw new RuntimeException('No pude conectarme con Drive (' . mb_substr($ct, 0, 120) . ').'); }
        if (stripos($ct, 'text/html') === false) break;
        $html = (string)@file_get_contents($dest, false, null, 0, 400000);
        if (preg_match('/<form[^>]*id="download-form"[^>]*action="([^"]+)"/i', $html, $fm)) {
            preg_match_all('/<input[^>]*type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"/i', $html, $in, PREG_SET_ORDER);
            $q = []; foreach ($in as $x) $q[html_entity_decode($x[1])] = html_entity_decode($x[2]);
            $u = html_entity_decode($fm[1]) . '?' . http_build_query($q); continue;
        }
        if (preg_match('/confirm=([0-9A-Za-z_-]+)/', $html, $cm)) { $u = 'https://drive.google.com/uc?export=download&confirm=' . $cm[1] . '&id=' . $id; continue; }
        break;
    }
    @unlink($jar);
    if (stripos($ct, 'text/html') !== false || !is_file($dest) || filesize($dest) < 1024) { @unlink($dest); throw new RuntimeException('No pude descargar de Drive. Revisá que el archivo esté compartido como «cualquier persona con el enlace».'); }
}

// de a un video por vez, para no saturar la notebook
$lock = fopen(COLA . '/.lock', 'c'); flock($lock, LOCK_EX);
$pdo->prepare("UPDATE proyectos SET estado='procesando', error_msg=NULL WHERE id=?")->execute([$id]);
$src = null; $descargado = false; $creados = [];
try {
    // 1) origen: archivo subido o descarga desde Drive
    $src = $p['cola_src'] ?: null;
    if ($src && !is_file($src)) $src = null;
    if (!$src && $p['fuente_drive']) { $src = COLA . '/dl_' . bin2hex(random_bytes(6)) . '.bin'; descargar_drive((string)$p['fuente_drive'], $src); $descargado = true; }
    if (!$src) throw new RuntimeException('No hay un archivo de origen para procesar.');

    // 2) medidas del video
    $o = []; exec('ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 ' . escapeshellarg($src) . ' 2>&1', $o);
    if (!preg_match('/^(\d+),(\d+)/', trim($o[0] ?? ''), $m)) throw new RuntimeException('El archivo no parece un video válido.');
    $short = min((int)$m[1], (int)$m[2]);

    // 3) calidades (nunca agrandan el original): lado corto 1080, 720 y 480
    $crf = [1080 => 20, 720 => 23, 480 => 26];
    $usar = array_values(array_filter(array_keys($crf), fn($t) => $short >= $t - 8));
    if (!$usar) $usar = [480];
    $base = bin2hex(random_bytes(8)); $out = [];
    foreach ($usar as $t) {
        $f = "$dirMedia/$base-$t.mp4";
        $vf = "scale='if(gt(iw,ih),-2,min($t,iw))':'if(gt(iw,ih),min($t,ih),-2)'";
        $o = [];
        exec('nice -n 10 ffmpeg -v error -y -i ' . escapeshellarg($src) . ' -vf ' . escapeshellarg($vf) . ' -c:v libx264 -preset veryfast -crf ' . $crf[$t] . ' -pix_fmt yuv420p -movflags +faststart -c:a aac -b:a ' . ($t >= 1080 ? '160k' : '128k') . ' ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
        if ($rc !== 0 || !is_file($f)) throw new RuntimeException('No se pudo convertir a ' . $t . 'p: ' . mb_substr(implode(' ', array_slice($o, -2)), 0, 160));
        $creados[] = $f; $out[$t] = "media/uploads/$base-$t.mp4";
    }
    $main = $out[720] ?? $out[480] ?? reset($out);
    $hd = $out[1080] ?? null;
    $sd = (isset($out[720], $out[480])) ? $out[480] : null;
    $cal = implode(',', array_keys($out));

    // 4) portada tomada del propio video (si no subiste una)
    $poster = null;
    if ($p['poster_auto'] || !$p['poster']) {
        $jpg = "$dirMedia/$base.jpg"; $ref = $pub . '/' . $main;
        foreach ([1, 0] as $ss) { exec('ffmpeg -v error -y -ss ' . $ss . ' -i ' . escapeshellarg($ref) . ' -frames:v 1 -vf ' . escapeshellarg("scale='min(720,iw)':-2") . ' -q:v 3 ' . escapeshellarg($jpg) . ' 2>&1'); if (is_file($jpg)) break; }
        if (is_file($jpg)) { $poster = "media/uploads/$base.jpg"; $creados[] = $jpg; }
    }

    // 5) guardar y limpiar lo anterior
    $pdo->prepare("UPDATE proyectos SET video_mp4=?, video_1080=?, video_480=?, video_webm=NULL, calidades=?, poster=COALESCE(?,poster), poster_auto=0, estado='listo', error_msg=NULL, cola_src=NULL WHERE id=?")
        ->execute([$main, $hd, $sd, $cal, $poster, $id]);
    foreach (['video_mp4', 'video_webm', 'video_1080', 'video_480'] as $k) borrar_pub($p[$k] ?? null, $pub);
    if ($poster) borrar_pub($p['poster'] ?? null, $pub);
    if ($p['cola_src'] && str_starts_with((string)$p['cola_src'], COLA . '/')) @unlink((string)$p['cola_src']);
    if ($descargado) @unlink($src);
} catch (Throwable $e) {
    foreach ($creados as $f) @unlink($f);
    if ($descargado && $src) @unlink($src);
    $pdo->prepare("UPDATE proyectos SET estado='error', error_msg=? WHERE id=?")->execute([mb_substr($e->getMessage(), 0, 250), $id]);
    exit(1);
}
