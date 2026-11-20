<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/drive.php';
session_start();
header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY');
if (empty($_SESSION['ok'])) { header('Location: ./'); exit; }
$pdo = blens_db();
$agN = agenda_pendientes($pdo);
$consN = consultas_pendientes($pdo);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tipoDe = fn(array $r): string => ((string)($r['tipo'] ?? 'video') === 'fotos') ? 'fotos' : 'video';
$msg = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$acc = $_SESSION['acceso'] ?? null; unset($_SESSION['acceso']);
const PRIV = '/var/www/blens-private/entregas';
const COLA = '/var/www/blens-private/cola';
const VIDEOS = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/x-matroska' => 'mkv', 'video/webm' => 'webm'];
function privado(?string $rel): ?string {
    if (!$rel) return null;
    if (!is_dir(PRIV)) @mkdir(PRIV, 0750, true);
    return @rename(__DIR__ . '/../' . $rel, PRIV . '/' . basename($rel)) ? basename($rel) : throw new RuntimeException('No se pudo guardar en la carpeta privada (permisos de /var/www/blens-private).');
}
function rmp(?string $f): void { if ($f) { @unlink(PRIV . '/' . basename($f)); @unlink(PRIV . '/' . basename($f) . '.name'); } }
function drive_ok(string $u): bool {
    if ($u === '') return true;
    return str_starts_with($u, 'https://') && in_array(strtolower((string)parse_url($u, PHP_URL_HOST)), ['drive.google.com', 'docs.google.com', 'drive.usercontent.google.com'], true);
}
function loc_agenda_descripcion(array $l): string {
    $lineas = [];
    foreach ([
        'Administrador' => 'administrador',
        'Teléfono' => 'telefono_contacto',
        'Horario acordado' => 'horario_acordado',
        'Tipo de contenido' => 'tipo_contenido',
        'Acceso' => 'acceso',
        'Notas' => 'notas',
    ] as $titulo => $campo) {
        $v = trim((string)($l[$campo] ?? ''));
        if ($v !== '') $lineas[] = $titulo . ': ' . $v;
    }
    return mb_substr(implode("\n", $lineas), 0, 4000);
}
function registrar_trabajo_entrega(PDO $pdo, int $clienteId, int $entregaId, string $titulo, string $tipo, array $post = []): void {
    $q = $pdo->prepare('SELECT 1 FROM trabajos WHERE entrega_id=? LIMIT 1'); $q->execute([$entregaId]);
    if ($q->fetchColumn()) return;
    $q = $pdo->prepare('SELECT tarifa_video,tarifa_foto FROM clientes WHERE id=?'); $q->execute([$clienteId]); $cl = $q->fetch();
    if (!$cl) return;
    $tipoTrabajo = $tipo === 'fotos' ? 'foto' : 'video';
    $tarifa = $tipo === 'fotos' ? ($cl['tarifa_foto'] ?? 0) : ($cl['tarifa_video'] ?? 0);
    $monto = round(max(0, (float)$tarifa), 2);
    $detalle = mb_substr(trim($titulo), 0, 200); if ($detalle === '') $detalle = $tipo === 'fotos' ? 'Pack de fotos' : 'Video';
    $pago = fin_datos_pago_post($post, $monto);
    $unidad = fin_unidad((string)($post['unidad_negocio'] ?? 'audiovisual'));
    $pdo->prepare('INSERT INTO trabajos (cliente_id,fecha,tipo,detalle,cantidad,monto_unit,prueba,estado,medio,fecha_pago,unidad_negocio,medio_pago,monto_senia,entrega_id) VALUES (?,?,?,?,1,?,?,?,?,?,?,?,?,?)')->execute([
        $clienteId, date('Y-m-d'), $tipoTrabajo, $detalle, $monto, modo_prueba($pdo) ? 1 : 0, $pago['estado'], $pago['medio'], $pago['fecha_pago'],
        $unidad, $pago['medio_pago'], $pago['abonado'], $entregaId,
    ]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['t'], (string)($_POST['t'] ?? ''))) { http_response_code(400); exit('Sesión vencida. Recargá la página.'); }
    $a = $_POST['a'] ?? ''; $c = (int)($_POST['cid'] ?? 0);
    try {
        if (($_POST['accion'] ?? '') === 'resolver_ajuste') {
            $eid = (int)($_POST['id'] ?? 0);
            if ($eid <= 0 || $c <= 0) throw new RuntimeException('Datos de entrega inválidos.');
            $s = $pdo->prepare("SELECT id FROM entregas WHERE id=? AND cliente_id=? AND estado_cliente='ajuste'"); $s->execute([$eid, $c]);
            if (!$s->fetch()) throw new RuntimeException('No hay un pedido de ajuste pendiente.');
            $estado = 'pendiente';
            $pdo->prepare("UPDATE entregas SET estado_cliente=COALESCE(?, 'pendiente'), comentario_cliente=NULL, comentario_fecha=NULL WHERE id=? AND cliente_id=? AND estado_cliente='ajuste'")->execute([$estado, $eid, $c]);
            $_SESSION['flash'] = 'Ajuste marcado como resuelto.'; header("Location: clientes.php?c=$c"); exit;
        }
        if ($a === 'etapa') {
            $n = (int)($_POST['etapa'] ?? 0);
            if ($c <= 0 || !isset(PROYECTO_ETAPAS[$n])) throw new RuntimeException('Etapa inválida.');
            $pdo->prepare('UPDATE clientes SET etapa_proyecto=? WHERE id=?')->execute([$n, $c]);
            $_SESSION['flash'] = 'Etapa actualizada: ' . etapa_proyecto_txt($n) . '.';
            header("Location: clientes.php?c=$c#tab-resumen"); exit;
        }
        if ($a === 'loc_estado') {
            $lid = (int)($_POST['id'] ?? 0); $estado = (string)($_POST['estado'] ?? '');
            if ($c <= 0 || $lid <= 0 || !isset(locaciones_estados()[$estado])) throw new RuntimeException('Datos de propiedad inválidos.');
            $q = $pdo->prepare('SELECT id FROM cliente_locaciones WHERE id=? AND cliente_id=?'); $q->execute([$lid, $c]);
            if (!$q->fetch()) throw new RuntimeException('Propiedad inexistente.');
            $pdo->prepare('UPDATE cliente_locaciones SET estado=? WHERE id=? AND cliente_id=?')->execute([$estado, $lid, $c]);
            $_SESSION['flash'] = 'Estado de la propiedad actualizado.'; header("Location: clientes.php?c=$c#locaciones"); exit;
        }
        if ($a === 'loc_agendar') {
            $lid = (int)($_POST['id'] ?? 0);
            if ($c <= 0 || $lid <= 0) throw new RuntimeException('Datos de propiedad inválidos.');
            $q = $pdo->prepare('SELECT * FROM cliente_locaciones WHERE id=? AND cliente_id=?'); $q->execute([$lid, $c]); $loc = $q->fetch();
            if (!$loc) throw new RuntimeException('Propiedad inexistente.');
            $qc = $pdo->prepare('SELECT nombre,empresa FROM clientes WHERE id=?'); $qc->execute([$c]); $clAg = $qc->fetch() ?: [];
            $clienteNom = trim((string)($clAg['empresa'] ?? '')) ?: trim((string)($clAg['nombre'] ?? '')) ?: 'Cliente';
            $titulo = mb_substr('Rodaje: ' . trim((string)$loc['nombre']) . ' (' . $clienteNom . ')', 0, 160);
            [$fecha, $hora] = locaciones_fecha_hora((string)$loc['horario_acordado']);
            $fechaPost = (string)($_POST['fecha'] ?? ''); $horaPost = trim((string)($_POST['hora'] ?? ''));
            if (fecha_ok($fechaPost)) $fecha = $fechaPost;
            if ($horaPost !== '') { $h = DateTime::createFromFormat('H:i', $horaPost); if ($h && $h->format('H:i') === $horaPost) $hora = $horaPost; }
            $dup = $pdo->prepare('SELECT id FROM agenda WHERE cliente_id=? AND titulo=? AND fecha=? LIMIT 1'); $dup->execute([$c, $titulo, $fecha]);
            if (!$dup->fetch()) {
                $pdo->prepare('INSERT INTO agenda (cliente_id,tipo,titulo,fecha,hora,lugar_nota,descripcion) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$c, 'rodaje', $titulo, $fecha, $hora !== '' ? $hora : null, mb_substr(trim((string)$loc['ubicacion']), 0, 255), loc_agenda_descripcion($loc)]);
            }
            $pdo->prepare("UPDATE cliente_locaciones SET estado='agendado' WHERE id=? AND cliente_id=?")->execute([$lid, $c]);
            $_SESSION['flash'] = 'Rodaje agendado para el ' . date('d/m/Y', strtotime($fecha)) . ($hora !== '' ? ' a las ' . $e($hora) : '') . '.';
            header("Location: clientes.php?c=$c#locaciones"); exit;
        }
        if ($a === 'cliente') {
            $n = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 80); $u = strtolower(trim((string)($_POST['usuario'] ?? '')));
            $tel = mb_substr(trim((string)($_POST['telefono'] ?? '')), 0, 30); $emp = mb_substr(trim((string)($_POST['empresa'] ?? '')), 0, 100);
            $tar = fn($k) => ($_POST[$k] ?? '') === '' ? null : (int)preg_replace('/\D/', '', (string)$_POST[$k]); $tv = $tar('tarifa_video'); $tf = $tar('tarifa_foto'); $act = isset($_POST['activo']) ? 1 : 0; $gl = isset($_POST['gestion_locaciones']) ? 1 : 0; $k = (string)($_POST['clave'] ?? ''); $gen = '';
            if ($n === '' || !preg_match('/^[a-z0-9._@-]{3,60}$/', $u)) throw new RuntimeException('Completá el nombre y un usuario de 3 a 60 caracteres (letras, números, punto, guion o @).');
            if ($k !== '' && mb_strlen($k) < 6) throw new RuntimeException('La clave tiene que tener al menos 6 caracteres.');
            $lg = up('logo', ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'], 5 * 1048576); $lg = $lg ? logo_proc($lg) : null;
            $lw = isset($_POST['logo_web']) ? 1 : 0; $quitar = isset($_POST['quitar_logo']); $oldLogo = null;
            if ($c) { $q0 = $pdo->prepare('SELECT logo FROM clientes WHERE id=?'); $q0->execute([$c]); $oldLogo = $q0->fetchColumn() ?: null; }
            if (!$c) {
                if ($k === '') { $k = blens_clave_nueva(); $gen = $k; }
                $pdo->prepare('INSERT INTO clientes (nombre,empresa,usuario,clave_hash,telefono,activo,tarifa_video,tarifa_foto,cambiar_clave,clave_inicial_hasta,gestion_locaciones,logo,logo_web) VALUES (?,?,?,?,?,?,?,?,1,DATE_ADD(NOW(), INTERVAL 7 DAY),?,?,?)')->execute([$n, $emp, $u, password_hash($k, PASSWORD_DEFAULT), $tel, $act, $tv, $tf, $gl, $lg, $lw]);
                $c = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE clientes SET nombre=?,empresa=?,usuario=?,telefono=?,activo=?,tarifa_video=?,tarifa_foto=?,gestion_locaciones=?,logo=COALESCE(?,logo),logo_web=? WHERE id=?')->execute([$n, $emp, $u, $tel, $act, $tv, $tf, $gl, $lg, $lw, $c]);
                if ($quitar && !$lg) $pdo->prepare('UPDATE clientes SET logo=NULL WHERE id=?')->execute([$c]);
                if ($oldLogo && ($lg || $quitar)) rmlogo($oldLogo);
                if ($k !== '') $pdo->prepare('UPDATE clientes SET clave_hash=?,cambiar_clave=1,clave_inicial_hasta=DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id=?')->execute([password_hash($k, PASSWORD_DEFAULT), $c]);
            }
            $_SESSION['flash'] = 'Cliente guardado.';
            if ($k !== '') $_SESSION['acceso'] = ['n' => $n, 'u' => $u, 'k' => $k, 'tel' => $tel];
            header("Location: clientes.php?c=$c"); exit;
        }
        if ($a === 'nueva_clave') {
            $nk = blens_clave_nueva();
            $pdo->prepare('UPDATE clientes SET clave_hash=?,cambiar_clave=1,clave_inicial_hasta=DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id=?')->execute([password_hash($nk, PASSWORD_DEFAULT), $c]);
            $q = $pdo->prepare('SELECT nombre,usuario,telefono FROM clientes WHERE id=?'); $q->execute([$c]); $r = $q->fetch();
            if ($r) $_SESSION['acceso'] = ['n' => $r['nombre'], 'u' => $r['usuario'], 'k' => $nk, 'tel' => $r['telefono']];
            $_SESSION['flash'] = 'Clave nueva guardada.'; header("Location: clientes.php?c=$c"); exit;
        }
        if ($a === 'ver_como') {
            $q = $pdo->prepare('SELECT id FROM clientes WHERE id=?'); $q->execute([$c]);
            if ($q->fetch()) {
                session_write_close(); session_name('blens_cliente');
                                session_start(); session_regenerate_id(true); $_SESSION['cid'] = $c; $_SESSION['vista'] = 1; $_SESSION['t'] = bin2hex(random_bytes(16)); session_write_close();
                header('Location: ../portal/'); exit;
            }
        }
        if ($a === 'del_cliente') {
            $q = $pdo->prepare('SELECT preview,poster FROM entregas WHERE cliente_id=?'); $q->execute([$c]);
            foreach ($q as $r) { rmp($r['preview']); rmp($r['poster']); }
            $q2 = $pdo->prepare('SELECT logo FROM clientes WHERE id=?'); $q2->execute([$c]); rmlogo((string)$q2->fetchColumn());
            $pdo->prepare('DELETE FROM clientes WHERE id=?')->execute([$c]);
            $_SESSION['flash'] = 'Cliente eliminado.'; header('Location: clientes.php'); exit;
        }
        if ($a === 'entrega') {
            $n0 = (string)($_POST['nonce'] ?? '');
            if ($n0 !== '' && isset($_SESSION['nonces'][$n0])) { $_SESSION['flash'] = 'Esa entrega ya estaba guardada.'; header("Location: clientes.php?c=$c"); exit; }
            if ($n0 !== '') $_SESSION['nonces'][$n0] = 1;
            $id = (int)($_POST['id'] ?? 0); $t = mb_substr(trim((string)($_POST['titulo'] ?? '')), 0, 120); $d = mb_substr(str_replace("\r\n", "\n", trim((string)($_POST['descripcion'] ?? ''))), 0, 4000); $tituloAuto = false;
            $tipoEn = (string)($_POST['tipo_entrega'] ?? 'video');
            if (!in_array($tipoEn, ['video', 'fotos'], true)) $tipoEn = 'video';
            $hd = trim((string)($_POST['link_hd'] ?? '')); $k4 = ''; $pj = (int)($_POST['proyecto_id'] ?? 0) ?: null;
            if ($tipoEn === 'fotos') {
                $pj = null;
                if ($hd === '') throw new RuntimeException('Pegá el link de la carpeta o archivo de Google Drive del pack de fotos.');
            } else {
                if ($pj) { $q0 = $pdo->prepare('SELECT id FROM proyectos WHERE id=? AND (cliente_id=? OR cliente_id IS NULL)'); $q0->execute([$pj, $c]); if (!$q0->fetchColumn()) throw new RuntimeException('Ese proyecto no pertenece a este cliente.'); }
                if ($hd === '' && !$pj) throw new RuntimeException('Pegá el link de Google Drive o vinculá un proyecto de la web.');
            }
            if (($hd !== '' && !drive_ok($hd)) || ($k4 !== '' && !drive_ok($k4))) throw new RuntimeException('Los links tienen que ser de Google Drive y empezar con https://');
            $old = null;
            if ($id) { $s = $pdo->prepare('SELECT * FROM entregas WHERE id=? AND cliente_id=?'); $s->execute([$id, $c]); $old = $s->fetch(); if (!$old) throw new RuntimeException('Entrega inexistente.'); }
            $mp4 = null; $pos = null; $auto = null;
            if ($tipoEn === 'video') {
                $subido = up('mp4', VIDEOS, 500 * 1048576);
                if ($subido) [$mp4, $auto] = procesar($subido);
                $pos = up('poster', ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 8 * 1048576);
                if ($auto) { if ($pos) rmf($auto); else $pos = $auto; }
                $mp4 = privado($mp4); $pos = privado($pos);
                if ($mp4 && !empty($_FILES['mp4']['name'])) @file_put_contents(PRIV . '/' . basename($mp4) . '.name', trim(pathinfo((string)$_FILES['mp4']['name'], PATHINFO_FILENAME)));
            }
            if ($t === '') {
                $tituloAuto = true;
                $nombreArchivo = (string)($_FILES['mp4']['name'] ?? '');
                if ($tipoEn === 'video' && $nombreArchivo !== '') {
                    $t = mb_substr(trim(pathinfo($nombreArchivo, PATHINFO_FILENAME)), 0, 120);
                } elseif ($old && trim((string)$old['titulo']) !== '') {
                    $t = (string)$old['titulo'];
                }
                if ($t === '') $t = $tipoEn === 'fotos' ? 'Pack de fotos sin título' : 'Video sin título';
            }
            if ($old) {
                if ($tipoEn === 'fotos') {
                    $pdo->prepare('UPDATE entregas SET tipo=?,titulo=?,descripcion=?,link_hd=?,link_4k=?,preview=NULL,poster=NULL,proyecto_id=NULL,version_num=version_num+1 WHERE id=?')->execute([$tipoEn, $t, $d, $hd, $k4, $id]);
                    rmp($old['preview']); rmp($old['poster']);
                } else {
                    $pdo->prepare('UPDATE entregas SET tipo=?,titulo=?,descripcion=?,link_hd=?,link_4k=?,preview=COALESCE(?,preview),poster=COALESCE(?,poster),proyecto_id=?,version_num=version_num+1 WHERE id=?')->execute([$tipoEn, $t, $d, $hd, $k4, $mp4, $pos, $pj, $id]);
                    if ($mp4) rmp($old['preview']); if ($pos) rmp($old['poster']);
                }
            } else {
                $pdo->prepare('INSERT INTO entregas (cliente_id,tipo,titulo,descripcion,link_hd,link_4k,preview,poster,proyecto_id) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$c, $tipoEn, $t, $d, $hd, $k4, $mp4, $pos, $pj]);
            }
            $nid = $id ?: (int)$pdo->lastInsertId();
            if (!$id) {
                $pdo->prepare('UPDATE clientes SET etapa_proyecto=GREATEST(etapa_proyecto,4) WHERE id=?')->execute([$c]);
                blens_push_notify($pdo, 'cliente', $c, [
                    'title' => 'Nuevo material en blens.ar',
                    'body' => $t,
                    'url' => '/portal/',
                    'tag' => 'blens-entrega-' . $nid,
                ]);
            }
            $crearTrabajo = isset($_POST['crear_trabajo']);
            if ($crearTrabajo) registrar_trabajo_entrega($pdo, $c, $nid, $t, $tipoEn, $_POST);
            if (!$id && $tipoEn === 'video' && ($d === '' || $tituloAuto) && trim((string)(blens_cfg()['ai_api_key'] ?? '')) !== '') {
                try { lanzar_worker($nid, 'entrega'); } catch (Throwable $x2) { error_log('[blens clientes] ' . $x2->getMessage()); }
            }
            $_SESSION['flash'] = 'Entrega guardada.' . ($crearTrabajo ? ' Trabajo registrado para cobro.' : ''); header("Location: clientes.php?c=$c" . ($id ? '' : "&avisar=$nid")); exit;
        }
        if ($a === 'ia_entrega') {
            $eid = (int)($_POST['id'] ?? 0);
            $s = $pdo->prepare("SELECT id FROM entregas WHERE id=? AND cliente_id=? AND tipo='video'"); $s->execute([$eid, $c]);
            if (!$s->fetch()) throw new RuntimeException('Entrega de video inexistente.');
            if (trim((string)(blens_cfg()['ai_api_key'] ?? '')) === '') throw new RuntimeException('Falta configurar la clave de IA.');
            lanzar_worker($eid, 'entrega', true);
            $_SESSION['flash'] = 'Generando título y copy con IA. Recargá en unos minutos.';
            header("Location: clientes.php?c=$c&e=$eid"); exit;
        }
        if ($a === 'ia_entregas') {
            if (trim((string)(blens_cfg()['ai_api_key'] ?? '')) === '') throw new RuntimeException('Falta configurar la clave de IA.');
            $s = $pdo->prepare("SELECT id FROM entregas WHERE cliente_id=? AND tipo='video' AND (descripcion IS NULL OR descripcion='')"); $s->execute([$c]);
            $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
            foreach ($ids as $eid) { try { lanzar_worker($eid, 'entrega'); } catch (Throwable $x2) { error_log('[blens clientes] ' . $x2->getMessage()); } }
            $_SESSION['flash'] = $ids ? 'Generando textos con IA para ' . count($ids) . ' entrega(s). Recargá en unos minutos.' : 'No hay entregas sin copy.';
            header("Location: clientes.php?c=$c"); exit;
        }
        if ($a === 'importar_carpeta') {
            if (!$c) throw new RuntimeException('Elegí un cliente para importar las entregas.');
            $fid = drive_folder_id((string)($_POST['carpeta'] ?? ''));
            if (!$fid) throw new RuntimeException('Pegá un link válido de carpeta de Google Drive (drive.google.com/drive/folders/…).');
            $vids = drive_folder_videos($fid);
            if (!$vids) throw new RuntimeException('No encontré videos en esa carpeta. Revisá que esté compartida como «cualquier persona con el enlace» y que tenga archivos MP4, MOV, MKV o WebM.');
            if (!is_dir(COLA)) @mkdir(COLA, 0750, true);
            $ins = $pdo->prepare("INSERT INTO entregas (cliente_id,tipo,titulo,descripcion,link_hd,link_4k,preview,poster,proyecto_id) VALUES (?,'video',?,?,?,?,NULL,NULL,NULL)");
            $n = 0;
            foreach ($vids as $vid => $name) {
                $titulo = trim(pathinfo($name, PATHINFO_FILENAME)); if ($titulo === '') $titulo = $name;
                $titulo = mb_substr($titulo, 0, 120);
                $ins->execute([$c, $titulo, '', 'https://drive.google.com/file/d/' . $vid . '/view', '']);
                $nid = (int)$pdo->lastInsertId();
                if (isset($_POST['crear_trabajo'])) registrar_trabajo_entrega($pdo, $c, $nid, $titulo, 'video', $_POST);
                @file_put_contents(COLA . '/drive_' . $vid . '.name', $titulo);
                blens_push_notify($pdo, 'cliente', $c, [
                    'title' => 'Nuevo material en blens.ar',
                    'body' => $titulo,
                    'url' => '/portal/',
                    'tag' => 'blens-entrega-' . $nid,
                ]);
                $n++;
            }
            if ($n > 0) $pdo->prepare('UPDATE clientes SET etapa_proyecto=GREATEST(etapa_proyecto,4) WHERE id=?')->execute([$c]);
            $_SESSION['flash'] = 'Se importaron ' . $n . ' videos a las entregas.' . (isset($_POST['crear_trabajo']) ? ' Trabajos registrados para cobro.' : '');
            header("Location: clientes.php?c=$c"); exit;
        }
        if ($a === 'del_entrega') {
            $s = $pdo->prepare('SELECT preview,poster FROM entregas WHERE id=? AND cliente_id=?'); $s->execute([(int)($_POST['id'] ?? 0), $c]);
            if ($r = $s->fetch()) { rmp($r['preview']); rmp($r['poster']); $pdo->prepare('DELETE FROM entregas WHERE id=? AND cliente_id=?')->execute([(int)$_POST['id'], $c]); }
            $_SESSION['flash'] = 'Entrega eliminada.'; header("Location: clientes.php?c=$c"); exit;
        }
    } catch (Throwable $x) {
        if ($x instanceof PDOException) { error_log('[blens clientes] ' . $x->getMessage()); $msg = $x->getCode() === '23000' ? 'Ese usuario ya existe.' : 'Error de base de datos.'; }
        else $msg = $x->getMessage();
    }
}
$vc = isset($_GET['c']) ? (int)$_GET['c'] : null; $ve = isset($_GET['e']) ? (int)$_GET['e'] : null; $T = $e($_SESSION['t']);
$ft = (string)($_GET['ft'] ?? 'todos'); if (!in_array($ft, ['todos', 'video', 'fotos'], true)) $ft = 'todos';
$list = $cli = $ent = null; $ents = []; $entsList = []; $locs = [];
if ($vc === null) { $list = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM entregas x WHERE x.cliente_id = c.id) AS n FROM clientes c ORDER BY c.nombre')->fetchAll(); }
else {
    $cli = ['id' => 0, 'nombre' => '', 'empresa' => '', 'usuario' => '', 'telefono' => '', 'activo' => 1, 'logo' => null, 'logo_web' => 1, 'tarifa_video' => null, 'tarifa_foto' => null, 'gestion_locaciones' => 0, 'portal_visitas' => 0, 'portal_ultima_visita' => null, 'etapa_proyecto' => 1];
    if ($vc) { $s = $pdo->prepare('SELECT * FROM clientes WHERE id=?'); $s->execute([$vc]); $cli = $s->fetch() ?: $cli; }
    if ($cli['id']) {
        $mpCli = modo_prueba($pdo);
        $sf = $pdo->prepare('SELECT * FROM trabajos WHERE cliente_id=? AND prueba=?'); $sf->execute([$cli['id'], $mpCli]); $trabajosCli = $sf->fetchAll();
        $cobCli = 0.0; $pendCli = 0.0; foreach ($trabajosCli as $tr) { $cobCli += fin_abonado($tr); $pendCli += fin_saldo($tr); }
        $fin = ['cob' => fin_num($cobCli), 'pend' => fin_num($pendCli)];
        $sr = $pdo->prepare('SELECT COUNT(*) FROM recibos WHERE cliente_id=? AND prueba=?'); $sr->execute([$cli['id'], $mpCli]); $nRec = (int)$sr->fetchColumn();
        $nomCli = trim((string)$cli['nombre']); $empCli = trim((string)$cli['empresa']);
        $sp = $pdo->prepare("SELECT DISTINCT p.* FROM presupuestos p LEFT JOIN trabajos t ON t.id=p.trabajo_id WHERE p.cliente_nombre=? OR (p.empresa<>'' AND p.empresa=?) OR t.cliente_id=? ORDER BY p.creado_en DESC LIMIT 5");
        $sp->execute([$nomCli, $empCli, $cli['id']]); $presus = $sp->fetchAll();
        $sa = $pdo->prepare('SELECT * FROM agenda WHERE cliente_id=? ORDER BY fecha DESC, id DESC LIMIT 30');
        $sa->execute([$cli['id']]); $agendaCli = $sa->fetchAll();
        $hoy = date('Y-m-d');
        $agendaFuturos = array_values(array_filter($agendaCli, fn($r) => (string)$r['fecha'] >= $hoy));
        $agendaPasados = array_values(array_filter($agendaCli, fn($r) => (string)$r['fecha'] < $hoy));
        $s = $pdo->prepare('SELECT * FROM entregas WHERE cliente_id=? ORDER BY id DESC'); $s->execute([$cli['id']]); $ents = $s->fetchAll();
        $entsList = $ft === 'todos' ? $ents : array_values(array_filter($ents, fn($r) => $tipoDe($r) === $ft));
        $sl = $pdo->prepare("SELECT * FROM cliente_locaciones WHERE cliente_id=? ORDER BY FIELD(estado,'pendiente','agendado','grabado'), id DESC"); $sl->execute([$cli['id']]); $locs = $sl->fetchAll();
        if ($ve !== null) { $ent = ['id' => 0, 'tipo' => 'video', 'titulo' => '', 'descripcion' => '', 'link_hd' => '', 'link_4k' => '', 'preview' => '', 'poster' => '', 'proyecto_id' => null];
            foreach ($ents as $r) if ((int)$r['id'] === $ve) $ent = $r; }
    }
}
$verLoc = is_array($cli) && (!empty($cli['gestion_locaciones']) || (bool)$locs);
$formCliente = function (bool $conBorrar = false) use ($e, $cli, $T): string {
    ob_start(); ?>
<form class="f cli-form" method="post" enctype="multipart/form-data"><input type="hidden" name="a" value="cliente"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>">
<label><span class="lbl">Empresa <span class="opt">(opcional)</span></span><input name="empresa" value="<?= $e($cli['empresa'] ?? '') ?>" maxlength="100"></label>
<label><span class="lbl">Persona de contacto <span class="req">* obligatorio</span></span><input name="nombre" value="<?= $e($cli['nombre']) ?>" maxlength="80" required></label>
<label><span class="lbl">Usuario para entrar <span class="req">* obligatorio</span></span><input name="usuario" value="<?= $e($cli['usuario']) ?>" maxlength="60" autocapitalize="none" required></label>
<label><span class="lbl">WhatsApp de esa persona <span class="opt">(opcional)</span></span><input name="telefono" value="<?= $e($cli['telefono']) ?>" maxlength="30"></label>
<label><span class="lbl">Clave <span class="opt">(opcional)</span> <?= $cli['id'] ? '(vacío = no cambiar)' : '(vacío = se genera una clave temporal fácil de dictar)' ?></span><input name="clave" type="text" autocomplete="off" placeholder="mínimo 6 caracteres"></label>
<label><span class="lbl">Tarifa por video ($) <span class="opt">(opcional)</span></span><input name="tarifa_video" inputmode="numeric" value="<?= $e($cli['tarifa_video'] ?? '') ?>"></label>
<label><span class="lbl">Tarifa por foto ($) <span class="opt">(opcional)</span></span><input name="tarifa_foto" inputmode="numeric" value="<?= $e($cli['tarifa_foto'] ?? '') ?>"></label>
<label class="full"><span class="lbl">Logo del cliente <span class="opt">(opcional)</span> (PNG, JPG o WebP; mejor con fondo transparente)</span><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
<?php if (!empty($cli['logo'])): ?><div class="full"><img class="clogo" loading="lazy" width="180" height="80" src="../media/logos/<?= $e($cli['logo']) ?>" alt=""><label class="chk"><input type="checkbox" name="quitar_logo">Quitar el logo actual <span class="opt">(opcional)</span></label></div><?php endif; ?>
<label class="chk full"><input type="checkbox" name="logo_web" <?= !empty($cli['logo_web']) ? 'checked' : '' ?>>Mostrar el logo en la página principal <span class="opt">(opcional)</span></label>
<label class="chk full"><input type="checkbox" name="activo" <?= $cli['activo'] ? 'checked' : '' ?>>Puede entrar al portal <span class="opt">(opcional)</span></label>
<label class="chk full"><input type="checkbox" name="gestion_locaciones" <?= !empty($cli['gestion_locaciones']) ? 'checked' : '' ?>>Habilitar carga de propiedades / locaciones en su portal <span class="opt">(opcional)</span></label>
<div class="row full"><button>Guardar cliente</button><a class="btn g" href="clientes.php">Volver</a></div></form>
<?php if ($conBorrar): ?>
<details class="del-cli"><summary>Eliminar cliente</summary>
<form method="post" class="row"><input type="hidden" name="a" value="del_cliente"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><label class="chk"><input type="checkbox" required>Confirmo: se borran el cliente y todas sus entregas <span class="req">* obligatorio</span></label><button class="r">Eliminar</button></form>
</details>
<?php endif; ?>
<?php return ob_get_clean();
};
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex"><title>Clientes | blens.ar</title><link rel="icon" href="../assets/favicon.svg" type="image/svg+xml"><link rel="manifest" href="manifest.webmanifest"><link rel="apple-touch-icon" href="icons/apple-touch-icon.png"><meta name="theme-color" content="#0b0b0b"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><link rel="stylesheet" href="admin.css?v=35"></head><body>
<svg class="sprite" aria-hidden="true"><symbol id="mark" viewBox="0 0 130 130"><polygon points="0,0 70,0 100,30 100,100 0,100" fill="#F5F1EA"/><polygon points="60,45 125,45 125,125 45,125 45,60" fill="#F5F1EA"/><polygon points="60,45 100,45 100,64 86,72 100,80 100,100 45,100 45,60" fill="#FF5A1F"/></symbol></svg>
<?php require __DIR__ . '/nav.php'; ?>
<?php if (isset($_GET['avisar']) && $cli && $cli['telefono']) { $s2 = $pdo->prepare('SELECT titulo FROM entregas WHERE id=? AND cliente_id=?'); $s2->execute([(int)$_GET['avisar'], $cli['id']]); if ($t2 = $s2->fetchColumn()) { $w = wa_url($cli['telefono'], 'Hola ' . explode(' ', trim($cli['nombre']))[0] . ', ya tenés una nueva entrega disponible en tu portal: «' . $t2 . '». Ingresá acá: ' . (blens_cfg()['sitio'] ?? 'https://blens.ar') . '/portal/'); if ($w) echo '<p class="ok">¿Avisamos al cliente? <a class="btn" href="' . $e($w) . '" target="_blank" rel="noopener">Enviar aviso por WhatsApp</a></p>'; } } ?>
<?php if ($msg) echo '<p class="' . (str_contains($msg, 'guardad') || str_contains($msg, 'eliminad') ? 'ok' : 'err') . '">' . $e($msg) . '</p>'; ?>
<?php if ($acc): $sitio = rtrim((string)(blens_cfg()['sitio'] ?? 'https://blens.ar'), '/'); $pn = explode(' ', trim($acc['n']))[0];
$txt = "Hola $pn! Te dejo tu acceso al portal de blens.ar, donde vas a ver y descargar tus entregas:\n\nPortal: $sitio/portal/\nUsuario: {$acc['u']}\nClave inicial: {$acc['k']}\n\nLa clave inicial vale 7 días: al entrar te pide que elijas la tuya. Cualquier duda, escribinos.";
$wl = $acc['tel'] ? wa_url($acc['tel'], $txt) : null; ?>
<div class="acceso" id="acceso" data-n="<?= $e($pn) ?>" data-u="<?= $e($acc['u']) ?>" data-k="<?= $e($acc['k']) ?>" data-url="<?= $e($sitio . '/portal/') ?>">
<div class="acc-card"><div class="acc-h"><svg class="mark"><use href="#mark"/></svg><b>Tu acceso al portal</b></div>
<dl><dt>Portal</dt><dd><?= $e($sitio) ?>/portal/</dd><dt>Usuario</dt><dd><?= $e($acc['u']) ?></dd><dt>Clave inicial</dt><dd class="k"><?= $e($acc['k']) ?></dd></dl>
<p class="mut">Vale 7 días. Al entrar, elige su propia clave.</p></div>
<div class="acc-btns"><button type="button" data-wa-img data-img="tarjeta" data-wa="<?= $e($wl ?? '') ?>" data-txt="<?= $e($txt) ?>" data-nombre="acceso-<?= $e($acc['u']) ?>">Enviar por WhatsApp con imagen</button><button type="button" class="g" data-copiar="<?= $e($txt) ?>">Copiar mensaje</button><button type="button" class="g" data-tarjeta>Descargar imagen</button></div>
<p class="mut">Esta clave se muestra una sola vez. Si la perdés, generás otra con «Nueva clave inicial».</p></div>
<?php endif; ?>
<?php if ($list !== null): ?>
<h1>Clientes</h1><a class="btn" href="?c=0">Agregar cliente</a>
<table class="cards"><tr><th>Empresa / contacto</th><th>Usuario</th><th>Entregas</th><th>Portal</th><th></th></tr>
<?php foreach ($list as $r): ?><tr class="<?= $r['activo'] ? '' : 'off' ?>"><td data-label="Cliente"><?= !empty($r['logo']) ? '<img class="clogo" loading="lazy" width="180" height="80" src="../media/logos/' . $e($r['logo']) . '" alt="">' : '' ?><?= $e($r['empresa'] ?: $r['nombre']) ?><br><span class="mut"><?= $e($r['empresa'] ? $r['nombre'] : '') ?></span></td><td class="mut" data-label="Usuario"><?= $e($r['usuario']) ?></td><td data-label="Entregas"><?= (int)$r['n'] ?></td><td class="mut" data-label="Portal"><?= $e(portal_info($r)) ?></td><td data-label=""><a class="btn g" href="?c=<?= (int)$r['id'] ?>">Abrir</a></td></tr><?php endforeach; if (!$list) echo '<tr><td colspan="5" class="mut">Todavía no hay clientes.</td></tr>'; ?></table>

<?php elseif ($ent !== null): ?>
<?php $tipoActual = $tipoDe($ent); ?>
<h1><?= $e($cli['nombre']) ?> · <?= $ent['id'] ? 'Editar entrega' : 'Nueva entrega' ?></h1>
<form class="f ent-form" method="post" enctype="multipart/form-data"><input type="hidden" name="a" value="entrega"><input type="hidden" name="nonce" value="<?= bin2hex(random_bytes(8)) ?>"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><input type="hidden" name="id" value="<?= (int)$ent['id'] ?>">
<label class="full"><span class="lbl">Tipo de entrega</span><select name="tipo_entrega"><option value="video"<?= $tipoActual === 'video' ? ' selected' : '' ?>>Video</option><option value="fotos"<?= $tipoActual === 'fotos' ? ' selected' : '' ?>>Fotos (Pack de fotos)</option></select></label>
<label class="full"><span class="lbl">Título <span class="opt">(opcional - lo completa la IA en videos o toma el nombre del archivo)</span></span><input name="titulo" value="<?= $e($ent['titulo']) ?>" maxlength="120"></label>
<label class="full"><span class="lbl">Descripción / copy para redes <span class="opt">(opcional)</span> (se respetan saltos de línea y emojis)</span><textarea name="descripcion" rows="9" maxlength="4000"><?= $e($ent['descripcion']) ?></textarea></label>
<?php if (trim((string)$ent['descripcion']) !== ''): ?><div class="full"><button type="button" class="g" data-copiar="<?= $e(trim((string)$ent['titulo']) . "\n\n" . trim((string)$ent['descripcion'])) ?>">Copiar título y copy para redes</button></div><?php endif; ?>
<label class="full f-link"><span class="lbl l-video">Link de Google Drive <span class="req">* obligatorio si no vinculás un proyecto</span> (público, en la mejor calidad)</span><span class="lbl l-fotos">Link de la carpeta o archivo de Google Drive <span class="req">* obligatorio</span> (público, con permisos de descarga)</span><input name="link_hd" value="<?= $e($ent['link_hd']) ?>" placeholder="https://drive.google.com/..."></label>
<p class="mut full only-fotos">Pegá el link de la carpeta o de un archivo del pack. El cliente verá una tarjeta de galería y descargará las fotos directo desde Drive. No se sube nada al servidor ni se procesa video.</p>
<?php $ps = $pdo->prepare("SELECT id,titulo,cliente FROM proyectos WHERE categoria <> 'showreel' AND (cliente_id = ? OR cliente_id IS NULL) ORDER BY id DESC"); $ps->execute([(int)$cli['id']]); ?>
<label class="full only-video"><span class="lbl">Proyecto de la web relacionado <span class="opt">(opcional)</span> (si no subís un video de vista previa, se usa el de ese proyecto; además evita que aparezca duplicado en Cobros)</span><select name="proyecto_id"><option value="0">— Ninguno —</option><?php foreach ($ps as $x): ?><option value="<?= (int)$x['id'] ?>"<?= (int)($ent['proyecto_id'] ?? 0) === (int)$x['id'] ? ' selected' : '' ?>><?= $e($x['titulo'] . ($x['cliente'] ? ' · ' . $x['cliente'] : '')) ?></option><?php endforeach; ?></select></label>
<label class="full only-video"><span class="lbl">Video para la vista previa <span class="opt">(opcional)</span> (si no subís nada, el portal muestra el video desde el link de Drive) <?= $ent['preview'] ? '· ya cargado; dejá vacío para mantenerlo' : '' ?></span><input type="file" name="mp4" accept="video/*"></label>
<p class="mut full only-video">Se convierte solo a 720p y solo lo ve este cliente al entrar con su usuario. Puede tardar unos minutos: no cierres la página.</p>
<label class="full only-video"><span class="lbl">Portada propia <span class="opt">(opcional)</span></span><input type="file" name="poster" accept="image/jpeg,image/png,image/webp"></label>
<div class="full fin-opts" data-crear-opts>
  <label><span class="lbl">Línea de negocio</span><select name="unidad_negocio"><?= fin_opts(FIN_UNIDADES, 'audiovisual') ?></select></label>
  <label><span class="lbl">Estado de pago</span><select name="estado_pago"><?= fin_opts(FIN_ESTADOS_PAGO, 'pendiente') ?></select></label>
  <label><span class="lbl">Medio de pago</span><select name="medio_pago"><option value="">—</option><?= fin_opts(array_combine(array_keys(FIN_MEDIOS_PAGO), array_keys(FIN_MEDIOS_PAGO)), '') ?></select></label>
  <label><span class="lbl">Seña cobrada <span class="opt">(si es parcial)</span></span><input name="monto_senia" inputmode="numeric" placeholder="0"></label>
</div>
<label class="full chk"><input type="checkbox" name="crear_trabajo" checked data-crear-trabajo> Registrar también como trabajo a cobrar <span class="opt">(usa la tarifa del cliente y lo vincula a esta entrega)</span></label>
<div class="row full"><button>Guardar</button><a class="btn g" href="?c=<?= (int)$cli['id'] ?>">Cancelar</a></div></form>
<?php if ($tipoActual === 'video' && $ent['id'] && trim((string)(blens_cfg()['ai_api_key'] ?? '')) !== ''): ?>
<div class="row"><form method="post"><input type="hidden" name="a" value="ia_entrega"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><input type="hidden" name="id" value="<?= (int)$ent['id'] ?>"><button class="g">Generar título y copy con IA</button></form></div>
<p class="mut">La IA escucha el audio y genera un título y un copy listo para Instagram/TikTok. Reemplaza el título y la descripción actuales. Tarda unos minutos: recargá la página para ver el resultado.</p>
<?php endif; ?>
<?php if ($ent['id']): ?><h2>Eliminar entrega</h2><form method="post" class="row"><input type="hidden" name="a" value="del_entrega"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><input type="hidden" name="id" value="<?= (int)$ent['id'] ?>"><label class="chk"><input type="checkbox" required>Confirmo que quiero eliminarla <span class="req">* obligatorio</span></label><button class="r">Eliminar</button></form><?php endif; ?>

<?php else: ?>
<h1 class="cli-title"><?= $cli['id'] ? $e(($cli['empresa'] ? $cli['empresa'] . ' · ' : '') . $cli['nombre']) : 'Nuevo cliente' ?></h1>
<?php if ($cli['id']):
$waTpl = wa_plantillas($pdo);
$waNombre = explode(' ', trim((string)$cli['nombre']))[0] ?: 'hola';
$waDatos = ['{nombre}' => $waNombre, '{empresa}' => (string)($cli['empresa'] ?? ''), '{usuario}' => (string)($cli['usuario'] ?? ''), '{link_portal}' => wa_link_portal(), '{monto}' => peso((float)($fin['pend'] ?? 0))];
$waLinks = [
    'entrega' => wa_link((string)($cli['telefono'] ?? ''), $waTpl['entrega'], $waDatos),
    'cobro' => wa_link((string)($cli['telefono'] ?? ''), $waTpl['cobro'], $waDatos),
    'libre' => wa_link((string)($cli['telefono'] ?? ''), 'Hola {nombre},', $waDatos),
];
?>
<nav class="tabs" id="cli-tabs" role="tablist" aria-label="Ficha del cliente">
  <a class="tab on" href="#tab-resumen" role="tab" aria-selected="true" aria-controls="tab-resumen">Resumen y Entregas</a>
  <?php if ($verLoc): ?><a class="tab" href="#tab-locaciones" role="tab" aria-selected="false" aria-controls="tab-locaciones">Propiedades y Locaciones <b><?= count($locs) ?></b></a><?php endif; ?>
  <a class="tab" href="#tab-datos" role="tab" aria-selected="false" aria-controls="tab-datos">Datos y Tarifas</a>
  <a class="tab" href="#tab-historial" role="tab" aria-selected="false" aria-controls="tab-historial">Historial</a>
</nav>
<section class="tab-panel" id="tab-resumen" role="tabpanel">
  <div class="c360 resumen-grid">
    <section class="c360-card">
      <div class="c360-stats">
        <div><span>Cobrado</span><b><?= $e(peso((float)$fin['cob'])) ?></b></div>
        <div><span>Pendiente</span><b class="<?= (float)$fin['pend'] > 0 ? 'warn' : '' ?>"><?= $e(peso((float)$fin['pend'])) ?></b></div>
        <div><span>Recibos</span><b><?= $nRec ?></b></div>
      </div>
      <p class="mut">Modo prueba <?= $mpCli ? 'activo' : 'desactivado' ?> · Último acceso: <?= !empty($cli['portal_ultima_visita']) ? $e(date('d/m/Y H:i', strtotime((string)$cli['portal_ultima_visita']))) : 'Nunca ingresó' ?></p>
    </section>
    <section class="c360-card">
      <div class="c360-actions">
        <?php if (!empty($cli['telefono']) && $waLinks['entrega']): ?>
        <div class="wa-quick" data-wa-quick data-tel="<?= $e((string)$cli['telefono']) ?>" data-wa-msg-entrega="<?= $e(wa_render($waTpl['entrega'], $waDatos)) ?>" data-wa-msg-cobro="<?= $e(wa_render($waTpl['cobro'], $waDatos)) ?>" data-wa-msg-libre="<?= $e(wa_render('Hola {nombre},', $waDatos)) ?>">
          <select class="wa-q-sel" data-wa-q-mode aria-label="Elegir mensaje de WhatsApp"><option value="entrega">Avisar entrega lista</option><option value="cobro">Recordatorio de saldo</option><option value="libre">Chat libre</option></select>
          <a class="btn" href="<?= $e($waLinks['entrega']) ?>" target="_blank" rel="noopener" data-wa-q-link>WhatsApp</a>
        </div>
        <?php else: ?><span class="mut">Sin teléfono cargado</span><?php endif; ?>
        <form method="post"><input type="hidden" name="a" value="ver_como"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><button class="g">Ver portal</button></form>
        <a class="btn g" href="cobros.php?cliente=<?= (int)$cli['id'] ?>">Cobros</a>
        <form method="post"><input type="hidden" name="a" value="nueva_clave"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><button class="g">Regenerar clave</button></form>
      </div>
    </section>
    <section class="c360-card stage-card">
      <div class="stage-head"><span class="lbl">Etapa del proyecto</span><form method="post" class="stage-form"><input type="hidden" name="a" value="etapa"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><select name="etapa" aria-label="Etapa del proyecto"><?php foreach (PROYECTO_ETAPAS as $n => $label): ?><option value="<?= (int)$n ?>"<?= (int)$cli['etapa_proyecto'] === $n ? ' selected' : '' ?>><?= (int)$n ?> · <?= $e($label) ?></option><?php endforeach; ?></select><button class="g">Actualizar</button></form></div>
      <?= etapa_track_html((int)$cli['etapa_proyecto']) ?>
    </section>
  </div>
  <div class="lh2 ent-head">
    <h2>Entregas</h2>
    <div class="row">
      <button type="button" class="g" data-importar="#impe">Importar carpeta de Drive</button>
      <?php if (trim((string)(blens_cfg()['ai_api_key'] ?? '')) !== ''): ?><form method="post"><input type="hidden" name="a" value="ia_entregas"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><button class="g">Generar textos con IA</button></form><?php endif; ?>
      <a class="btn" href="?c=<?= (int)$cli['id'] ?>&e=0">+ Nueva entrega</a>
    </div>
  </div>
  <dialog id="impe" class="imp">
  <form method="post" class="f">
  <input type="hidden" name="a" value="importar_carpeta"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>">
  <h2 class="full">Importar carpeta de Google Drive</h2>
  <p class="mut full">Pegá el link de una carpeta pública. Se crea una entrega por cada video encontrado, con el nombre del archivo como título y su link individual. No se sube ningún archivo al servidor.</p>
  <label class="full"><span class="lbl">Link de la carpeta <span class="req">* obligatorio</span></span><input name="carpeta" type="url" required placeholder="https://drive.google.com/drive/folders/…"></label>
  <div class="full fin-opts" data-crear-opts>
    <label><span class="lbl">Línea de negocio</span><select name="unidad_negocio"><?= fin_opts(FIN_UNIDADES, 'audiovisual') ?></select></label>
    <label><span class="lbl">Estado de pago</span><select name="estado_pago"><?= fin_opts(FIN_ESTADOS_PAGO, 'pendiente') ?></select></label>
    <label><span class="lbl">Medio de pago</span><select name="medio_pago"><option value="">—</option><?= fin_opts(array_combine(array_keys(FIN_MEDIOS_PAGO), array_keys(FIN_MEDIOS_PAGO)), '') ?></select></label>
    <label><span class="lbl">Seña cobrada <span class="opt">(si es parcial)</span></span><input name="monto_senia" inputmode="numeric" placeholder="0"></label>
  </div>
  <label class="full chk"><input type="checkbox" name="crear_trabajo" checked data-crear-trabajo> Registrar también como trabajo a cobrar <span class="opt">(uno por cada video importado)</span></label>
  <div class="row full"><button>Importar videos</button><button type="button" class="g" data-cerrar>Cancelar</button></div>
  </form>
  </dialog>
  <?php $nVidEnt = count(array_filter($ents, fn($r) => $tipoDe($r) === 'video')); $nFotEnt = count($ents) - $nVidEnt; ?>
  <div class="lf-filtros"><a class="lf-pill<?= $ft === 'todos' ? ' on' : '' ?>" href="?c=<?= (int)$cli['id'] ?>&ft=todos#tab-resumen">Todos <b><?= count($ents) ?></b></a><a class="lf-pill<?= $ft === 'video' ? ' on' : '' ?>" href="?c=<?= (int)$cli['id'] ?>&ft=video#tab-resumen">Videos <b><?= $nVidEnt ?></b></a><a class="lf-pill<?= $ft === 'fotos' ? ' on' : '' ?>" href="?c=<?= (int)$cli['id'] ?>&ft=fotos#tab-resumen">Fotos <b><?= $nFotEnt ?></b></a></div>
  <table class="cards ent-table"><tr><th>Tipo</th><th>Título</th><th>Fecha</th><th>Estado</th><th>Acciones</th></tr>
  <?php foreach ($entsList as $r): $est = (string)($r['estado_cliente'] ?? 'pendiente'); $com = trim((string)($r['comentario_cliente'] ?? '')); $ver = max(1, (int)($r['version_num'] ?? 1)); $txtEnt = 'Hola ' . $waNombre . ', te comparto la entrega «' . trim((string)$r['titulo']) . '» (v' . $ver . ') en tu portal de blens.ar: ' . wa_link_portal(); ?>
  <tr><td data-label="Tipo"><span class="b tipo-<?= $tipoDe($r) ?>"><?= $tipoDe($r) === 'fotos' ? 'FOTOS' : 'VIDEO' ?></span></td><td data-label="Título"><?= $e($r['titulo']) ?> <span class="ent-ver">v<?= $ver ?></span></td><td class="mut" data-label="Fecha"><?= $e(substr((string)$r['creado'], 0, 10)) ?></td>
  <td data-label="Estado"><span class="c360-est"><?php if ($est === 'ajuste'): ?><span class="b adj" title="<?= $e($com) ?>">Pidió ajuste</span><?php elseif ($est === 'aprobado'): ?><span class="badge ok">Aprobado</span><?php else: ?><span class="badge pend">Pendiente</span><?php endif; ?></span>
    <?php if ($est === 'ajuste'): ?><?php if ($com !== ''): ?><div class="mut ent-com" title="<?= $e($com) ?>"><?= $e(mb_substr($com, 0, 55)) ?></div><?php endif; ?><form method="post" class="resolver-f"><input type="hidden" name="accion" value="resolver_ajuste"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="g">Marcar resuelto</button></form><?php endif; ?></td>
    <td data-label="Acciones"><div class="ent-actions"><button type="button" class="g" data-copiar="<?= $e($txtEnt) ?>">Copiar mensaje WhatsApp</button><a class="btn g" href="?c=<?= (int)$cli['id'] ?>&e=<?= (int)$r['id'] ?>">Editar</a>
<?php if ($tipoDe($r) === 'video' && trim((string)(blens_cfg()['ai_api_key'] ?? '')) !== ''): ?><form method="post" class="ia-f"><input type="hidden" name="a" value="ia_entrega"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="g">IA</button></form><?php endif; ?><form method="post" class="del-f"><input type="hidden" name="a" value="del_entrega"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="r" data-confirm="¿Borrar esta entrega?">Borrar</button></form></div></td></tr>
  <?php endforeach; if (!$entsList) echo '<tr><td colspan="5" class="mut">Sin entregas todavía.</td></tr>'; ?></table>
</section>
<?php if ($verLoc): ?>
<section class="tab-panel" id="tab-locaciones" role="tabpanel" hidden>
  <div id="locaciones" class="loc-admin-head"><h2>Propiedades y Locaciones</h2><p class="mut">Cargadas por el cliente desde su portal. Estado, Maps, WhatsApp y pase a agenda de rodajes.</p></div>
  <div class="loc-grid">
  <?php foreach ($locs as $l): $est = (string)$l['estado']; $adminNom = trim((string)$l['administrador']) !== '' ? trim((string)$l['administrador']) : 'encargado/a';
    $waLoc = wa_link((string)$l['telefono_contacto'], 'Hola {administrador}, te escribo de blens.ar por el rodaje en {nombre}.', ['{administrador}' => $adminNom, '{nombre}' => trim((string)$l['nombre'])]);
    [$fLoc, $hLoc] = locaciones_fecha_hora((string)$l['horario_acordado']); ?>
  <article class="loc-card">
    <div class="loc-head"><h3><?= $e($l['nombre']) ?></h3><span class="loc-badge <?= $e($est) ?>"><?= $e(locaciones_estados()[$est] ?? $est) ?></span></div>
    <dl class="loc-meta">
      <div><dt>Ubicación</dt><dd><?= $e($l['ubicacion']) ?></dd></div>
      <?php if (trim((string)$l['acceso']) !== ''): ?><div><dt>Acceso</dt><dd><?= $e($l['acceso']) ?></dd></div><?php endif; ?>
      <?php if (trim((string)$l['administrador']) !== ''): ?><div><dt>Administrador</dt><dd><?= $e($l['administrador']) ?></dd></div><?php endif; ?>
      <?php if (trim((string)$l['telefono_contacto']) !== ''): ?><div><dt>Teléfono</dt><dd><?= $e($l['telefono_contacto']) ?></dd></div><?php endif; ?>
      <?php if (trim((string)$l['horario_acordado']) !== ''): ?><div><dt>Horario acordado</dt><dd><?= $e($l['horario_acordado']) ?></dd></div><?php endif; ?>
      <?php if (trim((string)$l['tipo_contenido']) !== ''): ?><div><dt>Tipo de contenido</dt><dd><?= $e($l['tipo_contenido']) ?></dd></div><?php endif; ?>
      <?php if (trim((string)$l['notas']) !== ''): ?><div class="loc-notes"><dt>Notas</dt><dd><?= nl2br($e($l['notas'])) ?></dd></div><?php endif; ?>
    </dl>
    <div class="loc-actions">
      <a class="btn g" href="<?= $e(locaciones_maps((string)$l['ubicacion'])) ?>" target="_blank" rel="noopener noreferrer">Maps ↗</a>
      <?php if ($waLoc): ?><a class="btn g" href="<?= $e($waLoc) ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a><?php endif; ?>
    </div>
    <form method="post" class="loc-estado-f"><input type="hidden" name="a" value="loc_estado"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
      <label><span class="lbl">Estado</span><select name="estado" data-loc-estado><?php foreach (locaciones_estados() as $k => $label): ?><option value="<?= $e($k) ?>"<?= $est === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
      <button class="g" type="submit">Guardar</button>
    </form>
    <form method="post" class="loc-agendar-f"><input type="hidden" name="a" value="loc_agendar"><input type="hidden" name="t" value="<?= $T ?>"><input type="hidden" name="cid" value="<?= (int)$cli['id'] ?>"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="fecha" value="<?= $e($fLoc) ?>"><input type="hidden" name="hora" value="<?= $e($hLoc) ?>"><button type="submit">Agendar rodaje</button><span class="mut">Fecha estimada: <?= $e(date('d/m/Y', strtotime($fLoc))) ?><?= $hLoc !== '' ? ' · ' . $e($hLoc) : '' ?></span></form>
  </article>
  <?php endforeach; if (!$locs): ?><p class="mut">Este cliente todavía no cargó propiedades.</p><?php endif; ?>
  </div>
</section>
<?php endif; ?>
<section class="tab-panel" id="tab-datos" role="tabpanel" hidden>
  <?= $formCliente(true) ?>
</section>
<section class="tab-panel" id="tab-historial" role="tabpanel" hidden>
  <div class="c360 hist-grid">
    <section class="c360-card">
      <h2>Presupuestos vinculados</h2>
      <div class="c360-list">
      <?php foreach ($presus as $r): ?><div class="c360-item"><span><a href="presupuesto_ver.php?id=<?= (int)$r['id'] ?>"><b><?= $e($r['numero'] ?: ('Presupuesto #' . (int)$r['id'])) ?></b></a><br><span class="mut"><?= $e(substr((string)$r['creado_en'], 0, 10)) ?></span></span><b><?= $e(peso((float)$r['total'])) ?></b></div><?php endforeach; if (!$presus) echo '<p class="mut">Sin presupuestos vinculados.</p>'; ?>
      </div>
    </section>
    <section class="c360-card">
      <h2>Agenda</h2>
      <p class="lbl">Próximos</p>
      <div class="c360-list">
      <?php foreach ($agendaFuturos as $r): ?><div class="c360-item"><span><b><?= $e($r['titulo']) ?></b><br><span class="mut"><?= $e(date('d/m/Y', strtotime((string)$r['fecha']))) ?><?= !empty($r['hora']) ? ' · ' . $e(substr((string)$r['hora'], 0, 5)) : '' ?></span></span><span class="badge <?= $e($r['tipo']) ?>"><?= $e(AGENDA_TIPOS[$r['tipo']] ?? $r['tipo']) ?></span></div><?php endforeach; if (!$agendaFuturos) echo '<p class="mut">Sin eventos próximos.</p>'; ?>
      </div>
      <p class="lbl">Pasados</p>
      <div class="c360-list">
      <?php foreach ($agendaPasados as $r): ?><div class="c360-item"><span><b><?= $e($r['titulo']) ?></b><br><span class="mut"><?= $e(date('d/m/Y', strtotime((string)$r['fecha']))) ?><?= $r['completado'] ? ' · Completado' : '' ?></span></span><span class="badge <?= $e($r['tipo']) ?>"><?= $e(AGENDA_TIPOS[$r['tipo']] ?? $r['tipo']) ?></span></div><?php endforeach; if (!$agendaPasados) echo '<p class="mut">Sin eventos pasados.</p>'; ?>
      </div>
    </section>
  </div>
</section>
<?php else: ?>
<?= $formCliente(false) ?>
<?php endif; ?>
<?php endif; ?><script src="admin.js?v=32"></script><script src="../assets/push.js?v=6"></script></body></html>
