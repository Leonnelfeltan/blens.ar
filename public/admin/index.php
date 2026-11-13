<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
require __DIR__ . '/drive.php';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY');

$cfg = blens_cfg(); $pdo = blens_db();
$faltaMig = false; try { $faltaMig = !$pdo->query("SHOW COLUMNS FROM proyectos LIKE 'video_1080'")->fetch(); } catch (Throwable $x0) { }
const MIG_CMD = "sudo mysql blens_web -e \"ALTER TABLE proyectos ADD COLUMN video_1080 VARCHAR(255) NULL, ADD COLUMN video_480 VARCHAR(255) NULL, ADD COLUMN calidades VARCHAR(40) NULL, ADD COLUMN estado VARCHAR(12) NOT NULL DEFAULT 'listo', ADD COLUMN error_msg VARCHAR(255) NULL, ADD COLUMN fuente_drive VARCHAR(500) NULL, ADD COLUMN cola_src VARCHAR(255) NULL, ADD COLUMN poster_auto TINYINT(1) NOT NULL DEFAULT 0;\"";
$faltaCat = false; try { $col = $pdo->query("SHOW COLUMNS FROM proyectos LIKE 'categoria'")->fetch(); $faltaCat = $col && stripos((string)$col['Type'], 'varchar(40)') === false; } catch (Throwable $x0) { }
const MIG_CAT_CMD = "sudo mysql blens_web -e \"ALTER TABLE proyectos MODIFY categoria VARCHAR(40) NOT NULL DEFAULT 'reels';\"";
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$_SESSION['t'] ??= bin2hex(random_bytes(16));
function cat_slug(string $s): string {
    $s = function_exists('mb_strtolower') ? mb_strtolower(trim($s), 'UTF-8') : strtolower(trim($s));
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
}
function cat_label(string $s): string {
    $base = ['reels' => 'Reels', 'cobertura' => 'Cobertura', 'estrategia' => 'Estrategia', 'auto' => 'Automática (IA)', 'showreel' => 'Video de presentación (sección Nosotros)'];
    if (isset($base[$s])) return $base[$s];
    return ucfirst(str_replace('-', ' ', $s));
}
$cats = ['reels' => 'Reels', 'cobertura' => 'Cobertura', 'estrategia' => 'Estrategia', 'auto' => 'Automática (IA)', 'showreel' => 'Video de presentación (sección Nosotros)'];
try {
    $extra = $pdo->query("SELECT DISTINCT categoria FROM proyectos WHERE categoria NOT IN ('','reels','cobertura','estrategia','auto','showreel') ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($extra as $c) { $c = (string)$c; if ($c !== '') $cats[$c] = cat_label($c); }
} catch (Throwable $x0) { }
$catsForm = $cats; unset($catsForm['auto']);
$msg = ''; $post = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($post && !hash_equals($_SESSION['t'], (string)($_POST['t'] ?? ''))) { http_response_code(400); exit('Sesión vencida. Recargá la página.'); }

function up(string $f, array $ok, int $max): ?string {
    if (empty($_FILES[$f]['name'])) return null;
    $u = $_FILES[$f];
    if ($u['error'] !== UPLOAD_ERR_OK) throw new RuntimeException("No se pudo subir «$f» (código {$u['error']}). Si es un video grande, revisá los límites de PHP (README).");
    if ($u['size'] > $max) throw new RuntimeException("«$f» supera el tamaño máximo permitido.");
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($u['tmp_name']);
    if (!isset($ok[$mime])) throw new RuntimeException("Formato no permitido en «$f» ($mime).");
    $dir = __DIR__ . '/../media/uploads'; if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(8)) . '.' . $ok[$mime];
    if (!move_uploaded_file($u['tmp_name'], "$dir/$name")) throw new RuntimeException('No se pudo guardar el archivo.');
    return "media/uploads/$name";
}
function rmf(?string $p): void { if ($p && str_starts_with($p, 'media/uploads/')) @unlink(__DIR__ . '/../' . $p); }

