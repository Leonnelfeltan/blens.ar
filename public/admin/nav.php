<?php
$navActual = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$navVista = (string)($_GET['v'] ?? '');
$navMas = in_array($navActual, ['consultas.php', 'presupuestos.php'], true) || ($navActual === 'index.php' && in_array($navVista, ['proyectos', 'consultas'], true));
$navInicio = $navActual === 'index.php' && !$navMas;
$navPdo = $pdo ?? blens_db();
$agN = $agN ?? agenda_pendientes($navPdo);
$consN = $consN ?? consultas_pendientes($navPdo);
$cobN = $cobN ?? cobros_pendientes($navPdo);
$navToken = htmlspecialchars((string)($_SESSION['t'] ?? ''), ENT_QUOTES, 'UTF-8');
$navVapid = htmlspecialchars(blens_vapid_public(), ENT_QUOTES, 'UTF-8');
$navCls = fn(bool $on): string => $on ? ' class="on" aria-current="page"' : '';
?>
<div class="top">
  <a class="brand" href="./"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span> · panel</a>
  <nav class="nav-desktop" aria-label="Navegación principal">
    <a href="./"<?= $navCls($navInicio) ?>>Inicio</a>
    <a href="consultas.php"<?= $navCls($navActual === 'consultas.php') ?>>Consultas<?php if ($consN > 0): ?><span class="nav-badge soft"><?= $consN ?></span><?php endif; ?></a>
    <a href="clientes.php"<?= $navCls($navActual === 'clientes.php') ?>>Clientes</a>
    <a href="cobros.php"<?= $navCls($navActual === 'cobros.php') ?>>Cobros<?php if ($cobN > 0): ?><span class="nav-badge soft"><?= $cobN ?></span><?php endif; ?></a>
    <a href="agenda.php"<?= $navCls($navActual === 'agenda.php') ?>>Agenda<?php if ($agN > 0): ?><span class="nav-badge"><?= $agN ?></span><?php endif; ?></a>
    <a href="presupuestos.php"<?= $navCls($navActual === 'presupuestos.php') ?>>Presupuestos</a>
    <a href="../portal/" target="_blank" rel="noopener">Ver portal</a>
  </nav>
  <div class="top-actions">
    <button type="button" class="push-btn" data-push data-push-tipo="admin" data-push-token="<?= $navToken ?>" data-push-key="<?= $navVapid ?>">Activar notificaciones push</button>
    <button type="button" class="pwa-install" data-pwa-install>Instalar app</button>
  </div>
  <form method="post" class="logout-form"><input type="hidden" name="a" value="logout"><input type="hidden" name="t" value="<?= $navToken ?>"><button class="g">Salir</button></form>
</div>
<nav class="mobile-nav" aria-label="Navegación principal">
  <a href="./"<?= $navCls($navInicio) ?>>Inicio</a>
  <a href="clientes.php"<?= $navCls($navActual === 'clientes.php') ?>>Clientes</a>
  <a href="cobros.php"<?= $navCls($navActual === 'cobros.php') ?>>Cobros</a>
  <a href="agenda.php"<?= $navCls($navActual === 'agenda.php') ?>>Agenda<?php if ($agN > 0): ?><span class="nav-badge"><?= $agN ?></span><?php endif; ?></a>
  <button type="button" data-more-toggle aria-expanded="false" aria-controls="nav-mas"<?= $navCls($navMas) ?>>Más</button>
</nav>
<div class="mobile-more" id="nav-mas" data-more-panel hidden>
  <a href="./?v=proyectos"<?= $navCls($navActual === 'index.php' && $navVista === 'proyectos') ?>>Proyectos</a>
  <a href="consultas.php"<?= $navCls($navActual === 'consultas.php') ?>>Consultas<?php if ($consN > 0): ?><span class="nav-badge soft"><?= $consN ?></span><?php endif; ?></a>
  <a href="presupuestos.php"<?= $navCls($navActual === 'presupuestos.php') ?>>Presupuestos</a>
  <a href="../portal/" target="_blank" rel="noopener">Ver portal</a>
  <button type="button" data-push data-push-tipo="admin" data-push-token="<?= $navToken ?>" data-push-key="<?= $navVapid ?>">Activar notificaciones push</button>
  <button type="button" data-pwa-install>Instalar app</button>
  <form method="post"><input type="hidden" name="a" value="logout"><input type="hidden" name="t" value="<?= $navToken ?>"><button class="g">Salir</button></form>
</div>
