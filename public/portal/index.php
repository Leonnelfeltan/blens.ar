<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
session_name('blens_cliente');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY');
$pdo = blens_db(); $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$_SESSION['t'] ??= bin2hex(random_bytes(16)); $msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['t'], (string)($_POST['t'] ?? ''))) { http_response_code(400); exit('Sesión vencida. Recargá la página.'); }
    $a = $_POST['a'] ?? '';
    if ($a === 'salir') { session_destroy(); header('Location: ./'); exit; }
    if ($a === 'entrar' && ($w = blens_bloqueado('portal'))) { $msg = 'Demasiados intentos. Probá de nuevo en ' . (int)ceil($w / 60) . ' min.'; }
    elseif ($a === 'entrar') {
        $s = $pdo->prepare('SELECT id,clave_hash,cambiar_clave,clave_inicial_hasta FROM clientes WHERE usuario=? AND activo=1'); $s->execute([strtolower(trim((string)($_POST['u'] ?? '')))]); $c = $s->fetch();
        $ok = password_verify((string)($_POST['p'] ?? ''), $c['clave_hash'] ?? '$2y$10$.vGA1O9wmRjrwAVXD98HNOgsNpDczlqm3Jq7KnEd1rVAGv3Fykk1a') && $c;
        if ($ok && $c['cambiar_clave'] && $c['clave_inicial_hasta'] && strtotime((string)$c['clave_inicial_hasta']) < time()) { usleep(900000); $msg = 'Tu clave inicial venció. Escribinos y la renovamos.'; }
        elseif ($ok) {
            blens_ok('portal'); session_regenerate_id(true); $_SESSION['cid'] = (int)$c['id']; $_SESSION['t'] = bin2hex(random_bytes(16));
            unset($_SESSION['visita_ok']);   // la próxima carga cuenta como una visita nueva al portal
            if ($c['cambiar_clave']) $_SESSION['forzar'] = 1; else unset($_SESSION['forzar']);
            header('Location: ./'); exit;
        } else { blens_fallo('portal'); usleep(900000); $msg = 'Usuario o clave incorrectos.'; }
    }
    if ($a === 'cambiar' && !empty($_SESSION['cid']) && !empty($_SESSION['forzar'])) {
        $p1 = (string)($_POST['p1'] ?? ''); $p2 = (string)($_POST['p2'] ?? '');
        if (mb_strlen($p1) < 6) $msg = 'La clave tiene que tener al menos 6 caracteres.';
        elseif ($p1 !== $p2) $msg = 'Las dos claves no coinciden.';
        elseif ($p1 === CLAVE_INICIAL) $msg = 'Elegí una clave distinta de la inicial.';
        else {
            $pdo->prepare('UPDATE clientes SET clave_hash=?,cambiar_clave=0,clave_inicial_hasta=NULL WHERE id=?')->execute([password_hash($p1, PASSWORD_DEFAULT), (int)$_SESSION['cid']]);
            unset($_SESSION['forzar']); header('Location: ./'); exit;
        }
    }
}
$cid = (int)($_SESSION['cid'] ?? 0); $T = $e($_SESSION['t']); $cli = null; $forzar = !empty($_SESSION['forzar']);
if ($cid) { $s = $pdo->prepare('SELECT nombre,empresa,logo FROM clientes WHERE id=? AND (activo=1 OR ?=1)'); $s->execute([$cid, empty($_SESSION['vista']) ? 0 : 1]); $cli = $s->fetch(); if (!$cli) { session_destroy(); header('Location: ./'); exit; } }
// ---- Actividad del portal: una visita por sesión (no por recarga) ----
if ($cid && empty($_SESSION['vista']) && empty($_SESSION['visita_ok'])) {
    $_SESSION['visita_ok'] = 1;
    try { blens_migrar($pdo); $pdo->prepare('UPDATE clientes SET portal_visitas=portal_visitas+1, portal_ultima_visita=NOW() WHERE id=?')->execute([$cid]); }
    catch (Throwable $x) { error_log('[blens portal] ' . $x->getMessage()); }
}
$wa = preg_replace('/\D+/', '', (string)(blens_cfg()['wa_negocio'] ?? ''));
$contacto = $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode('Hola, quiero coordinar un plan con blens.ar') : '../#contacto';
$drive = fn($r) => $r['link_hd'] ?: $r['link_4k'];
$vid = fn($r) => $r['preview'] ? 'media.php?e=' . (int)$r['id'] . '&t=v' : ($r['pv'] ? '../' . $r['pv'] : '');
$pst = fn($r) => $r['poster'] ? 'media.php?e=' . (int)$r['id'] . '&t=p' : ($r['pp'] ? '../' . $r['pp'] : '');
$did = function ($r) { foreach ([$r['link_hd'] ?? '', $r['link_4k'] ?? ''] as $l) { if (preg_match('~^https://drive\.google\.com/(?:file/(?:u/\d+/)?d/|open\?id=|uc\?(?:[^#]*&)?id=)([A-Za-z0-9_-]{10,})~', (string)$l, $m)) return $m[1]; } return ''; };
$SQL = 'SELECT e.*, COALESCE(p.video_1080, p.video_mp4, p.video_480) AS pv, p.poster AS pp FROM entregas e LEFT JOIN proyectos p ON p.id = e.proyecto_id WHERE e.cliente_id = ?';
?><!doctype html><html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex"><meta name="theme-color" content="#14110F">
<title>Portal de clientes | blens.ar</title><link rel="icon" href="../assets/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Sora:wght@200;400;600;800&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@200;400;600;800&display=swap" media="print" data-fonts>
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@200;400;600;800&display=swap"></noscript>
<link rel="stylesheet" href="../styles.css?v=17"><link rel="stylesheet" href="portal.css?v=10"></head><body>
<svg class="sprite" aria-hidden="true"><symbol id="mark" viewBox="0 0 130 130"><polygon points="0,0 70,0 100,30 100,100 0,100" fill="#F5F1EA"/><polygon points="60,45 125,45 125,125 45,125 45,60" fill="#F5F1EA"/><polygon points="60,45 100,45 100,64 86,72 100,80 100,100 45,100 45,60" fill="#FF5A1F"/></symbol></svg>
<header class="ph"><a class="brand" href="../" aria-label="blens.ar, inicio"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span></a>
<?php if ($cli): ?><div class="phr"><span class="mut">Portal de clientes</span><?= !empty($_SESSION['vista']) ? '<span class="perr">Vista de administrador</span>' : '' ?><form method="post"><input type="hidden" name="a" value="salir"><input type="hidden" name="t" value="<?= $T ?>"><button class="out">Salir</button></form></div>
<?php else: ?><a class="link" href="../">Volver a la web</a><?php endif; ?></header>
<?php if (!$cli): ?>
<main class="lgbox"><form class="lgf" method="post"><h1 class="lgh">Acceso clientes</h1>
<?php if ($msg) echo '<p class="perr">' . $e($msg) . '</p>'; ?>
<input type="hidden" name="a" value="entrar"><input type="hidden" name="t" value="<?= $T ?>">
<label>Usuario<input name="u" autocomplete="username" autocapitalize="none" required></label><label>Clave<input name="p" type="password" autocomplete="current-password" required></label><button class="btn">Entrar</button>
<p class="hint"><b>¿Primera vez?</b> Entrá con el usuario y la clave inicial que te pasamos. Al ingresar te pedimos que elijas la tuya.</p></form>
<div class="noc"><b>¿Todavía no sos cliente?</b><span class="mut">Contactanos para coordinar tu plan.</span><a class="btn" href="<?= $e($contacto) ?>" <?= $wa ? 'target="_blank" rel="noopener"' : '' ?>><?= $wa ? 'Escribinos por WhatsApp' : 'Contactanos' ?></a></div></main>
<?php elseif ($forzar): ?>
<main class="lgbox"><form class="lgf" method="post"><h1 class="lgh">Elegí tu clave</h1><p class="mut">Es tu primer ingreso. Elegí una clave nueva (mínimo 6 caracteres) para entrar la próxima vez.</p>
<?php if ($msg) echo '<p class="perr">' . $e($msg) . '</p>'; ?>
<input type="hidden" name="a" value="cambiar"><input type="hidden" name="t" value="<?= $T ?>">
<label>Clave nueva<input name="p1" type="password" autocomplete="new-password" minlength="6" required></label><label>Repetir clave<input name="p2" type="password" autocomplete="new-password" minlength="6" required></label><button class="btn">Guardar y entrar</button></form></main>
<?php else:
$id = (int)($_GET['e'] ?? 0); $en = null;
if ($id) { $s = $pdo->prepare($SQL . ' AND e.id = ?'); $s->execute([$cid, $id]); $en = $s->fetch() ?: null; }
if ($en): ?>
<main class="pt"><div class="pd"><div><?php if ($vid($en)): ?><div class="cvw"><video playsinline preload="auto" data-auto<?= $pst($en) ? ' poster="' . $e($pst($en)) . '"' : '' ?>><source src="<?= $e($vid($en)) ?>" type="video/mp4"></video></div><p class="mut sm">Vista previa del proyecto.</p><?php elseif ($df = $did($en)): ?><div class="cvw dfr" data-did="<?= $e($df) ?>"><iframe src="https://drive.google.com/file/d/<?= $e($df) ?>/preview" allow="autoplay; fullscreen" allowfullscreen loading="lazy" title="Vista previa"></iframe></div><p class="mut sm">Vista previa desde Google Drive.</p><?php endif; ?></div>
<div><a class="back" href="./">&larr; Mis entregas</a><h1><?= $e($en['titulo']) ?></h1><p class="sub"><?= $e(substr($en['creado'], 0, 10)) ?></p>
<?php if (trim((string)$en['descripcion']) !== ''): ?><div class="copy"><?= nl2br($e($en['descripcion'])) ?></div><?php endif; ?>
<?php if ($drive($en)): ?><a class="btn" href="<?= $e($drive($en)) ?>" target="_blank" rel="noopener">Abrir en Drive / Descargar</a><p class="mut sm">El archivo final está en la mejor calidad, en Google Drive.</p><?php else: ?><p class="mut">El archivo final todavía no está disponible.</p><?php endif; ?></div></div></main>
<?php else:
$s = $pdo->prepare($SQL . ' ORDER BY e.id DESC'); $s->execute([$cid]); $es = $s->fetchAll(); ?>
<main class="pt"><?= $cli['logo'] ? '<img class="clogo" loading="lazy" src="../media/logos/' . $e($cli['logo']) . '" alt="" width="180" height="80">' : '' ?><h1>Hola, <?= $e(explode(' ', trim($cli['nombre']))[0]) ?></h1><p class="sub"><?= $cli['empresa'] ? $e($cli['empresa']) . ' · ' : '' ?>Tus entregas (<?= count($es) ?>)</p>
<?php if (!$es) echo '<p class="mut">Todavía no hay entregas cargadas.</p>'; ?>
<div class="pg"><?php foreach ($es as $r): ?><article class="pc"><a class="pc-img" href="?e=<?= (int)$r['id'] ?>" aria-label="Ver <?= $e($r['titulo']) ?>"><?= $pst($r) ? '<img src="' . $e($pst($r)) . '" alt="" loading="lazy" width="800" height="500">' : ($did($r) ? '<img src="https://drive.google.com/thumbnail?id=' . $e($did($r)) . '&amp;sz=w800" alt="" loading="lazy" width="800" height="500" referrerpolicy="no-referrer">' : '') ?><span class="pc-date"><?= $e(substr($r['creado'], 0, 10)) ?></span></a>
<div class="pc-body"><h3><a href="?e=<?= (int)$r['id'] ?>"><?= $e($r['titulo']) ?></a></h3><?php if (trim((string)$r['descripcion']) !== '') echo '<p class="pc-desc">' . $e($r['descripcion']) . '</p>'; ?>
<?php if ($drive($r)): ?><a class="btn" href="<?= $e($drive($r)) ?>" target="_blank" rel="noopener">Abrir en Drive / Descargar</a><?php endif; ?></div></article><?php endforeach; ?></div></main>
<?php endif; endif; ?>
<footer class="foot"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span><span class="loc">Posadas, Misiones · Argentina</span><nav class="social"><a href="../">Web</a><a href="<?= $e($contacto) ?>">Contacto</a></nav><span class="mut">© 2026 blens.ar</span></footer>
<script src="../assets/fonts.js?v=1" defer></script><script src="../assets/player.js?v=11" defer></script><script src="dfr.js?v=10" defer></script></body></html>
