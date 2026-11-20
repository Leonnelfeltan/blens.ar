<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
session_name('blens_cliente');
session_start();
header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY');
$pdo = blens_db(); blens_migrar($pdo); $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tipoDe = fn(array $r): string => ((string)($r['tipo'] ?? 'video') === 'fotos') ? 'fotos' : 'video';
$ajaxFeedback = (string)($_POST['ajax'] ?? '') === '1' || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';
$jsonOut = function (int $code, array $data): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};
$_SESSION['t'] ??= bin2hex(random_bytes(16)); $msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['t'], (string)($_POST['t'] ?? ''))) {
        if ($ajaxFeedback) $jsonOut(400, ['ok' => false, 'error' => 'Sesión vencida. Recargá la página.']);
        http_response_code(400); exit('Sesión vencida. Recargá la página.');
    }
    $a = $_POST['a'] ?? '';
    if ($a === 'salir') { blens_session_expire(); header('Location: ./'); exit; }
    if ($a === 'entrar' && ($w = blens_bloqueado('portal'))) { $msg = 'Demasiados intentos. Probá de nuevo en ' . (int)ceil($w / 60) . ' min.'; }
    elseif ($a === 'entrar') {
        $s = $pdo->prepare('SELECT id,clave_hash,cambiar_clave,clave_inicial_hasta FROM clientes WHERE usuario=? AND activo=1'); $s->execute([strtolower(trim((string)($_POST['u'] ?? '')))]); $c = $s->fetch();
        $ok = password_verify((string)($_POST['p'] ?? ''), $c['clave_hash'] ?? '$2y$10$.vGA1O9wmRjrwAVXD98HNOgsNpDczlqm3Jq7KnEd1rVAGv3Fykk1a') && $c;
        if ($ok && $c['cambiar_clave'] && $c['clave_inicial_hasta'] && strtotime((string)$c['clave_inicial_hasta']) < time()) { usleep(900000); $msg = 'Tu clave inicial venció. Escribinos y la renovamos.'; }
        elseif ($ok) {
            blens_ok('portal'); session_regenerate_id(true); $_SESSION['cid'] = (int)$c['id']; $_SESSION['t'] = bin2hex(random_bytes(16));
            unset($_SESSION['visita_ok']);   // la próxima carga cuenta como una visita nueva al portal
            if ($c['cambiar_clave']) $_SESSION['forzar'] = 1; else unset($_SESSION['forzar']);
            header('Location: ./'); exit;
        } else { blens_fallo('portal'); usleep(900000); $msg = 'Usuario o clave incorrectos.'; }
    }
    if ($a === 'cambiar' && !empty($_SESSION['cid']) && !empty($_SESSION['forzar'])) {
        $p1 = (string)($_POST['p1'] ?? ''); $p2 = (string)($_POST['p2'] ?? '');
        if (mb_strlen($p1) < 6) $msg = 'La clave tiene que tener al menos 6 caracteres.';
        elseif ($p1 !== $p2) $msg = 'Las dos claves no coinciden.';
        elseif ($p1 === CLAVE_INICIAL) $msg = 'Elegí una clave distinta de la inicial.';
        else {
            $pdo->prepare('UPDATE clientes SET clave_hash=?,cambiar_clave=0,clave_inicial_hasta=NULL WHERE id=?')->execute([password_hash($p1, PASSWORD_DEFAULT), (int)$_SESSION['cid']]);
            unset($_SESSION['forzar']); header('Location: ./'); exit;
        }
    }
}
$cid = (int)($_SESSION['cid'] ?? 0); $T = $e($_SESSION['t']); $cli = null; $forzar = !empty($_SESSION['forzar']); $vista = !empty($_SESSION['vista']);
if ($cid) { $s = $pdo->prepare('SELECT nombre,empresa,logo,gestion_locaciones,etapa_proyecto FROM clientes WHERE id=? AND (activo=1 OR ?=1)'); $s->execute([$cid, $vista ? 1 : 0]); $cli = $s->fetch(); if (!$cli) { blens_session_expire(); header('Location: ./'); exit; } }
$locOK = (int)($cli['gestion_locaciones'] ?? 0) === 1;
$tabLoc = $locOK && (string)($_GET['tab'] ?? '') === 'locaciones';
// ---- Propiedades / locaciones del cliente ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['a'] ?? ''), ['loc_nueva', 'loc_editar', 'loc_borrar'], true)) {
    $accionLoc = (string)$_POST['a'];
    if (!$cid || $vista || !$locOK) { http_response_code(403); exit('Acceso denegado.'); }
    $lid = (int)($_POST['id'] ?? 0);
    if ($accionLoc === 'loc_borrar') {
        $q = $pdo->prepare("SELECT nombre FROM cliente_locaciones WHERE id=? AND cliente_id=? AND estado='pendiente'");
        $q->execute([$lid, $cid]); $locBorrar = $q->fetch();
        if (!$locBorrar) { http_response_code(404); exit('Propiedad inexistente o no se puede borrar.'); }
        $pdo->prepare("DELETE FROM cliente_locaciones WHERE id=? AND cliente_id=? AND estado='pendiente'")->execute([$lid, $cid]);
        $_SESSION['pflash'] = 'Se eliminó la propiedad «' . (string)$locBorrar['nombre'] . '».';
        header('Location: ./?tab=locaciones'); exit;
    }
    $campo = fn(string $k, int $max): string => mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? ''))), 0, $max);
    $nombre = $campo('nombre', 160); $ubicacion = $campo('ubicacion', 255); $acceso = $campo('acceso', 160);
    $admin = $campo('administrador', 120); $tel = $campo('telefono_contacto', 50);
    $horario = $campo('horario_acordado', 120); $notas = $campo('notas', 4000);
    $marcados = $_POST['tipo_contenido'] ?? [];
    if (!is_array($marcados)) $marcados = [];
    $tipos = array_values(array_intersect(array_map('strval', $marcados), array_keys(locaciones_contenidos())));
    $tipo = implode(', ', $tipos);
    if ($nombre === '' || $ubicacion === '') {
        $_SESSION['pflash'] = 'Completá el nombre y la ubicación de la propiedad.';
        header('Location: ./?tab=locaciones'); exit;
    }
    try {
        $datos = [$nombre, $ubicacion, $acceso !== '' ? $acceso : null, $admin !== '' ? $admin : null, $tel !== '' ? $tel : null, $horario !== '' ? $horario : null, $tipo !== '' ? $tipo : null, $notas !== '' ? $notas : null];
        if ($accionLoc === 'loc_nueva') {
            $pdo->prepare('INSERT INTO cliente_locaciones (cliente_id,nombre,ubicacion,acceso,administrador,telefono_contacto,horario_acordado,tipo_contenido,notas) VALUES (?,?,?,?,?,?,?,?,?)')->execute(array_merge([$cid], $datos));
            $lid = (int)$pdo->lastInsertId();
            $_SESSION['pflash'] = 'Propiedad cargada. Ya se avisó al equipo de blens.ar.';
            locaciones_push($pdo, $cid, $lid, $nombre, 'alta');
        } else {
            $q = $pdo->prepare('SELECT id FROM cliente_locaciones WHERE id=? AND cliente_id=?'); $q->execute([$lid, $cid]);
            if (!$q->fetch()) { http_response_code(404); exit('Propiedad inexistente.'); }
            $pdo->prepare('UPDATE cliente_locaciones SET nombre=?,ubicacion=?,acceso=?,administrador=?,telefono_contacto=?,horario_acordado=?,tipo_contenido=?,notas=? WHERE id=? AND cliente_id=?')->execute(array_merge($datos, [$lid, $cid]));
            $_SESSION['pflash'] = 'Propiedad actualizada. Ya se avisó al equipo de blens.ar.';
            locaciones_push($pdo, $cid, $lid, $nombre, 'modificacion');
        }
    } catch (Throwable $x) {
        error_log('[blens portal locaciones] ' . $x->getMessage());
        $_SESSION['pflash'] = 'No pudimos guardar la propiedad. Probá de nuevo en un momento.';
    }
    header('Location: ./?tab=locaciones'); exit;
}
// ---- Feedback del cliente sobre una entrega (pedir ajuste) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['a'] ?? '') === 'ajuste') {
    $eid = (int)($_POST['e'] ?? 0);
    $com = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', trim((string)($_POST['comentario'] ?? ''))) ?? '';
    $com = mb_substr($com, 0, 1000);
    if (!$cid || $vista || $eid <= 0) {
        if ($ajaxFeedback) $jsonOut(403, ['ok' => false, 'error' => 'Acceso denegado.']);
        http_response_code(403); exit('Acceso denegado.');
    }
    $q = $pdo->prepare('SELECT id,titulo,tipo FROM entregas WHERE id=? AND cliente_id=?'); $q->execute([$eid, $cid]); $enFb = $q->fetch();
    if (!$enFb) {
        if ($ajaxFeedback) $jsonOut(404, ['ok' => false, 'error' => 'Entrega inexistente.']);
        http_response_code(404); exit('Entrega inexistente.');
    }
    if ($com === '') {
        if ($ajaxFeedback) $jsonOut(422, ['ok' => false, 'error' => 'Escribí qué ajuste necesitás antes de enviar.']);
        $_SESSION['pflash'] = 'Escribí qué ajuste necesitás antes de enviar.';
        header('Location: ./'); exit;
    }
    try {
        $pdo->prepare("UPDATE entregas SET estado_cliente='ajuste', comentario_cliente=?, comentario_fecha=NOW() WHERE id=? AND cliente_id=?")->execute([$com, $eid, $cid]);
    } catch (Throwable $x) {
        error_log('[blens portal feedback] ' . $x->getMessage());
        if ($ajaxFeedback) $jsonOut(500, ['ok' => false, 'error' => 'No pudimos guardar tu respuesta. Probá de nuevo en un momento.']);
        $_SESSION['pflash'] = 'No pudimos guardar tu respuesta. Probá de nuevo en un momento.';
        header('Location: ./'); exit;
    }
    try {
        $qc = $pdo->prepare('SELECT nombre,empresa FROM clientes WHERE id=?'); $qc->execute([$cid]); $cf = $qc->fetch() ?: [];
        $empresa = trim((string)($cf['empresa'] ?? '')) ?: trim((string)($cf['nombre'] ?? '')) ?: 'Cliente';
        blens_push_notify($pdo, 'admin', null, [
            'title' => 'Pedido de ajuste · blens.ar',
            'body' => $empresa . ' — ' . (string)$enFb['titulo'] . ': ' . $com,
            'url' => '/admin/clientes.php?c=' . $cid . '&e=' . $eid,
            'tag' => 'blens-feedback-' . $eid,
        ]);
    } catch (Throwable $x) {
        error_log('[blens portal push ajuste] ' . $x->getMessage());
    }
    $msj = 'Recibimos tu pedido de ajuste.';
    $_SESSION['pflash'] = $msj;
    if ($ajaxFeedback) $jsonOut(200, ['ok' => true, 'estado' => 'ajuste', 'badge' => 'Pidió ajuste: "' . $com . '"', 'message' => $msj]);
    header('Location: ./'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['a'] ?? '') === 'aprobar') {
    $eid = (int)($_POST['e'] ?? 0);
    if (!$cid || $vista || $eid <= 0) {
        if ($ajaxFeedback) $jsonOut(403, ['ok' => false, 'error' => 'Acceso denegado.']);
        http_response_code(403); exit('Acceso denegado.');
    }
    $q = $pdo->prepare('SELECT id,titulo FROM entregas WHERE id=? AND cliente_id=?'); $q->execute([$eid, $cid]); $enAp = $q->fetch();
    if (!$enAp) {
        if ($ajaxFeedback) $jsonOut(404, ['ok' => false, 'error' => 'Entrega inexistente.']);
        http_response_code(404); exit('Entrega inexistente.');
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE entregas SET estado_cliente='aprobado', comentario_cliente=NULL, comentario_fecha=NOW() WHERE id=? AND cliente_id=?")->execute([$eid, $cid]);
        $pdo->prepare('UPDATE clientes SET etapa_proyecto=5 WHERE id=?')->execute([$cid]);
        $pdo->commit();
    } catch (Throwable $x) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[blens portal aprobación] ' . $x->getMessage());
        if ($ajaxFeedback) $jsonOut(500, ['ok' => false, 'error' => 'No pudimos guardar tu respuesta. Probá de nuevo en un momento.']);
        $_SESSION['pflash'] = 'No pudimos guardar tu respuesta. Probá de nuevo en un momento.';
        header('Location: ./'); exit;
    }
    try {
        $qc = $pdo->prepare('SELECT nombre,empresa FROM clientes WHERE id=?'); $qc->execute([$cid]); $cf = $qc->fetch() ?: [];
        $empresa = trim((string)($cf['empresa'] ?? '')) ?: trim((string)($cf['nombre'] ?? '')) ?: 'Cliente';
        blens_push_notify($pdo, 'admin', null, [
            'title' => 'Entrega aprobada · blens.ar',
            'body' => $empresa . ' — ' . (string)$enAp['titulo'],
            'url' => '/admin/clientes.php?c=' . $cid . '&e=' . $eid,
            'tag' => 'blens-aprobada-' . $eid,
        ]);
    } catch (Throwable $x) {
        error_log('[blens portal push aprobación] ' . $x->getMessage());
    }
    $msj = 'Marcamos la entrega como aprobada.';
    $_SESSION['pflash'] = $msj;
    if ($ajaxFeedback) $jsonOut(200, ['ok' => true, 'estado' => 'aprobado', 'badge' => 'Aprobado ✓', 'message' => $msj]);
    header('Location: ./'); exit;
}
$pflash = (string)($_SESSION['pflash'] ?? ''); unset($_SESSION['pflash']);
// ---- Actividad del portal: una visita por sesión (no por recarga) ----
if ($cid && empty($_SESSION['vista']) && empty($_SESSION['visita_ok'])) {
    $_SESSION['visita_ok'] = 1;
    try { blens_migrar($pdo); $pdo->prepare('UPDATE clientes SET portal_visitas=portal_visitas+1, portal_ultima_visita=NOW() WHERE id=?')->execute([$cid]); }
    catch (Throwable $x) { error_log('[blens portal] ' . $x->getMessage()); }
}
$wa = blens_wa_numero();
$contacto = $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode('Hola, quiero coordinar un plan con blens.ar') : '../#contacto';
$drive = fn($r) => $r['link_hd'] ?: $r['link_4k'];
$vid = fn($r) => $r['preview'] ? 'media.php?e=' . (int)$r['id'] . '&t=v' : ($r['pv'] ? '../' . $r['pv'] : '');
$pst = fn($r) => $r['poster'] ? 'media.php?e=' . (int)$r['id'] . '&t=p' : ($r['pp'] ? '../' . blens_webp((string)$r['pp']) : '');
$did = function ($r) { foreach ([$r['link_hd'] ?? '', $r['link_4k'] ?? ''] as $l) { if (preg_match('~^https://drive\.google\.com/(?:file/(?:u/\d+/)?d/|open\?id=|uc\?(?:[^#]*&)?id=)([A-Za-z0-9_-]{10,})~', (string)$l, $m)) return $m[1]; } return ''; };
$SQL = 'SELECT e.*, COALESCE(p.video_480, p.video_mp4, p.video_1080) AS pv, p.poster AS pp FROM entregas e LEFT JOIN proyectos p ON p.id = e.proyecto_id WHERE e.cliente_id = ?';
$fb = function (array $r) use ($e, $T, $vista): string {
    $est = (string)($r['estado_cliente'] ?? 'pendiente'); $com = trim((string)($r['comentario_cliente'] ?? '')); $id = (int)$r['id'];
    $h = '<div class="fb" id="fb-' . $id . '">';
    if ($est === 'aprobado') $h .= '<p class="fb-badge ok" aria-live="polite">Aprobado ✓</p>';
    elseif ($est === 'ajuste') $h .= '<p class="fb-badge adj" aria-live="polite">Pidió ajuste: "' . $e($com) . '"</p>';
    else $h .= '<p class="fb-badge pend" aria-live="polite">Esperando tu respuesta</p>';
    if (!$vista) {
        $h .= '<div class="fb-acts"><form method="post" action="./" class="fb-ap" data-feedback-form="aprobar">'
            . '<input type="hidden" name="a" value="aprobar"><input type="hidden" name="t" value="' . $T . '"><input type="hidden" name="e" value="' . $id . '">'
            . '<button class="fb-btn ok" type="submit" data-feedback-btn="aprobar">Aprobar</button>'
            . '</form><div class="fb-adj"><form method="post" action="./" data-feedback-form="ajuste">'
            . '<input type="hidden" name="a" value="ajuste"><input type="hidden" name="t" value="' . $T . '"><input type="hidden" name="e" value="' . $id . '">'
            . '<label>¿Qué querés ajustar?<span class="fb-help">Indicá el segundo exacto para que encontremos el momento.</span><textarea name="comentario" rows="3" maxlength="1000" required placeholder="Ej: En el segundo 0:12 bajar un poco la música y corregir el texto final..."></textarea></label>'
            . '<button class="fb-btn adj" type="submit" data-feedback-btn="ajuste">Pedir ajuste</button>'
            . '</form></div></div>';
    }
    return $h . '</div>';
};
$locForm = function (array $r, bool $nueva) use ($e, $T): string {
    $tipos = array_map('trim', explode(',', (string)($r['tipo_contenido'] ?? '')));
    ob_start(); ?>
<form class="loc-form" method="post">
  <input type="hidden" name="a" value="<?= $nueva ? 'loc_nueva' : 'loc_editar' ?>"><input type="hidden" name="t" value="<?= $T ?>">
  <?php if (!$nueva): ?><input type="hidden" name="id" value="<?= (int)($r['id'] ?? 0) ?>"><?php endif; ?>
  <p class="loc-example"><b>Ejemplo:</b> Depto 4B · Edificio Costanera · Av. Costanera y Bolívar (link Maps) · Portería con llave · Timbre 402 · Juan Pérez · 3764... · Jueves 10:30 hs · Reels + Fotos</p>
  <label class="loc-field"><span>Nombre de la propiedad</span><input name="nombre" value="<?= $e($r['nombre'] ?? '') ?>" maxlength="160" required placeholder="Ej: Depto 4B · Edificio Costanera"></label>
  <label class="loc-field"><span>Ubicación / Dirección o link Maps</span><input name="ubicacion" value="<?= $e($r['ubicacion'] ?? '') ?>" maxlength="255" required placeholder="Ej: Av. Costanera y Bolívar (link Maps)" inputmode="url"></label>
  <label class="loc-field"><span>Piso / Acceso / Llave</span><input name="acceso" value="<?= $e($r['acceso'] ?? '') ?>" maxlength="160" placeholder="Ej: Portería con llave · Timbre 402"></label>
  <label class="loc-field"><span>Administrador / Encargado</span><input name="administrador" value="<?= $e($r['administrador'] ?? '') ?>" maxlength="120" placeholder="Ej: Juan Pérez"></label>
  <label class="loc-field"><span>Teléfono de contacto</span><input name="telefono_contacto" value="<?= $e($r['telefono_contacto'] ?? '') ?>" maxlength="50" type="tel" placeholder="Ej: 3764..."></label>
  <label class="loc-field"><span>Horario acordado</span><input name="horario_acordado" value="<?= $e($r['horario_acordado'] ?? '') ?>" maxlength="120" placeholder="Ej: Jueves 10:30 hs · Reels + Fotos"></label>
  <fieldset class="loc-chips"><legend>Qué grabar</legend>
    <?php foreach (locaciones_contenidos() as $k => $label): ?><label class="loc-chip"><input type="checkbox" name="tipo_contenido[]" value="<?= $e($k) ?>"<?= in_array($k, $tipos, true) ? ' checked' : '' ?>><span><?= $e($label) ?></span></label><?php endforeach; ?>
  </fieldset>
  <label class="loc-field"><span>Notas</span><textarea name="notas" rows="3" maxlength="4000" placeholder="Detalles a destacar o evitar"><?= $e($r['notas'] ?? '') ?></textarea></label>
  <button class="btn loc-submit" type="submit"><?= $nueva ? 'Cargar propiedad' : 'Guardar cambios' ?></button>
</form>
<?php return ob_get_clean();
};
?><!doctype html><html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex"><meta name="theme-color" content="#0b0b0b">
<title>Portal de clientes | blens.ar</title><link rel="icon" href="../assets/favicon.svg" type="image/svg+xml"><link rel="manifest" href="manifest.webmanifest"><link rel="apple-touch-icon" href="icons/apple-touch-icon.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Sora:wght@200;400;600;800&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@200;400;600;800&display=swap" media="print" data-fonts>
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@200;400;600;800&display=swap"></noscript>
<link rel="stylesheet" href="../styles.css?v=25"><link rel="stylesheet" href="portal.css?v=20"></head><body>
<svg class="sprite" aria-hidden="true"><symbol id="mark" viewBox="0 0 130 130"><polygon points="0,0 70,0 100,30 100,100 0,100" fill="#F5F1EA"/><polygon points="60,45 125,45 125,125 45,125 45,60" fill="#F5F1EA"/><polygon points="60,45 100,45 100,64 86,72 100,80 100,100 45,100 45,60" fill="#FF5A1F"/></symbol><symbol id="photos" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m4 17 5-5 3.5 3.5L16 12l4 4"/></symbol></svg>
<header class="ph"><a class="brand" href="../" aria-label="blens.ar, inicio"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span></a>
<?php if ($cli): ?><div class="phr"><span class="mut">Portal de clientes</span><?= $vista ? '<span class="perr">Vista de administrador</span>' : '' ?><button type="button" class="push-btn" data-push data-push-tipo="cliente" data-push-token="<?= $T ?>" data-push-key="<?= $e(blens_vapid_public()) ?>" data-push-off="Activar notificaciones en este dispositivo" data-push-on="Notificaciones activadas ✓" aria-pressed="false">Activar notificaciones en este dispositivo</button><form method="post"><input type="hidden" name="a" value="salir"><input type="hidden" name="t" value="<?= $T ?>"><button class="out">Salir</button></form></div>
<?php else: ?><a class="link" href="../">Volver a la web</a><?php endif; ?></header>
<?php if ($pflash): ?><p class="pflash" role="status" aria-live="polite"><?= $e($pflash) ?></p><?php endif; ?>
<?php if ($cli): ?>
<section class="pnotif" data-push-card role="status" aria-live="polite" hidden>
  <span class="pnotif-ico" aria-hidden="true"></span>
  <div class="pnotif-copy"><b>Enterate apenas subamos material</b><span data-push-card-msg>Activá las notificaciones para recibir un aviso en este dispositivo cuando haya una nueva entrega o una respuesta.</span></div>
  <button type="button" class="btn sm" data-push data-push-tipo="cliente" data-push-token="<?= $T ?>" data-push-key="<?= $e(blens_vapid_public()) ?>" data-push-off="Activar notificaciones" data-push-on="Activado ✓">Activar notificaciones</button>
  <button type="button" class="pnotif-close" data-push-card-close aria-label="Cerrar aviso de notificaciones">×</button>
