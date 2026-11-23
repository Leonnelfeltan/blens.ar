<?php
declare(strict_types=1);

if (!defined('BLENS_ENTREGAS_DIR')) define('BLENS_ENTREGAS_DIR', '/var/www/blens-private/entregas');

function blens_uploads_proyectos(): string {
    $d = dirname(__DIR__) . '/public/uploads/proyectos';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}
function blens_ruta_publica(string $rel): string {
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if ($rel === '' || str_contains($rel, '..')) return '';
    $base = realpath(dirname(__DIR__) . '/public');
    $full = realpath(($base ?: dirname(__DIR__) . '/public') . '/' . $rel);
    if (!$base || !$full || !str_starts_with($full, $base . DIRECTORY_SEPARATOR)) return '';
    return $full;
}
function blens_slug(?string $s): string {
    $s = trim((string)$s);
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim(substr($s, 0, 60), '-');
}
function blens_curl_a_archivo(string $url, string $dest, ?string $jar = null, ?string $range = null, int $timeout = 120): array {
    $cmd = 'curl -sSL --max-time ' . max(1, $timeout) . ' -A ' . escapeshellarg('Mozilla/5.0');
    if ($jar) $cmd .= ' -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar);
    if ($range) $cmd .= ' -r ' . escapeshellarg($range);
    $cmd .= ' -o ' . escapeshellarg($dest) . ' -w ' . escapeshellarg('%{http_code}|%{content_type}') . ' ' . escapeshellarg($url) . ' 2>/dev/null';
    $out = []; $rc = 1; @exec($cmd, $out, $rc);
    $parts = explode('|', trim(implode('', $out)), 2);
    $code = $rc === 0 ? (int)($parts[0] ?? 0) : 0;
    return ['http_code' => $code, 'content_type' => $rc === 0 ? trim((string)($parts[1] ?? '')) : '', 'ok' => $code >= 200 && $code < 400];
}
function blens_curl_head(string $url, ?string $jar = null, int $timeout = 60): array {
    $h = blens_tmp('blensdh');
    $cmd = 'curl -sSIL --max-time ' . max(1, $timeout) . ' -A ' . escapeshellarg('Mozilla/5.0');
    if ($jar) $cmd .= ' -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar);
    $cmd .= ' -D ' . escapeshellarg($h) . ' -o /dev/null ' . escapeshellarg($url) . ' 2>/dev/null';
    $out = []; $rc = 1; @exec($cmd, $out, $rc);
    $txt = (string)@file_get_contents($h); @unlink($h);
    $blocks = preg_split('/\r?\n\r?\n/', trim($txt)) ?: [];
    $last = (string)end($blocks);
    preg_match_all('~HTTP/\S+\s+(\d{3})~', $txt, $codes);
    $code = (int)(end($codes[1]) ?: 0);
    $ct = ''; $loc = '';
    foreach (explode("\n", str_replace("\r", '', $last)) as $line) {
        if (preg_match('~^Content-Type:\s*(.+)$~i', $line, $m)) $ct = trim($m[1]);
        if (preg_match('~^Location:\s*(.+)$~i', $line, $m)) $loc = trim($m[1]);
    }
    return ['http_code' => $code, 'content_type' => $ct, 'location' => $loc, 'ok' => $code >= 200 && $code < 400];
}
function blens_header_html(string $tag, string $nombre): string {
    $re = '/\b' . preg_quote($nombre, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';
    if (!preg_match($re, $tag, $m)) return '';
    $valor = isset($m[2]) && $m[2] !== '' ? $m[2] : (isset($m[3]) && $m[3] !== '' ? $m[3] : (string)($m[4] ?? ''));
    return html_entity_decode($valor, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
function blens_url_absoluta(string $url, string $base = 'https://drive.google.com'): string {
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('~^https?://~i', $url)) return $url;
    if (str_starts_with($url, '//')) return 'https:' . $url;
    return rtrim($base, '/') . '/' . ltrim($url, '/');
}
function blens_url_versionada(string $rel): string {
    $rel = ltrim(str_replace('\\', '/', trim($rel)), '/');
    if ($rel === '' || preg_match('~^https?://~i', $rel)) return $rel;
    $sep = str_contains($rel, '?') ? '&' : '?';
    $abs = blens_ruta_publica(explode('?', $rel)[0]);
    return $rel . ($abs !== '' && is_file($abs) ? $sep . 'v=' . (int)@filemtime($abs) : '');
}
function blens_accel_ruta(string $rel): string {
    $rel = ltrim(str_replace('\\', '/', trim($rel)), '/');
    if ($rel === '' || str_contains($rel, '..') || preg_match('~^https?://~i', $rel)) return '';
    return '/media-proyecto-file/' . $rel;
}
function blens_tmp(string $prefijo): string {
    $f = @tempnam(sys_get_temp_dir(), $prefijo);
    return $f !== false ? $f : sys_get_temp_dir() . '/' . $prefijo . bin2hex(random_bytes(8));
}
function blens_drive_directa(string $url, int $timeout = 7200): string {
    $id = blens_drive_id($url);
    if ($id === '') return '';
    $jar = blens_tmp('blensdj'); $probe = blens_tmp('blensdp');
    $actual = 'https://drive.google.com/uc?export=download&id=' . $id;
    $vistos = [];
    try {
        for ($i = 0; $i < 5; $i++) {
            if (isset($vistos[$actual])) return '';
            $vistos[$actual] = true;
            $h = blens_curl_head($actual, $jar, min(60, $timeout));
            if (!$h['ok'] && $h['http_code'] !== 206) return '';
            if (stripos($h['content_type'], 'text/html') === false) return $actual;
            $page = blens_curl_a_archivo($actual, $probe, $jar, null, min(60, $timeout));
            if (!$page['ok']) return '';
            $html = (string)@file_get_contents($probe, false, null, 0, 400000);
            $next = '';
            if (preg_match('/<form\b([^>]*)>/i', $html, $fm)) {
                $action = blens_header_html($fm[1], 'action');
                preg_match_all('/<input\b([^>]*)>/i', $html, $in);
                $q = [];
                foreach ($in[1] ?? [] as $attrs) {
                    $name = blens_header_html($attrs, 'name');
                    $type = strtolower(blens_header_html($attrs, 'type'));
                    if ($name === '' || ($type !== '' && $type !== 'hidden')) continue;
                    $q[$name] = blens_header_html($attrs, 'value');
                }
                if (!isset($q['id'])) $q['id'] = $id;
                if (!isset($q['export'])) $q['export'] = 'download';
                if ($action !== '' && $q !== []) {
                    $urlNext = blens_url_absoluta($action);
                    $next = $urlNext . (str_contains($urlNext, '?') ? '&' : '?') . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
                }
            }
            if ($next === '') {
                if (preg_match('/confirm=([0-9A-Za-z_-]+)/', $html, $cm)) $next = 'https://drive.google.com/uc?export=download&confirm=' . $cm[1] . '&id=' . $id;
                elseif (preg_match('/name="confirm"[^>]*value="([^"]+)"/i', $html, $cm)) $next = 'https://drive.google.com/uc?export=download&confirm=' . rawurlencode($cm[1]) . '&id=' . $id;
            }
            if ($next === '') return '';
            $actual = $next;
        }
        return '';
    } finally { @unlink($jar); @unlink($probe); }
}
function blens_drive_descargar(string $url, string $dest, int $timeout = 7200): void {
    $id = blens_drive_id($url);
    if ($id === '' && preg_match('/^[A-Za-z0-9_-]{20,}$/', $url)) $id = $url;
    $intentos = [];
    if ($id !== '') $intentos[] = 'https://drive.usercontent.google.com/download?id=' . rawurlencode($id) . '&export=download&confirm=t';
    $directa = blens_drive_directa($id !== '' ? $id : $url, $timeout);
    if ($directa !== '') $intentos[] = $directa;
    if ($id !== '') $intentos[] = 'https://drive.google.com/uc?export=download&confirm=t&id=' . rawurlencode($id);
    $intentos = array_values(array_unique($intentos));
    foreach ($intentos as $u) {
        $jar = blens_tmp('blensdj');
        try {
            @unlink($dest);
            $res = blens_curl_a_archivo($u, $dest, $jar, null, $timeout);
            if ($res['ok'] && stripos($res['content_type'], 'text/html') === false && is_file($dest) && filesize($dest) >= 1024) return;
        } finally { @unlink($jar); }
    }
    @unlink($dest);
    throw new RuntimeException('No pude resolver la descarga de Drive.');
}
function blens_ff_bin(string $name): string {
    $env = getenv(strtoupper($name) . '_BIN');
    if ($env && @is_file($env) && @is_executable($env)) return $env;
    $out = []; $rc = 1; @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $out, $rc);
    $cmd = trim((string)($out[0] ?? ''));
    if ($rc === 0 && $cmd !== '') return $cmd;
    foreach ([dirname(__DIR__, 2) . '/blens-private/bin', __DIR__ . '/bin', '/usr/local/bin', '/usr/bin', '/bin', '/snap/bin'] as $d) {
        if (@is_file($d . '/' . $name) && @is_executable($d . '/' . $name)) return $d . '/' . $name;
    }
    throw new RuntimeException('Falta ' . $name . ' en el servidor.');
}
function blens_ejecutar(string $cmd): bool { $out = []; $rc = 1; @exec($cmd, $out, $rc); return $rc === 0; }
function blens_imagen_convertir(string $src, string $dest, string $tipo = 'webp'): bool {
    if (!is_file($src)) return false;
    $ff = blens_ff_bin('ffmpeg');
    $codec = $tipo === 'webp' ? '-c:v libwebp -quality 82' : '-q:v 3';
    $cmd = $ff . ' -y -hide_banner -loglevel error -i ' . escapeshellarg($src) . ' -frames:v 1 -vf ' . escapeshellarg('scale=min(1200\\,iw):-2') . ' ' . $codec . ' ' . escapeshellarg($dest);
    return blens_ejecutar($cmd) && is_file($dest) && filesize($dest) > 100;
}
function blens_video_transcode_web(string $src, string $dest): bool {
    if (!is_file($src)) return false;
    $ff = blens_ff_bin('ffmpeg');
    $filtro = "scale='min(1080,iw)':'min(1920,ih)':force_original_aspect_ratio=decrease,pad=ceil(iw/2)*2:ceil(ih/2)*2";
    $cmd = $ff . ' -y -hide_banner -loglevel error -i ' . escapeshellarg($src)
        . ' -map 0:v:0 -map 0:a? -vf ' . escapeshellarg($filtro)
        . ' -c:v libx264 -preset fast -crf 23 -maxrate 6M -bufsize 12M -pix_fmt yuv420p'
        . ' -c:a aac -b:a 128k -movflags +faststart ' . escapeshellarg($dest);
    return blens_ejecutar($cmd) && is_file($dest) && filesize($dest) > 10240;
}
function blens_poster_video(string $video, string $dest): bool {
    if (!is_file($video)) return false;
    $ff = blens_ff_bin('ffmpeg');
    $cmd = $ff . ' -y -hide_banner -loglevel error -ss 1 -i ' . escapeshellarg($video)
        . ' -frames:v 1 -vf ' . escapeshellarg('scale=min(720\\,iw):-2')
        . ' -c:v libwebp -quality 82 ' . escapeshellarg($dest);
    return blens_ejecutar($cmd) && is_file($dest) && filesize($dest) > 100;
}
function blens_persistir_poster(PDO $pdo, int $pid, string $rel): void {
    if ($pid <= 0 || $rel === '') return;
    try { $pdo->prepare("UPDATE proyectos SET poster=?,poster_auto=0 WHERE id=? AND (poster IS NULL OR poster='')")->execute([$rel, $pid]); }
    catch (Throwable $x) { error_log('[blens poster] ' . $x->getMessage()); }
}
function blens_portada_drive_cachear(string $id, string $dest): bool {
    foreach (['https://drive.google.com/thumbnail?id=' . $id . '&sz=w1200', 'https://lh3.googleusercontent.com/d/' . $id . '=w1200'] as $u) {
        $res = blens_curl_a_archivo($u, $dest, null, null, 45);
        if ($res['ok'] && stripos($res['content_type'], 'image/') === 0 && is_file($dest) && filesize($dest) > 1024) return true;
    }
    @unlink($dest);
    return false;
}
function blens_entrega_portada_origen(PDO $pdo, array $entrega): ?string {
    $raw = trim((string)($entrega['poster'] ?? ''));
    $id = blens_drive_id($raw) ?: blens_drive_id((string)($entrega['portada_drive_id'] ?? ''));
    if ($raw !== '' && $id === '') {
        $local = BLENS_ENTREGAS_DIR . '/' . basename(str_replace('\\', '/', $raw));
        if (is_file($local)) return $local;
    }
    if ($id === '') {
        foreach (['link_hd', 'link_4k'] as $k) { $id = blens_drive_id((string)($entrega[$k] ?? '')); if ($id !== '') break; }
    }
    if ($id === '') return null;
    $eid = (int)($entrega['id'] ?? 0);
    $cache = BLENS_ENTREGAS_DIR . '/portada-' . $eid . '-' . substr($id, 0, 12) . '.jpg';
    if (is_file($cache)) return $cache;
    if (!is_dir(BLENS_ENTREGAS_DIR)) @mkdir(BLENS_ENTREGAS_DIR, 0750, true);
    $tmp = $cache . '.part';
    if (blens_portada_drive_cachear($id, $tmp)) {
        @rename($tmp, $cache);
        if ($eid > 0 && $raw !== basename($cache)) {
            try { $pdo->prepare('UPDATE entregas SET poster=? WHERE id=?')->execute([basename($cache), $eid]); } catch (Throwable $x) { error_log('[blens portada] ' . $x->getMessage()); }
        }
        return $cache;
    }
    @unlink($tmp);
    return null;
}
function blens_publicar_portada(PDO $pdo, array $entrega, int $pid): ?string {
    $src = blens_entrega_portada_origen($pdo, $entrega);
    if (!$src) return null;
    $base = 'proyecto-' . $pid . '-portada';
    $webp = blens_uploads_proyectos() . '/' . $base . '.webp';
    if (!is_file($webp)) {
        if (!blens_imagen_convertir($src, $webp, 'webp')) {
            $jpg = blens_uploads_proyectos() . '/' . $base . '.jpg';
            if (!@copy($src, $jpg) || !is_file($jpg)) return null;
            return 'uploads/proyectos/' . $base . '.jpg';
        }
    }
    return 'uploads/proyectos/' . $base . '.webp';
}
function blens_portada_para_descarga(PDO $pdo, array $entrega): ?string {
    $src = blens_entrega_portada_origen($pdo, $entrega);
    if (!$src) return null;
    $dir = BLENS_ENTREGAS_DIR . '/descargas';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $dest = $dir . '/portada-' . (int)($entrega['id'] ?? 0) . '.jpg';
    if (strtolower((string)pathinfo($src, PATHINFO_EXTENSION)) === 'jpg') {
        if (!@copy($src, $dest)) return null;
    } elseif (!blens_imagen_convertir($src, $dest, 'jpg')) return null;
    return is_file($dest) ? $dest : null;
}
function blens_video_local(array $r): string {
    foreach (['video_1080', 'video_mp4', 'video_480'] as $k) {
        $v = trim((string)($r[$k] ?? ''));
        if ($v === '') continue;
        if (blens_ruta_publica($v) !== '') return $v;
    }
    return '';
}
function blens_proyecto_tiene_media(array $r): bool {
    if (blens_video_local($r) !== '') return true;
    foreach (['video_drive_id', 'fuente_drive', 'entrega_link', 'entrega_link4'] as $k) {
        if (blens_drive_id((string)($r[$k] ?? '')) !== '') return true;
    }
    foreach (['poster', 'entrega_poster', 'entrega_portada_drive_id'] as $k) {
        if (trim((string)($r[$k] ?? '')) !== '') return true;
    }
    return false;
}
function blens_proyecto_drive_id(PDO $pdo, int $pid): string {
    try {
        $s = $pdo->prepare('SELECT p.*, e.link_hd AS entrega_link, e.link_4k AS entrega_link4 FROM proyectos p LEFT JOIN entregas e ON e.id=p.entrega_id WHERE p.id=?');
        $s->execute([$pid]); $r = $s->fetch();
    } catch (Throwable $x) { return ''; }
    if (!$r) return '';
    $guardado = trim((string)($r['video_drive_id'] ?? ''));
    if ($guardado !== '' && preg_match('/^[A-Za-z0-9_-]{10,}$/', $guardado)) return $guardado;
    foreach (['fuente_drive', 'entrega_link', 'entrega_link4'] as $k) {
        $id = blens_drive_id((string)($r[$k] ?? ''));
        if ($id !== '') return $id;
    }
    return '';
}
function blens_drive_cachear_video(PDO $pdo, int $pid, bool $forzar = false): ?string {
    if ($pid <= 0) return null;
    $lock = (bool)$pdo->query("SELECT GET_LOCK('blens_video_drive_" . $pid . "', 20)")->fetchColumn();
    if (!$lock && !$forzar) {
        try { $s = $pdo->prepare('SELECT video_1080,video_mp4 FROM proyectos WHERE id=?'); $s->execute([$pid]); $r = $s->fetch(); if ($r) return blens_video_local($r) ?: null; } catch (Throwable $x) { }
        return null;
    }
    try {
        try {
            $s = $pdo->prepare('SELECT p.*, e.link_hd AS entrega_link, e.link_4k AS entrega_link4, e.poster AS entrega_poster, e.portada_drive_id AS entrega_portada_drive_id FROM proyectos p LEFT JOIN entregas e ON e.id=p.entrega_id WHERE p.id=?');
            $s->execute([$pid]); $p = $s->fetch();
        } catch (Throwable $x) {
            $s = $pdo->prepare('SELECT p.*, e.link_hd AS entrega_link, e.link_4k AS entrega_link4, e.poster AS entrega_poster FROM proyectos p LEFT JOIN entregas e ON e.id=p.entrega_id WHERE p.id=?');
            $s->execute([$pid]); $p = $s->fetch();
        }
        if (!$p) return null;
        $local = blens_video_local($p);
        if ($local !== '' && !$forzar) return $local;
        $id = blens_proyecto_drive_id($pdo, $pid);
        if ($id === '') return null;
        $url = 'https://drive.google.com/file/d/' . $id . '/view';
        $base = 'proyecto-' . $pid . '-' . substr($id, 0, 12);
        $dir = blens_uploads_proyectos();
        $part = $dir . '/' . $base . '.part';
        $mp4 = $dir . '/' . $base . '.mp4';
        if (is_file($mp4) && filesize($mp4) > 10240 && !$forzar) {
            $rel = 'uploads/proyectos/' . $base . '.mp4';
            $pdo->prepare('UPDATE proyectos SET video_mp4=?,video_1080=?,video_drive_id=?,estado=\'listo\' WHERE id=?')->execute([$rel, $rel, $id, $pid]);
            return $rel;
        }
        @unlink($part);
        blens_drive_descargar($url, $part);
        $ffprobe = blens_ff_bin('ffprobe');
        $probe = $ffprobe . ' -v error -show_entries format=duration -of default=nw=1:nk=1 ' . escapeshellarg($part);
        $out = []; $rc = 1; @exec($probe, $out, $rc);
        if ($rc !== 0 || (float)(trim((string)($out[0] ?? '')) ?: 0) <= 0) throw new RuntimeException('El archivo de Drive no es un video válido.');
        $mp4Tmp = $dir . '/' . $base . '-web.part';
        @unlink($mp4Tmp);
        if (!blens_video_transcode_web($part, $mp4Tmp)) {
            @unlink($mp4Tmp);
            throw new RuntimeException('No pude optimizar el video a MP4 web.');
        }
        if (!@rename($mp4Tmp, $mp4)) { @unlink($mp4Tmp); throw new RuntimeException('No pude guardar el MP4 optimizado.'); }
        @chmod($mp4, 0664);
        if (!is_file($mp4) || filesize($mp4) < 10240) throw new RuntimeException('El MP4 generado quedó vacío.');
        $rel = 'uploads/proyectos/' . $base . '.mp4';
        $poster = '';
        if (empty($p['poster'])) {
            $poster = blens_publicar_portada($pdo, $p, $pid) ?: '';
            if ($poster === '') {
                $posterAbs = blens_uploads_proyectos() . '/' . $base . '-poster.webp';
                if (blens_poster_video($mp4, $posterAbs)) $poster = 'uploads/proyectos/' . $base . '-poster.webp';
            }
        }
        if ($poster !== '') blens_persistir_poster($pdo, $pid, $poster);
        $pdo->prepare("UPDATE proyectos SET video_mp4=?,video_1080=?,video_drive_id=?,estado='listo',error_msg=NULL WHERE id=?")->execute([$rel, $rel, $id, $pid]);
        return $rel;
    } catch (Throwable $x) {
        error_log('[blens drive video ' . $pid . '] ' . $x->getMessage());
        try { $pdo->prepare("UPDATE proyectos SET estado='error',error_msg=? WHERE id=?")->execute([mb_substr($x->getMessage(), 0, 255), $pid]); } catch (Throwable $x2) { }
        return null;
    } finally {
        @unlink($part ?? '');
        @unlink($mp4Tmp ?? '');
        if ($lock) { try { $pdo->query("SELECT RELEASE_LOCK('blens_video_drive_" . $pid . "')"); } catch (Throwable $x) { } }
    }
}
function blens_lanzar_cachear_drive(int $pid, bool $forzar = false): void {
    $script = dirname(__DIR__) . '/scripts/cachear-drive.php';
    if (!is_file($script)) return;
    $php = PHP_BINARY ?: (trim((string)@shell_exec('command -v php')) ?: '/usr/bin/php');
    $args = (int)$pid . ($forzar ? ' --force' : '');
    @exec('setsid nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $args . ' > /dev/null 2>&1 &');
}
function blens_stream_archivo(string $path, string $mime, ?string $attachment = null, ?string $accel = null): void {
    if (!is_file($path)) { http_response_code(404); exit; }
    if ($accel !== null && $accel !== '' && $attachment === null && !headers_sent()) {
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Redirect: ' . $accel);
    }
    $size = (int)filesize($path);
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Cache-Control: ' . ($attachment !== null ? 'private, no-store' : ($accel !== null && $accel !== '' ? 'public, max-age=31536000, immutable' : 'public, max-age=3600')));
    header('X-Content-Type-Options: nosniff');
    if ($attachment !== null) header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $attachment) . '"');
    $start = 0; $end = max(0, $size - 1);
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] !== '') { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $end); }
        elseif ($m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
        if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return;
    $fp = @fopen($path, 'rb');
    if (!$fp) { http_response_code(500); exit; }
    fseek($fp, $start); $left = $end - $start + 1;
    while ($left > 0 && !feof($fp)) { $chunk = fread($fp, min(131072, $left)); if ($chunk === false || $chunk === '') break; echo $chunk; $left -= strlen($chunk); flush(); }
    fclose($fp);
}
function blens_stream_drive(string $url, string $mime = 'video/mp4'): bool {
    $directa = blens_drive_directa($url);
    if ($directa === '') return false;
    $range = isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=\d*-\d*/', $_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : 'bytes=0-';
    $hfile = blens_tmp('blensdh');
    $cmd = 'curl -sSL --max-time 60 -A ' . escapeshellarg('Mozilla/5.0') . ' -r ' . escapeshellarg($range) . ' -D ' . escapeshellarg($hfile) . ' -o /dev/null ' . escapeshellarg($directa) . ' 2>/dev/null';
    $out = []; $rc = 1; @exec($cmd, $out, $rc);
    $txt = (string)@file_get_contents($hfile); @unlink($hfile);
    if ($rc !== 0 || $txt === '') return false;
    $blocks = preg_split('/\r?\n\r?\n/', trim($txt)) ?: [];
    $last = (string)end($blocks);
    preg_match_all('~HTTP/\S+\s+(\d{3})~', $txt, $codes);
    $code = (int)(end($codes[1]) ?: 0);
    if (!in_array($code, [200, 206], true)) return false;
    http_response_code($code);
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    foreach (explode("\n", str_replace("\r", '', $last)) as $line) {
        if (preg_match('~^(Content-Range|Content-Length|ETag|Last-Modified):\s*(.+)$~i', $line, $hm)) header($hm[1] . ': ' . $hm[2]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return true;
    $ch = curl_init($directa);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 7200,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Range: ' . $range, 'User-Agent: Mozilla/5.0'],
        CURLOPT_WRITEFUNCTION => function ($ch, $s) { echo $s; return strlen($s); },
    ]);
    $ok = curl_exec($ch); curl_close($ch);
    return $ok !== false;
}
