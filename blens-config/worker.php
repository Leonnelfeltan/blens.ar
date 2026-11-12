<?php
declare(strict_types=1);
// Procesa el video de un proyecto o autocompleta los textos de una entrega. Solo se ejecuta por línea de comandos.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
set_time_limit(0);
const COLA = '/var/www/blens-private/cola';
const ENTREGAS = '/var/www/blens-private/entregas';
$id = (int)($argv[1] ?? 0);
$tipo = (string)($argv[2] ?? 'proyecto');
$forzar = ($argv[3] ?? '') === 'forzar';
if (!in_array($tipo, ['entrega', 'ia'], true)) $tipo = 'proyecto';
$pub = realpath(__DIR__ . '/../public') ?: '/var/www/blens.ar/public';
$dirMedia = $pub . '/media/uploads';
if (!is_dir(COLA)) @mkdir(COLA, 0750, true);
if (!is_dir($dirMedia)) @mkdir($dirMedia, 0755, true);
$pdo = blens_db();

function borrar_pub(?string $rel, string $pub): void { if ($rel && str_starts_with($rel, 'media/uploads/')) @unlink($pub . '/' . $rel); }

// Busca ffmpeg/ffprobe en el PATH, en rutas habituales o en una copia local (blens-private/bin).
function ff_bin(string $name): string {
    $env = getenv(strtoupper($name) . '_BIN');
    if ($env && @is_file($env) && @is_executable($env)) return $env;
    $o = []; $rc = 1; @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $o, $rc);
    $c = trim((string)($o[0] ?? ''));
    if ($rc === 0 && $c !== '') return $c;
    foreach ([dirname(__DIR__, 2) . '/blens-private/bin', __DIR__ . '/bin', '/usr/local/bin', '/usr/bin', '/bin', '/snap/bin'] as $d) {
        $p = $d . '/' . $name;
        if (@is_file($p) && @is_executable($p)) return $p;
    }
    throw new RuntimeException("Falta $name en el servidor. Instalá FFmpeg con: sudo apt install ffmpeg");
}

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

