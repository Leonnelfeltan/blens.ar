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
$ok = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$s = $pdo->prepare('SELECT p.*, t.cliente_id AS cobro_cliente_id FROM presupuestos p LEFT JOIN trabajos t ON t.id=p.trabajo_id WHERE p.id=?');
$s->execute([(int)($_GET['id'] ?? 0)]); $p = $s->fetch();
if (!$p) { http_response_code(404); exit('Presupuesto inexistente.'); }
$items = presu_items((string)$p['items_json']); $total = presu_total($items);
$wa = wa_url((string)$p['telefono'], presu_wa_texto($p));
$tieneCobro = !empty($p['cobro_cliente_id']);
$vence = date('d/m/Y', strtotime((string)$p['creado_en'] . ' +' . (int)$p['validez_dias'] . ' days'));
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title><?= $e($p['numero']) ?> | Presupuesto blens.ar</title><link rel="icon" href="../assets/favicon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="../assets/apple-touch-icon.png"><link rel="stylesheet" href="admin.css?v=20"></head><body class="presu-print">
<div class="p-tools">
    <a class="btn g" href="presupuestos.php">&larr; Presupuestos</a>
    <button type="button" data-print>Imprimir / Guardar PDF</button>
    <?php if ($wa): ?><a class="btn g" href="<?= $e($wa) ?>" target="_blank" rel="noopener">Enviar por WhatsApp</a><?php endif; ?>
    <?php if ($tieneCobro): ?><a class="btn g" href="cobros.php?c=<?= (int)$p['cobro_cliente_id'] ?>">Ver cobro</a>
    <?php else: ?><form method="post" action="presupuestos.php"><input type="hidden" name="a" value="aceptar"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="g">Aceptado → Crear cobro</button></form><?php endif; ?>
</div>
<?php if ($ok): ?><p class="ok p-aviso"><?= $e($ok) ?></p><?php endif; ?>
<div class="p-sheet">
    <div class="p-head">
        <div>
            <img class="p-logo" src="../assets/brand/blens_logo-horizontal_color_fondo-claro.png" alt="blens.ar">
            <p class="p-tag">Contenido audiovisual para empresas</p>
        </div>
        <div class="p-meta">
            <h1>Presupuesto</h1>
            <p><b><?= $e($p['numero']) ?></b></p>
            <p><?= $e(date('d/m/Y', strtotime((string)$p['creado_en']))) ?></p>
            <p class="p-valid">Válido hasta <?= $e($vence) ?> · <?= (int)$p['validez_dias'] ?> días</p>
        </div>
    </div>
    <?php if ($tieneCobro): ?><div class="p-stamp">Aceptado</div><?php endif; ?>
    <div class="p-client">
        <span class="p-k">Cliente</span>
        <b><?= $e($p['empresa'] ?: $p['cliente_nombre']) ?></b>
        <?php if ($p['empresa'] && $p['empresa'] !== $p['cliente_nombre']): ?><span><?= $e($p['cliente_nombre']) ?></span><?php endif; ?>
        <?php if (trim((string)$p['telefono']) !== ''): ?><span><?= $e($p['telefono']) ?></span><?php endif; ?>
    </div>
    <table class="p-table"><tr><th>Descripción</th><th class="n">Cant.</th><th class="n">Precio unitario</th><th class="n">Subtotal</th></tr>
    <?php foreach ($items as $it): ?><tr><td><?= $e($it['d']) ?></td><td class="n"><?= $e(presu_cant((float)$it['c'])) ?></td><td class="n"><?= $e(presu_money((float)$it['p'])) ?></td><td class="n"><?= $e(presu_money((float)$it['s'])) ?></td></tr><?php endforeach; ?>
    </table>
    <div class="p-total"><span>Total</span><b><?= $e(presu_money($total)) ?></b></div>
    <?php if (trim((string)$p['notas']) !== ''): ?><div class="p-notes"><span class="p-k">Notas y condiciones</span><p><?= nl2br($e($p['notas'])) ?></p></div><?php endif; ?>
    <div class="p-foot"><span>blens.ar</span><span>Presupuesto <?= $e($p['numero']) ?></span></div>
</div>
<script src="admin.js?v=19"></script></body></html>
