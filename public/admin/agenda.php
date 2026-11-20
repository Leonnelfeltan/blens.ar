<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
require __DIR__ . '/lib.php';
session_start();
header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY');
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }
$pdo = blens_db(); $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$_SESSION['t'] ??= bin2hex(random_bytes(16)); $T = $e($_SESSION['t']);
$ok = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']); $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['t'], (string)($_POST['t'] ?? ''))) { http_response_code(400); exit('Sesión vencida. Recargá la página.'); }
    $a = (string)($_POST['a'] ?? ''); $aviso = '';
    try {
        if ($a === 'add') {
            $tipo = (string)($_POST['tipo'] ?? 'rodaje');
            if (!isset(AGENDA_TIPOS[$tipo])) throw new RuntimeException('Elegí un tipo de evento válido.');
            $titulo = mb_substr(trim((string)($_POST['titulo'] ?? '')), 0, 160);
            $fecha = (string)($_POST['fecha'] ?? '');
            $hora = trim((string)($_POST['hora'] ?? ''));
            $lugar = mb_substr(trim((string)($_POST['lugar_nota'] ?? '')), 0, 255);
            $descripcion = mb_substr(str_replace("\r\n", "\n", trim((string)($_POST['descripcion'] ?? ''))), 0, 4000);
            $cid = (int)($_POST['cliente_id'] ?? 0);
            if ($titulo === '') throw new RuntimeException('Escribí un título para el evento.');
            if (!fecha_ok($fecha)) throw new RuntimeException('La fecha no es válida.');
            if ($hora !== '') { $h = DateTime::createFromFormat('H:i', $hora); if (!$h || $h->format('H:i') !== $hora) throw new RuntimeException('La hora no es válida.'); }
            if ($cid) { $q = $pdo->prepare('SELECT id FROM clientes WHERE id=?'); $q->execute([$cid]); if (!$q->fetchColumn()) $cid = 0; }
            $pdo->prepare('INSERT INTO agenda (cliente_id,tipo,titulo,fecha,hora,lugar_nota,descripcion) VALUES (?,?,?,?,?,?,?)')
                ->execute([$cid ?: null, $tipo, $titulo, $fecha, $hora !== '' ? $hora : null, $lugar !== '' ? $lugar : null, $descripcion !== '' ? $descripcion : null]);
            $aviso = 'Evento agregado a la agenda.';
        } elseif ($a === 'hecho' || $a === 'pendiente') {
            $pdo->prepare('UPDATE agenda SET completado=? WHERE id=?')->execute([$a === 'hecho' ? 1 : 0, (int)($_POST['id'] ?? 0)]);
            $aviso = $a === 'hecho' ? 'Evento marcado como completado.' : 'Evento reabierto.';
        } elseif ($a === 'del') {
            $pdo->prepare('DELETE FROM agenda WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            $aviso = 'Evento eliminado.';
        } elseif ($a === 'loc_agendar') {
            $lid = (int)($_POST['id'] ?? 0);
            $fecha = (string)($_POST['fecha'] ?? '');
            $hora = trim((string)($_POST['hora'] ?? ''));
            if (!fecha_ok($fecha)) throw new RuntimeException('La fecha de la locación no es válida.');
            if ($hora !== '' && (!DateTime::createFromFormat('H:i', $hora) || DateTime::createFromFormat('H:i', $hora)->format('H:i') !== $hora)) throw new RuntimeException('La hora de la locación no es válida.');
            $q = $pdo->prepare('SELECT l.*, c.nombre AS cliente_nombre, c.empresa AS cliente_empresa, c.telefono AS cliente_tel FROM cliente_locaciones l JOIN clientes c ON c.id=l.cliente_id WHERE l.id=? AND l.estado=\'pendiente\'');
            $q->execute([$lid]);
            $loc = $q->fetch();
            if (!$loc) throw new RuntimeException('La locación ya fue agendada o no existe.');
            $cid = (int)$loc['cliente_id'];
            $titulo = mb_substr('Rodaje · ' . trim((string)$loc['nombre']), 0, 160);
            $lugar = mb_substr(trim((string)$loc['ubicacion']), 0, 255);
            $detalle = array_filter([
                'Encargado: ' . trim((string)($loc['administrador'] ?? '')),
                'Tipo de contenido: ' . trim((string)($loc['tipo_contenido'] ?? '')),
                'Horario acordado: ' . trim((string)($loc['horario_acordado'] ?? '')),
                trim((string)($loc['notas'] ?? '')),
            ], fn($x) => trim($x) !== '');
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO agenda (cliente_id,tipo,titulo,fecha,hora,lugar_nota,descripcion) VALUES (?, 'rodaje', ?, ?, ?, ?, ?)")
                    ->execute([$cid, $titulo, $fecha, $hora !== '' ? $hora : null, $lugar !== '' ? $lugar : null, mb_substr(implode(' · ', $detalle), 0, 4000)]);
                $upd = $pdo->prepare("UPDATE cliente_locaciones SET estado='agendado', fecha_agendada=? WHERE id=? AND estado='pendiente'");
                $upd->execute([$fecha . ' ' . ($hora !== '' ? $hora . ':00' : '00:00:00'), $lid]);
                if ($upd->rowCount() < 1) throw new RuntimeException('La locación ya fue agendada por otro usuario.');
                $pdo->commit();
            } catch (Throwable $x) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $x;
            }
            $cliente = trim((string)($loc['cliente_empresa'] ?? '')) ?: trim((string)($loc['cliente_nombre'] ?? ''));
            $cuando = date('d/m/Y', strtotime($fecha)) . ($hora !== '' ? ' ' . $hora : '');
            try {
                blens_push_notify($pdo, 'cliente', $cid, [
                    'title' => 'Rodaje confirmado · blens.ar',
                    'body' => $cliente . ' — ' . trim((string)$loc['nombre']) . ' · ' . $cuando,
                    'url' => '/portal/',
                    'tag' => 'blens-rodaje-' . $lid,
                ]);
            } catch (Throwable $x) { error_log('[blens agenda push] ' . $x->getMessage()); }
            $aviso = 'Locación agendada y aviso enviado al cliente.';
        }
        if ($aviso !== '') { $_SESSION['flash'] = $aviso; header('Location: agenda.php'); exit; }
    } catch (Throwable $x) {
        if ($x instanceof PDOException) { error_log('[blens agenda] ' . $x->getMessage()); $err = 'Error de base de datos.'; }
        else $err = $x->getMessage();
    }
}