// ---- IA: transcripción con Whisper (Groq) y redacción con Groq o Gemini ----
function ai_key(): string { return trim((string)(blens_cfg()['ai_api_key'] ?? '')); }
function ai_provider(string $key): string { return str_starts_with($key, 'AIza') || str_starts_with($key, 'AQ.') ? 'gemini' : 'groq'; }
function ai_curl(string $url, array $headers, $body): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if (is_string($body)) $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new RuntimeException('IA: ' . $err);
    $j = json_decode((string)$res, true);
    if ($code < 200 || $code >= 300) {
        $m = is_array($j) ? ($j['error']['message'] ?? $j['error'] ?? '') : '';
        if (is_array($m)) $m = json_encode($m, JSON_UNESCAPED_UNICODE);
        throw new RuntimeException('IA: HTTP ' . $code . ($m ? ' · ' . mb_substr((string)$m, 0, 180) : ''));
    }
    return is_array($j) ? $j : [];
}
function ai_transcribir(string $mp3, string $key, string $provider): string {
    if ($provider === 'gemini') {
        $body = json_encode([
            'contents' => [['parts' => [
                ['text' => 'Transcribí este audio en español rioplatense. Devolvé únicamente la transcripción, sin comentarios ni comillas.'],
                ['inlineData' => ['mimeType' => 'audio/mpeg', 'data' => base64_encode((string)file_get_contents($mp3))]],
            ]]],
            'generationConfig' => ['temperature' => 0.1],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $j = ai_curl('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent?key=' . rawurlencode($key), [], (string)$body);
        return trim((string)($j['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    }
    $j = ai_curl('https://api.groq.com/openai/v1/audio/transcriptions', ['Authorization: Bearer ' . $key], [
        'file' => new CURLFile($mp3, 'audio/mpeg', 'audio.mp3'),
        'model' => 'whisper-large-v3-turbo',
        'language' => 'es',
        'response_format' => 'json',
        'temperature' => '0',
    ]);
    return trim((string)($j['text'] ?? ''));
}
function ai_generar(string $transcripcion, array $ctx, string $key, string $provider): array {
    $sistema = 'Sos redactor de blens.ar, estudio de contenido audiovisual. Escribís en español argentino neutro, profesional, claro y natural. No inventes datos verificables. Respondé únicamente con un objeto JSON válido, sin markdown.';
    if (($ctx['tipo'] ?? '') === 'entrega') {
        $pedido = 'A partir de la transcripción del video, escribí un copy listo para publicar en Instagram y TikTok. Devolvé JSON con las claves: "titulo" (título atractivo y breve en español argentino, máximo 120 caracteres, sin repetir el nombre del archivo) y "descripcion" (copy listo para publicar: un gancho inicial de una línea, una bajada breve con el mensaje principal, un cierre con llamado a la acción y entre 5 y 8 hashtags relevantes al final. Usá saltos de línea. No uses comillas innecesarias. Máximo 4000 caracteres).';
    } else {
        $pedido = 'A partir de la transcripción del video, completá los textos de la página del proyecto. Devolvé JSON con las claves: "titulo" (título atractivo y breve en español argentino, máximo 120 caracteres, sin repetir el nombre del archivo), "descripcion" (texto corto para la tarjeta, máximo 160 caracteres), "servicios" (qué hicimos, máximo 160 caracteres, por ejemplo guion, grabación y edición) y "detalle" (descripción amplia y profesional del proyecto, máximo 4000 caracteres, en 2 o 3 párrafos).';
    }
    $tx = trim($transcripcion);
    $trans = $tx !== '' ? $tx : '(El video no tiene audio o no se pudo transcribir. Generá los textos usando solo el contexto. No inventes datos verificables.)';
    $user = $pedido . "\n\nContexto del video:\n" . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\nTranscripción:\n" . $trans;
    if ($provider === 'gemini') {
        $body = json_encode([
            'contents' => [['parts' => [['text' => $sistema . "\n\n" . $user]]]],
            'generationConfig' => ['temperature' => 0.4, 'responseMimeType' => 'application/json'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $j = ai_curl('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent?key=' . rawurlencode($key), [], (string)$body);
        $txt = (string)($j['candidates'][0]['content']['parts'][0]['text'] ?? '');
    } else {
        $body = json_encode([
            'model' => 'openai/gpt-oss-120b',
            'temperature' => 0.4,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $sistema],
                ['role' => 'user', 'content' => $user],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $j = ai_curl('https://api.groq.com/openai/v1/chat/completions', ['Authorization: Bearer ' . $key], (string)$body);
        $txt = (string)($j['choices'][0]['message']['content'] ?? '');
    }
    $txt = trim((string)preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($txt)));
    $out = json_decode($txt, true);
    if (!is_array($out) && preg_match('/\{.*\}/s', $txt, $m)) $out = json_decode($m[0], true);
    return is_array($out) ? $out : [];
}
function ai_audio(string $src, string $ffmpeg): ?string {
    $mp3 = rtrim(sys_get_temp_dir(), '/') . '/blens_ia_' . bin2hex(random_bytes(6)) . '.mp3';
    $o = [];
    exec('nice -n 10 ' . escapeshellarg($ffmpeg) . ' -v error -y -i ' . escapeshellarg($src) . ' -map 0:a:0? -vn -ac 1 -ar 16000 -b:a 32k -t 1200 ' . escapeshellarg($mp3) . ' 2>&1', $o, $rc);
    if ($rc !== 0 || !is_file($mp3) || filesize($mp3) < 2048) { @unlink($mp3); return null; }
    return $mp3;
}
function ai_textos(string $src, string $ffmpeg, array $ctx): array {
    $key = ai_key();
    if ($key === '') return [];
    $provider = ai_provider($key);
    $mp3 = ai_audio($src, $ffmpeg);
    if (!$mp3) return ai_generar('', $ctx, $key, $provider);
    try {
        $tx = ai_transcribir($mp3, $key, $provider);
        return ai_generar($tx, $ctx, $key, $provider);
    } finally {
        @unlink($mp3);
    }
}
function ai_campo(array $a, string $k, int $max): string {
    $v = $a[$k] ?? '';
    if (!is_scalar($v)) return '';
    $v = trim((string)$v);
    return $v === '' ? '' : mb_substr($v, 0, $max);
}
function nombre_original(array $r, ?string $src = null): string {
    $n = '';
    $cola = (string)($r['cola_src'] ?? '');
    if ($cola !== '' && is_file($cola . '.name')) $n = trim((string)@file_get_contents($cola . '.name'));
    if ($n === '' && $src !== null && $src !== '' && is_file($src . '.name')) $n = trim((string)@file_get_contents($src . '.name'));
    foreach (['fuente_drive', 'link_hd'] as $campo) {
        if ($n !== '' || empty($r[$campo])) continue;
        $u = (string)$r[$campo];
        if (preg_match('#/d/([A-Za-z0-9_-]{10,})#', $u, $m) || preg_match('#[?&]id=([A-Za-z0-9_-]{10,})#', $u, $m)) {
            $side = COLA . '/drive_' . $m[1] . '.name';
            if (is_file($side)) $n = trim((string)@file_get_contents($side));
        }
    }
    return $n;
}
function titulo_provisorio(string $titulo, array $r, ?string $src = null): bool {
    $t = trim($titulo);
    if ($t === '' || $t === 'Video sin título') return true;
    $orig = trim(nombre_original($r, $src));
    if ($orig !== '' && ($orig === $t || mb_substr($orig, 0, 120) === $t)) return true;
    $cands = [];
    if ($src !== null && $src !== '') $cands[] = $src;
    if (!empty($r['cola_src'])) $cands[] = (string)$r['cola_src'];
    foreach ($cands as $f) {
        $b = pathinfo(basename($f), PATHINFO_FILENAME);
        if ($b === $t) return true;
        if (preg_match('/^[a-f0-9]{16}[_-](.+)$/i', $b, $m) && trim($m[1]) === $t) return true;
    }
    return false;
}
function limpiar_nombre_original(array $r, bool $incluir_drive = true): void {
    $cola = (string)($r['cola_src'] ?? '');
    if ($cola !== '') @unlink($cola . '.name');
    if ($incluir_drive) {
        foreach (['fuente_drive', 'link_hd'] as $campo) {
            if (empty($r[$campo])) continue;
            $u = (string)$r[$campo];
            if (preg_match('#/d/([A-Za-z0-9_-]{10,})#', $u, $m) || preg_match('#[?&]id=([A-Za-z0-9_-]{10,})#', $u, $m)) @unlink(COLA . '/drive_' . $m[1] . '.name');
        }
    }
}

// ---- Entregas: autocompleta el copy para redes solo si está vacío ----
if ($tipo === 'entrega') {
    $s = $pdo->prepare('SELECT * FROM entregas WHERE id=?'); $s->execute([$id]); $e = $s->fetch();
    if (!$e) exit(1);
    if (ai_key() === '') exit(0);
    $lock = fopen(COLA . '/.lock', 'c'); flock($lock, LOCK_EX);
    $src = null; $tmp = null;
    try {
        $preview = (string)($e['preview'] ?? '');
        if ($preview !== '') {
            foreach ([ENTREGAS . '/' . basename($preview), $pub . '/' . $preview] as $f) {
                if (is_file($f)) { $src = $f; break; }
            }
        }
        if (!$forzar && !titulo_provisorio((string)$e['titulo'], $e, $src) && trim((string)($e['descripcion'] ?? '')) !== '') exit(0);
        if (!$src && !empty($e['proyecto_id'])) {
            $q = $pdo->prepare('SELECT video_1080,video_mp4 FROM proyectos WHERE id=?'); $q->execute([(int)$e['proyecto_id']]);
            if ($r = $q->fetch()) {
                foreach ([(string)($r['video_1080'] ?? ''), (string)($r['video_mp4'] ?? '')] as $rel) {
                    if ($rel !== '' && is_file($pub . '/' . $rel)) { $src = $pub . '/' . $rel; break; }
                }
            }
        }
        if (!$src && !empty($e['link_hd'])) {
            $tmp = COLA . '/ia_' . bin2hex(random_bytes(6)) . '.bin';
            descargar_drive((string)$e['link_hd'], $tmp);
            $src = $tmp;
        }
        if ($src) {
            $provisorio = titulo_provisorio((string)$e['titulo'], $e, $src);
            if ($forzar || $provisorio || trim((string)($e['descripcion'] ?? '')) === '') {
                $ffmpeg = ff_bin('ffmpeg');
                $ia = ai_textos($src, $ffmpeg, ['tipo' => 'entrega', 'titulo' => (string)$e['titulo']]);
                $titIa = ai_campo($ia, 'titulo', 120);
                $desc = ai_campo($ia, 'descripcion', 4000);
                if ($desc !== '') {
                    if ($forzar) $pdo->prepare('UPDATE entregas SET descripcion=? WHERE id=?')->execute([$desc, $id]);
                    else $pdo->prepare("UPDATE entregas SET descripcion=? WHERE id=? AND (descripcion IS NULL OR descripcion='')")->execute([$desc, $id]);
                }
                if (($forzar || $provisorio) && $titIa !== '') {
                    if ($forzar) $pdo->prepare('UPDATE entregas SET titulo=? WHERE id=?')->execute([$titIa, $id]);
                    else $pdo->prepare('UPDATE entregas SET titulo=? WHERE id=? AND titulo=?')->execute([$titIa, $id, (string)$e['titulo']]);
                    limpiar_nombre_original($e);
                }
            }
        }
    } catch (Throwable $x) {
        error_log('[blens worker IA entrega] ' . $x->getMessage());
    }
    if ($tmp) @unlink($tmp);
    exit(0);
}

// ---- Proyectos ----
$s = $pdo->prepare('SELECT * FROM proyectos WHERE id=?'); $s->execute([$id]); $p = $s->fetch();
if (!$p) exit(1);

// Autocompleta solo los textos usando el video ya procesado (sin volver a convertir).
if ($tipo === 'ia') {
    if (ai_key() === '') exit(0);
    $lock = fopen(COLA . '/.lock', 'c'); flock($lock, LOCK_EX);
    $src = null; $tmp = null;
    try {
        foreach (['video_1080', 'video_mp4', 'video_webm', 'video_480'] as $k) {
            $rel = (string)($p[$k] ?? '');
            if ($rel !== '' && is_file($pub . '/' . $rel)) { $src = $pub . '/' . $rel; break; }
        }
        if (!$src && !empty($p['cola_src']) && is_file((string)$p['cola_src'])) $src = (string)$p['cola_src'];
        if (!$src && !empty($p['fuente_drive'])) {
            $tmp = COLA . '/ia_' . bin2hex(random_bytes(6)) . '.bin';
            descargar_drive((string)$p['fuente_drive'], $tmp);
            $src = $tmp;
        }
        if ($src) {
            $ffmpeg = ff_bin('ffmpeg');
            $provisorio = titulo_provisorio((string)$p['titulo'], $p, $src);
            $ia = ai_textos($src, $ffmpeg, [
                'tipo' => 'proyecto',
                'titulo' => (string)$p['titulo'],
                'cliente' => (string)$p['cliente'],
                'categoria' => (string)$p['categoria'],
            ]);
            $titIa = ai_campo($ia, 'titulo', 120);
            $dIa = ai_campo($ia, 'descripcion', 160);
            $sIa = ai_campo($ia, 'servicios', 160);
            $tIa = ai_campo($ia, 'detalle', 4000);
            if ($dIa !== '' || $sIa !== '' || $tIa !== '') {
                $pdo->prepare("UPDATE proyectos SET descripcion=IF(descripcion='', ?, descripcion), servicios=IF(servicios='', ?, servicios), detalle=IF(detalle IS NULL OR detalle='', ?, detalle) WHERE id=?")
                    ->execute([$dIa, $sIa, $tIa, $id]);
            }
            if ($provisorio && $titIa !== '') {
                $pdo->prepare('UPDATE proyectos SET titulo=? WHERE id=? AND titulo=?')->execute([$titIa, $id, (string)$p['titulo']]);
            }
        }
    } catch (Throwable $x) {
        error_log('[blens worker IA proyecto] ' . $x->getMessage());
    }
    if ($tmp) @unlink($tmp);
    exit(0);
}

// de a un video por vez, para no saturar la notebook
$lock = fopen(COLA . '/.lock', 'c'); flock($lock, LOCK_EX);
$pdo->prepare("UPDATE proyectos SET estado='procesando', error_msg=NULL WHERE id=?")->execute([$id]);
$src = null; $descargado = false; $creados = [];
try {
    // 1) origen: archivo subido o descarga desde Drive
    $src = $p['cola_src'] ?: null;
    if ($src && !is_file($src)) $src = null;
    if (!$src && $p['fuente_drive']) { $src = COLA . '/dl_' . bin2hex(random_bytes(6)) . '.bin'; $descargado = true; descargar_drive((string)$p['fuente_drive'], $src); }
    if (!$src) throw new RuntimeException('No hay un archivo de origen para procesar.');

    $ffmpeg = ff_bin('ffmpeg'); $ffprobe = ff_bin('ffprobe');

    // 2) medidas del video (solo la pista de video: los videos sin audio también son válidos)
    $o = []; exec(escapeshellarg($ffprobe) . ' -v error -select_streams v:0 -show_entries stream=width,height -of json ' . escapeshellarg($src) . ' 2>&1', $o, $prc);
    $pj = json_decode(implode("\n", $o), true); $st = is_array($pj) ? ($pj['streams'][0] ?? null) : null;
    $w = (int)($st['width'] ?? 0); $h = (int)($st['height'] ?? 0);
    if ($prc !== 0 || $w <= 0 || $h <= 0) {
        $det = trim(implode(' ', array_slice($o, -2)));
        throw new RuntimeException('El archivo no parece un video válido' . ($descargado ? ' (descarga de Drive)' : '') . ($det !== '' ? ': ' . mb_substr($det, 0, 160) : '.'));
    }
    // 3) máxima definición web: 1080p (H.264 CRF 19), sin agrandar el original
    $t = 1080; $base = bin2hex(random_bytes(8));
    $f = "$dirMedia/$base-$t.mp4";
    $vf = "scale='if(gt(iw,ih),-2,min($t,iw))':'if(gt(iw,ih),min($t,ih),-2)'";
    $o = [];
    exec('nice -n 10 ' . escapeshellarg($ffmpeg) . ' -v error -y -i ' . escapeshellarg($src) . ' -map 0:v:0 -map 0:a:0? -sn -dn -vf ' . escapeshellarg($vf) . ' -c:v libx264 -preset veryfast -threads 0 -crf 19 -pix_fmt yuv420p -movflags +faststart -c:a aac -b:a 160k ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
    if ($rc !== 0 || !is_file($f)) { if (is_file($f)) @unlink($f); throw new RuntimeException('No se pudo convertir a 1080p: ' . mb_substr(implode(' ', array_slice($o, -2)), 0, 160)); }
    $creados[] = $f; $main = $hd = "media/uploads/$base-$t.mp4";

    // 3b) versión liviana 480p para las tarjetas del index (no para la página del proyecto)
    $t480 = 480; $f480 = "$dirMedia/$base-$t480.mp4"; $main480 = null;
    $vf480 = "scale='if(gt(iw,ih),-2,min($t480,iw))':'if(gt(iw,ih),min($t480,ih),-2)'";
    $o480 = [];
    exec('nice -n 10 ' . escapeshellarg($ffmpeg) . ' -v error -y -i ' . escapeshellarg($src) . ' -map 0:v:0 -map 0:a:0? -sn -dn -vf ' . escapeshellarg($vf480) . ' -c:v libx264 -preset veryfast -threads 0 -crf 24 -pix_fmt yuv420p -movflags +faststart -c:a aac -b:a 96k ' . escapeshellarg($f480) . ' 2>&1', $o480, $rc480);
    if ($rc480 === 0 && is_file($f480)) { $creados[] = $f480; $main480 = "media/uploads/$base-$t480.mp4"; }
    elseif (is_file($f480)) @unlink($f480);

    if ($descargado && $src) { @unlink($src); $src = null; }

    // 4) portada tomada del propio video (si no subiste una)
    $poster = null;
    if ($p['poster_auto'] || !$p['poster']) {
        $jpg = "$dirMedia/$base.jpg"; $ref = $pub . '/' . $main;
        foreach ([1, 0] as $ss) { exec(escapeshellarg($ffmpeg) . ' -v error -y -threads 0 -ss ' . $ss . ' -i ' . escapeshellarg($ref) . ' -frames:v 1 -vf ' . escapeshellarg("scale='min(720,iw)':-2") . ' -q:v 3 ' . escapeshellarg($jpg) . ' 2>&1'); if (is_file($jpg)) break; }
        if (is_file($jpg)) { $poster = "media/uploads/$base.jpg"; $creados[] = $jpg; }
    }

    // 5) autocompletar textos con IA solo si los campos están vacíos (o el título es provisorio)
    $ia = [];
    $provisorio = titulo_provisorio((string)$p['titulo'], $p, $src);
    $faltaTexto = $provisorio || trim((string)($p['descripcion'] ?? '')) === '' || trim((string)($p['servicios'] ?? '')) === '' || trim((string)($p['detalle'] ?? '')) === '';
    if ($faltaTexto && ai_key() !== '') {
        try {
            $ia = ai_textos($f, $ffmpeg, [
                'tipo' => 'proyecto',
                'titulo' => (string)$p['titulo'],
                'cliente' => (string)$p['cliente'],
                'categoria' => (string)$p['categoria'],
            ]);
        } catch (Throwable $x) { error_log('[blens worker IA proyecto] ' . $x->getMessage()); }
    }
    $titIa = ai_campo($ia, 'titulo', 120);
    $dIa = ai_campo($ia, 'descripcion', 160);
    $sIa = ai_campo($ia, 'servicios', 160);
    $tIa = ai_campo($ia, 'detalle', 4000);

    // 6) guardar y limpiar lo anterior
    $pdo->prepare("UPDATE proyectos SET video_mp4=?, video_1080=?, video_480=?, video_webm=NULL, calidades=NULL, poster=COALESCE(?,poster), poster_auto=0, estado='listo', error_msg=NULL, cola_src=NULL, descripcion=IF(descripcion='', ?, descripcion), servicios=IF(servicios='', ?, servicios), detalle=IF(detalle IS NULL OR detalle='', ?, detalle) WHERE id=?")
        ->execute([$main, $hd, $main480, $poster, $dIa, $sIa, $tIa, $id]);
    if ($provisorio && $titIa !== '') $pdo->prepare('UPDATE proyectos SET titulo=? WHERE id=? AND titulo=?')->execute([$titIa, $id, (string)$p['titulo']]);
    foreach (['video_mp4', 'video_webm', 'video_1080', 'video_480'] as $k) borrar_pub($p[$k] ?? null, $pub);
    if ($poster) borrar_pub($p['poster'] ?? null, $pub);
    if ($p['cola_src'] && str_starts_with((string)$p['cola_src'], COLA . '/')) @unlink((string)$p['cola_src']);
    limpiar_nombre_original($p, !($provisorio && $titIa === ''));
} catch (Throwable $e) {
    foreach ($creados as $f) @unlink($f);
    if ($descargado && $src) @unlink($src);
    $pdo->prepare("UPDATE proyectos SET estado='error', error_msg=? WHERE id=?")->execute([mb_substr($e->getMessage(), 0, 250), $id]);
    exit(1);
}