</section>
<?php endif; ?>
<?php if ($cli && $locOK): ?>
<nav class="loc-tabs" aria-label="Secciones del portal">
  <a class="loc-tab<?= $tabLoc ? '' : ' on' ?>" href="./"<?= $tabLoc ? '' : ' aria-current="page"' ?>>Mis entregas</a>
  <a class="loc-tab<?= $tabLoc ? ' on' : '' ?>" href="./?tab=locaciones"<?= $tabLoc ? ' aria-current="page"' : '' ?>>Propiedades para fotos/videos</a>
</nav>
<?php endif; ?>
<?php if (!$cli): ?>
<main class="lgbox"><form class="lgf" method="post"><h1 class="lgh">Acceso clientes</h1>
<?php if ($msg) echo '<p class="perr">' . $e($msg) . '</p>'; ?>
<input type="hidden" name="a" value="entrar"><input type="hidden" name="t" value="<?= $T ?>">
<label>Usuario<input name="u" autocomplete="username" autocapitalize="none" required></label><label>Clave<input name="p" type="password" autocomplete="current-password" required></label><button class="btn">Entrar</button>
<p class="hint"><b>¿Primera vez?</b> Entrá con el usuario y la clave inicial que te pasamos. Al ingresar te pedimos que elijas la tuya.</p></form>
<div class="noc"><b>¿Todavía no sos cliente?</b><span class="mut">Contactanos para coordinar tu plan.</span><a class="btn" href="<?= $e($contacto) ?>" <?= $wa ? 'target="_blank" rel="noopener"' : '' ?>><?= $wa ? 'Escribinos por WhatsApp' : 'Contactanos' ?></a></div></main>
<?php elseif ($forzar): ?>
<main class="lgbox"><form class="lgf" method="post"><h1 class="lgh">Elegí tu clave</h1><p class="mut">Es tu primer ingreso. Elegí una clave nueva (mínimo 6 caracteres) para entrar la próxima vez.</p>
<?php if ($msg) echo '<p class="perr">' . $e($msg) . '</p>'; ?>
<input type="hidden" name="a" value="cambiar"><input type="hidden" name="t" value="<?= $T ?>">
<label>Clave nueva<input name="p1" type="password" autocomplete="new-password" minlength="6" required></label><label>Repetir clave<input name="p2" type="password" autocomplete="new-password" minlength="6" required></label><button class="btn">Guardar y entrar</button></form></main>
<?php else:
$id = (int)($_GET['e'] ?? 0); $en = null;
if ($id) { $s = $pdo->prepare($SQL . ' AND e.id = ?'); $s->execute([$cid, $id]); $en = $s->fetch() ?: null; }
if ($en): $tipoEn = $tipoDe($en); ?>
<main class="pt"><div class="pd"><div>
<?php if ($tipoEn === 'fotos'): ?>
  <div class="gal">
    <svg class="gal-ico"><use href="#photos"/></svg>
    <b>Pack de fotos</b>
    <p class="mut sm">Tus fotos están en Google Drive. Abrí la carpeta para verlas y descargarlas.</p>
    <?php if ($drive($en)): ?><a class="btn gal-btn" href="<?= $e($drive($en)) ?>" target="_blank" rel="noopener noreferrer">Ver y descargar fotos (Drive)</a><?php else: ?><p class="mut">El enlace todavía no está disponible.</p><?php endif; ?>
  </div>
