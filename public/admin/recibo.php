<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
require __DIR__ . '/lib.php';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY');
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }
$pdo = blens_db(); $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$s = $pdo->prepare('SELECT r.*, c.nombre, c.empresa, c.telefono, c.logo FROM recibos r JOIN clientes c ON c.id = r.cliente_id WHERE r.id = ?'); $s->execute([(int)($_GET['id'] ?? 0)]); $r = $s->fetch();
if (!$r) { http_response_code(404); exit('Recibo inexistente.'); }
$t = $pdo->prepare('SELECT * FROM trabajos WHERE recibo_id = ? ORDER BY fecha, id'); $t->execute([$r['id']]); $rows = $t->fetchAll();
$num = nro($r);
$lineas = implode("\n", array_map(fn($x) => '• ' . ($x['detalle'] ?: (TIPOS[$x['tipo']] ?? '')) . ' (' . $x['cantidad'] . ' × ' . peso((int)$x['monto_unit']) . ')', $rows));
$w = wa_url((string)$r['telefono'], 'Hola ' . explode(' ', trim($r['nombre']))[0] . ", te paso el recibo $num por " . peso((int)$r['total']) . ' (' . $r['periodo'] . ', ' . (MEDIOS[$r['medio']] ?? $r['medio']) . ")\n$lineas\n¡Gracias!");
$doc = ['titulo' => 'Recibo ' . $num, 'fecha' => date('d/m/Y', strtotime($r['fecha'])), 'estado' => '', 'cLabel' => 'Recibimos de:',
  'cliente' => ($r['empresa'] ?: $r['nombre']) . ($r['empresa'] ? ' (' . $r['nombre'] . ')' : ''), 'periodo' => $r['periodo'],
  'items' => array_map(fn($x) => ['d' => $x['detalle'] ?: (TIPOS[$x['tipo']] ?? ''), 'c' => (int)$x['cantidad'], 'p' => peso((int)$x['monto_unit']), 's' => peso((int)$x['cantidad'] * (int)$x['monto_unit'])], $rows),
  'totalLabel' => 'Total', 'total' => peso((int)$r['total']), 'medio' => MEDIOS[$r['medio']] ?? $r['medio'], 'prueba' => (bool)$r['prueba'], 'pruebaTxt' => 'RECIBO DE PRUEBA · sin valor',
  'pie' => 'Comprobante interno de pago. No reemplaza una factura.', 'logo' => '../assets/brand/blens_logo-horizontal_color_fondo-claro.png'];
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Recibo <?= $e($num) ?> | blens.ar</title><link rel="icon" href="../assets/favicon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="../assets/apple-touch-icon.png"><link rel="stylesheet" href="recibo.css?v=12"></head><body>
<div class="acc"><button data-print>Imprimir / guardar PDF</button><a class="g" href="cobros.php?c=<?= (int)$r['cliente_id'] ?>">Volver a cobros</a><button type="button" data-wa-img data-img="doc" data-wa="<?= $e($w ?? '') ?>" data-txt="<?= $e('Hola ' . explode(' ', trim($r['nombre']))[0] . ", te paso el recibo $num por " . peso((int)$r['total']) . ' (' . $r['periodo'] . ', ' . (MEDIOS[$r['medio']] ?? $r['medio']) . ")\n$lineas\n¡Gracias!") ?>" data-nombre="recibo-<?= $e($num) ?>" data-doc="<?= $e(json_encode($doc, JSON_UNESCAPED_UNICODE)) ?>">Enviar por WhatsApp con imagen</button>
<form method="post" action="cobros.php"><input type="hidden" name="a" value="anular_recibo"><input type="hidden" name="t" value="<?= $e($_SESSION['t']) ?>"><input type="hidden" name="cid" value="<?= (int)$r['cliente_id'] ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><label><input type="checkbox" required> Anular este recibo</label> <button class="g">Anular</button></form></div>
<?= $r['prueba'] ? '<p class="pru">RECIBO DE PRUEBA · sin valor</p>' : '' ?>
<div class="rh"><div><h1>Recibo <?= $e($num) ?></h1><p>Fecha: <?= $e(date('d/m/Y', strtotime($r['fecha']))) ?></p></div><div class="rt"><img class="blogo" src="../assets/brand/blens_logo-horizontal_color_fondo-claro.png" alt="blens.ar"><p>Contenido audiovisual para empresas</p></div></div>
<?= $r['logo'] ? '<img class="clogo" src="../media/logos/' . $e($r['logo']) . '" alt="">' : '' ?><p><b>Recibimos de:</b> <?= $e($r['empresa'] ?: $r['nombre']) ?><?= $r['empresa'] ? ' (' . $e($r['nombre']) . ')' : '' ?><br><b>Período:</b> <?= $e($r['periodo']) ?></p>
<table><tr><th>Fecha</th><th>Detalle</th><th class="n">Cant.</th><th class="n">Precio</th><th class="n">Subtotal</th></tr>
<?php foreach ($rows as $x): ?><tr><td><?= $e(date('d/m/Y', strtotime($x['fecha']))) ?></td><td><?= $e($x['detalle'] ?: (TIPOS[$x['tipo']] ?? '')) ?></td><td class="n"><?= (int)$x['cantidad'] ?></td><td class="n"><?= $e(peso((int)$x['monto_unit'])) ?></td><td class="n"><?= $e(peso((int)$x['cantidad'] * (int)$x['monto_unit'])) ?></td></tr><?php endforeach; ?></table>
<p class="tot">Total: <?= $e(peso((int)$r['total'])) ?></p><p><b>Forma de pago:</b> <?= $e(MEDIOS[$r['medio']] ?? $r['medio']) ?></p>
<div class="firma">Firma</div><p class="leg">Comprobante interno de pago. No reemplaza una factura.</p><script src="admin.js?v=15"></script></body></html>
