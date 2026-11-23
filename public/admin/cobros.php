<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
require __DIR__ . '/lib.php';
session_start();
header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY');
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }
$pdo = blens_db(); $agN = agenda_pendientes($pdo); $consN = consultas_pendientes($pdo); $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$T = $e($_SESSION['t']);
$vc = isset($_GET['c']) ? (int)$_GET['c'] : (isset($_GET['cliente']) ? (int)$_GET['cliente'] : null);
$tipoDe = fn(array $r): string => ((string)($r['tipo'] ?? 'video') === 'fotos') ? 'fotos' : 'video';
if (!$pdo->query("SHOW TABLES LIKE 'trabajos'")->fetch()) exit('Faltan las tablas de cobros. Recargá el admin para aplicar las migraciones automáticas.');
if (!$pdo->query("SHOW TABLES LIKE 'ajustes'")->fetch()) exit('Falta actualizar la base. Recargá el admin para aplicar las migraciones automáticas.');
if (!$pdo->query("SHOW COLUMNS FROM trabajos LIKE 'entrega_id'")->fetch()) exit('Falta actualizar la base. Recargá el admin para aplicar las migraciones automáticas.');
$mp = modo_prueba($pdo);
$ym = fin_mes_valido((string)($_GET['mes'] ?? ''));
if (isset($_GET['csv'])) {
    $mov = fin_movimientos_mes($pdo, $ym);
    header('Content-Type: text/csv; charset=utf-8'); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="finanzas-' . $ym . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Fecha', 'Tipo', 'Concepto', 'Cliente / Origen', 'Línea / Categoría', 'Medio de pago', 'Estado', 'Monto', 'Saldo pendiente'], ';');
    foreach ($mov as $r) fputcsv($out, array_map('fin_csv_celda', [$r['fecha'], $r['tipo'], $r['concepto'], $r['cliente'], $r['categoria'], $r['medio'], $r['estado'], $r['monto'], $r['saldo']]), ';');
    fclose($out); exit;
}
$fin = fin_resumen($pdo, $ym); $evo = fin_evolucion($pdo, 6, $ym); $pendGlobal = fin_pendientes($pdo);
$MESES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$mesTxt = ($MESES[(int)substr($ym, 5, 2)] ?? '') . ' ' . substr($ym, 0, 4);
$ymPrev = date('Y-m', strtotime($ym . '-01 -1 month')); $ymNext = date('Y-m', strtotime($ym . '-01 +1 month'));
$urlMes = fn(string $m): string => 'cobros.php?mes=' . $m . ($vc ? '&c=' . (int)$vc : '');
$uniTxt = fn(array $u): string => 'Audiovisual ' . peso((float)($u['audiovisual'] ?? 0)) . ' · Web ' . peso((float)($u['web'] ?? 0));
$medTxt = fn(array $m): string => ($m = array_filter($m, fn($v, $k) => $k !== '', ARRAY_FILTER_USE_BOTH)) ? implode(' · ', array_map(fn($k, $v) => $k . ' ' . peso((float)$v), array_slice(array_keys($m), 0, 2), array_slice(array_values($m), 0, 2))) : 'sin medio informado';
$catTxt = fn(array $c): string => $c ? implode(' · ', array_map(fn($k, $v) => $k . ' ' . peso((float)$v), array_slice(array_keys($c), 0, 3), array_slice(array_values($c), 0, 3))) : 'sin gastos cargados';
$chartMax = 1.0; foreach ($evo as $m) $chartMax = max($chartMax, $m['ingresos'], $m['gastos'], abs($m['neto']));
$barClase = fn(float $v): string => 'h-' . max(3, min(100, (int)round(abs($v) / $chartMax * 20) * 5));
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
            $pago = fin_datos_pago_post($_POST, fin_num($mon * $cant), $cero);
            $ei = (int)($_POST['entrega_id'] ?? 0) ?: null; $pi = (int)($_POST['proyecto_id'] ?? 0) ?: null;
            $comp = comprobante_subir();
            $pdo->prepare('INSERT INTO trabajos (cliente_id,fecha,tipo,detalle,cantidad,monto_unit,prueba,estado,medio,fecha_pago,unidad_negocio,medio_pago,monto_senia,entrega_id,proyecto_id,comprobante) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                $c, $f, $tipo, $det, $cant, $mon, $mp, $pago['estado'], $pago['medio'], $pago['fecha_pago'],
                fin_unidad((string)($_POST['unidad_negocio'] ?? 'audiovisual')), $pago['medio_pago'], $pago['abonado'], $ei, $pi, $comp,
            ]);
            $_SESSION['flash'] = $pago['estado_pago'] === 'seniado' ? 'Trabajo registrado con seña; el saldo quedó pendiente.' : 'Trabajo registrado.';
        } elseif ($a === 'cobro_rapido') {
            $f = (string)($_POST['fecha'] ?? ''); if (!fecha_ok($f)) $f = date('Y-m-d');
            $det = mb_substr(trim((string)($_POST['detalle'] ?? $_POST['concepto'] ?? '')), 0, 200); $mon = round((float)str_replace(',', '.', trim((string)($_POST['monto'] ?? '0'))), 2);
            if ($det === '') throw new RuntimeException('Escribí el concepto del cobro.');
            if ($mon <= 0) throw new RuntimeException('El monto tiene que ser mayor a cero.');
            $_POST['fecha_pago'] = (string)($_POST['fecha_pago'] ?? '') ?: $f;
            $pago = fin_datos_pago_post($_POST, $mon);
            $comp = comprobante_subir();
            $pdo->prepare('INSERT INTO trabajos (cliente_id,fecha,tipo,detalle,cantidad,monto_unit,prueba,estado,medio,fecha_pago,unidad_negocio,medio_pago,monto_senia,comprobante) VALUES (?,?,?,?,1,?,?,?,?,?,?,?,?,?)')->execute([
                $c, $f, 'otro', $det, $mon, $mp, $pago['estado'], $pago['medio'], $pago['fecha_pago'],
                fin_unidad((string)($_POST['unidad_negocio'] ?? 'audiovisual')), $pago['medio_pago'], $pago['abonado'], $comp,
            ]);
            if ($pago['abonado'] > 0 && isset($_POST['sumar_saldo'])) {
                set_ajuste($pdo, 'saldo_cuenta', (string)(saldo_cuenta($pdo) + $pago['abonado']));
                set_ajuste($pdo, 'tna_ultima_fecha', date('Y-m-d'));
                $_SESSION['flash'] = 'Cobro registrado y suma ' . peso($pago['abonado']) . ' al saldo en cuenta.';
            } else $_SESSION['flash'] = $pago['estado_pago'] === 'seniado' ? 'Seña registrada; el saldo quedó pendiente.' : 'Cobro registrado.';
        } elseif ($a === 'marcar_cobrado') {
            $id = (int)($_POST['id'] ?? 0);
            $s = $pdo->prepare("SELECT * FROM trabajos WHERE id=? AND cliente_id=? AND estado='pendiente' AND recibo_id IS NULL AND prueba=?"); $s->execute([$id, $c, $mp]);
            if (!$tr = $s->fetch()) throw new RuntimeException('Ese trabajo ya no está pendiente.');
            $resto = fin_saldo($tr);
            $_POST['estado_pago'] = 'cobrado'; $_POST['fecha_pago'] = (string)($_POST['fecha_pago'] ?? '') ?: date('Y-m-d');
            $pago = fin_datos_pago_post($_POST, fin_total($tr));
            $comp = comprobante_subir();
            $pdo->prepare("UPDATE trabajos SET estado='cobrado',medio=?,medio_pago=?,fecha_pago=?,monto_senia=?,comprobante=COALESCE(?,comprobante) WHERE id=?")->execute([$pago['medio'], $pago['medio_pago'], $pago['fecha_pago'], $pago['abonado'], $comp, $id]);
            if ($comp) comprobante_borrar((string)($tr['comprobante'] ?? ''));
            if (isset($_POST['sumar_saldo']) && $resto > 0) {
                set_ajuste($pdo, 'saldo_cuenta', (string)(saldo_cuenta($pdo) + $resto));
                set_ajuste($pdo, 'tna_ultima_fecha', date('Y-m-d'));
                $_SESSION['flash'] = 'Marcado como cobrado. Se suman ' . peso($resto) . ' al saldo en cuenta.';
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
        } elseif ($a === 'editar_trabajo') {
            $tid = (int)($_POST['id'] ?? 0);
            $s = $pdo->prepare('SELECT * FROM trabajos WHERE id=? AND cliente_id=? AND recibo_id IS NULL AND prueba=?'); $s->execute([$tid, $c, $mp]); $tr = $s->fetch();
            if (!$tr) throw new RuntimeException('Trabajo no encontrado.');
            $pago = fin_datos_pago_post($_POST, fin_total($tr));
            $pdo->prepare('UPDATE trabajos SET unidad_negocio=?, estado=?, medio=?, medio_pago=?, monto_senia=?, fecha_pago=? WHERE id=?')->execute([
                fin_unidad((string)($_POST['unidad_negocio'] ?? '')),
                $pago['estado'],
                $pago['estado'] === 'pendiente' ? null : $pago['medio'],
                $pago['estado'] === 'pendiente' ? null : $pago['medio_pago'],
                $pago['abonado'],
                $pago['estado'] === 'pendiente' ? null : $pago['fecha_pago'],
                $tid
            ]);
            $_SESSION['flash'] = 'Trabajo actualizado.';
        } elseif ($a === 'cobrar') {
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
            if (!$ids) throw new RuntimeException('Elegí al menos un trabajo pendiente.');
            $q = $pdo->prepare("SELECT * FROM trabajos WHERE cliente_id=? AND estado='pendiente' AND prueba=? AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')'); $q->execute(array_merge([$c, $mp], $ids)); $rows = $q->fetchAll();
            if (!$rows) throw new RuntimeException('Esos trabajos ya no están pendientes.');
            $tot = 0.0; $ms = []; foreach ($rows as $r) { $tot += fin_saldo($r); $ms[mes($r['fecha'])] = 1; }
            $tot = fin_num($tot);
            if ($tot <= 0) throw new RuntimeException('Esos trabajos ya no tienen saldo pendiente.');
            $_POST['estado_pago'] = 'cobrado'; $_POST['fecha_pago'] = (string)($_POST['fecha_pago'] ?? '') ?: date('Y-m-d');
            $pago = fin_datos_pago_post($_POST, $tot);
            $pdo->beginTransaction();
            $pr = $mp; foreach ($rows as $r) if (!empty($r['prueba'])) $pr = 1;
            $nq = $pdo->prepare('SELECT COALESCE(MAX(numero),0)+1 FROM recibos WHERE prueba=?'); $nq->execute([$pr]); $num = (int)$nq->fetchColumn();
            $pdo->prepare('INSERT INTO recibos (cliente_id,periodo,total,medio,fecha,numero,prueba) VALUES (?,?,?,?,?,?,?)')->execute([$c, count($ms) === 1 ? array_key_first($ms) : 'Varios períodos', $tot, $pago['medio'], $pago['fecha_pago'], $num, $pr]); $rid = (int)$pdo->lastInsertId();
            $up = $pdo->prepare("UPDATE trabajos SET estado='cobrado',medio=?,medio_pago=?,fecha_pago=?,monto_senia=?,recibo_id=? WHERE id=?");
            foreach ($rows as $r) $up->execute([$pago['medio'], $pago['medio_pago'], $pago['fecha_pago'], fin_total($r), $rid, $r['id']]);
            $pdo->commit(); header("Location: recibo.php?id=$rid"); exit;
        } elseif ($a === 'anular_recibo') {
            $rid = (int)($_POST['id'] ?? 0); $pdo->beginTransaction();
            $pdo->prepare("UPDATE trabajos SET estado='pendiente',medio=NULL,medio_pago=NULL,fecha_pago=NULL,monto_senia=0,recibo_id=NULL WHERE recibo_id=? AND cliente_id=?")->execute([$rid, $c]);
            $pdo->prepare('DELETE FROM recibos WHERE id=? AND cliente_id=?')->execute([$rid, $c]); $pdo->commit();
            $_SESSION['flash'] = 'Recibo anulado: los trabajos volvieron a pendientes.';
        }
        if (!$err) { header("Location: cobros.php?c=$c"); exit; }
    } catch (Throwable $x) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($x instanceof PDOException) { error_log('[blens cobros] ' . $x->getMessage()); $err = 'Error de base de datos.'; } else $err = $x->getMessage();
    }
}
$cl = null;
if ($vc) { $s = $pdo->prepare('SELECT * FROM clientes WHERE id=?'); $s->execute([$vc]); $cl = $s->fetch() ?: null; }
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex"><title>Cobros | blens.ar</title><link rel="icon" href="../assets/favicon.svg" type="image/svg+xml"><link rel="manifest" href="manifest.webmanifest"><link rel="apple-touch-icon" href="icons/apple-touch-icon.png"><meta name="theme-color" content="#0b0b0b"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><link rel="stylesheet" href="admin.css?v=35"></head><body>
<svg class="sprite" aria-hidden="true"><symbol id="mark" viewBox="0 0 130 130"><polygon points="0,0 70,0 100,30 100,100 0,100" fill="#F5F1EA"/><polygon points="60,45 125,45 125,125 45,125 45,60" fill="#F5F1EA"/><polygon points="60,45 100,45 100,64 86,72 100,80 100,100 45,100 45,60" fill="#FF5A1F"/></symbol></svg>
<?php require __DIR__ . '/nav.php'; ?>
<?php if ($ok) echo '<p class="ok">' . $e($ok) . '</p>'; if ($err) echo '<p class="err">' . $e($err) . '</p>'; ?>
<section class="fin-panel dash" id="finanzas">
  <div class="dash-head">
    <div><h1>Tablero financiero</h1><span class="mut">Período: <?= $e($mesTxt) ?> · <?= $mp ? 'modo prueba' : 'datos reales' ?></span></div>
    <form class="msel" method="get" action="cobros.php">
      <?php if ($vc): ?><input type="hidden" name="c" value="<?= (int)$vc ?>"><?php endif; ?>
      <a class="btn g" href="<?= $e($urlMes($ymPrev)) ?>" title="Mes anterior">‹ Anterior</a>
      <b class="msel-txt"><?= $e($mesTxt) ?></b>
      <a class="btn g" href="<?= $e($urlMes($ymNext)) ?>" title="Mes siguiente">Siguiente ›</a>
      <input type="month" name="mes" value="<?= $e($ym) ?>" aria-label="Elegir otro mes">
      <a class="btn g" href="cobros.php?csv=1&amp;mes=<?= $e($ym) ?>" title="Descargar movimientos del mes">Exportar CSV del mes</a>
    </form>
  </div>
  <div class="kpis fin">
    <div class="kpi fin"><span class="k">Cobrado real</span><b><?= $e(peso($fin['cobrado'])) ?></b><span class="mut"><?= $e($uniTxt($fin['por_unidad'])) ?></span><span class="mut"><?= $e($medTxt($fin['por_medio'])) ?></span></div>
    <a class="kpi fin link" href="#pendientes"><span class="k">Por cobrar (Saldos pendientes)</span><b class="<?= $fin['por_cobrar'] > 0 ? 'warn' : '' ?>"><?= $e(peso($fin['por_cobrar'])) ?></b><span class="mut"><?= (int)$fin['n_pendientes'] ?> trabajo(s) · ver recordatorios →</span></a>
    <div class="kpi fin"><span class="k">Gastos del mes</span><b><?= $e(peso($fin['gastos'])) ?></b><span class="mut"><?= $e($catTxt($fin['por_categoria'])) ?></span></div>
    <div class="kpi fin"><span class="k">Resultado Neto</span><b class="<?= $fin['neto'] < 0 ? 'warn' : '' ?>"><?= $e(peso($fin['neto'])) ?></b>
      <?php if ($fin['tna'] > 0): ?><span class="chip-r">Saldo <?= $e(peso($fin['saldo'])) ?> · +<?= $e(peso($fin['rend']['mensual'])) ?>/mes (TNA <?= $e(rtrim(rtrim(number_format($fin['tna'], 2, '.', ''), '0'), '.')) ?>%)</span>
      <?php else: ?><span class="mut">Cargá la TNA para estimar el rendimiento del saldo</span><?php endif; ?></div>
  </div>
  <div class="fin-grid">
    <div class="fin-box">
      <h2>evolución de los últimos 6 meses</h2>
      <div class="fin-legend"><span><i class="fin-dot ing"></i> Ingresos</span><span><i class="fin-dot gas"></i> Gastos</span><span><i class="fin-dot net"></i> Neto</span></div>
      <div class="fin-chart">
        <?php foreach ($evo as $m): ?>
        <div class="fin-col" title="<?= $e($m['ym'] . ' · Ingresos ' . peso($m['ingresos']) . ' · Gastos ' . peso($m['gastos']) . ' · Neto ' . peso($m['neto'])) ?>">
          <div class="fin-bars">
            <i class="fin-bar ing <?= $barClase($m['ingresos']) ?>"></i>
            <i class="fin-bar gas <?= $barClase($m['gastos']) ?>"></i>
            <i class="fin-bar net <?= $m['neto'] < 0 ? 'neg ' : '' ?><?= $barClase($m['neto']) ?>"></i>
          </div>
          <span class="fin-col-txt"><?= $e($m['label']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="fin-box">
      <h2>Detalle del período</h2>
      <p class="fin-line"><span>Cobrado por línea</span><b><?= $e($uniTxt($fin['por_unidad'])) ?></b></p>
      <p class="fin-line"><span>Medios de pago</span><b><?= $e($medTxt($fin['por_medio'])) ?></b></p>
      <p class="fin-line"><span>Gastos por categoría</span><b><?= $e($catTxt($fin['por_categoria'])) ?></b></p>
      <p class="fin-line"><span>Rendimiento estimado</span><b><?= $e(peso($fin['rend']['diario'])) ?>/día · <?= $e(peso($fin['rend']['anual'])) ?>/año</b></p>
    </div>
  </div>
  <div class="fin-pending" id="pendientes">
    <h2>Saldos pendientes de cobro</h2>
    <?php if (!$pendGlobal): ?><p class="mut">No hay saldos pendientes en este modo.</p><?php else: foreach ($pendGlobal as $r): $saldo = fin_saldo($r); $det = trim((string)$r['detalle']) !== '' ? (string)$r['detalle'] : (TIPOS[$r['tipo']] ?? (string)$r['tipo']); $nom = explode(' ', trim((string)($r['nombre'] ?? 'cliente')))[0] ?: 'cliente'; $w = trim((string)($r['telefono'] ?? '')) !== '' ? wa_link((string)$r['telefono'], 'Hola {nombre}, te recordamos el saldo pendiente de {concepto} en blens.ar: {monto}. ¡Gracias!', ['{nombre}' => $nom, '{concepto}' => $det, '{monto}' => peso($saldo)]) : null; ?>
    <div class="pend-row">
      <div class="pend-l"><span class="badge pend">Saldo</span><b><?= $e($det) ?></b><span class="mut"><?= $e(($r['empresa'] ?: $r['nombre']) . ' · ' . date('d/m/Y', strtotime($r['fecha'])) . ' · ' . fin_unidad_txt((string)($r['unidad_negocio'] ?? ''))) ?></span></div>
      <div class="pend-r"><b class="warn-t"><?= $e(peso($saldo)) ?></b>
        <?php if ($w): ?><a class="btn g" href="<?= $e($w) ?>" target="_blank" rel="noopener">Recordar saldo por WhatsApp</a><?php else: ?><span class="mut">Sin teléfono cargado</span><?php endif; ?>
        <form method="post"><input type="hidden" name="a" value="marcar_cobrado"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$r['cliente_id'] ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="estado_pago" value="cobrado"><input type="hidden" name="medio_pago" value="Transferencia / MP"><input type="hidden" name="sumar_saldo" value="1"><button>Marcar cobrado</button></form>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</section>
<?php if (!$cl): $ql = $pdo->prepare("SELECT c.id,c.empresa,c.nombre, COALESCE(SUM(CASE WHEN t.estado='cobrado' THEN 0 ELSE GREATEST(t.cantidad*t.monto_unit-COALESCE(t.monto_senia,0),0) END),0) AS pend, COALESCE(SUM(CASE WHEN t.estado='cobrado' THEN t.cantidad*t.monto_unit ELSE LEAST(COALESCE(t.monto_senia,0),t.cantidad*t.monto_unit) END),0) AS cobrado, COUNT(t.id) AS ntrab FROM clientes c LEFT JOIN trabajos t ON t.cliente_id=c.id AND t.prueba=? GROUP BY c.id ORDER BY c.empresa,c.nombre"); $ql->execute([$mp]); $rows = $ql->fetchAll(); $tp = $tc = 0.0; ?>
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
$sk = $pdo->prepare('SELECT * FROM trabajos WHERE cliente_id=? AND prueba=?'); $sk->execute([$cl['id'], $mp]); $todos = $sk->fetchAll();
$tcob = 0.0; $tpend = 0.0; foreach ($todos as $r) { $tcob += fin_abonado($r); $tpend += fin_saldo($r); }
$tcob = fin_num($tcob); $tpend = fin_num($tpend); $ntrab = count($todos);
$pend = fin_pendientes($pdo, (int)$cl['id']);
$resumen = resumen_cobro($cl, $pend, $tpend, $alias, $cbu);
$waCli = trim((string)$cl['telefono']) !== '' ? wa_url((string)$cl['telefono'], $resumen) : null;
?>
<?= !empty($cl['logo']) ? '<img class="clogo" loading="lazy" width="180" height="80" src="../media/logos/' . $e($cl['logo']) . '" alt="">' : '' ?>
<div class="clh"><h1><?= $e($cl['empresa'] ? $cl['empresa'] . ' · ' . $cl['nombre'] : $cl['nombre']) ?></h1><div class="row"><a class="btn g" href="cobros.php">&larr; Todos</a><a class="btn g" href="clientes.php?c=<?= (int)$cl['id'] ?>">Datos y tarifas</a><button type="button" class="btn" data-toggle="#nuevo">+ Nuevo movimiento</button><button type="button" class="btn g" data-open-detalle="#cobrar-varios">Cobrar varios / Recibo</button></div></div>
<div class="kpis">
  <div class="kpi"><span class="k">Cobrado real</span><b><?= $e(peso($tcob)) ?></b><span class="mut">histórico: señas + trabajos completos</span></div>
  <div class="kpi"><span class="k">Saldo pendiente</span><b class="<?= $tpend > 0 ? 'warn' : '' ?>"><?= $e(peso($tpend)) ?></b><span class="mut"><?= count($pend) ?> trabajo(s) con saldo</span></div>
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
    <form method="post" class="det-one"><input type="hidden" name="a" value="trabajo"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>"><input type="hidden" name="fecha" value="<?= $e($x['f']) ?>"><input type="hidden" name="detalle" value="<?= $e($x['t']) ?>"><input type="hidden" name="cantidad" value="1"><input type="hidden" name="<?= $x['k'] === 'entrega' ? 'entrega_id' : 'proyecto_id' ?>" value="<?= (int)$x['id'] ?>"><input type="hidden" name="tipo" value="<?= $tipoSel ?>"><input type="hidden" name="monto" value="<?= $montoSug > 0 ? $e((string)$montoSug) : '0' ?>"><input type="hidden" name="estado" value="pendiente"><input type="hidden" name="estado_pago" value="pendiente"><input type="hidden" name="unidad_negocio" value="audiovisual">
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
<form class="f" method="post" enctype="multipart/form-data" data-fin-form><input type="hidden" name="a" value="cobro_rapido" data-mov-a><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>">
<label class="full"><span class="lbl">Qué querés cargar</span><select name="modalidad" data-mov-mode><option value="cobro">Cobro directo (concepto y monto total)</option><option value="trabajo">Trabajo por cantidad y tarifa</option></select></label>
<label><span class="lbl">Fecha</span><input type="date" name="fecha" value="<?= date('Y-m-d') ?>" required></label>
<label class="full"><span class="lbl">Detalle / concepto <span class="req">* obligatorio</span></span><input name="detalle" maxlength="200" placeholder="Reel de producto" required></label>
<div class="full" data-mov-only="trabajo" hidden>
  <label><span class="lbl">Tipo <span class="req">* obligatorio</span></span><select name="tipo" class="tipo-sel"><?= $tipoOpts() ?></select></label>
  <label><span class="lbl">Cantidad <span class="req">* obligatorio</span></span><input type="number" name="cantidad" value="1" min="1" max="999" data-fin-cantidad></label>
</div>
<label><span class="lbl" data-mov-label>Monto total ($) <span class="req">* obligatorio</span></span><input name="monto" class="monto-in" data-fin-monto inputmode="numeric" placeholder="0" required></label>
<label><span class="lbl">Línea de negocio</span><select name="unidad_negocio"><?= fin_opts(FIN_UNIDADES, 'audiovisual') ?></select></label>
<label><span class="lbl">Estado de pago</span><select name="estado_pago" data-estado data-fin-estado><?= fin_opts(FIN_ESTADOS_PAGO, 'pendiente') ?></select></label>
<label data-fin-senia-wrap><span class="lbl">Seña cobrada <span class="opt">(solo si es parcial)</span></span><input name="monto_senia" data-fin-senia inputmode="numeric" placeholder="0"></label>
<label><span class="lbl">Medio de pago</span><select name="medio_pago"><option value="">—</option><?= fin_opts(array_combine(array_keys(FIN_MEDIOS_PAGO), array_keys(FIN_MEDIOS_PAGO)), '') ?></select></label>
<label><span class="lbl">Fecha de cobro <span class="opt">(si aplica)</span></span><input type="date" name="fecha_pago"></label>
<div class="full fin-calc"><span>Total: <output data-fin-total>$ 0</output></span><span>Saldo restante: <output class="warn-t" data-fin-saldo>$ 0</output></span></div>
<label class="full"><span class="lbl">Comprobante <span class="opt">(opcional, máximo 8 MB)</span></span><input type="file" name="comprobante" accept=".jpg,.jpeg,.png,.webp,.pdf"></label>
<label class="chk-inline full"><input type="checkbox" name="sumar_saldo" value="1" checked data-sumar> Sumar al saldo en cuenta</label>
<div class="row full"><button data-mov-submit>Registrar cobro</button><button class="g" name="cero" value="1" formnovalidate data-mov-cero hidden>Registrar en $0 (histórico)</button><button type="button" class="g" data-cerrar>Cerrar</button></div>
</form>
</details>
<h2>Movimientos</h2>
<?php if (!$movs): ?><p class="mut">Todavía no hay movimientos para este cliente.</p><?php else: ?>
<form id="fdel" method="post"><input type="hidden" name="a" value="del_trabajo"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>"></form>
<div class="mvs">
<?php foreach ($movs as $r): $sub = fin_total($r); $ab = fin_abonado($r); $saldo = fin_saldo($r); $est = fin_estado($r); $cob = $est === 'cobrado'; $ff = (string)($ab > 0 ? ($r['fecha_pago'] ?: $r['fecha']) : $r['fecha']); $mt = (string)$r['tipo']; $medio = fin_medio((string)($r['medio_pago'] ?? ''), (string)($r['medio'] ?? '')); ?>
<div class="mv">
  <div class="mv-l">
    <span class="badge <?= $cob ? 'ok' : ($est === 'seniado' ? 'sen' : 'pend') ?>"><?= $e(fin_estado_txt($r)) ?></span>
    <span class="b"><?= $e(fin_unidad_txt((string)($r['unidad_negocio'] ?? ''))) ?></span>
    <?php if ($mt === 'video' || $mt === 'foto'): ?><span class="b tipo-<?= $mt === 'foto' ? 'fotos' : 'video' ?>"><?= $mt === 'foto' ? 'FOTOS' : 'VIDEO' ?></span><?php endif; ?>
    <b><?= $e($r['detalle'] ?: (TIPOS[$r['tipo']] ?? $r['tipo'])) ?></b>
    <span class="mut"><?= $e(date('d/m/Y', strtotime($ff))) ?><?= $ab > 0 ? ' · ' . $e($medio) : '' ?><?= (int)$r['cantidad'] > 1 ? ' · ' . (int)$r['cantidad'] . ' × ' . $e(peso((float)$r['monto_unit'])) : '' ?></span>
    <?php if (!$cob): ?><span class="mut">Abonado <?= $e(peso($ab)) ?> · Saldo <?= $e(peso($saldo)) ?></span><?php endif; ?>
  </div>
  <div class="mv-r">
    <b class="<?= $cob ? 'ok-t' : 'warn-t' ?>"><?= $e(peso($cob ? $sub : $saldo)) ?></b>
    <?php if (!empty($r['comprobante'])) echo comprobante_boton('cobro', (int)$r['id']); ?>
    <?php if (!$cob): ?>
    <form class="mv-a" method="post" enctype="multipart/form-data"><input type="hidden" name="a" value="marcar_cobrado"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="estado_pago" value="cobrado">
      <div class="mv-actions">
        <select name="medio_pago" aria-label="Medio de pago"><?= fin_opts(array_combine(array_keys(FIN_MEDIOS_PAGO), array_keys(FIN_MEDIOS_PAGO)), 'Transferencia / MP') ?></select>
        <label class="chk" title="Sumar el saldo restante al saldo en cuenta"><input type="checkbox" name="sumar_saldo" value="1" checked> +saldo</label>
        <label class="up-min" title="Adjuntar comprobante (máximo 8 MB)"><input type="file" name="comprobante" accept=".jpg,.jpeg,.png,.webp,.pdf"> Comprobante</label>
        <button class="g">Marcar cobrado</button>
        <button class="g" form="fdel" name="id" value="<?= (int)$r['id'] ?>" title="Quitar este movimiento">Quitar</button>
      </div>
    </form>
    <?php elseif (!empty($r['recibo_id'])): ?><a class="btn g" href="recibo.php?id=<?= (int)$r['recibo_id'] ?>">Ver recibo</a>
    <?php else: ?><span class="mut">Cobrado sin recibo</span><?php endif; ?>
  </div>
</div>
<?php if (empty($r['recibo_id'])): ?>
<details class="accd mv-edit" id="edit-<?= (int)$r['id'] ?>"><summary>Editar línea / estado / pago</summary>
  <form class="f" method="post" data-fin-form>
    <input type="hidden" name="a" value="editar_trabajo"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <input type="hidden" name="monto" data-fin-monto value="<?= (float)$r['cantidad'] * (float)$r['monto_unit'] ?>"><input type="hidden" name="cantidad" data-fin-cantidad value="<?= (int)$r['cantidad'] ?>">
    <label><span class="lbl">Línea de negocio</span><select name="unidad_negocio"><?= fin_opts(FIN_UNIDADES, fin_unidad((string)($r['unidad_negocio'] ?? ''))) ?></select></label>
    <label><span class="lbl">Estado de pago</span><select name="estado_pago" data-fin-estado><?= fin_opts(FIN_ESTADOS_PAGO, $est) ?></select></label>
    <label data-fin-senia-wrap><span class="lbl">Seña cobrada <span class="opt">(solo si es parcial)</span></span><input name="monto_senia" data-fin-senia inputmode="numeric" value="<?= fin_num($ab) ?>"></label>
    <label><span class="lbl">Medio de pago</span><select name="medio_pago" aria-label="Medio de pago"><?= fin_opts(array_combine(array_keys(FIN_MEDIOS_PAGO), array_keys(FIN_MEDIOS_PAGO)), $medio) ?></select></label>
    <label><span class="lbl">Fecha del pago</span><input type="date" name="fecha_pago" value="<?= $e((string)($r['fecha_pago'] ?? '')) ?>"></label>
    <div class="full fin-calc"><span>Total: <b data-fin-total></b></span><span>Saldo: <b data-fin-saldo></b></span></div>
    <div class="full"><button class="g">Guardar cambios</button></div>
  </form>
</details>
<?php endif; ?>
<?php endforeach; ?>
</div><?php endif; ?>
<details class="accd" id="cobrar-varios"><summary>Cobrar varios y generar recibo</summary>
<?php if (!$pend): ?><p class="mut">No hay trabajos pendientes.</p><?php else: ?>
<form method="post"><input type="hidden" name="a" value="cobrar"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cl['id'] ?>">
<p class="mut"><label class="chk"><input type="checkbox" id="todos" aria-label="Elegir todos"> Elegir todos los pendientes</label></p>
<table class="cards"><tr><th></th><th>Fecha</th><th>Detalle</th><th>Saldo</th><th></th></tr>
<?php foreach ($pend as $r): $sub = fin_saldo($r); ?><tr><td data-label=""><input class="sel" type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>"></td><td class="mut" data-label="Fecha"><?= $e(date('d/m/Y', strtotime($r['fecha']))) ?></td><td data-label="Detalle"><?php if ((string)$r['tipo'] === 'video' || (string)$r['tipo'] === 'foto'): ?><span class="b tipo-<?= (string)$r['tipo'] === 'foto' ? 'fotos' : 'video' ?>"><?= (string)$r['tipo'] === 'foto' ? 'FOTOS' : 'VIDEO' ?></span> <?php endif; ?><?= $e($r['detalle'] ?: TIPOS[$r['tipo']]) ?><br><span class="mut"><?= (int)$r['cantidad'] ?> × <?= $e(peso((float)$r['monto_unit'])) ?><?= fin_abonado($r) > 0 ? ' · abonado ' . $e(peso(fin_abonado($r))) : '' ?></span></td><td data-label="Saldo"><?= $e(peso($sub)) ?></td><td data-label=""><button class="g" form="fdel" name="id" value="<?= (int)$r['id'] ?>">Quitar</button></td></tr><?php endforeach; ?>
<tr><th></th><th></th><th>Total pendiente</th><th><?= $e(peso($tpend)) ?></th><th></th></tr></table>
<div class="f"><label><span class="lbl">Cómo pagó <span class="req">* obligatorio</span></span><select name="medio_pago"><?= fin_opts(array_combine(array_keys(FIN_MEDIOS_PAGO), array_keys(FIN_MEDIOS_PAGO)), 'Transferencia / MP') ?></select></label><label><span class="lbl">Fecha de pago <span class="req">* obligatorio</span></span><input type="date" name="fecha_pago" value="<?= date('Y-m-d') ?>" required></label>
<p class="mut full">Tildá los trabajos que querés incluir. Si no tildás ninguno, el ticket incluye todos los pendientes.</p>
<div class="row full"><button type="submit" formaction="ticket.php" formnovalidate>Armar ticket de cobro</button><button class="g">Marcar como cobrado y generar recibo</button></div></div></form><?php endif; ?>
</details>
<h2>Recibos</h2><table class="cards"><tr><th>N°</th><th>Fecha</th><th>Período</th><th>Total</th><th></th></tr>
<?php foreach ($recs as $r): ?><tr><td data-label="N°"><?= $e(nro($r)) ?></td><td class="mut" data-label="Fecha"><?= $e(date('d/m/Y', strtotime($r['fecha']))) ?> · <?= $e(fin_medio('', (string)($r['medio'] ?? ''))) ?></td><td data-label="Período"><?= $e($r['periodo']) ?></td><td data-label="Total"><?= $e(peso((float)$r['total'])) ?></td><td data-label=""><a class="btn g" href="recibo.php?id=<?= (int)$r['id'] ?>">Ver / imprimir</a></td></tr><?php endforeach; if (!$recs) echo '<tr><td colspan="5" class="mut">Todavía no hay recibos.</td></tr>'; ?></table>
<?php endif; ?><script src="admin.js?v=34"></script><script src="../assets/push.js?v=8"></script></body></html>
