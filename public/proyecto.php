<?php
declare(strict_types=1);
require __DIR__ . '/../blens-config/bootstrap.php';
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$id = (int)($_GET['id'] ?? 0); $p = null;
try {
    $s = blens_db()->prepare("SELECT p.*, c.logo AS clogo FROM proyectos p LEFT JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ? AND p.visible = 1 AND p.categoria <> 'showreel' AND (p.estado = 'listo' OR p.video_mp4 IS NOT NULL)");
    $s->execute([$id]); $p = $s->fetch() ?: null;
} catch (Throwable $x) { error_log('[blens proyecto] ' . $x->getMessage()); }
if (!$p) { http_response_code(404); readfile(__DIR__ . '/404.html'); exit; }
$cats = ['reels' => 'Reels', 'cobertura' => 'Cobertura', 'estrategia' => 'Estrategia'];
$cat = (string)($p['categoria'] ?? '');
if ($cat === '' || $cat === 'auto') $cat = 'reels';
$catLabel = $cats[$cat] ?? ucfirst(str_replace('-', ' ', $cat));
$sub = implode(' · ', array_filter([$p['cliente'], $catLabel]));
$desc = $p['descripcion'] ?: ($p['servicios'] ?: 'Proyecto audiovisual de blens.ar');
$video = ($p['video_1080'] ?? null) ?: ($p['video_mp4'] ?? null) ?: ($p['video_480'] ?? null);
?><!doctype html>
<html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= $e($p['titulo']) ?> | blens.ar</title>
<meta name="description" content="<?= $e($desc) ?>"><meta name="theme-color" content="#14110F">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<meta property="og:type" content="article"><meta property="og:site_name" content="blens.ar"><meta property="og:title" content="<?= $e($p['titulo']) ?>"><meta property="og:description" content="<?= $e($desc) ?>">
<?php if ($p['poster']) echo '<meta property="og:image" content="https://blens.ar/' . $e($p['poster']) . '">'; ?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Sora:wght@200;400;600;800&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@200;400;600;800&display=swap" media="print" data-fonts>
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@200;400;600;800&display=swap"></noscript>
<link rel="stylesheet" href="styles.css?v=18"></head><body>
<svg class="sprite" aria-hidden="true"><symbol id="mark" viewBox="0 0 130 130"><polygon points="0,0 70,0 100,30 100,100 0,100" fill="#F5F1EA"/><polygon points="60,45 125,45 125,125 45,125 45,60" fill="#F5F1EA"/><polygon points="60,45 100,45 100,64 86,72 100,80 100,100 45,100 45,60" fill="#FF5A1F"/></symbol></svg>
<header class="nav solid"><a class="brand" href="./" aria-label="blens.ar, inicio"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span></a>
<button class="burger" type="button" aria-label="Abrir menú" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span></button>
  <nav id="menu" aria-label="Principal"><a href="./#proyectos">Proyectos</a><a href="./#servicios">Servicios</a><a href="./#nosotros">Nosotros</a><a class="keep" href="portal/">Acceso clientes</a><a class="btn sm" href="./#contacto">Contacto</a></nav></header>
<main class="pj">
  <a class="back" href="./#proyectos">&larr; Todos los proyectos</a>
  <h1><?= $e($p['titulo']) ?></h1>
  <p class="sub"><?= $e($sub) ?></p>
  <?php if ($video): ?><div class="cvw pv2"><video playsinline preload="auto" data-auto<?= $p['poster'] ? ' poster="' . $e($p['poster']) . '"' : '' ?>><source src="<?= $e($video) ?>" type="video/mp4"></video></div><?php endif; ?>
  <div class="cols">
    <div>
      <?php if ($p['cliente']): ?><div class="blk"><h2>Cliente</h2><?= !empty($p['clogo']) ? '<img class="clogo" loading="lazy" src="media/logos/' . $e($p['clogo']) . '" alt="" width="180" height="80">' : '' ?><p><?= $e($p['cliente']) ?></p></div><?php endif; ?>
      <?php if ($p['servicios']): ?><div class="blk"><h2>Qué hicimos</h2><p><?= $e($p['servicios']) ?></p></div><?php endif; ?>
      <?php if ($p['resultado']): ?><div class="blk"><h2>Resultado</h2><p><?= $e($p['resultado']) ?></p></div><?php endif; ?>
    </div>
    <div><?php if ($p['detalle']): ?><div class="blk"><h2>El proyecto</h2><p><?= nl2br($e($p['detalle'])) ?></p></div><?php endif; ?></div>
  </div>
  <div class="cta2"><p>¿Querés algo así para tu empresa?</p><a class="btn" href="./#contacto">Contanos tu proyecto</a></div>
</main>
<footer class="foot"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span><span class="loc">Posadas, Misiones · Argentina</span><span class="mut">© 2026 blens.ar</span></footer>
<script src="assets/fonts.js?v=1" defer></script><script src="assets/player.js?v=11" defer></script><script src="assets/nav.js?v=2" defer></script></body></html>
