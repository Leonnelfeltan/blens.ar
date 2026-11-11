<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
require __DIR__ . '/lib.php';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY');
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }
$pdo = blens_db(); $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
if (!$pdo->query("SHOW TABLES LIKE 'trabajos'")->fetch()) exit('Faltan las tablas de cobros. En el servidor corré: sudo mysql < /tmp/nuevo/blens-config/migracion-cobros.sql');
if (!$pdo->query("SHOW TABLES LIKE 'ajustes'")->fetch()) exit('Falta actualizar la base. En el servidor corré: sudo mysql < /tmp/nuevo/blens-config/migracion-4.sql');
if (!$pdo->query("SHOW COLUMNS FROM trabajos LIKE 'entrega_id'")->fetch()) exit('Falta actualizar la base. En el servidor corré: sudo mysql < /tmp/nuevo/blens-config/migracion-5.sql');
$mp = $pdo->query("SELECT valor FROM ajustes WHERE clave='modo_prueba'")->fetchColumn() === '1' ? 1 : 0;
$ok = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']); $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['t'], (string)($_POST['t'] ?? ''))) { http_response_code(400); exit('Sesión vencida. Recargá la página.'); }
    $a = $_POST['a'] ?? ''; $c = (int)($_POST['cid'] ?? 0);
    try {
        if ($a === 'modo') { $pdo->prepare("INSERT INTO ajustes (clave,valor) VALUES ('modo_prueba',?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")->execute([$mp ? '0' : '1']); $_SESSION['flash'] = 'Modo prueba ' . ($mp ? 'desactivado: ahora lo que cargues se guarda de verdad.' : 'activado.'); header('Location: ' . ($c ? "cobros.php?c=$c" : 'cobros.php')); exit; }
        if ($a === 'borrar_pruebas') { $pdo->exec('DELETE FROM recibos WHERE prueba=1'); $pdo->exec('DELETE FROM trabajos WHERE prueba=1'); $_SESSION['flash'] = 'Datos de prueba borrados (trabajos y recibos).'; header('Location: ' . ($c ? "cobros.php?c=$c" : 'cobros.php')); exit; }
        $s = $pdo->prepare('SELECT * FROM clientes WHERE id=?'); $s->execute([$c]); $cl = $s->fetch();
        if (!$cl) throw new RuntimeException('Cliente inexistente.');
        if ($a === 'trabajo') {
            $f = (string)($_POST['fecha'] ?? ''); if (!fecha_ok($f)) throw new RuntimeException('Fecha inválida.');
            $tipo = (string)($_POST['tipo'] ?? 'otro'); if (!isset(TIPOS[$tipo])) $tipo = 'otro';
            $det = mb_substr(trim((string)($_POST['detalle'] ?? '')), 0, 200); $cant = max(1, min(999, (int)($_POST['cantidad'] ?? 1))); $mon = (int)preg_replace('/\D/', '', (string)($_POST['monto'] ?? '0'));
            $est = ($_POST['estado'] ?? 'pendiente') === 'cobrado' ? 'cobrado' : 'pendiente'; $med = null; $fpg = null;
            if ($est === 'cobrado') { $med = (string)($_POST['medio'] ?? ''); if (!isset(MEDIOS[$med])) throw new RuntimeException('Elegí cómo te pagaron.'); $fpg = (string)($_POST['fecha_pago'] ?? '') ?: $f; if (!fecha_ok($fpg)) throw new RuntimeException('Fecha de pago inválida.'); }
            $ei = (int)($_POST['entrega_id'] ?? 0) ?: null; $pi = (int)($_POST['proyecto_id'] ?? 0) ?: null;
            $pdo->prepare('INSERT INTO trabajos (cliente_id,fecha,tipo,detalle,cantidad,monto_unit,prueba,estado,medio,fecha_pago,entrega_id,proyecto_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$c, $f, $tipo, $det, $cant, $mon, $mp, $est, $med, $fpg, $ei, $pi]);
            $_SESSION['flash'] = 'Trabajo registrado.';
        } elseif ($a === 'abono_mes') {
            if (!(int)$cl['abono']) throw new RuntimeException('Este cliente no tiene abono cargado (editá sus tarifas en Clientes).');
            $ini = date('Y-m-01'); $q = $pdo->prepare("SELECT COUNT(*) FROM trabajos WHERE cliente_id=? AND tipo='abono' AND fecha>=? AND fecha<=LAST_DAY(?)"); $q->execute([$c, $ini, $ini]);
            if ($q->fetchColumn()) throw new RuntimeException('El abono de este mes ya está cargado.');
            $pdo->prepare("INSERT INTO trabajos (cliente_id,fecha,tipo,detalle,cantidad,monto_unit,prueba) VALUES (?,?,'abono',?,1,?,?)")->execute([$c, date('Y-m-d'), 'Abono mensual ' . mes(date('Y-m-d')), (int)$cl['abono'], $mp]);
            $_SESSION['flash'] = 'Abono del mes cargado como pendiente.';
        } elseif ($a === 'del_trabajo') {
            $pdo->prepare('DELETE FROM trabajos WHERE id=? AND cliente_id=? AND recibo_id IS NULL')->execute([(int)($_POST['id'] ?? 0), $c]); $_SESSION['flash'] = 'Trabajo quitado.';
        } elseif ($a === 'cobrar') {
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
            if (!$ids) throw new RuntimeException('Elegí al menos un trabajo pendiente.');
            $medio = (string)($_POST['medio'] ?? ''); if (!isset(MEDIOS[$medio])) throw new RuntimeException('Elegí cómo te pagaron.');
            $fp = (string)($_POST['fecha_pago'] ?? ''); if (!fecha_ok($fp)) throw new RuntimeException('Fecha de pago inválida.');
            $q = $pdo->prepare("SELECT * FROM trabajos WHERE cliente_id=? AND estado='pendiente' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')'); $q->execute(array_merge([$c], $ids)); $rows = $q->fetchAll();
            if (!$rows) throw new RuntimeException('Esos trabajos ya no están pendientes.');
            $tot = 0; $ms = []; foreach ($rows as $r) { $tot += $r['cantidad'] * $r['monto_unit']; $ms[mes($r['fecha'])] = 1; }
            $pdo->beginTransaction();
            $pr = $mp; foreach ($rows as $r) if (!empty($r['prueba'])) $pr = 1;
            $nq = $pdo->prepare('SELECT COALESCE(MAX(numero),0)+1 FROM recibos WHERE prueba=?'); $nq->execute([$pr]); $num = (int)$nq->fetchColumn();
            $pdo->prepare('INSERT INTO recibos (cliente_id,periodo,total,medio,fecha,numero,prueba) VALUES (?,?,?,?,?,?,?)')->execute([$c, count($ms) === 1 ? array_key_first($ms) : 'Varios períodos', $tot, $medio, $fp, $num, $pr]); $rid = (int)$pdo->lastInsertId();
            $up = $pdo->prepare("UPDATE trabajos SET estado='cobrado',medio=?,fecha_pago=?,recibo_id=? WHERE id=?"); foreach ($rows as $r) $up->execute([$medio, $fp, $rid, $r['id']]);
            $pdo->commit(); header("Location: recibo.php?id=$rid"); exit;
        } elseif ($a === 'anular_recibo') {
            $rid = (int)($_POST['id'] ?? 0); $pdo->beginTransaction();
            $pdo->prepare("UPDATE trabajos SET estado='pendiente',medio=NULL,fecha_pago=NULL,recibo_id=NULL WHERE recibo_id=? AND cliente_id=?")->execute([$rid, $c]);
            $pdo->prepare('DELETE FROM recibos WHERE id=? AND cliente_id=?')->execute([$rid, $c]); $pdo->commit();
            $_SESSION['flash'] = 'Recibo anulado: los trabajos volvieron a pendientes.';
        }
        if (!$err) { header("Location: cobros.php?c=$c"); exit; }
    } catch (Throwable $x) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($x instanceof PDOException) { error_log('[blens cobros] ' . $x->getMessage()); $err = 'Error de base de datos.'; } else $err = $x->getMessage();
    }
}
$vc = isset($_GET['c']) ? (int)$_GET['c'] : null; $T = $e($_SESSION['t']); $cl = null;
if ($vc) { $s = $pdo->prepare('SELECT * FROM clientes WHERE id=?'); $s->execute([$vc]); $cl = $s->fetch() ?: null; }
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Cobros | blens.ar</title><link rel="icon" href="../assets/favicon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="../assets/apple-touch-icon.png"><link rel="stylesheet" href="admin.css?v=8"></head><body>
<svg class="sprite" aria-hidden="true"><symbol id="mark" viewBox="0 0 130 130"><polygon points="0,0 70,0 100,30 100,100 0,100" fill="#F5F1EA"/><polygon points="60,45 125,45 125,125 45,125 45,60" fill="#F5F1EA"/><polygon points="60,45 100,45 100,64 86,72 100,80 100,100 45,100 45,60" fill="#FF5A1F"/></symbol></svg>
<div class="top"><a class="brand" href="./"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span></a><nav><a href="./">Proyectos</a><a href="./?v=consultas">Consultas</a><a href="clientes.php">Clientes</a><a href="cobros.php">Cobros</a></nav>
<form method="post" action="./"><input type="hidden" name="a" value="logout"><input type="hidden" name="t" value="<?= $T ?>"><button class="g">Salir</button></form></div>
<?php if ($ok) echo '<p class="ok">' . $e($ok) . '</p>'; if ($err) echo '<p class="err">' . $e($err) . '</p>'; ?>
<div class="<?= $mp ? 'ok' : 'err' ?>"><b>Modo prueba: <?= $mp ? 'ACTIVADO' : 'DESACTIVADO' ?>.</b> <?= $mp ? 'Los trabajos y recibos que cargues quedan marcados como PRUEBA, no ocupan la numeración real y se pueden borrar.' : 'Lo que cargues ahora se guarda de verdad.' ?>
<div class="row"><form method="post"><input type="hidden" name="a" value="modo"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)($cl['id'] ?? 0) ?>"><button class="g"><?= $mp ? 'Salir del modo prueba' : 'Activar modo prueba' ?></button></form>
<form method="post"><input type="hidden" name="a" value="borrar_pruebas"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)($cl['id'] ?? 0) ?>"><label class="chk"><input type="checkbox" required>Confirmo borrar los datos de prueba</label><button class="r">Borrar datos de prueba</button></form></div></div>
<?php if (!$cl): $rows = $pdo->query("SELECT c.id,c.empresa,c.nombre,c.abono, COALESCE(SUM(CASE WHEN t.estado='pendiente' THEN t.cantidad*t.monto_unit END),0) AS pend, COALESCE(SUM(CASE WHEN t.estado='cobrado' AND DATE_FORMAT(t.fecha_pago,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') THEN t.cantidad*t.monto_unit END),0) AS cobrado FROM clientes c LEFT JOIN trabajos t ON t.cliente_id=c.id GROUP BY c.id ORDER BY c.empresa,c.nombre")->fetchAll(); $tp = $tc = 0; ?>
<h1>Cobros</h1><table><tr><th>Cliente</th><th>Pendiente</th><th>Cobrado este mes</th><th></th></tr>
<?php foreach ($rows as $r): $tp += $r['pend']; $tc += $r['cobrado']; ?><tr><td><?= $e($r['empresa'] ?: $r['nombre']) ?></td><td><?= $e(peso((int)$r['pend'])) ?></td><td><?= $e(peso((int)$r['cobrado'])) ?></td><td><a class="btn g" href="?c=<?= (int)$r['id'] ?>">Abrir</a></td></tr><?php endforeach; if (!$rows) echo '<tr><td colspan="4" class="mut">Primero agregá clientes.</td></tr>'; ?>
<tr><th>Total</th><th><?= $e(peso($tp)) ?></th><th><?= $e(peso($tc)) ?></th><th></th></tr></table>
<?php else:
$s = $pdo->prepare("SELECT * FROM trabajos WHERE cliente_id=? AND estado='pendiente' ORDER BY fecha,id"); $s->execute([$cl['id']]); $pend = $s->fetchAll();
$s = $pdo->prepare("SELECT * FROM trabajos WHERE cliente_id=? AND estado='cobrado' ORDER BY fecha_pago DESC,id DESC LIMIT 30"); $s->execute([$cl['id']]); $cob = $s->fetchAll();
$s = $pdo->prepare('SELECT * FROM recibos WHERE cliente_id=? ORDER BY id DESC LIMIT 20'); $s->execute([$cl['id']]); $recs = $s->fetchAll(); $tp = 0;
$tipoOpts = '<option value="video" data-m="' . (int)$cl['tarifa_video'] . '">Video</option><option value="foto" data-m="' . (int)$cl['tarifa_foto'] . '">Foto</option><option value="otro" data-m="0">Otro</option>';
$det = [];
$q = $pdo->prepare('SELECT en.id,en.titulo,en.creado,en.proyecto_id FROM entregas en WHERE en.cliente_id=? AND NOT EXISTS (SELECT 1 FROM trabajos t WHERE t.entrega_id = en.id) ORDER BY en.id DESC'); $q->execute([$cl['id']]);
foreach ($q as $x) $det[] = ['k' => 'entrega', 'id' => (int)$x['id'], 't' => $x['titulo'], 'f' => substr($x['creado'], 0, 10), 'p' => (int)$x['proyecto_id']];
$vinc = array_filter(array_column($det, 'p'));
$q = $pdo->prepare("SELECT p.id,p.titulo,p.creado FROM proyectos p WHERE p.cliente_id=? AND p.categoria <> 'showreel' AND NOT EXISTS (SELECT 1 FROM trabajos t WHERE t.proyecto_id = p.id) AND NOT EXISTS (SELECT 1 FROM entregas en JOIN trabajos t ON t.entrega_id = en.id WHERE en.proyecto_id = p.id AND en.cliente_id = ?) ORDER BY p.id DESC"); $q->execute([$cl['id'], $cl['id']]);
foreach ($q as $x) if (!in_array((int)$x['id'], $vinc, true)) $det[] = ['k' => 'proyecto', 'id' => (int)$x['id'], 't' => $x['titulo'], 'f' => substr($x['creado'], 0, 10), 'p' => 0];
?>
<?= !empty($cl['logo']) ? '<img class="clogo" src="../media/logos/' . $e($cl['logo']) . '" alt="">' : '' ?><h1><?= $e($cl['empresa'] ? $cl['empresa'] . ' · ' . $cl['nombre'] : $cl['nombre']) ?></h1><div class="row"><a class="btn g" href="cobros.php">&larr; Todos</a><a class="btn g" href="clientes.php?c=<?= (int)$cl['id'] ?>">Datos y tarifas</a>
<?php if ((int)$cl['abono']): ?><form method="post"><input type="hidden" name="a" value="abono_mes"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>"><button>Cargar abono de este mes</button></form><?php endif; ?></div>
<h2>Registrar trabajo</h2>
<form class="f" method="post"><input type="hidden" name="a" value="trabajo"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>">
<label>Fecha<input type="date" name="fecha" value="<?= date('Y-m-d') ?>" required></label>
<label>Tipo<select name="tipo" class="tipo-sel"><option value="video" data-m="<?= (int)$cl['tarifa_video'] ?>">Video</option><option value="foto" data-m="<?= (int)$cl['tarifa_foto'] ?>">Foto</option><option value="abono" data-m="<?= (int)$cl['abono'] ?>">Abono mensual</option><option value="otro" data-m="0">Otro</option></select></label>
<label class="full">Detalle (ej: reel de la propiedad de Av. Corrientes)<input name="detalle" maxlength="200"></label>
<label>Cantidad<input type="number" name="cantidad" value="1" min="1" max="999"></label><label>Precio unitario ($)<input name="monto" class="monto-in" inputmode="numeric" required></label>
<label>Estado<select name="estado"><option value="pendiente">Pendiente de cobro</option><option value="cobrado">Ya cobrado (queda constancia)</option></select></label>
<label>Si ya cobraste: medio<select name="medio"><?php foreach (MEDIOS as $k => $n) echo '<option value="' . $k . '">' . $e($n) . '</option>'; ?></select></label>
<label>Si ya cobraste: fecha de cobro<input type="date" name="fecha_pago"></label>
<div class="row full"><button>Registrar</button></div></form>
<h2>Detectados en lo que subiste (<?= count($det) ?>)</h2>
<?php if (!$det): ?><p class="mut">No hay entregas ni proyectos de este cliente sin registrar como trabajo.</p><?php endif; ?>
<?php foreach ($det as $x): ?><form class="f det" method="post"><input type="hidden" name="a" value="trabajo"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>"><input type="hidden" name="fecha" value="<?= $e($x['f']) ?>"><input type="hidden" name="detalle" value="<?= $e($x['t']) ?>"><input type="hidden" name="cantidad" value="1"><input type="hidden" name="<?= $x['k'] === 'entrega' ? 'entrega_id' : 'proyecto_id' ?>" value="<?= (int)$x['id'] ?>">
<div class="full"><b><?= $e($x['t']) ?></b> <span class="mut"><?= $x['k'] === 'entrega' ? 'Entrega del portal' : 'Proyecto de la web' ?> · <?= $e(date('d/m/Y', strtotime($x['f']))) ?></span></div>
<label>Tipo<select name="tipo" class="tipo-sel"><?= $tipoOpts ?></select></label><label>Precio ($)<input name="monto" class="monto-in" inputmode="numeric" required></label>
<label>Estado<select name="estado"><option value="pendiente">Pendiente de cobro</option><option value="cobrado">Ya cobrado</option></select></label>
<label>Si ya cobraste: medio<select name="medio"><?php foreach (MEDIOS as $k => $n) echo '<option value="' . $k . '">' . $e($n) . '</option>'; ?></select></label>
<div class="row full"><button>Registrar como trabajo</button></div></form><?php endforeach; ?>
<h2>Pendientes</h2>
<?php if (!$pend): ?><p class="mut">No hay trabajos pendientes.</p><?php else: ?>
<form id="fdel" method="post"><input type="hidden" name="a" value="del_trabajo"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>"></form>
<form method="post"><input type="hidden" name="a" value="cobrar"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>">
<table><tr><th><input type="checkbox" id="todos" aria-label="Elegir todos"></th><th>Fecha</th><th>Detalle</th><th>Subtotal</th><th></th></tr>
<?php foreach ($pend as $r): $sub = (int)$r['cantidad'] * (int)$r['monto_unit']; $tp += $sub; ?><tr><td><input class="sel" type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>"></td><td class="mut"><?= $e(date('d/m/Y', strtotime($r['fecha']))) ?></td><td><?= $e($r['detalle'] ?: TIPOS[$r['tipo']]) ?><br><span class="mut"><?= (int)$r['cantidad'] ?> × <?= $e(peso((int)$r['monto_unit'])) ?></span></td><td><?= $e(peso($sub)) ?></td><td><button class="g" form="fdel" name="id" value="<?= (int)$r['id'] ?>">Quitar</button></td></tr><?php endforeach; ?>
<tr><th></th><th></th><th>Total pendiente</th><th><?= $e(peso($tp)) ?></th><th></th></tr></table>
<div class="f"><label>Cómo pagó<select name="medio"><?php foreach (MEDIOS as $k => $n) echo '<option value="' . $k . '">' . $e($n) . '</option>'; ?></select></label><label>Fecha de pago<input type="date" name="fecha_pago" value="<?= date('Y-m-d') ?>" required></label>
<p class="mut full">Tildá los trabajos que querés incluir. Si no tildás ninguno, el ticket incluye todos los pendientes.</p>
<div class="row full"><button type="submit" formaction="ticket.php" formnovalidate>Armar ticket de cobro</button><button class="g">Marcar como cobrado y generar recibo</button></div></div></form><?php endif; ?>
<h2>Recibos</h2><table><tr><th>N°</th><th>Fecha</th><th>Período</th><th>Total</th><th></th></tr>
<?php foreach ($recs as $r): ?><tr><td><?= $e(nro($r)) ?></td><td class="mut"><?= $e(date('d/m/Y', strtotime($r['fecha']))) ?> · <?= $e(MEDIOS[$r['medio']] ?? $r['medio']) ?></td><td><?= $e($r['periodo']) ?></td><td><?= $e(peso((int)$r['total'])) ?></td><td><a class="btn g" href="recibo.php?id=<?= (int)$r['id'] ?>">Ver / imprimir</a></td></tr><?php endforeach; if (!$recs) echo '<tr><td colspan="5" class="mut">Todavía no hay recibos.</td></tr>'; ?></table>
<h2>Últimos cobrados</h2><table><?php foreach ($cob as $r): ?><tr><td class="mut"><?= $e(date('d/m/Y', strtotime((string)$r['fecha_pago']))) ?></td><td><?= $e($r['detalle'] ?: TIPOS[$r['tipo']]) ?></td><td><?= $e(peso((int)$r['cantidad'] * (int)$r['monto_unit'])) ?></td><td class="mut"><?= $e(MEDIOS[$r['medio']] ?? '') ?></td></tr><?php endforeach; if (!$cob) echo '<tr><td class="mut">Todavía no hay cobros registrados.</td></tr>'; ?></table>
<?php endif; ?><script src="admin.js?v=8"></script></body></html>