$hoy = date('Y-m-d');
$clientes = $pdo->query('SELECT id,empresa,nombre FROM clientes ORDER BY empresa,nombre')->fetchAll();
$locPendientes = $pdo->query("SELECT l.*, c.nombre AS cliente_nombre, c.empresa AS cliente_empresa, c.telefono AS cliente_tel FROM cliente_locaciones l JOIN clientes c ON c.id=l.cliente_id WHERE l.estado='pendiente' ORDER BY l.creado_en DESC, l.id DESC")->fetchAll();
$agN = agenda_pendientes($pdo);
$consN = consultas_pendientes($pdo);
$rows = $pdo->query('SELECT a.*, c.nombre AS cliente_nombre, c.empresa AS cliente_empresa FROM agenda a LEFT JOIN clientes c ON c.id=a.cliente_id ORDER BY a.fecha, a.hora IS NULL, a.hora, a.id')->fetchAll();
$gAtras = $gHoy = $gProx = $gDone = [];
foreach ($rows as $r) {
    if ((int)$r['completado']) $gDone[] = $r;
    elseif ($r['fecha'] < $hoy) $gAtras[] = $r;
    elseif ($r['fecha'] === $hoy) $gHoy[] = $r;
    else $gProx[] = $r;
}
usort($gAtras, fn(array $a, array $b): int => strcmp($b['fecha'] . ($b['hora'] ?? ''), $a['fecha'] . ($a['hora'] ?? '')));
usort($gDone, fn(array $a, array $b): int => strcmp($b['fecha'] . ($b['hora'] ?? ''), $a['fecha'] . ($a['hora'] ?? '')));
$gDone = array_slice($gDone, 0, 60);
$render = function (array $items, bool $done) use ($e, $T, $hoy): void {
    foreach ($items as $r) {
        $cliente = trim((string)($r['cliente_empresa'] ?? '')) ?: trim((string)($r['cliente_nombre'] ?? ''));
        $nota = trim((string)($r['lugar_nota'] ?? ''));
        $late = !$done && $r['fecha'] < $hoy;
        $tipo = AGENDA_TIPOS[$r['tipo']] ?? ucfirst((string)$r['tipo']);
        ?>
        <div class="ag-item<?= $done ? ' done' : '' ?><?= $late ? ' late' : '' ?>">
            <div class="ag-main">
                <div class="ag-meta"><span class="badge <?= $e($r['tipo']) ?>"><?= $e($tipo) ?></span><span><?= $e(date('d/m/Y', strtotime((string)$r['fecha']))) ?></span><?php if (!empty($r['hora'])): ?><span><?= $e(substr((string)$r['hora'], 0, 5)) ?></span><?php endif; ?><?= $late ? '<span class="ag-late">Atrasado</span>' : '' ?></div>
                <b><?= $e($r['titulo']) ?></b>
                <?php if ($cliente !== '' || $nota !== ''): ?><span class="mut"><?= $e(implode(' · ', array_filter([$cliente, $nota]))) ?></span><?php endif; ?>
                <?php if (trim((string)($r['descripcion'] ?? '')) !== ''): ?><span class="mut ag-desc"><?= nl2br($e($r['descripcion'])) ?></span><?php endif; ?>
            </div>
            <div class="ag-actions">
                <form method="post"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="a" value="<?= $done ? 'pendiente' : 'hecho' ?>"><button class="g"><?= $done ? 'Reabrir' : 'Marcar hecho' ?></button></form>
                <form method="post"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="a" value="del"><button class="r">Eliminar</button></form>
            </div>
        </div>
        <?php
    }
};
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex"><title>Agenda | blens.ar</title><link rel="icon" href="../assets/favicon.svg" type="image/svg+xml"><link rel="manifest" href="manifest.webmanifest"><link rel="apple-touch-icon" href="icons/apple-touch-icon.png"><meta name="theme-color" content="#0b0b0b"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><link rel="stylesheet" href="admin.css?v=35"></head><body>
<svg class="sprite" aria-hidden="true"><symbol id="mark" viewBox="0 0 130 130"><polygon points="0,0 70,0 100,30 100,100 0,100" fill="#F5F1EA"/><polygon points="60,45 125,45 125,125 45,125 45,60" fill="#F5F1EA"/><polygon points="60,45 100,45 100,64 86,72 100,80 100,100 45,100 45,60" fill="#FF5A1F"/></symbol></svg>
<?php require __DIR__ . '/nav.php'; ?>
<?php if ($ok) echo '<p class="ok">' . $e($ok) . '</p>'; if ($err) echo '<p class="err">' . $e($err) . '</p>'; ?>
<section class="loc-pend">
  <div class="lh2"><h2>Locaciones enviadas por clientes (pendientes de fecha)</h2><span class="mut"><?= count($locPendientes) ?> pendiente(s)</span></div>
  <?php if (!$locPendientes): ?><p class="mut">No hay locaciones esperando fecha.</p><?php else: ?>
  <div class="loc-pend-grid">
  <?php foreach ($locPendientes as $l):
    $cliente = trim((string)($l['cliente_empresa'] ?? '')) ?: trim((string)($l['cliente_nombre'] ?? ''));
    $maps = locaciones_maps((string)($l['ubicacion'] ?? ''));
    $tel = trim((string)($l['telefono_contacto'] ?? '')) ?: trim((string)($l['cliente_tel'] ?? ''));
    $wa = $tel !== '' ? wa_url($tel, 'Hola ' . $cliente . ', te escribo de blens.ar por la locación ' . (string)$l['nombre'] . '. ¿Confirmamos el rodaje?') : null;
    $tipos = preg_split('/[,|\/]+/', (string)($l['tipo_contenido'] ?? '')) ?: [];
    $tipos = array_values(array_filter(array_map('trim', $tipos), fn($x) => $x !== ''));
  ?>
    <article class="loc-pend-card">
      <div class="loc-pend-head"><h3><?= $e($l['nombre']) ?></h3><span class="loc-badge pendiente">Pendiente</span></div>
      <p class="mut loc-pend-place"><?= $e($l['ubicacion']) ?></p>
      <div class="loc-pend-info">
        <span><b>Cliente:</b> <?= $e($cliente) ?></span>
        <span><b>Encargado:</b> <?= $e(trim((string)($l['administrador'] ?? '')) ?: 'No indicado') ?><?php if ($wa): ?> <a class="g" href="<?= $e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?></span>
        <span><b>Tipo de contenido:</b> <?= $e($tipos ? implode(', ', $tipos) : 'No indicado') ?></span>
        <span><b>Horario acordado:</b> <?= $e(trim((string)($l['horario_acordado'] ?? '')) ?: 'A coordinar') ?></span>
        <span class="mut">Enviada <?= $e(date('d/m/Y H:i', strtotime((string)$l['creado_en']))) ?></span>
      </div>
      <div class="loc-pend-links"><a class="g" href="<?= $e($maps) ?>" target="_blank" rel="noopener">Maps ↗</a></div>
      <form class="loc-pend-form" method="post">
        <input type="hidden" name="a" value="loc_agendar"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
        <label><span class="lbl">Fecha</span><input type="date" name="fecha" value="<?= $e($hoy) ?>" required></label>
        <label><span class="lbl">Hora</span><input type="time" name="hora"></label>
        <button>Poner fecha y agendar</button>
      </form>
    </article>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<div class="lh2"><h1>Agenda de rodajes y entregas</h1><span class="mut"><?= $agN ?> evento(s) pendiente(s) para hoy o los próximos 3 días</span></div>