<?php elseif ($vid($en)): ?><div class="cvw"><video playsinline preload="none" data-lazy<?= $pst($en) ? ' poster="' . $e($pst($en)) . '"' : '' ?>><source data-src="<?= $e($vid($en)) ?>" type="video/mp4"></video></div><p class="mut sm">Vista previa del proyecto.</p>
<?php elseif ($df = $did($en)): ?><div class="cvw dfr" data-did="<?= $e($df) ?>"><iframe src="https://drive.google.com/file/d/<?= $e($df) ?>/preview" allow="autoplay; fullscreen" allowfullscreen loading="lazy" title="Vista previa"></iframe></div><p class="mut sm">Vista previa desde Google Drive.</p>
<?php endif; ?></div>
<div><a class="back" href="./">&larr; Mis entregas</a><h1><?= $e($en['titulo']) ?> <span class="ent-ver">v<?= max(1, (int)($en['version_num'] ?? 1)) ?></span></h1><p class="sub"><span class="b tipo-<?= $tipoEn ?>"><?= $tipoEn === 'fotos' ? 'FOTOS' : 'VIDEO' ?></span> <?= $e(substr($en['creado'], 0, 10)) ?> · <?= $tipoEn === 'fotos' ? 'Pack de fotos' : 'Video' ?></p>
<?php if (trim((string)$en['descripcion']) !== ''): ?><div class="copy"><?= nl2br($e($en['descripcion'])) ?></div><?php endif; ?>
<?php if ($tipoEn !== 'fotos'): ?><?php if ($drive($en)): ?><a class="btn" href="<?= $e($drive($en)) ?>" target="_blank" rel="noopener">Abrir en Drive / Descargar</a><p class="mut sm">El archivo final está en la mejor calidad, en Google Drive.</p><?php else: ?><p class="mut">El archivo final todavía no está disponible.</p><?php endif; ?><?php endif; ?>
<?= $fb($en) ?></div></div></main>
<?php else:
if ($tabLoc):
$sl = $pdo->prepare("SELECT * FROM cliente_locaciones WHERE cliente_id=? ORDER BY FIELD(estado,'pendiente','agendado','grabado'), id DESC"); $sl->execute([$cid]); $locs = $sl->fetchAll(); ?>
<main class="pt loc-pt">
  <h1>Propiedades para fotos/videos</h1>
  <p class="sub">Cargá cada locación con sus datos de acceso y contacto. Cada cambio avisa a blens.ar.</p>
  <section class="loc-form-card" id="loc-nueva">
    <h2>Nueva propiedad / locación</h2>
    <?= $locForm([], true) ?>
  </section>
  <h2>Mis propiedades (<?= count($locs) ?>)</h2>
  <?php if (!$locs): ?><p class="mut">Todavía no cargaste propiedades. Cargá la primera con el formulario de arriba.</p><?php endif; ?>
  <div class="loc-grid">
  <?php foreach ($locs as $l): $est = (string)$l['estado']; $adminNom = trim((string)$l['administrador']) !== '' ? trim((string)$l['administrador']) : 'encargado/a';
    $nLoc = blens_wa_num((string)$l['telefono_contacto']); $waLoc = $nLoc ? 'https://wa.me/' . $nLoc . '?text=' . rawurlencode('Hola ' . $adminNom . ', te escribo de blens.ar por el rodaje en ' . trim((string)$l['nombre']) . '.') : null; ?>
  <article class="loc-card" id="loc-<?= (int)$l['id'] ?>">
    <div class="loc-head"><h3><?= $e($l['nombre']) ?></h3><span class="loc-badge <?= $e($est) ?>"><?= $e(locaciones_estados()[$est] ?? $est) ?></span></div>
    <dl class="loc-meta">
      <div><dt>Ubicación</dt><dd><?= $e($l['ubicacion']) ?></dd></div>
      <?php if (trim((string)$l['acceso']) !== ''): ?><div><dt>Acceso</dt><dd><?= $e($l['acceso']) ?></dd></div><?php endif; ?>
      <?php if (trim((string)$l['administrador']) !== ''): ?><div><dt>Encargado</dt><dd><?= $e($l['administrador']) ?></dd></div><?php endif; ?>
      <?php if (trim((string)$l['telefono_contacto']) !== ''): ?><div><dt>Teléfono</dt><dd><?= $e($l['telefono_contacto']) ?></dd></div><?php endif; ?>
      <?php if (trim((string)$l['horario_acordado']) !== ''): ?><div><dt>Horario</dt><dd><?= $e($l['horario_acordado']) ?></dd></div><?php endif; ?>
      <?php if (trim((string)$l['tipo_contenido']) !== ''): ?><div><dt>Qué grabar</dt><dd><?= $e($l['tipo_contenido']) ?></dd></div><?php endif; ?>
      <?php if (trim((string)$l['notas']) !== ''): ?><div class="loc-notes"><dt>Notas</dt><dd><?= nl2br($e($l['notas'])) ?></dd></div><?php endif; ?>
    </dl>
    <div class="loc-actions"><a class="btn g" href="<?= $e(locaciones_maps((string)$l['ubicacion'])) ?>" target="_blank" rel="noopener noreferrer">Maps ↗</a><?php if ($waLoc): ?><a class="btn g" href="<?= $e($waLoc) ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a><?php endif; ?></div>
    <details class="loc-edit"><summary>Editar propiedad</summary><?= $locForm($l, false) ?></details>
    <?php if ($est === 'pendiente'): ?>
    <form class="loc-del" method="post"><input type="hidden" name="a" value="loc_borrar"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><label class="loc-chk"><input type="checkbox" required> Confirmo que quiero eliminar esta propiedad</label><button class="loc-del-btn" type="submit">Eliminar</button></form>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>
  </div>
