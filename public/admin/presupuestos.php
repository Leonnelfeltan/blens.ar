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

function presu_cliente(PDO $pdo, array $p): array {
    $tel = preg_replace('/\D+/', '', (string)($p['telefono'] ?? '')) ?? '';
    $nombre = trim((string)($p['cliente_nombre'] ?? ''));
    $empresa = trim((string)($p['empresa'] ?? ''));
    $rows = $pdo->query('SELECT id,nombre,empresa,telefono FROM clientes')->fetchAll();
    foreach ($rows as $r) {
        $rt = preg_replace('/\D+/', '', (string)($r['telefono'] ?? '')) ?? '';
        if ($tel !== '' && $rt === $tel) return [(int)$r['id'], false];
    }
    foreach ($rows as $r) {
        if (mb_strtolower(trim((string)$r['nombre'])) === mb_strtolower($nombre)
            && ($empresa === '' || mb_strtolower(trim((string)$r['empresa'])) === mb_strtolower($empresa))) return [(int)$r['id'], false];
    }
    $base = mb_strtolower($nombre, 'UTF-8');
    $base = strtr($base, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $base = trim(preg_replace('/[^a-z0-9]+/', '.', $base) ?? '', '.');
    if ($base === '') $base = 'cliente';
    $base = substr($base, 0, 40);
    $usuario = $base; $i = 1; $q = $pdo->prepare('SELECT 1 FROM clientes WHERE usuario=?');
    while (true) {
        $q->execute([$usuario]);
        if (!$q->fetchColumn()) break;
        $usuario = $base . (++$i);
        if (strlen($usuario) > 60) $usuario = substr($base, 0, 50) . $i;
    }
    $pdo->prepare('INSERT INTO clientes (nombre,empresa,usuario,clave_hash,telefono,activo,cambiar_clave,clave_inicial_hasta) VALUES (?,?,?,?,?,1,1,DATE_ADD(NOW(), INTERVAL 7 DAY))')
        ->execute([$nombre, $empresa, $usuario, password_hash(blens_clave_nueva(), PASSWORD_DEFAULT), trim((string)($p['telefono'] ?? ''))]);
    return [(int)$pdo->lastInsertId(), true];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['t'], (string)($_POST['t'] ?? ''))) { http_response_code(400); exit('Sesión vencida. Recargá la página.'); }
    $a = (string)($_POST['a'] ?? '');
    try {
        if ($a === 'crear') {
            $nombre = mb_substr(trim((string)($_POST['cliente_nombre'] ?? '')), 0, 120);
            $empresa = mb_substr(trim((string)($_POST['empresa'] ?? '')), 0, 120);
            $telefono = mb_substr(trim((string)($_POST['telefono'] ?? '')), 0, 30);
            $validez = (int)($_POST['validez_dias'] ?? 15);
            if ($validez < 1 || $validez > 365) $validez = 15;
            $notas = mb_substr(str_replace("\r\n", "\n", trim((string)($_POST['notas'] ?? ''))), 0, 3000);
            $items = [];
            foreach ((array)($_POST['items'] ?? []) as $it) {
                if (!is_array($it)) continue;
                $d = mb_substr(trim((string)($it['d'] ?? '')), 0, 200);
                $c = (float)($it['c'] ?? 0);
                $precio = (float)($it['p'] ?? 0);
                if ($d === '' || $c <= 0) continue;
                $c = min(9999, $c);
                $precio = min(999999999, max(0, $precio));
                $items[] = ['d' => $d, 'c' => $c, 'p' => round($precio, 2)];
            }
            if ($nombre === '') throw new RuntimeException('Escribí el nombre del cliente o prospecto.');
            if (!$items) throw new RuntimeException('Agregá al menos un ítem con descripción y cantidad.');
            $total = presu_total($items);
            if ($total <= 0) throw new RuntimeException('El total del presupuesto tiene que ser mayor a cero.');
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO presupuestos (numero,cliente_nombre,empresa,telefono,items_json,total,validez_dias,notas,estado) VALUES ('',?,?,?,?,?,?,?,'enviado')")
                    ->execute([$nombre, $empresa, $telefono, json_encode($items, JSON_UNESCAPED_UNICODE), $total, $validez, $notas !== '' ? $notas : null]);
                $id = (int)$pdo->lastInsertId();
                $numero = 'P-' . date('ymd') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
                $pdo->prepare('UPDATE presupuestos SET numero=? WHERE id=?')->execute([$numero, $id]);
                $pdo->commit();
            } catch (Throwable $x) { $pdo->rollBack(); throw $x; }
            $_SESSION['flash'] = 'Presupuesto ' . $numero . ' guardado.';
            header('Location: presupuesto_ver.php?id=' . $id); exit;
        }
        if ($a === 'aceptar') {
            $id = (int)($_POST['id'] ?? 0);
            $q = $pdo->prepare('SELECT * FROM presupuestos WHERE id=?'); $q->execute([$id]); $p = $q->fetch();
            if (!$p) throw new RuntimeException('Presupuesto inexistente.');
            if (!empty($p['trabajo_id'])) {
                $qt = $pdo->prepare('SELECT cliente_id FROM trabajos WHERE id=?'); $qt->execute([(int)$p['trabajo_id']]);
                if (($cidOk = $qt->fetchColumn()) !== false) {
                    $_SESSION['flash'] = 'Este presupuesto ya tiene un cobro creado.';
                    header('Location: cobros.php?c=' . (int)$cidOk); exit;
                }
            }
            $items = presu_items((string)$p['items_json']); $total = presu_total($items);
            if (!$items || $total <= 0) throw new RuntimeException('El presupuesto no tiene ítems válidos.');
            $prueba = ajuste($pdo, 'modo_prueba', '0') === '1' ? 1 : 0;
            $detalle = mb_substr('Presupuesto ' . (string)$p['numero'] . ' · ' . (string)$p['cliente_nombre'], 0, 200);
            $pdo->beginTransaction();
            try {
                [$cid, $creado] = presu_cliente($pdo, $p);
                $pdo->prepare("INSERT INTO trabajos (cliente_id,fecha,tipo,detalle,cantidad,monto_unit,prueba,estado) VALUES (?,?,?,?,1,?,?,'pendiente')")
                    ->execute([$cid, date('Y-m-d'), 'otro', $detalle, (int)round($total), $prueba]);
                $tid = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE presupuestos SET estado='aceptado', trabajo_id=? WHERE id=?")->execute([$tid, $id]);
                $pdo->commit();
            } catch (Throwable $x) { $pdo->rollBack(); throw $x; }
            $_SESSION['flash'] = 'Presupuesto aceptado. Se creó un cobro pendiente por ' . presu_money($total) . ($creado ? '. Se creó también el cliente en Clientes.' : '.');
            header('Location: cobros.php?c=' . $cid); exit;
        }
    } catch (Throwable $x) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($x instanceof PDOException) { error_log('[blens presupuestos] ' . $x->getMessage()); $err = 'Error de base de datos.'; }
        else $err = $x->getMessage();
    }
}

