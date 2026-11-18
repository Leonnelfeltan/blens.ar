<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
require __DIR__ . '/lib.php';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY');
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }
$pdo = blens_db(); $agN = agenda_pendientes($pdo); $consN = consultas_pendientes($pdo); $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tipoDe = fn(array $r): string => ((string)($r['tipo'] ?? 'video') === 'fotos') ? 'fotos' : 'video';
if (!$pdo->query("SHOW TABLES LIKE 'trabajos'")->fetch()) exit('Faltan las tablas de cobros. En el servidor corré: sudo mysql < /tmp/nuevo/blens-config/migracion-cobros.sql');
if (!$pdo->query("SHOW TABLES LIKE 'ajustes'")->fetch()) exit('Falta actualizar la base. En el servidor corré: sudo mysql < /tmp/nuevo/blens-config/migracion-4.sql');
if (!$pdo->query("SHOW COLUMNS FROM trabajos LIKE 'entrega_id'")->fetch()) exit('Falta actualizar la base. En el servidor corré: sudo mysql < /tmp/nuevo/blens-config/migracion-5.sql');
$mp = modo_prueba($pdo);
$ok = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']); $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['t'], (string)($_POST['t'] ?? ''))) { http_response_code(400); exit('Sesión vencida. Recargá la página.'); }
    $a = $_POST['a'] ?? ''; $c = (int)($_POST['cid'] ?? 0);
    try {
        if ($a === 'modo') { $pdo->prepare("INSERT INTO ajustes (clave,valor) VALUES ('modo_prueba',?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")->execute([$mp ? '0' : '1']); $_SESSION['flash'] = 'Modo prueba ' . ($mp ? 'desactivado: ahora lo que cargues se guarda de verdad.' : 'activado.'); header('Location: ' . ($c ? "cobros.php?c=$c" : 'cobros.php')); exit; }
        if ($a === 'borrar_pruebas') { $pdo->exec('DELETE FROM recibos WHERE prueba=1'); $pdo->exec('DELETE FROM trabajos WHERE prueba=1'); $pdo->exec('DELETE FROM gastos WHERE prueba=1'); $_SESSION['flash'] = 'Datos de prueba borrados (trabajos, recibos y gastos).'; header('Location: ' . ($c ? "cobros.php?c=$c" : 'cobros.php')); exit; }
        $s = $pdo->prepare('SELECT * FROM clientes WHERE id=?'); $s->execute([$c]); $cl = $s->fetch();
        if (!$cl) throw new RuntimeException('Cliente inexistente.');
        if ($a === 'trabajo') {
            $f = (string)($_POST['fecha'] ?? ''); if (!fecha_ok($f)) throw new RuntimeException('Fecha inválida.');
            $tipo = (string)($_POST['tipo'] ?? 'otro'); if (!isset(TIPOS[$tipo])) $tipo = 'otro';
            $det = mb_substr(trim((string)($_POST['detalle'] ?? '')), 0, 200); $cant = max(1, min(999, (int)($_POST['cantidad'] ?? 1))); $cero = isset($_POST['cero']); $mon = $cero ? 0.0 : round((float)str_replace(',', '.', trim((string)($_POST['monto'] ?? '0'))), 2);
            if (!$cero && $mon <= 0) throw new RuntimeException('El precio unitario tiene que ser mayor a cero.');
            $est = $cero || ($_POST['estado'] ?? 'pendiente') === 'cobrado' ? 'cobrado' : 'pendiente'; $med = null; $fpg = null;
            if ($est === 'cobrado') { $med = $cero ? 'otro' : (string)($_POST['medio'] ?? ''); if (!isset(MEDIOS[$med])) { if ($cero) $med = 'otro'; else throw new RuntimeException('Elegí cómo te pagaron.'); } $fpg = (string)($_POST['fecha_pago'] ?? '') ?: $f; if (!fecha_ok($fpg)) throw new RuntimeException('Fecha de pago inválida.'); }
            $ei = (int)($_POST['entrega_id'] ?? 0) ?: null; $pi = (int)($_POST['proyecto_id'] ?? 0) ?: null;
            $comp = comprobante_subir();
            $pdo->prepare('INSERT INTO trabajos (cliente_id,fecha,tipo,detalle,cantidad,monto_unit,prueba,estado,medio,fecha_pago,entrega_id,proyecto_id,comprobante) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$c, $f, $tipo, $det, $cant, $mon, $mp, $est, $med, $fpg, $ei, $pi, $comp]);
            $_SESSION['flash'] = 'Trabajo registrado.';
        } elseif ($a === 'cobro_rapido') {
            $f = (string)($_POST['fecha'] ?? ''); if (!fecha_ok($f)) $f = date('Y-m-d');
            $det = mb_substr(trim((string)($_POST['detalle'] ?? $_POST['concepto'] ?? '')), 0, 200); $mon = round((float)str_replace(',', '.', trim((string)($_POST['monto'] ?? '0'))), 2);
            if ($det === '') throw new RuntimeException('Escribí el concepto del cobro.');
            if ($mon <= 0) throw new RuntimeException('El monto tiene que ser mayor a cero.');
            $est = ($_POST['estado'] ?? 'pendiente') === 'cobrado' ? 'cobrado' : 'pendiente'; $med = null; $fpg = null;
            if ($est === 'cobrado') { $med = (string)($_POST['medio'] ?? 'transferencia'); if (!isset(MEDIOS[$med])) $med = 'transferencia'; $fpg = (string)($_POST['fecha_pago'] ?? '') ?: $f; if (!fecha_ok($fpg)) throw new RuntimeException('Fecha de pago inválida.'); }
            $comp = comprobante_subir();
            $pdo->prepare('INSERT INTO trabajos (cliente_id,fecha,tipo,detalle,cantidad,monto_unit,prueba,estado,medio,fecha_pago,comprobante) VALUES (?,?,?,?,1,?,?,?,?,?,?)')->execute([$c, $f, 'otro', $det, $mon, $mp, $est, $med, $fpg, $comp]);
            if ($est === 'cobrado' && isset($_POST['sumar_saldo'])) {
                set_ajuste($pdo, 'saldo_cuenta', (string)(saldo_cuenta($pdo) + $mon));
                set_ajuste($pdo, 'tna_ultima_fecha', date('Y-m-d'));   // el importe nuevo empieza a rendir desde hoy
                $_SESSION['flash'] = 'Cobro registrado y suma ' . peso($mon) . ' al saldo en cuenta.';
            } else $_SESSION['flash'] = 'Cobro registrado.';
        } elseif ($a === 'marcar_cobrado') {
            $id = (int)($_POST['id'] ?? 0);
            $s = $pdo->prepare("SELECT * FROM trabajos WHERE id=? AND cliente_id=? AND estado='pendiente' AND recibo_id IS NULL AND prueba=?"); $s->execute([$id, $c, $mp]);
            if (!$tr = $s->fetch()) throw new RuntimeException('Ese trabajo ya no está pendiente.');
            $med = (string)($_POST['medio'] ?? 'transferencia'); if (!isset(MEDIOS[$med])) $med = 'transferencia';
            $fpg = (string)($_POST['fecha_pago'] ?? '') ?: date('Y-m-d'); if (!fecha_ok($fpg)) throw new RuntimeException('Fecha de pago inválida.');
            $comp = comprobante_subir();
            $pdo->prepare("UPDATE trabajos SET estado='cobrado',medio=?,fecha_pago=?,comprobante=COALESCE(?,comprobante) WHERE id=?")->execute([$med, $fpg, $comp, $id]);
            if ($comp) comprobante_borrar((string)($tr['comprobante'] ?? ''));
            $mon = (float)$tr['cantidad'] * (float)$tr['monto_unit'];
            if (isset($_POST['sumar_saldo'])) {
                set_ajuste($pdo, 'saldo_cuenta', (string)(saldo_cuenta($pdo) + $mon));
                set_ajuste($pdo, 'tna_ultima_fecha', date('Y-m-d'));
                $_SESSION['flash'] = 'Marcado como cobrado. Se suman ' . peso($mon) . ' al saldo en cuenta.';
            } else $_SESSION['flash'] = 'Marcado como cobrado.';
        } elseif ($a === 'cobro_cfg') {
            $tel = mb_substr(trim((string)($_POST['telefono'] ?? '')), 0, 30);
            $pdo->prepare('UPDATE clientes SET telefono=? WHERE id=?')->execute([$tel, $c]);
            set_ajuste($pdo, 'alias_cobro', mb_substr(trim((string)($_POST['alias_cobro'] ?? '')), 0, 120));
            set_ajuste($pdo, 'cbu_cobro', mb_substr(trim((string)($_POST['cbu_cobro'] ?? '')), 0, 60));
            $_SESSION['flash'] = 'Datos de cobro guardados.';
        } elseif ($a === 'del_trabajo') {
            $tid = (int)($_POST['id'] ?? 0);
            $s = $pdo->prepare('SELECT comprobante FROM trabajos WHERE id=? AND cliente_id=? AND recibo_id IS NULL AND prueba=?'); $s->execute([$tid, $c, $mp]); $comp = $s->fetchColumn();
            $pdo->prepare('DELETE FROM trabajos WHERE id=? AND cliente_id=? AND recibo_id IS NULL AND prueba=?')->execute([$tid, $c, $mp]);
            if ($comp) comprobante_borrar((string)$comp);
            $_SESSION['flash'] = 'Trabajo quitado.';
        } elseif ($a === 'cobrar') {
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
            if (!$ids) throw new RuntimeException('Elegí al menos un trabajo pendiente.');
            $medio = (string)($_POST['medio'] ?? ''); if (!isset(MEDIOS[$medio])) throw new RuntimeException('Elegí cómo te pagaron.');
            $fp = (string)($_POST['fecha_pago'] ?? ''); if (!fecha_ok($fp)) throw new RuntimeException('Fecha de pago inválida.');
            $q = $pdo->prepare("SELECT * FROM trabajos WHERE cliente_id=? AND estado='pendiente' AND prueba=? AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')'); $q->execute(array_merge([$c, $mp], $ids)); $rows = $q->fetchAll();
            if (!$rows) throw new RuntimeException('Esos trabajos ya no están pendientes.');
            $tot = 0; $ms = []; foreach ($rows as $r) { $tot += (float)$r['cantidad'] * (float)$r['monto_unit']; $ms[mes($r['fecha'])] = 1; }
            $tot = round($tot, 2);
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
$vc = isset($_GET['c']) ? (int)$_GET['c'] : (isset($_GET['cliente']) ? (int)$_GET['cliente'] : null); $T = $e($_SESSION['t']); $cl = null;
if ($vc) { $s = $pdo->prepare('SELECT * FROM clientes WHERE id=?'); $s->execute([$vc]); $cl = $s->fetch() ?: null; }
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Cobros | blens.ar</title><link rel="icon" href="../assets/favicon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="../assets/apple-touch-icon.png"><link rel="stylesheet" href="admin.css?v=27"></head><body>
<svg class="sprite" aria-hidden="true"><symbol id="mark" viewBox="0 0 130 130"><polygon points="0,0 70,0 100,30 100,100 0,100" fill="#F5F1EA"/><polygon points="60,45 125,45 125,125 45,125 45,60" fill="#F5F1EA"/><polygon points="60,45 100,45 100,64 86,72 100,80 100,100 45,100 45,60" fill="#FF5A1F"/></symbol></svg>
<div class="top"><a class="brand" href="./"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span></a><nav><a href="./">Inicio</a><a href="consultas.php">Consultas<?php if ($consN > 0): ?><span class="nav-badge soft"><?= $consN ?></span><?php endif; ?></a><a href="clientes.php">Clientes</a><a href="cobros.php">Cobros</a><a href="agenda.php">Agenda<?php if ($agN > 0): ?><span class="nav-badge"><?= $agN ?></span><?php endif; ?></a><a href="presupuestos.php">Presupuestos</a></nav>
<form method="post" action="./"><input type="hidden" name="a" value="logout"><input type="hidden" name="t" value="<?= $T ?>"><button class="g">Salir</button></form></div>
<?php if ($ok) echo '<p class="ok">' . $e($ok) . '</p>'; if ($err) echo '<p class="err">' . $e($err) . '</p>'; ?>
<?php if (!$cl): $ql = $pdo->prepare("SELECT c.id,c.empresa,c.nombre, COALESCE(SUM(CASE WHEN t.estado='pendiente' THEN t.cantidad*t.monto_unit END),0) AS pend, COALESCE(SUM(CASE WHEN t.estado='cobrado' THEN t.cantidad*t.monto_unit END),0) AS cobrado, COUNT(t.id) AS ntrab FROM clientes c LEFT JOIN trabajos t ON t.cliente_id=c.id AND t.prueba=? GROUP BY c.id ORDER BY c.empresa,c.nombre"); $ql->execute([$mp]); $rows = $ql->fetchAll(); $tp = $tc = 0.0; ?>
<h1>Cobros</h1><table class="cards"><tr><th>Cliente</th><th>Trabajos</th><th>Pendiente</th><th>Cobrado</th><th></th></tr>
<?php foreach ($rows as $r): $tp += (float)$r['pend']; $tc += (float)$r['cobrado']; ?><tr><td data-label="Cliente"><?= $e($r['empresa'] ?: $r['nombre']) ?></td><td data-label="Trabajos"><?= (int)$r['ntrab'] ?></td><td data-label="Pendiente"><?= $e(peso((float)$r['pend'])) ?></td><td data-label="Cobrado"><?= $e(peso((float)$r['cobrado'])) ?></td><td data-label=""><a class="btn g" href="?c=<?= (int)$r['id'] ?>">Abrir</a></td></tr><?php endforeach; if (!$rows) echo '<tr><td colspan="5" class="mut">Primero agregá clientes.</td></tr>'; ?>
<tr><th>Total</th><th></th><th><?= $e(peso($tp)) ?></th><th><?= $e(peso($tc)) ?></th><th></th></tr></table>
<?php else:
$s = $pdo->prepare('SELECT * FROM recibos WHERE cliente_id=? AND prueba=? ORDER BY id DESC LIMIT 20'); $s->execute([$cl['id'], $mp]); $recs = $s->fetchAll();
$tipoOpts = function (string $sel = '') use ($cl): string {
    $op = fn(string $v, string $n, int $m) => '<option value="' . $v . '" data-m="' . $m . '"' . ($sel === $v ? ' selected' : '') . '>' . $n . '</option>';
    return $op('video', 'Video', (int)$cl['tarifa_video']) . $op('foto', 'Foto / Pack de fotos', (int)$cl['tarifa_foto']) . $op('otro', 'Otro', 0);
};
$det = [];
$q = $pdo->prepare('SELECT en.id,en.titulo,en.creado,en.proyecto_id,en.tipo FROM entregas en WHERE en.cliente_id=? AND en.proyecto_id IS NULL AND NOT EXISTS (SELECT 1 FROM trabajos t WHERE t.entrega_id = en.id) ORDER BY en.id DESC'); $q->execute([$cl['id']]);
foreach ($q as $x) $det[] = ['k' => 'entrega', 'id' => (int)$x['id'], 't' => $x['titulo'], 'f' => substr($x['creado'], 0, 10), 'tipo' => $tipoDe($x)];
$q = $pdo->prepare("SELECT p.id,p.titulo,p.creado FROM proyectos p WHERE p.cliente_id=? AND p.categoria <> 'showreel' AND NOT EXISTS (SELECT 1 FROM trabajos t WHERE t.proyecto_id = p.id) AND NOT EXISTS (SELECT 1 FROM entregas en JOIN trabajos t ON t.entrega_id = en.id WHERE en.proyecto_id = p.id AND en.cliente_id = ?) ORDER BY p.id DESC"); $q->execute([$cl['id'], $cl['id']]);
foreach ($q as $x) $det[] = ['k' => 'proyecto', 'id' => (int)$x['id'], 't' => $x['titulo'], 'f' => substr($x['creado'], 0, 10), 'tipo' => 'video'];
$alias = ajuste($pdo, 'alias_cobro'); $cbu = ajuste($pdo, 'cbu_cobro');
$s = $pdo->prepare('SELECT * FROM trabajos WHERE cliente_id=? AND prueba=? ORDER BY fecha DESC,id DESC LIMIT 150'); $s->execute([$cl['id'], $mp]); $movs = $s->fetchAll();
$sk = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN estado='cobrado' THEN cantidad*monto_unit END),0) AS cob, COALESCE(SUM(CASE WHEN estado='pendiente' THEN cantidad*monto_unit END),0) AS pend, COUNT(*) AS n FROM trabajos WHERE cliente_id=? AND prueba=?");
$sk->execute([$cl['id'], $mp]); $k = $sk->fetch();
$tcob = round((float)$k['cob'], 2); $tpend = round((float)$k['pend'], 2); $ntrab = (int)$k['n'];
$qp = $pdo->prepare("SELECT * FROM trabajos WHERE cliente_id=? AND prueba=? AND estado='pendiente' ORDER BY fecha,id"); $qp->execute([$cl['id'], $mp]); $pend = $qp->fetchAll();
$resumen = resumen_cobro($cl, $pend, $tpend, $alias, $cbu);
$waCli = trim((string)$cl['telefono']) !== '' ? wa_url((string)$cl['telefono'], $resumen) : null;
?>
<?= !empty($cl['logo']) ? '<img class="clogo" src="../media/logos/' . $e($cl['logo']) . '" alt="">' : '' ?>
<div class="clh"><h1><?= $e($cl['empresa'] ? $cl['empresa'] . ' · ' . $cl['nombre'] : $cl['nombre']) ?></h1><div class="row"><a class="btn g" href="cobros.php">&larr; Todos</a><a class="btn g" href="clientes.php?c=<?= (int)$cl['id'] ?>">Datos y tarifas</a><button type="button" class="btn" data-toggle="#nuevo">+ Nuevo movimiento</button><button type="button" class="btn g" data-open-detalle="#cobrar-varios">Cobrar varios / Recibo</button></div></div>
<div class="kpis">
  <div class="kpi"><span class="k">Cobrado total</span><b><?= $e(peso($tcob)) ?></b><span class="mut">histórico del cliente</span></div>
  <div class="kpi"><span class="k">Pendiente a cobrar</span><b class="<?= $tpend > 0 ? 'warn' : '' ?>"><?= $e(peso($tpend)) ?></b><span class="mut"><?= count($pend) ?> trabajo(s) sin cobrar</span></div>
  <div class="kpi"><span class="k">Trabajos registrados</span><b><?= (int)$ntrab ?></b><span class="mut">en total</span></div>
</div>
<div class="wbar">
  <?php if ($waCli): ?><a class="btn" href="<?= $e($waCli) ?>" target="_blank" rel="noopener">Enviar resumen por WhatsApp</a>
  <?php else: ?><span class="mut">Cargá el teléfono del cliente para poder mandarle el resumen por WhatsApp.</span><?php endif; ?>
  <details class="prev"><summary>Datos de cobro (alias, CBU y teléfono)</summary>
    <form class="f" method="post"><input type="hidden" name="a" value="cobro_cfg"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>">
    <label><span class="lbl">WhatsApp del cliente <span class="opt">(opcional)</span></span><input name="telefono" value="<?= $e($cl['telefono'] ?? '') ?>" maxlength="30"></label>
    <label><span class="lbl">Alias para cobrar <span class="opt">(opcional)</span></span><input name="alias_cobro" value="<?= $e($alias) ?>" maxlength="120" placeholder="alias.blens.ar"></label>
    <label><span class="lbl">CBU <span class="opt">(opcional)</span></span><input name="cbu_cobro" value="<?= $e($cbu) ?>" maxlength="60"></label>
    <div class="row full"><button>Guardar datos de cobro</button></div></form>
  </details>
  <?php if ($waCli): ?><details class="prev"><summary>Ver el mensaje que se va a mandar</summary><pre class="msj"><?= $e($resumen) ?></pre></details><?php endif; ?>
</div>
<?php if ($det): ?>
<div class="det-aviso">
  <div class="det-h"><span class="b">DETECTADOS</span><b><?= count($det) ?> pendiente(s) de registrar</b><span class="mut">Entregas y proyectos listos para cobrar</span></div>
  <div class="det-items">
  <?php foreach ($det as $x): $esFotos = $x['k'] === 'entrega' && $x['tipo'] === 'fotos'; $tipoSel = $esFotos ? 'foto' : 'video'; $montoSug = (float)($esFotos ? $cl['tarifa_foto'] : $cl['tarifa_video']); ?>
    <form method="post" class="det-one"><input type="hidden" name="a" value="trabajo"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>"><input type="hidden" name="fecha" value="<?= $e($x['f']) ?>"><input type="hidden" name="detalle" value="<?= $e($x['t']) ?>"><input type="hidden" name="cantidad" value="1"><input type="hidden" name="<?= $x['k'] === 'entrega' ? 'entrega_id' : 'proyecto_id' ?>" value="<?= (int)$x['id'] ?>"><input type="hidden" name="tipo" value="<?= $tipoSel ?>"><input type="hidden" name="monto" value="<?= $montoSug > 0 ? $e((string)$montoSug) : '0' ?>"><input type="hidden" name="estado" value="pendiente">
      <span class="b tipo-<?= $esFotos ? 'fotos' : ($x['k'] === 'entrega' ? 'video' : '') ?>"><?= $x['k'] === 'entrega' ? ($esFotos ? 'FOTOS' : 'VIDEO') : 'PROYECTO' ?></span>
      <span class="det-txt"><b><?= $e($x['t']) ?></b><small class="mut"><?= $e(date('d/m/Y', strtotime($x['f']))) ?></small></span>
      <?php if ($montoSug > 0): ?><button title="Registrar como trabajo pendiente">+ Registrar</button><?php endif; ?>
      <button class="g" name="cero" value="1" formnovalidate title="Registrar en $0 (histórico)"><?= $montoSug > 0 ? '$0' : 'Registrar' ?></button>
    </form>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<details class="accd mov-form" id="nuevo">
<summary>Nuevo movimiento</summary>
<form class="f" method="post" enctype="multipart/form-data"><input type="hidden" name="a" value="cobro_rapido" data-mov-a><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>">
<label class="full"><span class="lbl">Qué querés cargar</span><select name="modalidad" data-mov-mode><option value="cobro">Cobro directo (concepto y monto total)</option><option value="trabajo">Trabajo por cantidad y tarifa</option></select></label>
<label><span class="lbl">Fecha</span><input type="date" name="fecha" value="<?= date('Y-m-d') ?>" required></label>
<label class="full"><span class="lbl">Detalle / concepto <span class="req">* obligatorio</span></span><input name="detalle" maxlength="200" placeholder="Reel de producto" required></label>
<div class="full" data-mov-only="trabajo" hidden>
  <label><span class="lbl">Tipo <span class="req">* obligatorio</span></span><select name="tipo" class="tipo-sel"><?= $tipoOpts() ?></select></label>
  <label><span class="lbl">Cantidad <span class="req">* obligatorio</span></span><input type="number" name="cantidad" value="1" min="1" max="999"></label>
</div>
<label><span class="lbl" data-mov-label>Monto total ($) <span class="req">* obligatorio</span></span><input name="monto" class="monto-in" inputmode="numeric" placeholder="0" required></label>
<label><span class="lbl">Estado <span class="req">* obligatorio</span></span><select name="estado" data-estado><option value="pendiente">Pendiente de cobro</option><option value="cobrado">Ya cobrado</option></select></label>
<label><span class="lbl">Medio</span><select name="medio"><?php foreach (MEDIOS as $k => $n) echo '<option value="' . $k . '">' . $e($n) . '</option>'; ?></select></label>
<label><span class="lbl">Si ya cobraste: fecha de cobro</span><input type="date" name="fecha_pago"></label>
<label class="full"><span class="lbl">Comprobante <span class="opt">(opcional, máximo 8 MB)</span></span><input type="file" name="comprobante" accept=".jpg,.jpeg,.png,.webp,.pdf"></label>
<label class="chk-inline full"><input type="checkbox" name="sumar_saldo" value="1" checked data-sumar> Sumar al saldo en cuenta</label>
<div class="row full"><button data-mov-submit>Registrar cobro</button><button class="g" name="cero" value="1" formnovalidate data-mov-cero hidden>Registrar en $0 (histórico)</button><button type="button" class="g" data-cerrar>Cerrar</button></div>
</form>
</details>
<h2>Movimientos</h2>
<?php if (!$movs): ?><p class="mut">Todavía no hay movimientos para este cliente.</p><?php else: ?>
<form id="fdel" method="post"><input type="hidden" name="a" value="del_trabajo"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>"></form>
<div class="mvs">
<?php foreach ($movs as $r): $sub = (float)$r['cantidad'] * (float)$r['monto_unit']; $cob = $r['estado'] === 'cobrado'; $ff = (string)($cob ? ($r['fecha_pago'] ?: $r['fecha']) : $r['fecha']); $mt = (string)$r['tipo']; ?>
<div class="mv">
  <div class="mv-l">
    <span class="badge <?= $cob ? 'ok' : 'pend' ?>"><?= $cob ? 'Cobrado' : 'Pendiente' ?></span>
    <?php if ($mt === 'video' || $mt === 'foto'): ?><span class="b tipo-<?= $mt === 'foto' ? 'fotos' : 'video' ?>"><?= $mt === 'foto' ? 'FOTOS' : 'VIDEO' ?></span><?php endif; ?>
    <b><?= $e($r['detalle'] ?: (TIPOS[$r['tipo']] ?? $r['tipo'])) ?></b>
    <span class="mut"><?= $e(date('d/m/Y', strtotime($ff))) ?><?= $r['medio'] ? ' · ' . $e(MEDIOS[$r['medio']] ?? (string)$r['medio']) : '' ?><?= (int)$r['cantidad'] > 1 ? ' · ' . (int)$r['cantidad'] . ' × ' . $e(peso((float)$r['monto_unit'])) : '' ?></span>
  </div>
  <div class="mv-r">
    <b class="<?= $cob ? 'ok-t' : 'warn-t' ?>"><?= $e(peso($sub)) ?></b>
    <?php if (!empty($r['comprobante'])) echo comprobante_boton('cobro', (int)$r['id']); ?>
    <?php if (!$cob): ?>
    <form class="mv-a" method="post" enctype="multipart/form-data"><input type="hidden" name="a" value="marcar_cobrado"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <div class="mv-actions">
        <select name="medio" aria-label="Medio de pago"><?php foreach (MEDIOS as $k => $n) echo '<option value="' . $k . '">' . $e($n) . '</option>'; ?></select>
        <label class="chk" title="Sumar el importe al saldo en cuenta"><input type="checkbox" name="sumar_saldo" value="1" checked> +saldo</label>
        <label class="up-min" title="Adjuntar comprobante (máximo 8 MB)"><input type="file" name="comprobante" accept=".jpg,.jpeg,.png,.webp,.pdf"> Comprobante</label>
        <button class="g">Marcar cobrado</button>
        <button class="g" form="fdel" name="id" value="<?= (int)$r['id'] ?>" title="Quitar este movimiento">Quitar</button>
      </div>
    </form>
    <?php elseif (!empty($r['recibo_id'])): ?><a class="btn g" href="recibo.php?id=<?= (int)$r['recibo_id'] ?>">Ver recibo</a>
    <?php else: ?><span class="mut">Cobrado sin recibo</span><?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div><?php endif; ?>
<details class="accd" id="cobrar-varios"><summary>Cobrar varios y generar recibo</summary>
<?php if (!$pend): ?><p class="mut">No hay trabajos pendientes.</p><?php else: ?>
<form method="post"><input type="hidden" name="a" value="cobrar"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>">
<p class="mut"><label class="chk"><input type="checkbox" id="todos" aria-label="Elegir todos"> Elegir todos los pendientes</label></p>
<table class="cards"><tr><th></th><th>Fecha</th><th>Detalle</th><th>Subtotal</th><th></th></tr>
<?php foreach ($pend as $r): $sub = (float)$r['cantidad'] * (float)$r['monto_unit']; ?><tr><td data-label=""><input class="sel" type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>"></td><td class="mut" data-label="Fecha"><?= $e(date('d/m/Y', strtotime($r['fecha']))) ?></td><td data-label="Detalle"><?php if ((string)$r['tipo'] === 'video' || (string)$r['tipo'] === 'foto'): ?><span class="b tipo-<?= (string)$r['tipo'] === 'foto' ? 'fotos' : 'video' ?>"><?= (string)$r['tipo'] === 'foto' ? 'FOTOS' : 'VIDEO' ?></span> <?php endif; ?><?= $e($r['detalle'] ?: TIPOS[$r['tipo']]) ?><br><span class="mut"><?= (int)$r['cantidad'] ?> × <?= $e(peso((float)$r['monto_unit'])) ?></span></td><td data-label="Subtotal"><?= $e(peso($sub)) ?></td><td data-label=""><button class="g" form="fdel" name="id" value="<?= (int)$r['id'] ?>">Quitar</button></td></tr><?php endforeach; ?>
<tr><th></th><th></th><th>Total pendiente</th><th><?= $e(peso($tpend)) ?></th><th></th></tr></table>
<div class="f"><label><span class="lbl">Cómo pagó <span class="req">* obligatorio</span></span><select name="medio"><?php foreach (MEDIOS as $k => $n) echo '<option value="' . $k . '">' . $e($n) . '</option>'; ?></select></label><label><span class="lbl">Fecha de pago <span class="req">* obligatorio</span></span><input type="date" name="fecha_pago" value="<?= date('Y-m-d') ?>" required></label>
<p class="mut full">Tildá los trabajos que querés incluir. Si no tildás ninguno, el ticket incluye todos los pendientes.</p>
<div class="row full"><button type="submit" formaction="ticket.php" formnovalidate>Armar ticket de cobro</button><button class="g">Marcar como cobrado y generar recibo</button></div></div></form><?php endif; ?>
</details>
<h2>Recibos</h2><table class="cards"><tr><th>N°</th><th>Fecha</th><th>Período</th><th>Total</th><th></th></tr>
<?php foreach ($recs as $r): ?><tr><td data-label="N°"><?= $e(nro($r)) ?></td><td class="mut" data-label="Fecha"><?= $e(date('d/m/Y', strtotime($r['fecha']))) ?> · <?= $e(MEDIOS[$r['medio']] ?? $r['medio']) ?></td><td data-label="Período"><?= $e($r['periodo']) ?></td><td data-label="Total"><?= $e(peso((float)$r['total'])) ?></td><td data-label=""><a class="btn g" href="recibo.php?id=<?= (int)$r['id'] ?>">Ver / imprimir</a></td></tr><?php endforeach; if (!$recs) echo '<tr><td colspan="5" class="mut">Todavía no hay recibos.</td></tr>'; ?></table>
<?php endif; ?><script src="admin.js?v=23"></script></body></html>