</main>
<?php else:
$s = $pdo->prepare($SQL . ' ORDER BY e.id DESC'); $s->execute([$cid]); $es = $s->fetchAll();
$nVideos = count(array_filter($es, fn($r) => $tipoDe($r) === 'video')); $nFotos = count($es) - $nVideos;
$ft = (string)($_GET['ft'] ?? 'todos'); if (!in_array($ft, ['todos', 'video', 'fotos'], true)) $ft = 'todos';
$esView = $ft === 'todos' ? $es : array_values(array_filter($es, fn($r) => $tipoDe($r) === $ft)); ?>
<section class="pstage" aria-label="Etapa del proyecto">
  <div class="pstage-head"><span class="lbl">Etapa del proyecto</span><b><?= $e(etapa_proyecto_txt((int)($cli['etapa_proyecto'] ?? 1))) ?></b></div>
  <?= etapa_track_html((int)($cli['etapa_proyecto'] ?? 1)) ?>
</section>
<section class="pguide" aria-label="Guía rápida del portal">
  <details class="pguide-card" open>
    <summary>¿Cómo usar tu portal? (Guía rápida)</summary>
    <ol class="pguide-steps">
      <li><b>Revisá y descargá:</b> tocá el video o el pack de fotos para verlo a pantalla completa o abrir la máxima calidad en Drive.</li>
      <li><b>Aprobá o pedí cambios:</b> usá <em>Aprobar</em> o <em>Pedir ajuste</em> indicando el segundo exacto del video.</li>
      <li><b>Recibí avisos en tu celu:</b> tocá «Activar notificaciones en este dispositivo» para enterarte de cada entrega.</li>
    </ol>
    <details class="pguide-install">
      <summary>¿Cómo instalar la app en tu celular?</summary>
      <div class="pguide-devices">
        <p><b>iPhone</b><span>Safari: botón Compartir → Agregar a pantalla de inicio</span></p>
        <p><b>Android</b><span>Chrome: menú ⋮ → Instalar app</span></p>
      </div>
    </details>
  </details>