const COLA = '/var/www/blens-private/cola';
function fin(string $to): never {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) { header('Content-Type: application/json'); echo json_encode(['ok' => true, 'to' => $to]); exit; }
    header("Location: $to"); exit;
}
function up_cola(string $f, array $ok, int $max): ?string {
    if (empty($_FILES[$f]['name'])) return null;
    $u = $_FILES[$f];
    if ($u['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('No se pudo subir el video (código ' . $u['error'] . '). Si es muy pesado, probá con el link de Drive.');
    if ($u['size'] > $max) throw new RuntimeException('El video supera el máximo permitido. Para archivos más pesados usá el link de Drive.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($u['tmp_name']);
    if (!isset($ok[$mime])) throw new RuntimeException("Formato de video no permitido ($mime).");
    if (!is_dir(COLA)) @mkdir(COLA, 0750, true);
    $dest = COLA . '/' . bin2hex(random_bytes(8)) . '.' . $ok[$mime];
    if (!move_uploaded_file($u['tmp_name'], $dest)) throw new RuntimeException('No se pudo guardar el video en el servidor.');
    @file_put_contents($dest . '.name', trim(pathinfo((string)$u['name'], PATHINFO_FILENAME)));
    return $dest;
}
function drive_ok(string $u): bool {
    $h = strtolower((string)parse_url($u, PHP_URL_HOST));
    return str_starts_with($u, 'https://') && in_array($h, ['drive.google.com', 'docs.google.com', 'drive.usercontent.google.com'], true)
        && (bool)(preg_match('#/d/([A-Za-z0-9_-]{10,})#', $u) || preg_match('#[?&]id=([A-Za-z0-9_-]{10,})#', $u));
}
function drive_sidecar(string $u): ?string {
    if (preg_match('#/d/([A-Za-z0-9_-]{10,})#', $u, $m) || preg_match('#[?&]id=([A-Za-z0-9_-]{10,})#', $u, $m)) return COLA . '/drive_' . $m[1] . '.name';
    return null;
}
function lanzar(int $id, string $tipo = 'proyecto'): void {
    $w = realpath(__DIR__ . '/../../blens-config/worker.php');
    if (!$w) throw new RuntimeException('Falta el procesador de videos (blens-config/worker.php).');
    $php = trim((string)@shell_exec('command -v php')) ?: '/usr/bin/php';
    $arg = $tipo === 'proyecto' ? '' : ' ' . $tipo;
    exec('setsid nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($w) . ' ' . (int)$id . $arg . ' > /dev/null 2>&1 &');
}
function borrar_archivos(array $r): void {
    foreach (['video_mp4', 'video_webm', 'video_1080', 'video_480', 'poster'] as $k) rmf($r[$k] ?? null);
    $c = (string)($r['cola_src'] ?? ''); if ($c !== '' && str_starts_with($c, COLA . '/')) { @unlink($c); @unlink($c . '.name'); }
    $ds = drive_sidecar((string)($r['fuente_drive'] ?? '')); if ($ds) @unlink($ds);
}

const VIDEO_PX = 720; // lado corto del video final (720 = 720p)
function procesar(string $rel): array {
    if (!function_exists('exec') || trim((string)@shell_exec('command -v ffmpeg')) === '') throw new RuntimeException('Falta ffmpeg en el servidor (sudo apt install ffmpeg).');
    ignore_user_abort(true); set_time_limit(0);
    $src = __DIR__ . '/../' . $rel; $base = bin2hex(random_bytes(8));
    $out = "media/uploads/$base.mp4"; $jpg = "media/uploads/$base.jpg"; $abs = __DIR__ . '/../';
    $px = VIDEO_PX; $vf = "scale='if(gt(iw,ih),-2,min($px,iw))':'if(gt(iw,ih),min($px,ih),-2)'";
    exec('nice -n 10 ffmpeg -v error -y -i ' . escapeshellarg($src) . ' -map 0:v:0 -map 0:a:0? -sn -dn -vf ' . escapeshellarg($vf) . ' -c:v libx264 -preset veryfast -threads 0 -crf 25 -pix_fmt yuv420p -movflags +faststart -c:a aac -b:a 96k ' . escapeshellarg($abs . $out) . ' 2>&1', $o, $rc);
    if ($rc !== 0 || !is_file($abs . $out)) { rmf($rel); throw new RuntimeException('No se pudo convertir el video: ' . mb_substr(implode(' ', $o), 0, 200)); }
    foreach ([1, 0] as $t) { exec('ffmpeg -v error -y -ss ' . $t . ' -i ' . escapeshellarg($abs . $out) . ' -frames:v 1 -vf scale=800:-2 -q:v 4 ' . escapeshellarg($abs . $jpg) . ' 2>&1'); if (is_file($abs . $jpg)) break; }
    rmf($rel);
    return [$out, is_file($abs . $jpg) ? $jpg : null];
}
// Convierte el teléfono (como lo escriba el cliente) al formato de WhatsApp Argentina: 549 + área + número
function wa_link(string $tel, string $nombre, string $servicio): ?string {
    $d = preg_replace('/\D+/', '', $tel) ?? ''; if ($d === '') return null;
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    if (str_starts_with($d, '54')) { if (!str_starts_with($d, '549')) $d = '549' . substr($d, 2); }
    else {
        $d = ltrim($d, '0');
        if (strlen($d) === 12) foreach (str_starts_with($d, '11') ? [2] : [3, 4] as $a) { if (substr($d, $a, 2) === '15') { $d = substr($d, 0, $a) . substr($d, $a + 2); break; } }
        if (strlen($d) !== 10) return null;
        $d = '549' . $d;
    }
    $p = explode(' ', trim($nombre))[0];
    $txt = "Hola $p, te escribimos de blens.ar por tu consulta" . ($servicio !== '' ? " sobre $servicio" : '') . ". ¿Cuándo podemos hablar para contarte cómo trabajamos?";
    return 'https://wa.me/' . $d . '?text=' . rawurlencode($txt);
}

if ($post) {
    $a = $_POST['a'] ?? '';
    if ($a === 'login') {
        if ($w = blens_bloqueado('admin')) { $msg = 'Demasiados intentos. Probá de nuevo en ' . (int)ceil($w / 60) . ' min.'; }
        else {
            $okU = hash_equals($cfg['admin_user'], (string)($_POST['u'] ?? ''));
            if ($okU && password_verify((string)($_POST['p'] ?? ''), $cfg['admin_hash'])) {
                blens_ok('admin'); session_regenerate_id(true); $_SESSION['ok'] = 1; $_SESSION['t'] = bin2hex(random_bytes(16));
                header('Location: ./'); exit;
            }
            blens_fallo('admin'); usleep(900000); $msg = 'Usuario o clave incorrectos.';
        }
    } elseif (!empty($_SESSION['ok'])) {
        try {
            if ($a === 'logout') { session_destroy(); header('Location: ./'); exit; }
            if ($a === 'toggle') { $pdo->prepare('UPDATE proyectos SET visible = 1 - visible WHERE id = ?')->execute([(int)$_POST['id']]); fin('./?ok=1'); }
            if ($a === 'delete') {
                $s = $pdo->prepare('SELECT * FROM proyectos WHERE id = ?'); $s->execute([(int)$_POST['id']]);
                if ($r = $s->fetch()) { $pdo->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$r['id']]); borrar_archivos($r); }
                fin('./?ok=1');
            }
            if ($a === 'reintentar') {
                $s = $pdo->prepare('SELECT id,cola_src,fuente_drive FROM proyectos WHERE id = ?'); $s->execute([(int)$_POST['id']]);
                if (($r = $s->fetch()) && ($r['cola_src'] || $r['fuente_drive'])) { $pdo->prepare("UPDATE proyectos SET estado='procesando', error_msg=NULL WHERE id=?")->execute([$r['id']]); lanzar((int)$r['id']); }
                fin('./?ok=1');
            }
            if ($a === 'ia') {
                $s = $pdo->prepare('SELECT id FROM proyectos WHERE id = ?'); $s->execute([(int)($_POST['id'] ?? 0)]);
                if (!$s->fetch()) throw new RuntimeException('Proyecto inexistente.');
                lanzar((int)$_POST['id'], 'ia');
                fin('./?ok=1');
            }
            if ($a === 'importar_carpeta') {
                if ($faltaMig) throw new RuntimeException('Falta actualizar la base de datos (mirá el aviso rojo en la lista de proyectos).');
                if ($faltaCat) throw new RuntimeException('Falta ampliar la columna de categorías (mirá el aviso rojo en la lista de proyectos).');
                $fid = drive_folder_id((string)($_POST['carpeta'] ?? ''));
                if (!$fid) throw new RuntimeException('Pegá un link válido de carpeta de Google Drive (drive.google.com/drive/folders/…).');
                $ci = cat_slug((string)($_POST['categoria_nueva'] ?? ''));
                if ($ci === '') $ci = (string)($_POST['categoria'] ?? '');
                if ($ci === '' || $ci === 'showreel' || strlen($ci) > 40) throw new RuntimeException('Elegí una categoría para los proyectos importados.');
                if (!isset($cats[$ci])) $cats[$ci] = cat_label($ci);
                $clid = (int)($_POST['cliente_id'] ?? 0); $cli = '';
                if ($clid) { $q0 = $pdo->prepare('SELECT empresa,nombre FROM clientes WHERE id=?'); $q0->execute([$clid]); if ($r0 = $q0->fetch()) $cli = $r0['empresa'] ?: $r0['nombre']; else $clid = 0; }
                $w = isset($_POST['ancho']) ? 1 : 0;
                $vids = drive_folder_videos($fid);
                if (!$vids) throw new RuntimeException('No encontré videos en esa carpeta. Revisá que esté compartida como «cualquier persona con el enlace» y que tenga archivos MP4, MOV, MKV o WebM.');
                $ins = $pdo->prepare("INSERT INTO proyectos (titulo,cliente,categoria,descripcion,ancho,orden,visible,cliente_id,estado,fuente_drive,poster_auto) VALUES (?,?,?,?,?,?,1,?,'procesando',?,1)");
                $orden = (int)$pdo->query("SELECT COALESCE(MAX(orden),0) FROM proyectos WHERE categoria <> 'showreel'")->fetchColumn();
                if (!is_dir(COLA)) @mkdir(COLA, 0750, true);
                $n = 0; $err = 0;
                foreach ($vids as $vid => $name) {
                    $titulo = trim(pathinfo($name, PATHINFO_FILENAME)); if ($titulo === '') $titulo = $name;
                    @file_put_contents(COLA . '/drive_' . $vid . '.name', $titulo);
                    $ins->execute([mb_substr($titulo, 0, 120), $cli, $ci, '', $w, ++$orden, $clid ?: null, 'https://drive.google.com/file/d/' . $vid . '/view']);
                    $nid = (int)$pdo->lastInsertId();
                    try { lanzar($nid); $n++; }
                    catch (Throwable $x2) { $err++; $pdo->prepare("UPDATE proyectos SET estado='error', error_msg=? WHERE id=?")->execute([mb_substr($x2->getMessage(), 0, 250), $nid]); }
                }
                fin('./?ok=1&imp=' . $n . ($err ? '&imp_err=' . $err : ''));
            }
            if ($a === 'reorder') {
                $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
                if (!$ids) throw new RuntimeException('No llegó el nuevo orden.');
                $pdo->beginTransaction();
                try {
                    $q = $pdo->prepare("UPDATE proyectos SET orden=? WHERE id=? AND categoria <> 'showreel'");
                    foreach ($ids as $i => $pid) $q->execute([$i + 1, $pid]);
                    $pdo->commit();
                } catch (Throwable $x) { $pdo->rollBack(); throw $x; }
                fin('./?ok=1');
            }
            if ($a === 'save') {
                if ($faltaMig) throw new RuntimeException('Falta actualizar la base de datos (mirá el aviso rojo en la lista de proyectos).');
                if ($faltaCat) throw new RuntimeException('Falta ampliar la columna de categorías (mirá el aviso rojo en la lista de proyectos).');
                $n0 = (string)($_POST['nonce'] ?? '');
                if ($n0 !== '' && isset($_SESSION['nonces'][$n0])) fin('./?ok=1');
                if ($n0 !== '') $_SESSION['nonces'][$n0] = 1;
                $id = (int)($_POST['id'] ?? 0);
                $t = mb_substr(trim((string)($_POST['titulo'] ?? '')), 0, 120);
                $nueva = cat_slug((string)($_POST['categoria_nueva'] ?? ''));
                if (in_array($nueva, ['auto', 'showreel'], true)) throw new RuntimeException('Ese nombre de categoría está reservado.');
                $c = $nueva !== '' ? $nueva : (string)($_POST['categoria'] ?? '');
                if (isset($_POST['categoria_auto'])) $c = 'auto';
                if ($c === '' || strlen($c) > 40) throw new RuntimeException('Elegí una categoría válida (hasta 40 caracteres).');
                if (!isset($cats[$c])) $cats[$c] = cat_label($c);
                $cli = mb_substr(trim((string)($_POST['cliente'] ?? '')), 0, 80); $clid = (int)($_POST['cliente_id'] ?? 0);
                if ($cli !== '') { $clid = 0; } elseif ($clid) { $q0 = $pdo->prepare('SELECT empresa,nombre FROM clientes WHERE id=?'); $q0->execute([$clid]); if ($r0 = $q0->fetch()) $cli = $r0['empresa'] ?: $r0['nombre']; else $clid = 0; }
                $d = mb_substr(trim((string)($_POST['descripcion'] ?? '')), 0, 160);
                $w = isset($_POST['ancho']) ? 1 : 0; $v = isset($_POST['visible']) ? 1 : 0;
                $sv = mb_substr(trim((string)($_POST['servicios'] ?? '')), 0, 160); $dt = mb_substr(trim((string)($_POST['detalle'] ?? '')), 0, 4000); $rs = mb_substr(trim((string)($_POST['resultado'] ?? '')), 0, 200);
                $old = null;
                if ($id) { $s = $pdo->prepare('SELECT * FROM proyectos WHERE id = ?'); $s->execute([$id]); $old = $s->fetch(); if (!$old) throw new RuntimeException('Proyecto inexistente.'); }
                $o = $old ? (int)$old['orden'] : 0;
                if (isset($_POST['orden']) && $_POST['orden'] !== '') $o = (int)$_POST['orden'];
                if (!$old || (($old['categoria'] === 'showreel') !== ($c === 'showreel'))) {
                    $o = $c === 'showreel' ? 0 : (int)$pdo->query("SELECT COALESCE(MAX(orden),0)+1 FROM proyectos WHERE categoria <> 'showreel'")->fetchColumn();
                }
                $fuente = (string)($_POST['fuente'] ?? 'mantener'); $cola = null; $drive = null;
                if ($fuente === 'archivo') {
                    $cola = up_cola('mp4', ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/x-matroska' => 'mkv', 'video/webm' => 'webm'], 300 * 1048576);
                    if (!$cola) throw new RuntimeException('Elegí el archivo de video, o pasá a la opción «Link de Drive».');
                } elseif ($fuente === 'drive') {
                    $drive = trim((string)($_POST['drive'] ?? ''));
                    if (!drive_ok($drive)) throw new RuntimeException('Pegá un link de Google Drive válido (drive.google.com/file/d/…), compartido como «cualquier persona con el enlace».');
                }
                if ($t === '') {
                    $nombreArchivo = (string)($_FILES['mp4']['name'] ?? '');
                    if ($fuente === 'archivo' && $nombreArchivo !== '') {
                        $t = mb_substr(trim(pathinfo($nombreArchivo, PATHINFO_FILENAME)), 0, 120);
                    } elseif ($fuente === 'mantener' && $old && trim((string)$old['titulo']) !== '') {
                        $t = (string)$old['titulo'];
                    }
                    if ($t === '') $t = 'Video sin título';
                }
                $nuevo = $cola !== null || $drive !== null;
                $pos = up('poster', ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 8 * 1048576);
                $pa = ($nuevo && !$pos) ? 1 : 0;
                if ($old) {
                    $pdo->prepare('UPDATE proyectos SET titulo=?,cliente=?,categoria=?,descripcion=?,ancho=?,orden=?,visible=?,servicios=?,detalle=?,resultado=?,cliente_id=? WHERE id=?')
                        ->execute([$t, $cli, $c, $d, $w, $o, $v, $sv, $dt, $rs, $clid ?: null, $id]);
                    if ($pos) { $pdo->prepare('UPDATE proyectos SET poster=?, poster_auto=0 WHERE id=?')->execute([$pos, $id]); rmf($old['poster']); }
                    if ($nuevo) {
                        $oc = (string)($old['cola_src'] ?? ''); if ($oc !== '' && str_starts_with($oc, COLA . '/')) { @unlink($oc); @unlink($oc . '.name'); }
                        $ods = drive_sidecar((string)($old['fuente_drive'] ?? '')); if ($ods) @unlink($ods);
                        $pdo->prepare("UPDATE proyectos SET estado='procesando', error_msg=NULL, fuente_drive=?, cola_src=?, poster_auto=? WHERE id=?")->execute([$drive, $cola, $pa, $id]);
                    }
                } else {
                    $pdo->prepare('INSERT INTO proyectos (titulo,cliente,categoria,descripcion,ancho,orden,visible,poster,servicios,detalle,resultado,cliente_id,estado,fuente_drive,cola_src,poster_auto) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$t, $cli, $c, $d, $w, $o, $v, $pos, $sv, $dt, $rs, $clid ?: null, $nuevo ? 'procesando' : 'listo', $drive, $cola, $pa]);
                    $id = (int)$pdo->lastInsertId();
                }
                if ($clid && $id) { $pdo->prepare('UPDATE entregas SET cliente_id=? WHERE proyecto_id=?')->execute([$clid, $id]); }
                if (isset($_POST['crear_entrega']) && $clid) {
                    $descEnt = empty($cfg['ai_api_key']) ? $d : '';
                    $q = $pdo->prepare('SELECT id FROM entregas WHERE proyecto_id=? ORDER BY id LIMIT 1'); $q->execute([$id]);
                    $eid = (int)($q->fetchColumn() ?: 0);
                    if (!$eid) {
                        $pdo->prepare('INSERT INTO entregas (cliente_id,titulo,descripcion,link_hd,link_4k,proyecto_id) VALUES (?,?,?,?,?,?)')
                            ->execute([$clid, $t, $descEnt, '', '', $id]);
                        $eid = (int)$pdo->lastInsertId();
                    } else {
                        $pdo->prepare("UPDATE entregas SET cliente_id=?, titulo=IF(titulo='',?,titulo), descripcion=IF(descripcion IS NULL OR descripcion='',?,descripcion) WHERE id=?")
                            ->execute([$clid, $t, $descEnt, $eid]);
                    }
                    if (!$nuevo && $eid && !empty($cfg['ai_api_key'])) { try { lanzar($eid, 'entrega'); } catch (Throwable $x3) { } }
                }
                if ($nuevo) {
                    try { lanzar($id); }
                    catch (Throwable $x2) { $pdo->prepare("UPDATE proyectos SET estado='error', error_msg=? WHERE id=?")->execute([mb_substr($x2->getMessage(), 0, 250), $id]); throw $x2; }
                }
                fin('./?ok=1');
            }
        } catch (Throwable $x) { if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $x->getMessage()]); exit; } $msg = $x->getMessage(); }
    }
}
$auth = !empty($_SESSION['ok']);
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Panel | blens.ar</title><link rel="icon" href="../assets/favicon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="../assets/apple-touch-icon.png"><link rel="stylesheet" href="admin.css?v=16"></head><body>
<svg class="sprite" aria-hidden="true"><symbol id="mark" viewBox="0 0 130 130"><polygon points="0,0 70,0 100,30 100,100 0,100" fill="#F5F1EA"/><polygon points="60,45 125,45 125,125 45,125 45,60" fill="#F5F1EA"/><polygon points="60,45 100,45 100,64 86,72 100,80 100,100 45,100 45,60" fill="#FF5A1F"/></symbol></svg>
<?php if (!$auth): ?>
<form class="login" method="post"><h1 class="lh"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span> · panel</h1>
<?php if ($msg) echo '<p class="err">' . $e($msg) . '</p>'; ?>
<input type="hidden" name="a" value="login"><input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>">
<input name="u" placeholder="Usuario * obligatorio" autocomplete="username" required><input name="p" type="password" placeholder="Clave * obligatorio" autocomplete="current-password" required><button>Entrar</button></form>
<?php else:
$view = $_GET['v'] ?? 'proyectos'; $edit = isset($_GET['e']) ? (int)$_GET['e'] : null; ?>
<div class="top"><a class="brand" href="./"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span></a>
<nav><a href="./">Proyectos</a><a href="?v=consultas">Consultas</a><a href="clientes.php">Clientes</a><a href="cobros.php">Cobros</a><a href="../" target="_blank" rel="noopener">Ver web</a></nav>
<form method="post"><input type="hidden" name="a" value="logout"><input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>"><button class="g">Salir</button></form></div>
<?php if (isset($_GET['ok'])) { $imp = (int)($_GET['imp'] ?? 0); $impErr = (int)($_GET['imp_err'] ?? 0); echo '<p class="ok">' . ($imp > 0 ? 'Se importaron ' . $imp . ' videos a la cola de procesamiento.' : 'Cambios guardados.') . ' Pueden tardar hasta un minuto en verse en la web.' . ($impErr > 0 ? ' ' . $impErr . ' no se pudieron iniciar; revisá la lista.' : '') . '</p>'; } if ($msg) echo '<p class="err">' . $e($msg) . '</p>'; ?>

<?php if ($view === 'consultas'): $rows = $pdo->query('SELECT * FROM consultas ORDER BY id DESC LIMIT 100')->fetchAll(); ?>
<h1>Consultas recibidas</h1>
<table class="cards"><tr><th>Fecha</th><th>Nombre / empresa</th><th>Servicio</th><th>Mensaje</th></tr>
<?php foreach ($rows as $r): ?><tr><td class="mut" data-label="Fecha"><?= $e(substr($r['creado'], 0, 16)) ?></td>
<td data-label="Contacto"><?= $e($r['nombre']) ?><br><span class="mut"><?= $e($r['empresa']) ?></span><br><a href="mailto:<?= $e($r['email']) ?>"><?= $e($r['email']) ?></a><?php if ($r['telefono'] !== '') { $w = wa_link($r['telefono'], $r['nombre'], $r['servicio']); echo '<br>' . $e($r['telefono']) . ' '; echo $w ? '<a class="btn" href="' . $e($w) . '" target="_blank" rel="noopener">WhatsApp</a>' : '<span class="mut">(revisar número)</span>'; } ?></td>
<td data-label="Servicio"><?= $e($r['servicio']) ?></td><td data-label="Mensaje"><?= nl2br($e($r['mensaje'])) ?></td></tr><?php endforeach; if (!$rows) echo '<tr><td colspan="4" class="mut">Todavía no hay consultas.</td></tr>'; ?></table>

<?php elseif ($edit !== null):
$p = ['id' => 0, 'titulo' => '', 'cliente' => '', 'cliente_id' => null, 'categoria' => 'reels', 'descripcion' => '', 'ancho' => 0, 'orden' => 0, 'visible' => 1, 'video_mp4' => '', 'poster' => '', 'servicios' => '', 'detalle' => '', 'resultado' => '', 'estado' => 'listo', 'error_msg' => '', 'calidades' => '', 'fuente_drive' => ''];
if ($edit && !$faltaMig) { $s = $pdo->prepare('SELECT * FROM proyectos WHERE id = ?'); $s->execute([$edit]); $p = $s->fetch() ?: $p; }
elseif ($edit) { $s = $pdo->prepare('SELECT * FROM proyectos WHERE id = ?'); $s->execute([$edit]); $p = ($s->fetch() ?: []) + $p; }
$cls = $pdo->query('SELECT id,empresa,nombre FROM clientes ORDER BY empresa,nombre')->fetchAll();
$tiene = !empty($p['video_mp4']) || $p['estado'] === 'procesando';
$ent = null;
if ($p['id']) { try { $q = $pdo->prepare('SELECT id,cliente_id FROM entregas WHERE proyecto_id=? ORDER BY id LIMIT 1'); $q->execute([(int)$p['id']]); $ent = $q->fetch(); } catch (Throwable $x0) { } }
if ($faltaMig) echo '<p class="err">Falta actualizar la base de datos. En el servidor corré:<br><code>' . $e(MIG_CMD) . '</code></p>';
if ($faltaCat) echo '<p class="err">Falta ampliar la columna de categorías a 40 caracteres. En el servidor corré:<br><code>' . $e(MIG_CAT_CMD) . '</code></p>'; ?>
<a class="back" href="./">&larr; Proyectos</a>
<h1><?= $p['id'] ? 'Editar proyecto' : 'Nuevo proyecto' ?></h1>
<?php if ($p['estado'] === 'procesando'): ?><p class="ok">⏳ Se está procesando el video. Podés seguir trabajando; en la lista vas a ver cuándo termina.</p><?php elseif ($p['estado'] === 'error'): ?><p class="err">El último video no se pudo procesar: <?= $e($p['error_msg']) ?></p><?php endif; ?>
<form id="pform" class="pf" method="post" enctype="multipart/form-data">
<input type="hidden" name="a" value="save"><input type="hidden" name="nonce" value="<?= bin2hex(random_bytes(8)) ?>"><input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">

<section class="pc">
  <h2>Video</h2>
  <p class="mut">Elegí un origen: <span class="req">* obligatorio</span> (subir archivo o link de Drive; si ya hay un video, podés mantenerlo).</p>
  <div class="seg">
    <?php if ($tiene): ?><label><input type="radio" name="fuente" value="mantener" checked><span>Mantener el actual</span></label><?php endif; ?>
    <label><input type="radio" name="fuente" value="archivo"<?= $tiene ? '' : ' checked' ?>><span>Subir archivo</span></label>
    <label><input type="radio" name="fuente" value="drive"><span>Link de Drive</span></label>
  </div>
  <?php if ($tiene && !empty($p['video_1080'])): ?><p class="mut">Video actual en máxima definición web (1080p).</p><?php endif; ?>
  <div class="src s-archivo"><label class="drop"><input type="file" name="mp4" accept="video/*"><strong>Elegí o arrastrá tu video acá</strong><small>MP4, MOV, MKV o WebM · hasta 300 MB</small><span class="req">* obligatorio si elegís «Subir archivo»</span><em id="fname"></em></label></div>
  <div class="src s-drive"><label><span class="lbl">Link de Google Drive <span class="req">* obligatorio si elegís «Link de Drive»</span></span><input name="drive" type="url" placeholder="https://drive.google.com/file/d/…"></label><p class="mut">Tiene que estar compartido como «cualquier persona con el enlace». El servidor lo descarga y lo guarda; no importa el tamaño.</p></div>
  <p class="mut hint">Se genera sola la versión 1080p en máxima definición web (sin agrandar el original) y la portada. Se procesa en segundo plano.</p>
  <label><span class="lbl">Portada propia <span class="opt">(opcional)</span> (si no subís una, se toma del video)</span><input type="file" name="poster" accept="image/jpeg,image/png,image/webp"></label>
</section>

<section class="pc">
  <h2>Datos</h2>
  <label><span class="lbl">Título <span class="opt">(opcional - lo completa la IA o toma el nombre del archivo)</span></span><input name="titulo" value="<?= $e($p['titulo']) ?>" maxlength="120"></label>
  <div class="two">
    <label><span class="lbl">Cliente (de tu lista) <span class="opt">(opcional)</span></span><select name="cliente_id"><option value="0">— Sin elegir —</option><?php foreach ($cls as $x): ?><option value="<?= (int)$x['id'] ?>"<?= (int)($p['cliente_id'] ?? 0) === (int)$x['id'] ? ' selected' : '' ?>><?= $e($x['empresa'] ?: $x['nombre']) ?></option><?php endforeach; ?></select></label>
    <label><span class="lbl">…o escribí otro cliente <span class="opt">(opcional)</span></span><input name="cliente" value="<?= !empty($p['cliente_id']) ? '' : $e($p['cliente']) ?>" maxlength="80" placeholder="Si no está en la lista"></label>
  </div>
  <fieldset class="chips"><legend>Categoría <span class="req">* obligatorio</span></legend><?php foreach ($catsForm as $k => $n): ?><label><input type="radio" name="categoria" value="<?= $e($k) ?>"<?= $p['categoria'] === $k ? ' checked' : '' ?>><span><?= $e($n) ?></span></label><?php endforeach; ?></fieldset>
  <label><span class="lbl">Nueva categoría <span class="opt">(opcional)</span> (ej: institucional)</span><input name="categoria_nueva" maxlength="40" placeholder="Se agrega como etiqueta nueva"></label>
  <label class="chk"><input type="checkbox" name="categoria_auto" <?= $p['categoria'] === 'auto' ? 'checked' : '' ?>> Que la IA elija la categoría al procesar el video <span class="opt">(opcional)</span></label>
  <label><span class="lbl">Descripción corta <span class="opt">(opcional)</span> (se ve en la tarjeta)</span><input name="descripcion" value="<?= $e($p['descripcion']) ?>" maxlength="160"></label>
</section>

<section class="pc">
  <h2>Página del proyecto</h2>
  <label><span class="lbl">Qué hicimos <span class="opt">(opcional)</span> (ej: guion, grabación y edición)</span><input name="servicios" value="<?= $e($p['servicios'] ?? '') ?>" maxlength="160"></label>
  <label><span class="lbl">Detalle del proyecto <span class="opt">(opcional)</span></span><textarea name="detalle" rows="5" maxlength="4000"><?= $e($p['detalle'] ?? '') ?></textarea></label>
  <label><span class="lbl">Resultado <span class="opt">(opcional)</span> (ej: 12 consultas en el primer mes)</span><input name="resultado" value="<?= $e($p['resultado'] ?? '') ?>" maxlength="200"></label>
</section>

<section class="pc">
  <h2>Publicación</h2>
  <label class="chk"><input type="checkbox" name="visible" <?= $p['visible'] ? 'checked' : '' ?>>Visible en la web <span class="opt">(opcional)</span></label>
  <label class="chk"><input type="checkbox" name="ancho" <?= $p['ancho'] ? 'checked' : '' ?>>Tarjeta ancha (para videos horizontales) <span class="opt">(opcional)</span></label>
  <?php if ($ent): ?>
  <p class="mut">Este proyecto ya tiene una entrega en el portal del cliente. <a href="clientes.php?c=<?= (int)$ent['cliente_id'] ?>&e=<?= (int)$ent['id'] ?>">Ver entrega</a></p>
  <?php else: ?>
  <label class="chk"><input type="checkbox" name="crear_entrega" <?= (!$p['id'] && !empty($p['cliente_id'])) ? 'checked' : '' ?>> Crear también una entrega en el portal del cliente <span class="opt">(opcional)</span></label>
  <p class="mut">Se vincula a este proyecto y se completa con IA. Requiere elegir un cliente de la lista.</p>
  <?php endif; ?>
</section>

<div class="savebar"><button type="submit" id="gb">Guardar proyecto</button><a class="btn g" href="./">Cancelar</a><div class="prog" id="prog" hidden><i></i><span></span></div></div>
</form>
<?php if ($p['id'] && (!empty($p['video_mp4']) || !empty($p['video_1080']) || !empty($p['fuente_drive']))): ?>
<h2>Textos con IA</h2><form method="post" class="row"><input type="hidden" name="a" value="ia"><input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="g">Completar textos con IA</button></form>
<p class="mut">Completa el título si es provisorio y los textos que estén vacíos.</p>
<?php endif; ?>
<?php if ($p['id']): ?><h2>Eliminar</h2><form method="post" class="row"><input type="hidden" name="a" value="delete"><input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
<label class="chk"><input type="checkbox" required>Confirmo que quiero eliminarlo y borrar sus archivos <span class="req">* obligatorio</span></label><button class="r">Eliminar</button></form><?php endif; ?>

<?php else:
if ($faltaMig) echo '<p class="err">Falta actualizar la base de datos. En el servidor corré:<br><code>' . $e(MIG_CMD) . '</code></p>';
if ($faltaCat) echo '<p class="err">Falta ampliar la columna de categorías a 40 caracteres. En el servidor corré:<br><code>' . $e(MIG_CAT_CMD) . '</code></p>';
$rows = $pdo->query('SELECT * FROM proyectos ORDER BY orden ASC, id DESC')->fetchAll();
$show = array_values(array_filter($rows, fn($r) => ($r['categoria'] ?? '') === 'showreel'));
$port = array_values(array_filter($rows, fn($r) => ($r['categoria'] ?? '') !== 'showreel'));
$cls = $pdo->query('SELECT id,empresa,nombre FROM clientes ORDER BY empresa,nombre')->fetchAll();
$catsImp = $cats; unset($catsImp['showreel']);
$card = function (array $r, bool $drag) use ($cats, $e): void { $est = $r['estado'] ?? 'listo'; ?>
<article class="pi <?= $r['visible'] ? '' : 'off' ?>"<?= $drag ? ' draggable="true" data-id="' . (int)$r['id'] . '" title="Arrastrá para cambiar el orden"' : '' ?>>
  <div class="th"><?= $r['poster'] ? '<img src="../' . $e($r['poster']) . '" alt="">' : '<div></div>' ?></div>
  <div class="in"><b><?= $e($r['titulo']) ?></b><span class="mut"><?= $e(trim(($r['cliente'] ? $r['cliente'] . ' · ' : '') . ($cats[$r['categoria']] ?? $r['categoria']))) ?></span>
    <div class="tags">
      <?php if ($est === 'procesando'): ?><span class="b proc" data-proc>⏳ Procesando…</span>
      <?php elseif ($est === 'error'): ?><span class="b err2">Error al procesar</span>
      <?php else: ?><span class="b ok2">Listo</span><?php endif; ?>
      <?php if (!empty($r['video_1080'])): ?><span class="b">1080p</span><?php endif; ?>
      <?php if (!$r['visible']): ?><span class="b">Oculto</span><?php endif; ?>
    </div>
    <?php if ($est === 'error'): ?><span class="mut"><?= $e($r['error_msg'] ?? '') ?></span><?php endif; ?>
  </div>
  <div class="ac"><a class="btn g" href="?e=<?= (int)$r['id'] ?>">Editar</a>
    <form method="post"><input type="hidden" name="a" value="toggle"><input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="g"><?= $r['visible'] ? 'Ocultar' : 'Mostrar' ?></button></form>
    <?php if ($est === 'error' && ($r['cola_src'] || $r['fuente_drive'])): ?><form method="post"><input type="hidden" name="a" value="reintentar"><input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button>Reintentar</button></form><?php endif; ?>
  </div>
</article>
<?php };
?>
<div class="lh2"><h1>Proyectos</h1><div class="row"><button type="button" class="g" data-importar>Importar carpeta de Drive</button><a class="btn" href="?e=0">+ Nuevo proyecto</a></div></div>
<dialog id="imp" class="imp">
<form method="post" class="f">
<input type="hidden" name="a" value="importar_carpeta"><input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>">
<h2 class="full">Importar carpeta de Google Drive</h2>
<p class="mut full">Pegá el link de una carpeta pública. Se crea un proyecto pendiente por cada video encontrado y se procesan en cola.</p>
<label class="full"><span class="lbl">Link de la carpeta <span class="req">* obligatorio</span></span><input name="carpeta" type="url" required placeholder="https://drive.google.com/drive/folders/…"></label>
<div class="two full">
<label><span class="lbl">Cliente <span class="opt">(opcional)</span></span><select name="cliente_id"><option value="0">— Sin elegir —</option><?php foreach ($cls as $x): ?><option value="<?= (int)$x['id'] ?>"><?= $e($x['empresa'] ?: $x['nombre']) ?></option><?php endforeach; ?></select></label>
<label><span class="lbl">Categoría <span class="req">* obligatorio</span></span><select name="categoria"><?php foreach ($catsImp as $k => $n): ?><option value="<?= $e($k) ?>"><?= $e($n) ?></option><?php endforeach; ?></select></label>
</div>
<label class="full"><span class="lbl">…o nueva categoría <span class="opt">(opcional)</span></span><input name="categoria_nueva" maxlength="40" placeholder="ej: institucional (reemplaza la del selector)"></label>
<label class="chk full"><input type="checkbox" name="ancho"> Tarjeta ancha (para videos horizontales) <span class="opt">(opcional)</span></label>
<div class="row full"><button>Importar videos</button><button type="button" class="g" data-cerrar>Cancelar</button></div>
</form>
</dialog>
<h2>Video de presentación - Nosotros</h2>
<div class="plist plist-showreel">
<?php foreach ($show as $r) $card($r, false); if (!$show) echo '<p class="mut">Todavía no cargaste el video de presentación.</p>'; ?>
</div>
<h2>Proyectos del portfolio</h2>
<p class="mut">Arrastrá las tarjetas para cambiar el orden; se guarda solo.</p>
<div class="plist plist-portfolio" id="portfolio-list" data-t="<?= $e($_SESSION['t']) ?>">
<?php foreach ($port as $r) $card($r, true); if (!$port) echo '<p class="mut">Todavía no cargaste proyectos del portfolio.</p>'; ?>
</div>
<?php endif; endif; ?><script src="admin.js?v=17"></script></body></html>
