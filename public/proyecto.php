<?php
declare(strict_types=1);
require __DIR__ . '/../blens-config/bootstrap.php';
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$id = (int)($_GET['id'] ?? 0); $p = null;
try {
    $db = blens_db(); blens_migrar($db);
    try {
        $s = $db->prepare("SELECT p.*, c.logo AS clogo, e.titulo AS entrega_titulo, e.descripcion AS entrega_descripcion, e.tipo AS entrega_tipo, e.link_hd AS entrega_link, e.link_4k AS entrega_link4 FROM proyectos p LEFT JOIN clientes c ON c.id = p.cliente_id LEFT JOIN entregas e ON e.id = p.entrega_id WHERE p.id = ? AND p.visible = 1 AND p.categoria <> 'showreel' AND (p.estado = 'listo' OR p.video_mp4 IS NOT NULL OR p.entrega_id IS NOT NULL)");
        $s->execute([$id]); $p = $s->fetch() ?: null;
    } catch (Throwable $x0) {
        $s = $db->prepare("SELECT p.*, c.logo AS clogo FROM proyectos p LEFT JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ? AND p.visible = 1 AND p.categoria <> 'showreel'");
        $s->execute([$id]); $p = $s->fetch() ?: null;
    }
} catch (Throwable $x) { error_log('[blens proyecto] ' . $x->getMessage()); }
if (!$p) { http_response_code(404); readfile(__DIR__ . '/404.html'); exit; }
$cats = ['reels' => 'Reels', 'cobertura' => 'Cobertura', 'estrategia' => 'Estrategia'];
$cat = (string)($p['categoria'] ?? '');
if ($cat === '' || $cat === 'auto') $cat = 'reels';
$catLabel = $cats[$cat] ?? ucfirst(str_replace('-', ' ', $cat));
$clienteWeb = (int)($p['mostrar_cliente'] ?? 1) === 1 ? (string)($p['cliente'] ?? '') : '';
$desc = $p['descripcion'] ?: ($p['entrega_descripcion'] ?: ($p['servicios'] ?: 'Proyecto audiovisual de blens.ar'));
$poster = blens_webp((string)($p['poster'] ?? ''));
$clogo = '';
if ($clienteWeb !== '') {
    $logo = trim((string)($p['clogo'] ?? ''));
    if ($logo !== '' && !preg_match('~^https?://~i', $logo)) {
        $rel = blens_webp('media/logos/' . basename(str_replace('\\', '/', $logo)));
        if (is_file(__DIR__ . '/' . $rel)) $clogo = $rel;
    }
}
$video = !empty($p['video_1080']) ? $p['video_1080'] : (!empty($p['video_mp4']) ? $p['video_mp4'] : null);
$entregaLink = (string)($p['entrega_link'] ?? '') ?: (string)($p['entrega_link4'] ?? '');
$driveId = $video ? '' : blens_drive_id($entregaLink);
if (!$poster && $driveId) $poster = 'https://drive.google.com/thumbnail?id=' . $driveId . '&sz=w800';
$sitio = rtrim((string)(blens_cfg()['sitio'] ?? 'https://blens.ar'), '/');
$canonical = $sitio . '/proyecto.php?id=' . (int)$p['id'];
$img = ($poster !== '' && !preg_match('~^https?://~i', $poster) && is_file(__DIR__ . '/' . ltrim($poster, '/'))) ? @getimagesize(__DIR__ . '/' . ltrim($poster, '/')) : false;
$iw = $img ? (int)$img[0] : 1200;
$ih = $img ? (int)$img[1] : 630;
$formato = blens_formato((string)($p['formato'] ?? ''));
if ($formato === '9:16' && (!empty($p['ancho']) || ($img && $iw > $ih))) $formato = '16:9';
$modificador = $formato === '16:9' ? 'pj-16-9' : 'pj-9-16';
$detalle = trim((string)($p['detalle'] ?? '')) !== '' ? (string)$p['detalle'] : $desc;
$upload = !empty($p['creado']) ? date('c', (int)strtotime((string)$p['creado'])) : date('c');
$waNum = blens_wa_numero();
$waMsg = "Hola Leo, vi el proyecto '" . $p['titulo'] . "' en blens.ar y quiero consultar por algo similar.";
$waHref = $waNum !== '' ? 'https://wa.me/' . $waNum . '?text=' . rawurlencode($waMsg) : 'wa.php?text=' . rawurlencode($waMsg);
$ld = [
    '@context' => 'https://schema.org', '@type' => 'VideoObject', 'name' => $p['titulo'], 'description' => $desc,
    'thumbnailUrl' => $poster !== '' ? $sitio . '/' . $poster : $sitio . '/assets/og.png',
    'uploadDate' => $upload, 'url' => $canonical,
    'publisher' => ['@type' => 'Organization', 'name' => 'blens.ar', 'url' => $sitio . '/'],
];
if ($video) { $ld['contentUrl'] = $sitio . '/' . $video; $ld['embedUrl'] = $sitio . '/' . $video; }
$ldJson = json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
?><!doctype html>
<html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= $e($p['titulo']) ?> | blens.ar</title>
<meta name="description" content="<?= $e($desc) ?>"><meta name="theme-color" content="#14110F">
<link rel="canonical" href="<?= $e($canonical) ?>">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<meta property="og:type" content="article"><meta property="og:site_name" content="blens.ar"><meta property="og:title" content="<?= $e($p['titulo']) ?>"><meta property="og:description" content="<?= $e($desc) ?>"><meta property="og:url" content="<?= $e($canonical) ?>">
<?php if ($poster !== ''): ?><meta property="og:image" content="<?= $e($sitio . '/' . $poster) ?>"><meta property="og:image:width" content="<?= $iw ?>"><meta property="og:image:height" content="<?= $ih ?>"><meta name="twitter:image" content="<?= $e($sitio . '/' . $poster) ?>"><?php endif; ?>
<?php if ($video): ?><meta property="og:video" content="<?= $e($sitio . '/' . $video) ?>"><meta property="og:video:type" content="video/mp4"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="<?= $e($p['titulo']) ?>"><meta name="twitter:description" content="<?= $e($desc) ?>">
<script type="application/ld+json"><?= $ldJson ?></script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Sora:wght@200;400;600;800&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@200;400;600;800&display=swap" media="print" data-fonts>
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@200;400;600;800&display=swap"></noscript>
<link rel="stylesheet" href="styles.css?v=26"></head><body>
<svg class="sprite" aria-hidden="true"><symbol id="mark" viewBox="0 0 130 130"><polygon points="0,0 70,0 100,30 100,100 0,100" fill="#F5F1EA"/><polygon points="60,45 125,45 125,125 45,125 45,60" fill="#F5F1EA"/><polygon points="60,45 100,45 100,64 86,72 100,80 100,100 45,100 45,60" fill="#FF5A1F"/></symbol></svg>
<header class="nav solid"><a class="brand" href="./" aria-label="blens.ar, inicio"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span></a>
<button class="burger" type="button" aria-label="Abrir menú" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span></button>
  <nav id="menu" aria-label="Principal"><a href="./#proyectos">Proyectos</a><a href="./#servicios">Servicios</a><a href="./#nosotros">Nosotros</a><a class="keep" href="portal/">Acceso clientes</a><a class="btn sm" href="./#contacto">Contacto</a></nav></header>