</section>
<?php if ($cli): ?>
<section class="pappcard" data-pwa-card role="status" aria-live="polite" hidden>
  <span class="papp-ico" aria-hidden="true"></span>
  <div class="papp-copy"><b>Tu portal, también como app</b><span data-pwa-msg>Instalá el portal en tu celular para entrar más rápido.</span></div>
  <button type="button" class="btn sm" data-pwa-install hidden>Instalar app</button>
  <button type="button" class="papp-close" data-pwa-close aria-label="Cerrar aviso de instalación">×</button>
</section>
<?php endif; ?>
<main class="pt"><?= $cli['logo'] ? '<img class="clogo" loading="lazy" src="../media/logos/' . $e($cli['logo']) . '" alt="" width="180" height="80">' : '' ?><h1>Hola, <?= $e(explode(' ', trim($cli['nombre']))[0]) ?></h1><p class="sub"><?= $cli['empresa'] ? $e($cli['empresa']) . ' · ' : '' ?>Tus entregas (<?= count($es) ?>)</p>
<div class="pf-filtros"><a class="pf-pill<?= $ft === 'todos' ? ' on' : '' ?>" href="./?ft=todos">Todos <b><?= count($es) ?></b></a><a class="pf-pill<?= $ft === 'video' ? ' on' : '' ?>" href="./?ft=video">Videos <b><?= $nVideos ?></b></a><a class="pf-pill<?= $ft === 'fotos' ? ' on' : '' ?>" href="./?ft=fotos">Fotos <b><?= $nFotos ?></b></a></div>
<?php if (!$esView) echo '<p class="mut">' . ($es ? 'No hay entregas de este tipo.' : 'Todavía no hay entregas cargadas.') . '</p>'; ?>
<div class="pg"><?php foreach ($esView as $r): $tf = $tipoDe($r); ?><article class="pc tipo-<?= $tf ?>"><a class="pc-img" href="?e=<?= (int)$r['id'] ?>" aria-label="Ver <?= $e($r['titulo']) ?>"><?php if ($tf === 'fotos'): ?><span class="pc-gal"><svg class="gal-ico"><use href="#photos"/></svg><b>Galería de fotos</b></span><?php else: ?><?= $pst($r) ? '<img src="' . $e($pst($r)) . '" alt="" loading="lazy" width="800" height="500">' : ($did($r) ? '<img src="https://drive.google.com/thumbnail?id=' . $e($did($r)) . '&amp;sz=w800" alt="" loading="lazy" width="800" height="500" referrerpolicy="no-referrer">' : '') ?><?php endif; ?><span class="pc-date"><?= $e(substr($r['creado'], 0, 10)) ?></span></a>
<div class="pc-body"><span class="pc-tipo tipo-<?= $tf ?>"><?= $tf === 'fotos' ? 'FOTOS' : 'VIDEO' ?></span><span class="ent-ver">v<?= max(1, (int)($r['version_num'] ?? 1)) ?></span><h3><a href="?e=<?= (int)$r['id'] ?>"><?= $e($r['titulo']) ?></a></h3><?php if (trim((string)$r['descripcion']) !== '') echo '<p class="pc-desc">' . $e($r['descripcion']) . '</p>'; ?>
<?php if ($drive($r)): ?><a class="btn" href="<?= $e($drive($r)) ?>" target="_blank" rel="<?= $tf === 'fotos' ? 'noopener noreferrer' : 'noopener' ?>"><?= $tf === 'fotos' ? 'Ver y descargar fotos (Drive)' : 'Abrir en Drive / Descargar' ?></a><?php endif; ?></div><?= $fb($r) ?></article><?php endforeach; ?></div></main>
<?php endif; endif; endif; ?>
<aside class="pwa-tip" data-pwa-tip hidden><span data-pwa-msg>Instalá el portal en tu celular para entrar más rápido.</span><button type="button" class="pwa-tip-btn" data-pwa-install hidden>Instalar app</button><button type="button" class="pwa-tip-close" data-pwa-close aria-label="Cerrar">×</button></aside>
<footer class="foot"><svg class="mark"><use href="#mark"/></svg><span class="wm">blens<i></i><b>ar</b></span><span class="loc">Posadas, Misiones · Argentina</span><nav class="social"><a href="../">Web</a><a href="<?= $e($contacto) ?>">Contacto</a></nav><span class="mut">© 2026 blens.ar</span></footer>
<script src="../assets/fonts.js?v=2" defer></script><script src="../assets/player.js?v=13" defer></script><script src="dfr.js?v=14" defer></script><script src="feedback.js?v=1" defer></script><script src="../assets/push.js?v=8" defer></script></body></html>
