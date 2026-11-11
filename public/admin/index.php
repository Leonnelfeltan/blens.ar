<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY');

$cfg = blens_cfg(); $pdo = blens_db();
$faltaMig = false; try { $faltaMig = !$pdo->query("SHOW COLUMNS FROM proyectos LIKE 'video_1080'")->fetch(); } catch (Throwable $x0) { }
const MIG_CMD = "sudo mysql blens_db -e \"ALTER TABLE proyectos ADD COLUMN video_1080 VARCHAR(255) NULL, ADD COLUMN video_480 VARCHAR(255) NULL, ADD COLUMN calidades VARCHAR(40) NULL, ADD COLUMN estado VARCHAR(12) NOT NULL DEFAULT 'listo', ADD COLUMN error_msg VARCHAR(255) NULL, ADD COLUMN fuente_drive VARCHAR(500) NULL, ADD COLUMN cola_src VARCHAR(255) NULL, ADD COLUMN poster_auto TINYINT(1) NOT NULL DEFAULT 0;\"";
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$_SESSION['t'] ??= bin2hex(random_bytes(16));
$cats = ['reels' => 'Reels', 'cobertura' => 'Cobertura', 'estrategia' => 'Estrategia', 'showreel' => 'Video de presentación (sección Nosotros)'];
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
    return $dest;
}
function drive_ok(string $u): bool {
    $h = strtolower((string)parse_url($u, PHP_URL_HOST));
    return str_starts_with($u, 'https://') && in_array($h, ['drive.google.com', 'docs.google.com', 'drive.usercontent.google.com'], true)
        && (bool)(preg_match('#/d/([A-Za-z0-9_-]{10,})#', $u) || preg_match('#[?&]id=([A-Za-z0-9_-]{10,})#', $u));
}
function lanzar(int $id): void {
    $w = realpath(__DIR__ . '/../../blens-config/worker.php');
    if (!$w) throw new RuntimeException('Falta el procesador de videos (blens-config/worker.php).');
    $php = trim((string)@shell_exec('command -v php')) ?: '/usr/bin/php';
    exec('setsid nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($w) . ' ' . (int)$id . ' > /dev/null 2>&1 &');
}
function borrar_archivos(array $r): void {
    foreach (['video_mp4', 'video_webm', 'video_1080', 'video_480', 'poster'] as $k) rmf($r[$k] ?? null);
    $c = (string)($r['cola_src'] ?? ''); if ($c !== '' && str_starts_with($c, COLA . '/')) @unlink($c);
}

const VIDEO_PX = 720; // lado corto del video final (720 = 720p)
function procesar(string $rel): array {
    if (!function_exists('exec') || trim((string)@shell_exec('command -v ffmpeg')) === '') throw new RuntimeException('Falta ffmpeg en el servidor (sudo apt install ffmpeg).');
    ignore_user_abort(true); set_time_limit(0);
    $src = __DIR__ . '/../' . $rel; $base = bin2hex(random_bytes(8));
    $out = "media/uploads/$base.mp4"; $jpg = "media/uploads/$base.jpg"; $abs = __DIR__ . '/../';
    $px = VIDEO_PX; $vf = "scale='if(gt(iw,ih),-2,min($px,iw))':'if(gt(iw,ih),min($px,ih),-2)'";
    exec('nice -n 10 ffmpeg -v error -y -i ' . escapeshellarg($src) . ' -vf ' . escapeshellarg($vf) . ' -c:v libx264 -preset veryfast -crf 25 -pix_fmt yuv420p -movflags +faststart -c:a aac -b:a 96k ' . escapeshellarg($abs . $out) . ' 2>&1', $o, $rc);
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
            if ($a === 'save') {
                if ($faltaMig) throw new RuntimeException('Falta actualizar la base de datos (mirá el aviso rojo en la lista de proyectos).');
                $n0 = (string)($_POST['nonce'] ?? '');
                if ($n0 !== '' && isset($_SESSION['nonces'][$n0])) fin('./?ok=1');
                if ($n0 !== '') $_SESSION['nonces'][$n0] = 1;
                $id = (int)($_POST['id'] ?? 0);
                $t = mb_substr(trim((string)($_POST['titulo'] ?? '')), 0, 120);
                if ($t === '') throw new RuntimeException('El título es obligatorio.');
                $c = (string)($_POST['categoria'] ?? ''); if (!isset($cats[$c])) throw new RuntimeException('Elegí una categoría.');
                $cli = mb_substr(trim((string)($_POST['cliente'] ?? '')), 0, 80); $clid = (int)($_POST['cliente_id'] ?? 0);
                if ($cli !== '') { $clid = 0; } elseif ($clid) { $q0 = $pdo->prepare('SELECT empresa,nombre FROM clientes WHERE id=?'); $q0->execute([$clid]); if ($r0 = $q0->fetch()) $cli = $r0['empresa'] ?: $r0['nombre']; else $clid = 0; }
                $d = mb_substr(trim((string)($_POST['descripcion'] ?? '')), 0, 160);
                $w = isset($_POST['ancho']) ? 1 : 0; $v = isset($_POST['visible']) ? 1 : 0; $o = (int)($_POST['orden'] ?? 0);
                $sv = mb_substr(trim((string)($_POST['servicios'] ?? '')), 0, 160); $dt = mb_substr(trim((string)($_POST['detalle'] ?? '')), 0, 4000); $rs = mb_substr(trim((string)($_POST['resultado'] ?? '')), 0, 200);
                $old = null;
                if ($id) { $s = $pdo->prepare('SELECT * FROM proyectos WHERE id = ?'); $s->execute([$id]); $old = $s->fetch(); if (!$old) throw new RuntimeException('Proyecto inexistente.'); }
                $fuente = (string)($_POST['fuente'] ?? 'mantener'); $cola = null; $drive = null;
                if ($fuente === 'archivo') {
                    $cola = up_cola('mp4', ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/x-matroska' => 'mkv', 'video/webm' => 'webm'], 300 * 1048576);
                    if (!$cola) throw new RuntimeException('Elegí el archivo de video, o pasá a la opción «Link de Drive».');
                } elseif ($fuente === 'drive') {
                    $drive = trim((string)($_POST['drive'] ?? ''));
                    if (!drive_ok($drive)) throw new RuntimeException('Pegá un link de Google Drive válido (drive.google.com/file/d/…), compartido como «cualquier persona con el enlace».');
                }
                $nuevo = $cola !== null || $drive !== null;
                $pos = up('poster', ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 8 * 1048576);
                $pa = ($nuevo && !$pos) ? 1 : 0;
                if ($old) {
                    $pdo->prepare('UPDATE proyectos SET titulo=?,cliente=?,categoria=?,descripcion=?,ancho=?,orden=?,visible=?,servicios=?,detalle=?,resultado=?,cliente_id=? WHERE id=?')
                        ->execute([$t, $cli, $c, $d, $w, $o, $v, $sv, $dt, $rs, $clid ?: null, $id]);
                    if ($pos) { $pdo->prepare('UPDATE proyectos SET poster=?, poster_auto=0 WHERE id=?')->execute([$pos, $id]); rmf($old['poster']); }
                    if ($nuevo) {
                        $oc = (string)($old['cola_src'] ?? ''); if ($oc !== '' && str_starts_with($oc, COLA . '/')) @unlink($oc);
                        $pdo->prepare("UPDATE proyectos SET estado='procesando', error_msg=NULL, fuente_drive=?, cola_src=?, poster_auto=? WHERE id=?")->execute([$drive, $cola, $pa, $id]);
                    }
                } else {
                    $pdo->prepare('INSERT INTO proyectos (titulo,cliente,categoria,descripcion,ancho,orden,visible,poster,servicios,detalle,resultado,cliente_id,estado,fuente_drive,cola_src,poster_auto) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$t, $cli, $c, $d, $w, $o, $v, $pos, $sv, $dt, $rs, $clid ?: null, $nuevo ? 'procesando' : 'listo', $drive, $cola, $pa]);
                    $id = (int)$pdo->lastInsertId();
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
<title>Panel | blens.ar</title><link rel="icon" href="../assets/favicon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="../assets/apple-touch-icon.png"><link rel="stylesheet" href="admin.css?v=9"></head><body>
<svg class="sprite" aria-hidden="true"><symbol id="mark" viewBox="0 0 130 130"><polygon points="0,0 70,0 100,30 100,100 0,100" fill="#F5F1EA"/><polygon points="60,45 125,45 125,125 45,125 45,60" fill="#F5F1EA"/><polygon points="60,45 100,45 100,64 86,72 100,80 100,100 45,100 45,60" fill="#FF5A1F"/></symbol></svg>
<?php if (!$auth): ?>
<form class="login" method="post"><h1 class="lh"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span> · panel</h1>
<?php if ($msg) echo '<p class="err">' . $e($msg) . '</p>'; ?>
<input type="hidden" name="a" value="login"><input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>">
<input name="u" placeholder="Usuario" autocomplete="username" required><input name="p" type="password" placeholder="Clave" autocomplete="current-password" required><button>Entrar</button></form>
<?php else:
$view = $_GET['v'] ?? 'proyectos'; $edit = isset($_GET['e']) ? (int)$_GET['e'] : null; ?>
<div class="top"><a class="brand" href="./"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span></a>
<nav><a href="./">Proyectos</a><a href="?v=consultas">Consultas</a><a href="clientes.php">Clientes</a><a href="cobros.php">Cobros</a><a href="../" target="_blank" rel="noopener">Ver web</a></nav>
<form method="post"><input type="hidden" name="a" value="logout"><input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>"><button class="g">Salir</button></form></div>
<?php if (isset($_GET['ok'])) echo '<p class="ok">Cambios guardados. Pueden tardar hasta un minuto en verse en la web.</p>'; if ($msg) echo '<p class="err">' . $e($msg) . '</p>'; ?>

<?php if ($view === 'consultas'): $rows = $pdo->query('SELECT * FROM consultas ORDER BY id DESC LIMIT 100')->fetchAll(); ?>
<h1>Consultas recibidas</h1>
<table><tr><th>Fecha</th><th>Nombre / empresa</th><th>Servicio</th><th>Mensaje</th></tr>
<?php foreach ($rows as $r): ?><tr><td class="mut"><?= $e(substr($r['creado'], 0, 16)) ?></td>
<td><?= $e($r['nombre']) ?><br><span class="mut"><?= $e($r['empresa']) ?></span><br><a href="mailto:<?= $e($r['email']) ?>"><?= $e($r['email']) ?></a><?php if ($r['telefono'] !== '') { $w = wa_link($r['telefono'], $r['nombre'], $r['servicio']); echo '<br>' . $e($r['telefono']) . ' '; echo $w ? '<a class="btn" href="' . $e($w) . '" target="_blank" rel="noopener">WhatsApp</a>' : '<span class="mut">(revisar número)</span>'; } ?></td>
<td><?= $e($r['servicio']) ?></td><td><?= nl2br($e($r['mensaje'])) ?></td></tr><?php endforeach; if (!$rows) echo '<tr><td colspan="4" class="mut">Todavía no hay consultas.</td></tr>'; ?></table>

<?php elseif ($edit !== null):
$p = ['id' => 0, 'titulo' => '', 'cliente' => '', 'cliente_id' => null, 'categoria' => 'reels', 'descripcion' => '', 'ancho' => 0, 'orden' => 0, 'visible' => 1, 'video_mp4' => '', 'poster' => '', 'servicios' => '', 'detalle' => '', 'resultado' => '', 'estado' => 'listo', 'error_msg' => '', 'calidades' => '', 'fuente_drive' => ''];
if ($edit && !$faltaMig) { $s = $pdo->prepare('SELECT * FROM proyectos WHERE id = ?'); $s->execute([$edit]); $p = $s->fetch() ?: $p; }
elseif ($edit) { $s = $pdo->prepare('SELECT * FROM proyectos WHERE id = ?'); $s->execute([$edit]); $p = ($s->fetch() ?: []) + $p; }
$cls = $pdo->query('SELECT id,empresa,nombre FROM clientes ORDER BY empresa,nombre')->fetchAll();
$tiene = !empty($p['video_mp4']) || $p['estado'] === 'procesando';
if ($faltaMig) echo '<p class="err">Falta actualizar la base de datos. En el servidor corré:<br><code>' . $e(MIG_CMD) . '</code></p>'; ?>
<a class="back" href="./">&larr; Proyectos</a>
<h1><?= $p['id'] ? 'Editar proyecto' : 'Nuevo proyecto' ?></h1>
<?php if ($p['estado'] === 'procesando'): ?><p class="ok">⏳ Se está procesando el video. Podés seguir trabajando; en la lista vas a ver cuándo termina.</p><?php elseif ($p['estado'] === 'error'): ?><p class="err">El último video no se pudo procesar: <?= $e($p['error_msg']) ?></p><?php endif; ?>
<form id="pform" class="pf" method="post" enctype="multipart/form-data">
<input type="hidden" name="a" value="save"><input type="hidden" name="nonce" value="<?= bin2hex(random_bytes(8)) ?>"><input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">

<section class="pc">
  <h2>Video</h2>
  <div class="seg">
    <?php if ($tiene): ?><label><input type="radio" name="fuente" value="mantener" checked><span>Mantener el actual</span></label><?php endif; ?>
    <label><input type="radio" name="fuente" value="archivo"<?= $tiene ? '' : ' checked' ?>><span>Subir archivo</span></label>
    <label><input type="radio" name="fuente" value="drive"><span>Link de Drive</span></label>
  </div>
  <?php if ($tiene && $p['calidades']): ?><p class="mut">Calidades guardadas hoy: <?= $e(str_replace(',', 'p · ', $p['calidades']) . 'p') ?></p><?php endif; ?>
  <div class="src s-archivo"><label class="drop"><input type="file" name="mp4" accept="video/*"><strong>Elegí o arrastrá tu video acá</strong><small>MP4, MOV, MKV o WebM · hasta 300 MB</small><em id="fname"></em></label></div>
  <div class="src s-drive"><label>Link de Google Drive<input name="drive" type="url" placeholder="https://drive.google.com/file/d/…"></label><p class="mut">Tiene que estar compartido como «cualquier persona con el enlace». El servidor lo descarga y lo guarda; no importa el tamaño.</p></div>
  <p class="mut hint">Se generan solas las calidades 1080p, 720p y 480p (sin agrandar el original) y la portada. Se procesa en segundo plano.</p>
  <label>Portada propia, opcional (si no subís una, se toma del video)<input type="file" name="poster" accept="image/jpeg,image/png,image/webp"></label>
</section>

<section class="pc">
  <h2>Datos</h2>
  <label>Título<input name="titulo" value="<?= $e($p['titulo']) ?>" maxlength="120" required></label>
  <div class="two">
    <label>Cliente (de tu lista)<select name="cliente_id"><option value="0">— Sin elegir —</option><?php foreach ($cls as $x): ?><option value="<?= (int)$x['id'] ?>"<?= (int)($p['cliente_id'] ?? 0) === (int)$x['id'] ? ' selected' : '' ?>><?= $e($x['empresa'] ?: $x['nombre']) ?></option><?php endforeach; ?></select></label>
    <label>…o escribí otro cliente<input name="cliente" value="<?= !empty($p['cliente_id']) ? '' : $e($p['cliente']) ?>" maxlength="80" placeholder="Si no está en la lista"></label>
  </div>
  <fieldset class="chips"><legend>Categoría</legend><?php foreach ($cats as $k => $n): ?><label><input type="radio" name="categoria" value="<?= $e($k) ?>"<?= $p['categoria'] === $k ? ' checked' : '' ?>><span><?= $e($n) ?></span></label><?php endforeach; ?></fieldset>
  <label>Descripción corta (se ve en la tarjeta)<input name="descripcion" value="<?= $e($p['descripcion']) ?>" maxlength="160"></label>
</section>

<section class="pc">
  <h2>Página del proyecto</h2>
  <label>Qué hicimos (ej: guion, grabación y edición)<input name="servicios" value="<?= $e($p['servicios'] ?? '') ?>" maxlength="160"></label>
  <label>Detalle del proyecto<textarea name="detalle" rows="5" maxlength="4000"><?= $e($p['detalle'] ?? '') ?></textarea></label>
  <label>Resultado, opcional (ej: 12 consultas en el primer mes)<input name="resultado" value="<?= $e($p['resultado'] ?? '') ?>" maxlength="200"></label>
</section>

<section class="pc">
  <h2>Publicación</h2>
  <label class="chk"><input type="checkbox" name="visible" <?= $p['visible'] ? 'checked' : '' ?>>Visible en la web</label>
  <label class="chk"><input type="checkbox" name="ancho" <?= $p['ancho'] ? 'checked' : '' ?>>Tarjeta ancha (para videos horizontales)</label>
  <label>Orden en la lista (menor = primero)<input type="number" name="orden" value="<?= (int)$p['orden'] ?>"></label>
</section>

<div class="savebar"><button type="submit" id="gb">Guardar proyecto</button><a class="btn g" href="./">Cancelar</a><div class="prog" id="prog" hidden><i></i><span></span></div></div>
</form>
<?php if ($p['id']): ?><h2>Eliminar</h2><form method="post" class="row"><input type="hidden" name="a" value="delete"><input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
<label class="chk"><input type="checkbox" required>Confirmo que quiero eliminarlo y borrar sus archivos</label><button class="r">Eliminar</button></form><?php endif; ?>

<?php else:
if ($faltaMig) echo '<p class="err">Falta actualizar la base de datos. En el servidor corré:<br><code>' . $e(MIG_CMD) . '</code></p>';
$rows = $pdo->query('SELECT * FROM proyectos ORDER BY orden ASC, id DESC')->fetchAll(); ?>
<div class="lh2"><h1>Proyectos</h1><a class="btn" href="?e=0">+ Nuevo proyecto</a></div>
<div class="plist">
<?php foreach ($rows as $r): $est = $r['estado'] ?? 'listo'; ?>
<article class="pi <?= $r['visible'] ? '' : 'off' ?>">
  <div class="th"><?= $r['poster'] ? '<img src="../' . $e($r['poster']) . '" alt="">' : '<div></div>' ?></div>
  <div class="in"><b><?= $e($r['titulo']) ?></b><span class="mut"><?= $e(trim(($r['cliente'] ? $r['cliente'] . ' · ' : '') . ($cats[$r['categoria']] ?? $r['categoria']))) ?></span>
    <div class="tags">
      <?php if ($est === 'procesando'): ?><span class="b proc" data-proc>⏳ Procesando…</span>
      <?php elseif ($est === 'error'): ?><span class="b err2">Error al procesar</span>
      <?php else: ?><span class="b ok2">Listo</span><?php endif; ?>
      <?php if (!empty($r['calidades'])): ?><span class="b"><?= $e(str_replace(',', 'p · ', $r['calidades']) . 'p') ?></span><?php endif; ?>
      <?php if (!$r['visible']): ?><span class="b">Oculto</span><?php endif; ?>
    </div>
    <?php if ($est === 'error'): ?><span class="mut"><?= $e($r['error_msg'] ?? '') ?></span><?php endif; ?>
  </div>
  <div class="ac"><a class="btn g" href="?e=<?= (int)$r['id'] ?>">Editar</a>
    <form method="post"><input type="hidden" name="a" value="toggle"><input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="g"><?= $r['visible'] ? 'Ocultar' : 'Mostrar' ?></button></form>
    <?php if ($est === 'error' && ($r['cola_src'] || $r['fuente_drive'])): ?><form method="post"><input type="hidden" name="a" value="reintentar"><input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button>Reintentar</button></form><?php endif; ?>
  </div>
</article>
<?php endforeach; if (!$rows) echo '<p class="mut">Todavía no cargaste proyectos.</p>'; ?>
</div>
<?php endif; endif; ?><script src="admin.js?v=9"></script></body></html>