<main class="pj <?= $modificador ?>">
  <div class="pj-layout">
    <div class="pj-media">
      <?php if ($video): ?><div class="cvw"><video playsinline preload="none" data-lazy<?= $poster !== '' ? ' poster="' . $e($poster) . '"' : '' ?>><source data-src="<?= $e($video) ?>" type="video/mp4"></video></div>
      <?php elseif ($driveId): ?><div class="cvw dfr"><iframe src="https://drive.google.com/file/d/<?= $e($driveId) ?>/preview" allow="autoplay; fullscreen" allowfullscreen loading="lazy" title="Video del proyecto"></iframe></div><p class="mut sm"><a class="link" href="<?= $e($entregaLink) ?>" target="_blank" rel="noopener">Abrir video en Drive ↗</a></p>
      <?php elseif ($entregaLink && ($p['entrega_tipo'] ?? '') === 'fotos'): ?><div class="pj-gal"><b>Pack de fotos</b><p class="mut">Las fotos de este proyecto están en Google Drive.</p><a class="btn" href="<?= $e($entregaLink) ?>" target="_blank" rel="noopener noreferrer">Ver galería ↗</a></div><?php endif; ?>
    </div>
    <div class="pj-info">
      <a class="back" href="./#proyectos">&larr; Todos los proyectos</a>
      <p class="pj-cat"><?= $e($catLabel) ?></p>
      <h1><?= $e($p['titulo']) ?></h1>
      <div class="pj-meta-row">
        <?php if ($clienteWeb !== ''): ?><div class="blk"><h2>Cliente</h2><?php if ($clogo !== ''): ?><span class="clogo-box"><img class="clogo" loading="lazy" src="<?= $e($clogo) ?>" alt=""></span><?php endif; ?><p><?= $e($clienteWeb) ?></p></div><?php endif; ?>
        <?php if ($p['servicios']): ?><div class="blk"><h2>Qué hicimos</h2><p><?= $e($p['servicios']) ?></p></div><?php endif; ?>
        <?php if ($p['resultado']): ?><div class="blk"><h2>Resultado</h2><p><?= $e($p['resultado']) ?></p></div><?php endif; ?>
      </div>
      <?php if ($detalle !== ''): ?><div class="blk"><h2>El proyecto</h2><p><?= nl2br($e($detalle)) ?></p></div><?php endif; ?>
      <div class="cta2"><p>¿Querés algo así para tu empresa?</p><a class="btn" href="<?= $e($waHref) ?>" target="_blank" rel="noopener">Consultar por WhatsApp</a><a class="link" href="./#contacto">O completar el formulario</a></div>
    </div>
  </div>
</main>
<footer class="foot"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span><span class="loc">Posadas, Misiones · Argentina</span><span class="mut">© 2026 blens.ar</span></footer>
<script src="assets/fonts.js?v=2" defer></script><script src="assets/player.js?v=13" defer></script><script src="assets/nav.js?v=3" defer></script></body></html>