<form class="agenda-form" id="nuevo" method="post">
<input type="hidden" name="a" value="add"><input type="hidden" name="t" value="<?= $T ?>">
<label><span class="lbl">Tipo</span><select name="tipo"><?php foreach (AGENDA_TIPOS as $k => $n) echo '<option value="' . $e($k) . '">' . $e($n) . '</option>'; ?></select></label>
<label><span class="lbl">Cliente <span class="opt">(opcional)</span></span><select name="cliente_id"><option value="0">— Sin cliente —</option><?php foreach ($clientes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= $e($c['empresa'] ?: $c['nombre']) ?></option><?php endforeach; ?></select></label>
<label class="wide"><span class="lbl">Título <span class="req">* obligatorio</span></span><input name="titulo" maxlength="160" required placeholder="Ej: Rodaje en planta, entrega de reels…"></label>
<label><span class="lbl">Fecha <span class="req">* obligatorio</span></span><input type="date" name="fecha" value="<?= $e($hoy) ?>" required></label>
<label><span class="lbl">Hora <span class="opt">(opcional)</span></span><input type="time" name="hora"></label>
<label class="wide"><span class="lbl">Lugar / nota <span class="opt">(opcional)</span></span><input name="lugar_nota" maxlength="255" placeholder="Dirección, link de reunión o detalle"></label>
<label class="wide"><span class="lbl">Descripción <span class="opt">(opcional)</span></span><textarea name="descripcion" rows="3" maxlength="4000" placeholder="Contacto, horario, tipo de contenido…"></textarea></label>
<button>Agregar a la agenda</button>
</form>
<h2>Atrasados</h2>
<?php if (!$gAtras): ?><p class="mut">No hay eventos vencidos sin completar.</p><?php else: ?><div class="ag-list"><?php $render($gAtras, false); ?></div><?php endif; ?>
<h2>Hoy</h2>
<?php if (!$gHoy): ?><p class="mut">No hay eventos pendientes para hoy.</p><?php else: ?><div class="ag-list"><?php $render($gHoy, false); ?></div><?php endif; ?>
<h2>Próximos</h2>
<?php if (!$gProx): ?><p class="mut">No hay eventos próximos.</p><?php else: ?><div class="ag-list"><?php $render($gProx, false); ?></div><?php endif; ?>
<h2>Completados</h2>
<?php if (!$gDone): ?><p class="mut">Todavía no completaste eventos.</p><?php else: ?><div class="ag-list"><?php $render($gDone, true); ?></div><?php endif; ?>
<script src="admin.js?v=34"></script><script src="../assets/push.js?v=8"></script></body></html>
