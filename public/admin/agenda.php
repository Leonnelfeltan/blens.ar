<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
require __DIR__ . '/lib.php';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
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
            $cid = (int)($_POST['cliente_id'] ?? 0);
            if ($titulo === '') throw new RuntimeException('Escribí un título para el evento.');
            if (!fecha_ok($fecha)) throw new RuntimeException('La fecha no es válida.');
            if ($hora !== '') { $h = DateTime::createFromFormat('H:i', $hora); if (!$h || $h->format('H:i') !== $hora) throw new RuntimeException('La hora no es válida.'); }
            if ($cid) { $q = $pdo->prepare('SELECT id FROM clientes WHERE id=?'); $q->execute([$cid]); if (!$q->fetchColumn()) $cid = 0; }
            $pdo->prepare('INSERT INTO agenda (cliente_id,tipo,titulo,fecha,hora,lugar_nota) VALUES (?,?,?,?,?,?)')
                ->execute([$cid ?: null, $tipo, $titulo, $fecha, $hora !== '' ? $hora : null, $lugar !== '' ? $lugar : null]);
            $aviso = 'Evento agregado a la agenda.';
        } elseif ($a === 'hecho' || $a === 'pendiente') {
            $pdo->prepare('UPDATE agenda SET completado=? WHERE id=?')->execute([$a === 'hecho' ? 1 : 0, (int)($_POST['id'] ?? 0)]);
            $aviso = $a === 'hecho' ? 'Evento marcado como completado.' : 'Evento reabierto.';
        } elseif ($a === 'del') {
            $pdo->prepare('DELETE FROM agenda WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            $aviso = 'Evento eliminado.';
        }
        if ($aviso !== '') { $_SESSION['flash'] = $aviso; header('Location: agenda.php'); exit; }
    } catch (Throwable $x) {
        if ($x instanceof PDOException) { error_log('[blens agenda] ' . $x->getMessage()); $err = 'Error de base de datos.'; }
        else $err = $x->getMessage();
    }
}

$hoy = date('Y-m-d');
$clientes = $pdo->query('SELECT id,empresa,nombre FROM clientes ORDER BY empresa,nombre')->fetchAll();
$agN = agenda_pendientes($pdo);
$rows = $pdo->query('SELECT a.*, c.nombre AS cliente_nombre, c.empresa AS cliente_empresa FROM agenda a LEFT JOIN clientes c ON c.id=a.cliente_id ORDER BY a.fecha, a.hora IS NULL, a.hora, a.id')->fetchAll();
$gHoy = $gProx = $gDone = [];
foreach ($rows as $r) {
    if ((int)$r['completado']) $gDone[] = $r;
    elseif ($r['fecha'] === $hoy) $gHoy[] = $r;
    else $gProx[] = $r;
}
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
            </div>
            <div class="ag-actions">
                <form method="post"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="a" value="<?= $done ? 'pendiente' : 'hecho' ?>"><button class="g"><?= $done ? 'Reabrir' : 'Marcar hecho' ?></button></form>
                <form method="post"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="a" value="del"><button class="r">Eliminar</button></form>
            </div>
        </div>
        <?php
    }
};
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Agenda | blens.ar</title><link rel="icon" href="../assets/favicon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="../assets/apple-touch-icon.png"><link rel="stylesheet" href="admin.css?v=20"></head><body>
<svg class="sprite" aria-hidden="true"><symbol id="mark" viewBox="0 0 130 130"><polygon points="0,0 70,0 100,30 100,100 0,100" fill="#F5F1EA"/><polygon points="60,45 125,45 125,125 45,125 45,60" fill="#F5F1EA"/><polygon points="60,45 100,45 100,64 86,72 100,80 100,100 45,100 45,60" fill="#FF5A1F"/></symbol></svg>
<div class="top"><a class="brand" href="./"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span></a><nav><a href="./">Proyectos</a><a href="./?v=consultas">Consultas</a><a href="clientes.php">Clientes</a><a href="cobros.php">Cobros</a><a class="on" href="agenda.php">Agenda<?php if ($agN > 0): ?><span class="nav-badge"><?= $agN ?></span><?php endif; ?></a><a href="presupuestos.php">Presupuestos</a><a href="../" target="_blank" rel="noopener">Ver web</a></nav>
<form method="post" action="./"><input type="hidden" name="a" value="logout"><input type="hidden" name="t" value="<?= $T ?>"><button class="g">Salir</button></form></div>
<?php if ($ok) echo '<p class="ok">' . $e($ok) . '</p>'; if ($err) echo '<p class="err">' . $e($err) . '</p>'; ?>
<div class="lh2"><h1>Agenda de rodajes y entregas</h1><span class="mut"><?= $agN ?> evento(s) pendiente(s) para hoy o los próximos 3 días</span></div>
<form class="agenda-form" method="post">
<input type="hidden" name="a" value="add"><input type="hidden" name="t" value="<?= $T ?>">
<label><span class="lbl">Tipo</span><select name="tipo"><?php foreach (AGENDA_TIPOS as $k => $n) echo '<option value="' . $e($k) . '">' . $e($n) . '</option>'; ?></select></label>
<label><span class="lbl">Cliente <span class="opt">(opcional)</span></span><select name="cliente_id"><option value="0">— Sin cliente —</option><?php foreach ($clientes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= $e($c['empresa'] ?: $c['nombre']) ?></option><?php endforeach; ?></select></label>
<label class="wide"><span class="lbl">Título <span class="req">* obligatorio</span></span><input name="titulo" maxlength="160" required placeholder="Ej: Rodaje en planta, entrega de reels…"></label>
<label><span class="lbl">Fecha <span class="req">* obligatorio</span></span><input type="date" name="fecha" value="<?= $e($hoy) ?>" required></label>
<label><span class="lbl">Hora <span class="opt">(opcional)</span></span><input type="time" name="hora"></label>
<label class="wide"><span class="lbl">Lugar / nota <span class="opt">(opcional)</span></span><input name="lugar_nota" maxlength="255" placeholder="Dirección, link de reunión o detalle"></label>
<button>Agregar a la agenda</button>
</form>
<h2>Hoy</h2>
<?php if (!$gHoy): ?><p class="mut">No hay eventos pendientes para hoy.</p><?php else: ?><div class="ag-list"><?php $render($gHoy, false); ?></div><?php endif; ?>
<h2>Próximos</h2>
<?php if (!$gProx): ?><p class="mut">No hay eventos próximos.</p><?php else: ?><div class="ag-list"><?php $render($gProx, false); ?></div><?php endif; ?>
<h2>Completados</h2>
<?php if (!$gDone): ?><p class="mut">Todavía no completaste eventos.</p><?php else: ?><div class="ag-list"><?php $render($gDone, true); ?></div><?php endif; ?>
<script src="admin.js?v=19"></script></body></html>
