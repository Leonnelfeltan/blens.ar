<?php
declare(strict_types=1);
require __DIR__ . '/../blens-config/bootstrap.php';
session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Robots-Tag: noindex,nofollow');
header('Cache-Control: no-store');
$pdo = blens_db();
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$token = trim((string)($_GET['t'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    ?><!doctype html><html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><title>Presupuesto no encontrado | blens.ar</title><link rel="icon" href="/assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/styles.css?v=26"></head><body><main class="err404"><h1>Presupuesto no encontrado.</h1><p class="lead">El link no es válido o venció.</p><p><a class="btn" href="/">Ir a blens.ar</a></p></main></body></html><?php
    exit;
}
$s = $pdo->prepare('SELECT * FROM presupuestos WHERE token=?');
$s->execute([$token]);
$p = $s->fetch();
if (!$p) {
    http_response_code(404);
    ?><!doctype html><html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><title>Presupuesto no encontrado | blens.ar</title><link rel="icon" href="/assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/styles.css?v=26"></head><body><main class="err404"><h1>Presupuesto no encontrado.</h1><p class="lead">El link no es válido o venció.</p><p><a class="btn" href="/">Ir a blens.ar</a></p></main></body></html><?php
    exit;
}
$items = presu_items((string)$p['items_json']);
$total = presu_total($items);
$estado = (string)$p['estado'];
$aceptado = $estado === 'aceptado';
$numero = trim((string)($p['numero'] ?? '')) ?: ('Presupuesto #' . (int)$p['id']);
$vence = date('d/m/Y', strtotime((string)$p['creado_en'] . ' +' . (int)$p['validez_dias'] . ' days'));
$condiciones = presu_condiciones($p);
$cliente = trim((string)($p['empresa'] ?? '')) ?: trim((string)($p['cliente_nombre'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $csrf = trim((string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $guard = $_SESSION['presu_csrf'][$token] ?? null;
    if (!is_array($guard) || !hash_equals((string)($guard['token'] ?? ''), $csrf) || time() - (int)($guard['at'] ?? 0) > 7200) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'message' => 'La sesión venció. Recargá la página para volver a intentarlo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($aceptado) {
        echo json_encode(['ok' => true, 'mensaje' => 'Este presupuesto ya estaba aceptado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $res = presu_aceptar_id($pdo, (int)$p['id']);
        unset($_SESSION['presu_csrf'][$token]);
        echo json_encode(['ok' => true, 'mensaje' => '¡Listo! Aceptamos tu presupuesto.'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $x) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => $x->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (!$aceptado) {
    $_SESSION['presu_csrf'] ??= [];
    if (!is_array($_SESSION['presu_csrf'])) $_SESSION['presu_csrf'] = [];
    $guard = $_SESSION['presu_csrf'][$token] ?? null;
    if (!is_array($guard) || time() - (int)($guard['at'] ?? 0) > 7200) {
        $guard = ['token' => bin2hex(random_bytes(16)), 'at' => time()];
        $_SESSION['presu_csrf'][$token] = $guard;
    }
    $csrf = (string)$guard['token'];
}
?><!doctype html>
<html lang="es-AR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?= $e($numero) ?> | Presupuesto blens.ar</title>
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/styles.css?v=26">
</head>
<body class="pub-presu">
<svg class="sprite" aria-hidden="true"><symbol id="mark" viewBox="0 0 130 130"><polygon points="0,0 70,0 100,30 100,100 0,100" fill="#F5F1EA"/><polygon points="60,45 125,45 125,125 45,125 45,60" fill="#F5F1EA"/><polygon points="60,45 100,45 100,64 86,72 100,80 100,100 45,100 45,60" fill="#FF5A1F"/></symbol></svg>
<header class="pub-presu-top">
  <a class="brand" href="/" aria-label="blens.ar, inicio"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span></a>
  <button class="btn sm" type="button" data-print>Imprimir / Guardar PDF</button>
</header>
<main class="pub-presu-sheet">
  <div class="pub-presu-head">
    <div>
      <img class="pub-presu-logo" src="/assets/brand/blens_logo-horizontal_color_fondo-oscuro.svg" width="180" height="48" alt="blens.ar">
      <p class="pub-presu-tag">Contenido audiovisual y desarrollo web para empresas</p>
    </div>
    <div class="pub-presu-meta">
      <span class="pub-presu-k">Presupuesto</span>
      <h1><?= $e($numero) ?></h1>
      <p><?= $e(date('d/m/Y', strtotime((string)$p['creado_en']))) ?></p>
      <p class="pub-presu-valid">Válido hasta <?= $e($vence) ?> · <?= (int)$p['validez_dias'] ?> días</p>
      <span class="pub-presu-badge <?= $aceptado ? 'ok' : '' ?>"><?= $e(presu_estado($estado)) ?></span>
      <span class="pub-presu-unit"><?= $e(presu_unidad_txt((string)($p['unidad_negocio'] ?? ''))) ?></span>
    </div>
  </div>
  <section class="pub-presu-client">
    <span class="pub-presu-k">Cliente</span>
    <b><?= $e($cliente) ?></b>
    <?php if (!empty($p['empresa']) && (string)$p['empresa'] !== (string)$p['cliente_nombre']): ?><span><?= $e($p['cliente_nombre']) ?></span><?php endif; ?>
  </section>
  <table class="pub-presu-table">
    <thead><tr><th>Descripción</th><th class="n">Cant.</th><th class="n">Precio unitario</th><th class="n">Subtotal</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?><tr><td><?= $e($it['d']) ?></td><td class="n"><?= $e(presu_cant((float)$it['c'])) ?></td><td class="n"><?= $e(presu_money((float)$it['p'])) ?></td><td class="n"><?= $e(presu_money((float)$it['s'])) ?></td></tr><?php endforeach; ?>
    </tbody>
  </table>
  <div class="pub-presu-total"><span>Total</span><b><?= $e(presu_money($total)) ?></b></div>
  <section class="pub-presu-cond"><span class="pub-presu-k">Condiciones</span><p><?= nl2br($e($condiciones)) ?></p></section>
  <div class="pub-presu-foot"><span>blens.ar</span><span><?= $e($numero) ?></span></div>
</main>
<section class="pub-presu-actions">
  <?php if ($aceptado): ?>
    <div class="pub-presu-done">
      <b>Presupuesto aceptado</b>
      <?php if (!empty($p['aceptado_en'])): ?><span><?= $e(date('d/m/Y H:i', strtotime((string)$p['aceptado_en']))) ?></span><?php endif; ?>
      <p>Gracias. Nos vamos a contactar para coordinar los detalles y la seña.</p>
    </div>
  <?php else: ?>
    <form id="presu-accept" method="post" action="/presupuesto.php?t=<?= $e(rawurlencode($token)) ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <button class="btn" type="submit" data-presu-accept>Aceptar presupuesto</button>
      <p class="pub-presu-help">Al aceptar se confirma el presupuesto y se genera la orden de trabajo.</p>
      <p class="pub-presu-msg" id="presu-msg" role="status" aria-live="polite"></p>
    </form>
  <?php endif; ?>
</section>
<footer class="foot"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span><nav class="social" aria-label="Contacto"><a href="mailto:hola@blens.ar">hola@blens.ar</a><a href="/">Web</a></nav><span class="loc">Posadas, Misiones · Argentina</span></footer>
<script src="/presupuesto.js?v=1" defer></script>
</body>
</html>