$agN = agenda_pendientes($pdo);
$list = $pdo->query('SELECT p.*, t.cliente_id AS cobro_cliente_id FROM presupuestos p LEFT JOIN trabajos t ON t.id=p.trabajo_id ORDER BY p.id DESC')->fetchAll();
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Presupuestos | blens.ar</title><link rel="icon" href="../assets/favicon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="../assets/apple-touch-icon.png"><link rel="stylesheet" href="admin.css?v=20"></head><body>
<svg class="sprite" aria-hidden="true"><symbol id="mark" viewBox="0 0 130 130"><polygon points="0,0 70,0 100,30 100,100 0,100" fill="#F5F1EA"/><polygon points="60,45 125,45 125,125 45,125 45,60" fill="#F5F1EA"/><polygon points="60,45 100,45 100,64 86,72 100,80 100,100 45,100 45,60" fill="#FF5A1F"/></symbol></svg>
<div class="top"><a class="brand" href="./"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span></a><nav><a href="./">Proyectos</a><a href="./?v=consultas">Consultas</a><a href="clientes.php">Clientes</a><a href="cobros.php">Cobros</a><a href="agenda.php">Agenda<?php if ($agN > 0): ?><span class="nav-badge"><?= $agN ?></span><?php endif; ?></a><a class="on" href="presupuestos.php">Presupuestos</a><a href="../" target="_blank" rel="noopener">Ver web</a></nav>
<form method="post" action="./"><input type="hidden" name="a" value="logout"><input type="hidden" name="t" value="<?= $T ?>"><button class="g">Salir</button></form></div>
<?php if ($ok) echo '<p class="ok">' . $e($ok) . '</p>'; if ($err) echo '<p class="err">' . $e($err) . '</p>'; ?>
<div class="lh2"><h1>Presupuestos rápidos</h1><span class="mut">Armá, imprimí y enviá un presupuesto desde el celular en menos de 30 segundos.</span></div>
<form class="f presu-form" id="presu-form" method="post" autocomplete="off">
<input type="hidden" name="a" value="crear"><input type="hidden" name="t" value="<?= $T ?>">
<label><span class="lbl">Cliente / prospecto <span class="req">* obligatorio</span></span><input name="cliente_nombre" maxlength="120" required placeholder="Nombre de la persona"></label>
<label><span class="lbl">Empresa <span class="opt">(opcional)</span></span><input name="empresa" maxlength="120" placeholder="Empresa o marca"></label>
<label><span class="lbl">WhatsApp <span class="opt">(opcional)</span></span><input name="telefono" maxlength="30" inputmode="tel" placeholder="11 5555-5555"></label>
<label><span class="lbl">Validez (días) <span class="opt">(opcional)</span></span><input type="number" name="validez_dias" value="15" min="1" max="365"></label>
<div class="full presu-items-wrap">
    <div class="lh2"><span class="lbl">Ítems del presupuesto</span><button type="button" class="g" id="presu-add">+ Agregar ítem</button></div>
    <div class="presu-items" id="presu-items">
        <div class="presu-item">
            <label class="desc"><span class="lbl">Descripción</span><input name="items[0][d]" maxlength="200" placeholder="Ej: Reel de producto"></label>
            <label><span class="lbl">Cantidad</span><input type="number" name="items[0][c]" value="1" min="0.01" step="0.01" inputmode="decimal"></label>
            <label><span class="lbl">Precio unitario</span><input type="number" name="items[0][p]" min="0" step="0.01" inputmode="decimal" placeholder="0"></label>
            <div class="presu-sub"><span class="lbl">Subtotal</span><b data-presu-subtotal>$ 0</b></div>
            <button type="button" class="g presu-del" aria-label="Quitar ítem">×</button>
        </div>
    </div>
