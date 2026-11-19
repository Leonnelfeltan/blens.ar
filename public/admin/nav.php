<?php
$navActual = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$navVista = (string)($_GET['v'] ?? '');
$navMas = $navActual === 'presupuestos.php' || ($navActual === 'index.php' && $navVista === 'proyectos');
$navInicio = $navActual === 'index.php' && !$navMas;
$navPdo = $pdo ?? blens_db();
$agN = $agN ?? agenda_pendientes($navPdo);
$consN = $consN ?? consultas_pendientes($navPdo);
$cobN = $cobN ?? cobros_pendientes($navPdo);
$navToken = htmlspecialchars((string)($_SESSION['t'] ?? ''), ENT_QUOTES, 'UTF-8');
$navVapid = htmlspecialchars(blens_vapid_public(), ENT_QUOTES, 'UTF-8');
$navCls = fn(bool $on): string => $on ? ' class="on" aria-current="page"' : '';
$navLink = function (string $href, string $label, bool $on, int $badge = 0, string $badgeCls = '') use ($navCls): string {
    $h = '<a href="' . $href . '"' . $navCls($on) . '>' . $label;
    if ($badge > 0) $h .= '<span class="nav-badge' . ($badgeCls !== '' ? ' ' . $badgeCls : '') . '">' . $badge . '</span>';
    return $h . '</a>';
};
$navEjes = [
    $navLink('./', 'Inicio', $navInicio),
    $navLink('clientes.php', 'Clientes', $navActual === 'clientes.php'),
    $navLink('cobros.php', 'Cobros', $navActual === 'cobros.php', $cobN, 'soft'),
    $navLink('agenda.php', 'Agenda', $navActual === 'agenda.php', $agN),
    $navLink('consultas.php', 'Consultas', $navActual === 'consultas.php', $consN, 'soft'),
];
?>
<div class="top">
  <a class="brand" href="./"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span> · panel</a>
  <nav class="nav-desktop" aria-label="Navegación principal">
    <?= implode("\n    ", $navEjes) ?>
    <button type="button" class="nav-mas-btn" data-more-toggle aria-expanded="false" aria-controls="nav-mas"<?= $navCls($navMas) ?>>Más ▾</button>
  </nav>
  <div class="mobile-more" id="nav-mas" data-more-panel hidden>
    <a href="./?v=proyectos"<?= $navCls($navActual === 'index.php' && $navVista === 'proyectos') ?>>Proyectos</a>
    <a href="presupuestos.php"<?= $navCls($navActual === 'presupuestos.php') ?>>Presupuestos</a>
    <a href="../portal/" target="_blank" rel="noopener">Ver portal</a>
    <button type="button" class="push-btn" data-push data-push-tipo="admin" data-push-token="<?= $navToken ?>" data-push-key="<?= $navVapid ?>" data-push-off="Notificaciones" data-push-on="Notificaciones ✓">Notificaciones</button>
    <button type="button" class="g" data-push-test>Probar push en este celu</button>
    <button type="button" class="g" data-pwa-install>Instalar app</button>
    <form method="post"><input type="hidden" name="a" value="logout"><input type="hidden" name="t" value="<?= $navToken ?>"><button class="g">Salir</button></form>
  </div>
</div>
<nav class="mobile-nav" aria-label="Navegación principal">
  <?= implode("\n  ", $navEjes) ?>
  <button type="button" data-more-toggle aria-expanded="false" aria-controls="nav-mas"<?= $navCls($navMas) ?>>Más ▾</button>
</nav>
