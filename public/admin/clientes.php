<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
require __DIR__ . '/lib.php';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY');
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }
$pdo = blens_db();
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$msg = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$acc = $_SESSION['acceso'] ?? null; unset($_SESSION['acceso']);
const PRIV = '/var/www/blens-private/entregas';
const VIDEOS = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/x-matroska' => 'mkv', 'video/webm' => 'webm'];
function privado(?string $rel): ?string {
    if (!$rel) return null;
    if (!is_dir(PRIV)) @mkdir(PRIV, 0750, true);
    return @rename(__DIR__ . '/../' . $rel, PRIV . '/' . basename($rel)) ? basename($rel) : throw new RuntimeException('No se pudo guardar en la carpeta privada (permisos de /var/www/blens-private).');
}
function rmp(?string $f): void { if ($f) @unlink(PRIV . '/' . basename($f)); }
function drive_ok(string $u): bool {
    if ($u === '') return true;
    return str_starts_with($u, 'https://') && in_array(strtolower((string)parse_url($u, PHP_URL_HOST)), ['drive.google.com', 'docs.google.com', 'drive.usercontent.google.com'], true);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['t'], (string)($_POST['t'] ?? ''))) { http_response_code(400); exit('Sesión vencida. Recargá la página.'); }
    $a = $_POST['a'] ?? ''; $c = (int)($_POST['cid'] ?? 0);
    try {
        if ($a === 'cliente') {
            $n = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 80); $u = strtolower(trim((string)($_POST['usuario'] ?? '')));
            $tel = mb_substr(trim((string)($_POST['telefono'] ?? '')), 0, 30); $emp = mb_substr(trim((string)($_POST['empresa'] ?? '')), 0, 100);
            $tar = fn($k) => ($_POST[$k] ?? '') === '' ? null : (int)preg_replace('/\D/', '', (string)$_POST[$k]); $tv = $tar('tarifa_video'); $tf = $tar('tarifa_foto'); $ab = $tar('abono'); $act = isset($_POST['activo']) ? 1 : 0; $k = (string)($_POST['clave'] ?? ''); $gen = '';
            if ($n === '' || !preg_match('/^[a-z0-9._@-]{3,60}$/', $u)) throw new RuntimeException('Completá el nombre y un usuario de 3 a 60 caracteres (letras, números, punto, guion o @).');
            if ($k !== '' && mb_strlen($k) < 6) throw new RuntimeException('La clave tiene que tener al menos 6 caracteres.');
            $lg = up('logo', ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'], 5 * 1048576); $lg = $lg ? logo_proc($lg) : null;
            $lw = isset($_POST['logo_web']) ? 1 : 0; $quitar = isset($_POST['quitar_logo']); $oldLogo = null;
            if ($c) { $q0 = $pdo->prepare('SELECT logo FROM clientes WHERE id=?'); $q0->execute([$c]); $oldLogo = $q0->fetchColumn() ?: null; }
            if (!$c) {
                if ($k === '') { $k = blens_clave_nueva(); $gen = $k; }
                $pdo->prepare('INSERT INTO clientes (nombre,empresa,usuario,clave_hash,telefono,activo,tarifa_video,tarifa_foto,abono,cambiar_clave,clave_inicial_hasta,logo,logo_web) VALUES (?,?,?,?,?,?,?,?,?,1,DATE_ADD(NOW(), INTERVAL 7 DAY),?,?)')->execute([$n, $emp, $u, password_hash($k, PASSWORD_DEFAULT), $tel, $act, $tv, $tf, $ab, $lg, $lw]);
                $c = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE clientes SET nombre=?,empresa=?,usuario=?,telefono=?,activo=?,tarifa_video=?,tarifa_foto=?,abono=?,logo=COALESCE(?,logo),logo_web=? WHERE id=?')->execute([$n, $emp, $u, $tel, $act, $tv, $tf, $ab, $lg, $lw, $c]);
                if ($quitar && !$lg) $pdo->prepare('UPDATE clientes SET logo=NULL WHERE id=?')->execute([$c]);
                if ($oldLogo && ($lg || $quitar)) rmlogo($oldLogo);
                if ($k !== '') $pdo->prepare('UPDATE clientes SET clave_hash=?,cambiar_clave=1,clave_inicial_hasta=DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id=?')->execute([password_hash($k, PASSWORD_DEFAULT), $c]);
            }
            $_SESSION['flash'] = 'Cliente guardado.';
            if ($k !== '') $_SESSION['acceso'] = ['n' => $n, 'u' => $u, 'k' => $k, 'tel' => $tel];
            header("Location: clientes.php?c=$c"); exit;
        }
        if ($a === 'nueva_clave') {
            $nk = blens_clave_nueva();
            $pdo->prepare('UPDATE clientes SET clave_hash=?,cambiar_clave=1,clave_inicial_hasta=DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id=?')->execute([password_hash($nk, PASSWORD_DEFAULT), $c]);
            $q = $pdo->prepare('SELECT nombre,usuario,telefono FROM clientes WHERE id=?'); $q->execute([$c]); $r = $q->fetch();
            if ($r) $_SESSION['acceso'] = ['n' => $r['nombre'], 'u' => $r['usuario'], 'k' => $nk, 'tel' => $r['telefono']];
            $_SESSION['flash'] = 'Clave nueva guardada.'; header("Location: clientes.php?c=$c"); exit;
        }
        if ($a === 'ver_como') {
            $q = $pdo->prepare('SELECT id FROM clientes WHERE id=?'); $q->execute([$c]);
            if ($q->fetch()) {
                session_write_close(); session_name('blens_cliente');
                session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
                session_start(); session_regenerate_id(true); $_SESSION['cid'] = $c; $_SESSION['vista'] = 1; $_SESSION['t'] = bin2hex(random_bytes(16)); session_write_close();
                header('Location: ../portal/'); exit;
            }
        }
        if ($a === 'del_cliente') {
            $q = $pdo->prepare('SELECT preview,poster FROM entregas WHERE cliente_id=?'); $q->execute([$c]);
            foreach ($q as $r) { rmp($r['preview']); rmp($r['poster']); }
            $q2 = $pdo->prepare('SELECT logo FROM clientes WHERE id=?'); $q2->execute([$c]); rmlogo((string)$q2->fetchColumn());
            $pdo->prepare('DELETE FROM clientes WHERE id=?')->execute([$c]);
            $_SESSION['flash'] = 'Cliente eliminado.'; header('Location: clientes.php'); exit;
        }
        if ($a === 'entrega') {
            $n0 = (string)($_POST['nonce'] ?? '');
            if ($n0 !== '' && isset($_SESSION['nonces'][$n0])) { $_SESSION['flash'] = 'Esa entrega ya estaba guardada.'; header("Location: clientes.php?c=$c"); exit; }
            if ($n0 !== '') $_SESSION['nonces'][$n0] = 1;
            $id = (int)($_POST['id'] ?? 0); $t = mb_substr(trim((string)($_POST['titulo'] ?? '')), 0, 120); $d = mb_substr(str_replace("\r\n", "\n", trim((string)($_POST['descripcion'] ?? ''))), 0, 4000);
            $hd = trim((string)($_POST['link_hd'] ?? '')); $k4 = ''; $pj = (int)($_POST['proyecto_id'] ?? 0) ?: null;
            if ($t === '') throw new RuntimeException('El título es obligatorio.');
            if ($hd === '') throw new RuntimeException('Pegá el link de Google Drive.');
            if (!drive_ok($hd) || !drive_ok($k4)) throw new RuntimeException('Los links tienen que ser de Google Drive y empezar con https://');
            $old = null;
            if ($id) { $s = $pdo->prepare('SELECT * FROM entregas WHERE id=? AND cliente_id=?'); $s->execute([$id, $c]); $old = $s->fetch(); if (!$old) throw new RuntimeException('Entrega inexistente.'); }
            $mp4 = up('mp4', VIDEOS, 500 * 1048576); $auto = null;
            if ($mp4) { [$mp4, $auto] = procesar($mp4); }
            $pos = up('poster', ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 8 * 1048576);
            if ($auto) { if ($pos) rmf($auto); else $pos = $auto; }
            $mp4 = privado($mp4); $pos = privado($pos);
            if ($old) {
                $pdo->prepare('UPDATE entregas SET titulo=?,descripcion=?,link_hd=?,link_4k=?,preview=COALESCE(?,preview),poster=COALESCE(?,poster),proyecto_id=? WHERE id=?')->execute([$t, $d, $hd, $k4, $mp4, $pos, $pj, $id]);
                if ($mp4) rmp($old['preview']); if ($pos) rmp($old['poster']);
            } else {
                $pdo->prepare('INSERT INTO entregas (cliente_id,titulo,descripcion,link_hd,link_4k,preview,poster,proyecto_id) VALUES (?,?,?,?,?,?,?,?)')->execute([$c, $t, $d, $hd, $k4, $mp4, $pos, $pj]);
            }
            $nid = $id ?: (int)$pdo->lastInsertId(); $_SESSION['flash'] = 'Entrega guardada.'; header("Location: clientes.php?c=$c" . ($id ? '' : "&avisar=$nid")); exit;
        }
        if ($a === 'del_entrega') {
            $s = $pdo->prepare('SELECT preview,poster FROM entregas WHERE id=? AND cliente_id=?'); $s->execute([(int)($_POST['id'] ?? 0), $c]);
            if ($r = $s->fetch()) { rmp($r['preview']); rmp($r['poster']); $pdo->prepare('DELETE FROM entregas WHERE id=? AND cliente_id=?')->execute([(int)$_POST['id'], $c]); }
            $_SESSION['flash'] = 'Entrega eliminada.'; header("Location: clientes.php?c=$c"); exit;
        }
    } catch (Throwable $x) {
        if ($x instanceof PDOException) { error_log('[blens clientes] ' . $x->getMessage()); $msg = $x->getCode() === '23000' ? 'Ese usuario ya existe.' : 'Error de base de datos.'; }
        else $msg = $x->getMessage();
    }
}
$vc = isset($_GET['c']) ? (int)$_GET['c'] : null; $ve = isset($_GET['e']) ? (int)$_GET['e'] : null; $T = $e($_SESSION['t']);
$list = $cli = $ent = null; $ents = [];
if ($vc === null) { $list = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM entregas x WHERE x.cliente_id = c.id) AS n FROM clientes c ORDER BY c.nombre')->fetchAll(); }
else {
    $cli = ['id' => 0, 'nombre' => '', 'empresa' => '', 'usuario' => '', 'telefono' => '', 'activo' => 1, 'logo' => null, 'logo_web' => 1, 'tarifa_video' => null, 'tarifa_foto' => null, 'abono' => null];
    if ($vc) { $s = $pdo->prepare('SELECT * FROM clientes WHERE id=?'); $s->execute([$vc]); $cli = $s->fetch() ?: $cli; }
    if ($cli['id']) {
        $s = $pdo->prepare('SELECT * FROM entregas WHERE cliente_id=? ORDER BY id DESC'); $s->execute([$cli['id']]); $ents = $s->fetchAll();
        if ($ve !== null) { $ent = ['id' => 0, 'titulo' => '', 'descripcion' => '', 'link_hd' => '', 'link_4k' => '', 'preview' => '', 'poster' => '', 'proyecto_id' => null];
            foreach ($ents as $r) if ((int)$r['id'] === $ve) $ent = $r; }
    }
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Clientes | blens.ar</title><link rel="icon" href="../assets/favicon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="../assets/apple-touch-icon.png"><link rel="stylesheet" href="admin.css?v=10"></head><body>
<svg class="sprite" aria-hidden="true"><symbol id="mark" viewBox="0 0 130 130"><polygon points="0,0 70,0 100,30 100,100 0,100" fill="#F5F1EA"/><polygon points="60,45 125,45 125,125 45,125 45,60" fill="#F5F1EA"/><polygon points="60,45 100,45 100,64 86,72 100,80 100,100 45,100 45,60" fill="#FF5A1F"/></symbol></svg>
<div class="top"><a class="brand" href="./"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span></a><nav><a href="./">Proyectos</a><a href="./?v=consultas">Consultas</a><a href="clientes.php">Clientes</a><a href="cobros.php">Cobros</a><a href="../portal/" target="_blank" rel="noopener">Ver portal</a></nav>
<form method="post" action="./"><input type="hidden" name="a" value="logout"><input type="hidden" name="t" value="<?= $T ?>"><button class="g">Salir</button></form></div>
<?php if (isset($_GET['avisar']) && $cli && $cli['telefono']) { $s2 = $pdo->prepare('SELECT titulo FROM entregas WHERE id=? AND cliente_id=?'); $s2->execute([(int)$_GET['avisar'], $cli['id']]); if ($t2 = $s2->fetchColumn()) { $w = wa_url($cli['telefono'], 'Hola ' . explode(' ', trim($cli['nombre']))[0] . ', ya tenés una nueva entrega disponible en tu portal: «' . $t2 . '». Ingresá acá: ' . (blens_cfg()['sitio'] ?? 'https://blens.ar') . '/portal/'); if ($w) echo '<p class="ok">¿Avisamos al cliente? <a class="btn" href="' . $e($w) . '" target="_blank" rel="noopener">Enviar aviso por WhatsApp</a></p>'; } } ?>
<?php if ($msg) echo '<p class="' . (str_contains($msg, 'guardad') || str_contains($msg, 'eliminad') ? 'ok' : 'err') . '">' . $e($msg) . '</p>'; ?>
<?php if ($acc): $sitio = rtrim((string)(blens_cfg()['sitio'] ?? 'https://blens.ar'), '/'); $pn = explode(' ', trim($acc['n']))[0];
$txt = "Hola $pn! Te dejo tu acceso al portal de blens.ar, donde vas a ver y descargar tus entregas:\n\nPortal: $sitio/portal/\nUsuario: {$acc['u']}\nClave inicial: {$acc['k']}\n\nLa clave inicial vale 7 días: al entrar te pide que elijas la tuya. Cualquier duda, escribinos.";
$wl = $acc['tel'] ? wa_url($acc['tel'], $txt) : null; ?>
<div class="acceso" id="acceso" data-n="<?= $e($pn) ?>" data-u="<?= $e($acc['u']) ?>" data-k="<?= $e($acc['k']) ?>" data-url="<?= $e($sitio . '/portal/') ?>">
<div class="acc-card"><div class="acc-h"><svg class="mark"><use href="#mark"/></svg><b>Tu acceso al portal</b></div>
<dl><dt>Portal</dt><dd><?= $e($sitio) ?>/portal/</dd><dt>Usuario</dt><dd><?= $e($acc['u']) ?></dd><dt>Clave inicial</dt><dd class="k"><?= $e($acc['k']) ?></dd></dl>
<p class="mut">Vale 7 días. Al entrar, elige su propia clave.</p></div>
<div class="acc-btns"><button type="button" data-wa-img data-img="tarjeta" data-wa="<?= $e($wl ?? '') ?>" data-txt="<?= $e($txt) ?>" data-nombre="acceso-<?= $e($acc['u']) ?>">Enviar por WhatsApp con imagen</button><button type="button" class="g" data-copiar="<?= $e($txt) ?>">Copiar mensaje</button><button type="button" class="g" data-tarjeta>Descargar imagen</button></div>
<p class="mut">Esta clave se muestra una sola vez. Si la perdés, generás otra con «Nueva clave inicial».</p></div>
<?php endif; ?>
<?php if ($list !== null): ?>
<h1>Clientes</h1><a class="btn" href="?c=0">Agregar cliente</a>
<table><tr><th>Empresa / contacto</th><th>Usuario</th><th>Entregas</th><th></th></tr>
<?php foreach ($list as $r): ?><tr class="<?= $r['activo'] ? '' : 'off' ?>"><td><?= !empty($r['logo']) ? '<img class="clogo" src="../media/logos/' . $e($r['logo']) . '" alt="">' : '' ?><?= $e($r['empresa'] ?: $r['nombre']) ?><br><span class="mut"><?= $e($r['empresa'] ? $r['nombre'] : '') ?></span></td><td class="mut"><?= $e($r['usuario']) ?></td><td><?= (int)$r['n'] ?></td><td><a class="btn g" href="?c=<?= (int)$r['id'] ?>">Abrir</a></td></tr><?php endforeach; if (!$list) echo '<tr><td colspan="4" class="mut">Todavía no hay clientes.</td></tr>'; ?></table>

<?php elseif ($ent !== null): ?>
<h1><?= $e($cli['nombre']) ?> · <?= $ent['id'] ? 'Editar entrega' : 'Nueva entrega' ?></h1>
<form class="f" method="post" enctype="multipart/form-data"><input type="hidden" name="a" value="entrega"><input type="hidden" name="nonce" value="<?= bin2hex(random_bytes(8)) ?>"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><input type="hidden" name="id" value="<?= (int)$ent['id'] ?>">
<label class="full">Título<input name="titulo" value="<?= $e($ent['titulo']) ?>" maxlength="120" required></label>
<label class="full">Descripción / copy para publicar (se respetan saltos de línea y emojis)<textarea name="descripcion" rows="9" maxlength="4000"><?= $e($ent['descripcion']) ?></textarea></label>
<label class="full">Link de Google Drive (público, en la mejor calidad)<input name="link_hd" value="<?= $e($ent['link_hd']) ?>" placeholder="https://drive.google.com/..." required></label>
<?php $ps = $pdo->prepare("SELECT id,titulo,cliente FROM proyectos WHERE categoria <> 'showreel' ORDER BY (cliente_id = ?) DESC, id DESC"); $ps->execute([(int)$cli['id']]); ?>
<label class="full">Proyecto de la web relacionado, opcional (si no subís un video de vista previa, se usa el de ese proyecto)<select name="proyecto_id"><option value="0">— Ninguno —</option><?php foreach ($ps as $x): ?><option value="<?= (int)$x['id'] ?>"<?= (int)($ent['proyecto_id'] ?? 0) === (int)$x['id'] ? ' selected' : '' ?>><?= $e($x['titulo'] . ($x['cliente'] ? ' · ' . $x['cliente'] : '')) ?></option><?php endforeach; ?></select></label>
<label class="full">Video para la vista previa, opcional (si no subís nada, el portal muestra el video desde el link de Drive) <?= $ent['preview'] ? '· ya cargado; dejá vacío para mantenerlo' : '' ?><input type="file" name="mp4" accept="video/*"></label>
<p class="mut full">Se convierte solo a 720p y solo lo ve este cliente al entrar con su usuario. Puede tardar unos minutos: no cierres la página.</p>
<label class="full">Portada propia, opcional<input type="file" name="poster" accept="image/jpeg,image/png,image/webp"></label>
<div class="row full"><button>Guardar</button><a class="btn g" href="?c=<?= (int)$cli['id'] ?>">Cancelar</a></div></form>
<?php if ($ent['id']): ?><h2>Eliminar entrega</h2><form method="post" class="row"><input type="hidden" name="a" value="del_entrega"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><input type="hidden" name="id" value="<?= (int)$ent['id'] ?>"><label class="chk"><input type="checkbox" required>Confirmo que quiero eliminarla</label><button class="r">Eliminar</button></form><?php endif; ?>

<?php else: ?>
<h1><?= $cli['id'] ? $e(($cli['empresa'] ? $cli['empresa'] . ' · ' : '') . $cli['nombre']) : 'Nuevo cliente' ?></h1>
<?php if ($cli['id'] && $cli['telefono'] && ($w = wa_url($cli['telefono'], 'Hola ' . explode(' ', trim($cli['nombre']))[0] . ', '))) echo '<p><a class="btn g" href="' . $e($w) . '" target="_blank" rel="noopener">Escribir por WhatsApp</a></p>'; ?>
<form class="f" method="post" enctype="multipart/form-data"><input type="hidden" name="a" value="cliente"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>">
<label>Empresa<input name="empresa" value="<?= $e($cli['empresa'] ?? '') ?>" maxlength="100"></label>
<label>Persona de contacto<input name="nombre" value="<?= $e($cli['nombre']) ?>" maxlength="80" required></label>
<label>Usuario para entrar<input name="usuario" value="<?= $e($cli['usuario']) ?>" maxlength="60" autocapitalize="none" required></label>
<label>WhatsApp de esa persona<input name="telefono" value="<?= $e($cli['telefono']) ?>" maxlength="30"></label>
<label>Clave <?= $cli['id'] ? '(vacío = no cambiar)' : '(vacío = se genera una al azar)' ?><input name="clave" type="text" autocomplete="off" placeholder="mínimo 6 caracteres"></label>
<label>Tarifa por video ($)<input name="tarifa_video" inputmode="numeric" value="<?= $e($cli['tarifa_video'] ?? '') ?>"></label>
<label>Tarifa por foto ($)<input name="tarifa_foto" inputmode="numeric" value="<?= $e($cli['tarifa_foto'] ?? '') ?>"></label>
<label>Abono mensual ($)<input name="abono" inputmode="numeric" value="<?= $e($cli['abono'] ?? '') ?>"></label>
<label class="full">Logo del cliente (PNG, JPG o WebP; mejor con fondo transparente)<input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
<?php if (!empty($cli['logo'])): ?><div class="full"><img class="clogo" src="../media/logos/<?= $e($cli['logo']) ?>" alt=""><label class="chk"><input type="checkbox" name="quitar_logo">Quitar el logo actual</label></div><?php endif; ?>
<label class="chk full"><input type="checkbox" name="logo_web" <?= !empty($cli['logo_web']) ? 'checked' : '' ?>>Mostrar el logo en la página principal</label>
<label class="chk full"><input type="checkbox" name="activo" <?= $cli['activo'] ? 'checked' : '' ?>>Puede entrar al portal</label>
<div class="row full"><button>Guardar cliente</button><a class="btn g" href="clientes.php">Volver</a></div></form>
<?php if ($cli['id']): ?>
<div class="row"><a class="btn" href="cobros.php?c=<?= (int)$cli['id'] ?>">Cobros y trabajos</a><form method="post"><input type="hidden" name="a" value="ver_como"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><button class="g">Ver el portal como este cliente</button></form><form method="post"><input type="hidden" name="a" value="nueva_clave"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><button class="g">Restablecer a la clave inicial</button></form></div>
<h2>Entregas</h2><a class="btn" href="?c=<?= (int)$cli['id'] ?>&e=0">Agregar entrega</a>
<table><tr><th>Título</th><th>Fecha</th><th>Links</th><th></th></tr>
<?php foreach ($ents as $r): ?><tr><td><?= $e($r['titulo']) ?></td><td class="mut"><?= $e(substr($r['creado'], 0, 10)) ?></td><td><?= $r['link_hd'] ? 'HD ' : '' ?><?= $r['link_4k'] ? '4K' : '' ?></td><td><a class="btn g" href="?c=<?= (int)$cli['id'] ?>&e=<?= (int)$r['id'] ?>">Editar</a></td></tr><?php endforeach; if (!$ents) echo '<tr><td colspan="4" class="mut">Sin entregas todavía.</td></tr>'; ?></table>
<h2>Eliminar cliente</h2><form method="post" class="row"><input type="hidden" name="a" value="del_cliente"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><label class="chk"><input type="checkbox" required>Confirmo: se borran el cliente y todas sus entregas</label><button class="r">Eliminar</button></form>
<?php endif; endif; ?><script src="admin.js?v=10"></script></body></html>
