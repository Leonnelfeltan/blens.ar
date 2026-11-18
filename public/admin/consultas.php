<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
require __DIR__ . '/lib.php';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY');
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }
$pdo = blens_db(); $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$agN = agenda_pendientes($pdo); $consN = consultas_pendientes($pdo);
$T = $e($_SESSION['t']); $msg = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$f = (string)($_GET['f'] ?? 'pendientes') === 'todas' ? 'todas' : 'pendientes';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['t'], (string)($_POST['t'] ?? ''))) { http_response_code(400); exit('Sesión vencida. Recargá la página.'); }
    if (($_POST['a'] ?? '') === 'marcar') {
        $id = (int)($_POST['id'] ?? 0); $v = (int)($_POST['v'] ?? 0) === 1 ? 1 : 0;
        if ($id > 0) { $pdo->prepare('UPDATE consultas SET respondida=? WHERE id=?')->execute([$v, $id]); $_SESSION['flash'] = $v ? 'Consulta marcada como respondida.' : 'Consulta vuelta a pendiente.'; }
    }
    header('Location: consultas.php?f=' . $f); exit;
}
$q = $f === 'pendientes' ? 'SELECT * FROM consultas WHERE respondida=0 ORDER BY id DESC LIMIT 300' : 'SELECT * FROM consultas ORDER BY id DESC LIMIT 300';
$rows = $pdo->query($q)->fetchAll();
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Consultas | blens.ar</title><link rel="icon" href="../assets/favicon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="../assets/apple-touch-icon.png"><link rel="stylesheet" href="admin.css?v=23"></head><body>
<svg class="sprite" aria-hidden="true"><symbol id="mark" viewBox="0 0 130 130"><polygon points="0,0 70,0 100,30 100,100 0,100" fill="#F5F1EA"/><polygon points="60,45 125,45 125,125 45,125 45,60" fill="#F5F1EA"/><polygon points="60,45 100,45 100,64 86,72 100,80 100,100 45,100 45,60" fill="#FF5A1F"/></symbol></svg>
<div class="top"><a class="brand" href="./"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span></a><nav><a href="./">Proyectos</a><a class="on" href="consultas.php">Consultas<?php if ($consN > 0): ?><span class="nav-badge soft"><?= $consN ?></span><?php endif; ?></a><a href="clientes.php">Clientes</a><a href="cobros.php">Cobros</a><a href="agenda.php">Agenda<?php if ($agN > 0): ?><span class="nav-badge"><?= $agN ?></span><?php endif; ?></a><a href="presupuestos.php">Presupuestos</a><a href="../" target="_blank" rel="noopener">Ver web</a></nav>
<form method="post" action="./"><input type="hidden" name="a" value="logout"><input type="hidden" name="t" value="<?= $T ?>"><button class="g">Salir</button></form></div>
<?php if ($msg) echo '<p class="ok">' . $e($msg) . '</p>'; ?>
<div class="lh2"><h1>Consultas recibidas</h1><span class="mut"><?= $consN ?> pendiente(s) · <?= count($rows) ?> en esta vista</span></div>
<div class="row"><a class="pill <?= $f === 'pendientes' ? 'on' : '' ?>" href="?f=pendientes">Pendientes</a><a class="pill <?= $f === 'todas' ? 'on' : '' ?>" href="?f=todas">Todas</a></div>
<table class="cards"><tr><th>Fecha</th><th>Nombre / empresa</th><th>Servicio</th><th>Mensaje</th><th>Estado</th><th></th></tr>
<?php foreach ($rows as $r): $resp = (int)($r['respondida'] ?? 0) === 1; $pn = explode(' ', trim((string)$r['nombre']))[0] ?: 'hola'; $tel = preg_replace('/\D+/', '', (string)$r['telefono']) ?: ''; $w = $tel !== '' ? wa_url((string)$r['telefono'], 'Hola ' . $pn . ', te escribimos de blens.ar por tu consulta sobre ' . (string)$r['servicio'] . '.') : null; ?>
<tr>
<td class="mut" data-label="Fecha"><?= $e(substr((string)$r['creado'], 0, 16)) ?></td>
<td data-label="Contacto"><?= $e($r['nombre']) ?><br><span class="mut"><?= $e($r['empresa']) ?></span><br><a href="mailto:<?= $e($r['email']) ?>"><?= $e($r['email']) ?></a></td>
<td data-label="Servicio"><?= $e($r['servicio']) ?></td>
<td data-label="Mensaje"><?= nl2br($e($r['mensaje'])) ?></td>
<td data-label="Estado"><?= $resp ? '<span class="badge ok">Respondida</span>' : '<span class="badge pend">Pendiente</span>' ?></td>
<td data-label=""><div class="row"><?php if ($tel !== ''): ?><a class="btn g" href="tel:<?= $e($tel) ?>">Llamar</a><?php endif; ?>
<?php if ($w): ?><a class="btn g" href="<?= $e($w) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
<form method="post" class="ia-f"><input type="hidden" name="a" value="marcar"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="v" value="<?= $resp ? 0 : 1 ?>"><button class="g"><?= $resp ? 'Volver a pendiente' : 'Respondida' ?></button></form></div></td>
</tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="6" class="mut">' . ($f === 'pendientes' ? 'No hay consultas pendientes.' : 'Todavía no hay consultas.') . '</td></tr>'; ?></table>
<script src="admin.js?v=21"></script></body></html>
