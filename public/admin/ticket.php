<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
require __DIR__ . '/lib.php';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY');
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }
$pdo = blens_db(); $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['t'], (string)($_POST['t'] ?? ''))) { http_response_code(400); exit('Sesión vencida. Volvé a Cobros y reintentá.'); }
$cid = (int)($_POST['cid'] ?? $_GET['c'] ?? 0);
$s = $pdo->prepare('SELECT nombre,empresa,telefono,logo FROM clientes WHERE id=?'); $s->execute([$cid]); $cl = $s->fetch();
if (!$cl) { http_response_code(404); exit('Cliente inexistente.'); }
$ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
$sql = "SELECT * FROM trabajos WHERE cliente_id=? AND estado='pendiente'" . ($ids ? ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' : '') . ' ORDER BY fecha, id';
$q = $pdo->prepare($sql); $q->execute(array_merge([$cid], $ids)); $rows = $q->fetchAll();
if (!$rows) exit('No hay trabajos pendientes para armar el ticket. <a href="cobros.php?c=' . $cid . '">Volver a cobros</a>');
$tot = 0; $pru = 0;
foreach ($rows as $x) { $tot += (int)$x['cantidad'] * (int)$x['monto_unit']; if (!empty($x['prueba'])) $pru = 1; }
$lineas = implode("\n", array_map(fn($x) => '• ' . ($x['detalle'] ?: (TIPOS[$x['tipo']] ?? '')) . ' (' . $x['cantidad'] . ' × ' . peso((int)$x['monto_unit']) . ')', $rows));
$pn = explode(' ', trim($cl['nombre']))[0];
$txt = "Hola $pn, te paso el detalle de lo pendiente de pago:\n$lineas\nTotal a pagar: " . peso($tot) . "\nCuando lo abones, te mando el recibo. ¡Gracias!";
$w = wa_url((string)$cl['telefono'], $txt);
$doc = ['titulo' => 'Detalle de cobro', 'fecha' => date('d/m/Y'), 'estado' => 'Pendiente de pago', 'cLabel' => 'Cliente:',
  'cliente' => ($cl['empresa'] ?: $cl['nombre']) . ($cl['empresa'] ? ' (' . $cl['nombre'] . ')' : ''),
  'items' => array_map(fn($x) => ['d' => $x['detalle'] ?: (TIPOS[$x['tipo']] ?? ''), 'c' => (int)$x['cantidad'], 'p' => peso((int)$x['monto_unit']), 's' => peso((int)$x['cantidad'] * (int)$x['monto_unit'])], $rows),
  'totalLabel' => 'Total a pagar', 'total' => peso($tot), 'medio' => '', 'prueba' => (bool)$pru, 'pruebaTxt' => 'TICKET DE PRUEBA · sin valor',
  'pie' => 'Detalle de importes pendientes de pago. No es un recibo ni una factura: al recibir el pago se emite el recibo.', 'logo' => '../assets/brand/blens_logo-horizontal_color_fondo-claro.png'];
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Detalle de cobro | blens.ar</title><link rel="icon" href="../assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="recibo.css?v=12"></head><body>
<div class="acc"><button data-print>Imprimir / guardar PDF</button><button type="button" data-wa-img data-img="doc" data-wa="<?= $e($w ?? '') ?>" data-txt="<?= $e($txt) ?>" data-nombre="ticket-cobro" data-doc="<?= $e(json_encode($doc, JSON_UNESCAPED_UNICODE)) ?>">Enviar por WhatsApp con imagen</button><button type="button" class="g" data-copiar="<?= $e($txt) ?>">Copiar mensaje</button><a class="g" href="cobros.php?c=<?= $cid ?>">Volver a cobros</a></div>
<?= $pru ? '<p class="pru">TICKET DE PRUEBA · sin valor</p>' : '' ?>
<div class="rh"><div><h1>Detalle de cobro</h1><p>Fecha: <?= $e(date('d/m/Y')) ?></p><p><span class="est">Pendiente de pago</span></p></div><div class="rt"><img class="blogo" src="../assets/brand/blens_logo-horizontal_color_fondo-claro.png" alt="blens.ar"><p>Contenido audiovisual para empresas</p></div></div>
<?= $cl['logo'] ? '<img class="clogo" src="../media/logos/' . $e($cl['logo']) . '" alt="">' : '' ?><p><b>Cliente:</b> <?= $e($cl['empresa'] ?: $cl['nombre']) ?><?= $cl['empresa'] ? ' (' . $e($cl['nombre']) . ')' : '' ?></p>
<table><tr><th>Fecha</th><th>Detalle</th><th class="n">Cant.</th><th class="n">Precio</th><th class="n">Subtotal</th></tr>
<?php foreach ($rows as $x): ?><tr><td><?= $e(date('d/m/Y', strtotime($x['fecha']))) ?></td><td><?= $e($x['detalle'] ?: (TIPOS[$x['tipo']] ?? '')) ?></td><td class="n"><?= (int)$x['cantidad'] ?></td><td class="n"><?= $e(peso((int)$x['monto_unit'])) ?></td><td class="n"><?= $e(peso((int)$x['cantidad'] * (int)$x['monto_unit'])) ?></td></tr><?php endforeach; ?></table>
<p class="tot">Total a pagar: <?= $e(peso($tot)) ?></p>
<p class="leg">Detalle de importes pendientes de pago. No es un recibo ni una factura: al recibir el pago se emite el recibo.</p><script src="admin.js?v=15"></script></body></html>