</div>
<label class="full"><span class="lbl">Notas / condiciones <span class="opt">(opcional)</span></span><textarea name="notas" rows="3" maxlength="3000" placeholder="Forma de pago, tiempos de entrega, aclaraciones…"></textarea></label>
<div class="full presu-totalbar"><span>Total</span><b data-presu-total>$ 0</b></div>
<div class="row full"><button>Guardar presupuesto</button><span class="mut">Después podés imprimirlo, guardarlo como PDF o enviarlo por WhatsApp.</span></div>
</form>
<template id="presu-item-template"><div class="presu-item">
    <label class="desc"><span class="lbl">Descripción</span><input name="items[__i__][d]" maxlength="200" placeholder="Ej: Reel de producto"></label>
    <label><span class="lbl">Cantidad</span><input type="number" name="items[__i__][c]" value="1" min="0.01" step="0.01" inputmode="decimal"></label>
    <label><span class="lbl">Precio unitario</span><input type="number" name="items[__i__][p]" min="0" step="0.01" inputmode="decimal" placeholder="0"></label>
    <div class="presu-sub"><span class="lbl">Subtotal</span><b data-presu-subtotal>$ 0</b></div>
    <button type="button" class="g presu-del" aria-label="Quitar ítem">×</button>
</div></template>
<h2>Guardados</h2>
<?php if (!$list): ?><p class="mut">Todavía no hay presupuestos guardados.</p><?php else: ?>
<table class="cards"><tr><th>N°</th><th>Cliente</th><th>Total</th><th>Fecha</th><th>Estado</th><th></th></tr>
<?php foreach ($list as $p): $wa = wa_url((string)$p['telefono'], presu_wa_texto($p)); $tieneCobro = !empty($p['cobro_cliente_id']); ?>
<tr>
<td data-label="N°"><b><?= $e($p['numero']) ?></b></td>
<td data-label="Cliente"><?= $e($p['empresa'] ?: $p['cliente_nombre']) ?><br><span class="mut"><?= $e($p['empresa'] ? $p['cliente_nombre'] : '') ?></span></td>
<td data-label="Total"><?= $e(presu_money((float)$p['total'])) ?></td>
<td class="mut" data-label="Fecha"><?= $e(date('d/m/Y', strtotime((string)$p['creado_en']))) ?></td>
<td data-label="Estado"><span class="badge <?= $p['estado'] === 'aceptado' ? 'ok' : 'pend' ?>"><?= $e(ucfirst((string)$p['estado'])) ?></span></td>
<td data-label=""><div class="presu-actions">
    <a class="btn g" href="presupuesto_ver.php?id=<?= (int)$p['id'] ?>">Ver / imprimir</a>
    <?php if ($wa): ?><a class="btn g" href="<?= $e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php else: ?><span class="mut">Sin WhatsApp válido</span><?php endif; ?>
    <?php if ($tieneCobro): ?><a class="btn g" href="cobros.php?c=<?= (int)$p['cobro_cliente_id'] ?>">Ver cobro</a>
    <?php else: ?><form method="post" action="presupuestos.php"><input type="hidden" name="a" value="aceptar"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="g">Aceptado → Crear cobro</button></form><?php endif; ?>
</div></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
<script src="admin.js?v=19"></script></body></html>
