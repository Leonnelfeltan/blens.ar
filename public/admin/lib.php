<?php
declare(strict_types=1);
require_once __DIR__ . '/../../blens-config/bootstrap.php';
blens_migrar();                                    // migración automática de columnas nuevas (idempotente)

/* ---- Ajustes de cobros (alias / CBU): primero la base, si no, lo que esté en config.php ---- */
function ajuste(PDO $pdo, string $clave, string $def = ''): string {
    try { $s = $pdo->prepare('SELECT valor FROM ajustes WHERE clave=?'); $s->execute([$clave]); $v = $s->fetchColumn(); if ($v !== false && trim((string)$v) !== '') return trim((string)$v); } catch (Throwable $x) { }
    $c = blens_cfg(); $v = trim((string)($c[$clave] ?? ''));
    return $v !== '' ? $v : $def;
}
function set_ajuste(PDO $pdo, string $clave, string $valor): void {
    $pdo->prepare('INSERT INTO ajustes (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)')->execute([$clave, $valor]);
}
/* ---- Actividad del portal de un cliente ---- */
function portal_info(?array $c): string {
    $v = (int)($c['portal_visitas'] ?? 0); $u = trim((string)($c['portal_ultima_visita'] ?? ''));
    if ($v <= 0 && $u === '') return 'Nunca ingresó';
    $t = 'Visto ' . $v . ' ' . ($v === 1 ? 'vez' : 'veces');
    if ($u !== '') $t .= ' · Último acceso: ' . date('d/m H:i', strtotime($u));
    return $t;
}
/* ---- Datos del plan mensual ---- */
function plan_de(?array $c): array {
    return ['nombre' => trim((string)($c['plan_nombre'] ?? '')), 'monto' => (int)($c['plan_monto'] ?? 0), 'videos' => (int)($c['plan_videos'] ?? 0)];
}
function videos_del_mes(PDO $pdo, int $cid): int {
    $s = $pdo->prepare("SELECT COALESCE(SUM(cantidad),0) FROM trabajos WHERE cliente_id=? AND tipo='video' AND fecha>=? AND fecha<=LAST_DAY(?)");
    $ini = date('Y-m-01'); $s->execute([$cid, $ini, $ini]); return (int)$s->fetchColumn();
}
function abono_cargado(PDO $pdo, int $cid): bool {
    $ini = date('Y-m-01'); $s = $pdo->prepare("SELECT COUNT(*) FROM trabajos WHERE cliente_id=? AND tipo='abono' AND fecha>=? AND fecha<=LAST_DAY(?)");
    $s->execute([$cid, $ini, $ini]); return (int)$s->fetchColumn() > 0;
}
/* ---- Mensaje de resumen para mandar por WhatsApp ---- */
function resumen_cobro(array $cl, array $pend, int $total, string $alias, string $cbu, int $vm, array $pl): string {
    $nombre = explode(' ', trim((string)($cl['nombre'] ?? 'cliente')))[0] ?: 'cliente';
    $empresa = trim((string)($cl['empresa'] ?? ''));
    $l = [];
    $l[] = 'Hola ' . $nombre . ($empresa !== '' ? ' (' . $empresa . ')' : '') . ', te escribimos desde blens.ar.';
    $l[] = '';
    $l[] = 'Este es el resumen de tus trabajos y las cuotas pendientes al ' . date('d/m/Y') . ':';
    $l[] = '';
    if ($pend) { foreach ($pend as $r) { $sub = (int)$r['cantidad'] * (int)$r['monto_unit']; $det = trim((string)$r['detalle']) !== '' ? (string)$r['detalle'] : (TIPOS[$r['tipo']] ?? (string)$r['tipo']); $l[] = '• ' . $det . ' — ' . (int)$r['cantidad'] . ' × ' . peso((int)$r['monto_unit']) . ' = ' . peso($sub); } }
    else $l[] = '• No tenés trabajos pendientes de pago.';
    $l[] = '';
    $l[] = 'Total a pagar: ' . peso($total);
    if ($pl['videos'] > 0) $l[] = 'Videos de este mes: ' . $vm . ' de ' . $pl['videos'] . ' incluidos en tu plan.';
    if ($alias !== '') $l[] = 'Podés pagar por alias: ' . $alias;
    if ($cbu !== '') $l[] = 'CBU: ' . $cbu;
    if ($alias === '' && $cbu === '') $l[] = 'Consultanos los datos de pago y te los pasamos.';
    $l[] = '';
    $l[] = 'Cuando lo pagues, confirmanos y te emitimos el recibo. ¡Gracias!';
    return implode("\n", $l);
}
function up(string $f, array $ok, int $max): ?string {
    if (empty($_FILES[$f]['name'])) return null;
    $u = $_FILES[$f];
    if ($u['error'] !== UPLOAD_ERR_OK) throw new RuntimeException("No se pudo subir «$f» (código {$u['error']}). Si es un video grande, revisá los límites de PHP (README).");
    if ($u['size'] > $max) throw new RuntimeException("«$f» supera el tamaño máximo permitido.");
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($u['tmp_name']);
    if (!isset($ok[$mime])) throw new RuntimeException("Formato no permitido en «$f» ($mime).");
    $dir = __DIR__ . '/../media/uploads'; if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(8)) . '.' . $ok[$mime];
    if (!move_uploaded_file($u['tmp_name'], "$dir/$name")) throw new RuntimeException('No se pudo guardar el archivo.');
    return "media/uploads/$name";
}
function rmf(?string $p): void { if ($p && str_starts_with($p, 'media/uploads/')) @unlink(__DIR__ . '/../' . $p); }

const VIDEO_PX = 720; // lado corto del video final (720 = 720p)
function procesar(string $rel): array {
    if (!function_exists('exec') || trim((string)@shell_exec('command -v ffmpeg')) === '') throw new RuntimeException('Falta ffmpeg en el servidor (sudo apt install ffmpeg).');
    ignore_user_abort(true); set_time_limit(0);
    $src = __DIR__ . '/../' . $rel; $base = bin2hex(random_bytes(8));
    $out = "media/uploads/$base.mp4"; $jpg = "media/uploads/$base.jpg"; $abs = __DIR__ . '/../';
    $px = VIDEO_PX; $vf = "scale='if(gt(iw,ih),-2,min($px,iw))':'if(gt(iw,ih),min($px,ih),-2)'";
    exec('nice -n 10 ffmpeg -v error -y -i ' . escapeshellarg($src) . ' -vf ' . escapeshellarg($vf) . ' -c:v libx264 -preset veryfast -crf 25 -pix_fmt yuv420p -movflags +faststart -c:a aac -b:a 96k ' . escapeshellarg($abs . $out) . ' 2>&1', $o, $rc);
    if ($rc !== 0 || !is_file($abs . $out)) { rmf($rel); throw new RuntimeException('No se pudo convertir el video: ' . mb_substr(implode(' ', $o), 0, 200)); }
    foreach ([1, 0] as $t) { exec('ffmpeg -v error -y -ss ' . $t . ' -i ' . escapeshellarg($abs . $out) . ' -frames:v 1 -vf scale=800:-2 -q:v 4 ' . escapeshellarg($abs . $jpg) . ' 2>&1'); if (is_file($abs . $jpg)) break; }
    rmf($rel);
    return [$out, is_file($abs . $jpg) ? $jpg : null];
}

function lanzar_worker(int $id, string $tipo = 'proyecto', bool $forzar = false): void {
    $w = realpath(__DIR__ . '/../../blens-config/worker.php');
    if (!$w) throw new RuntimeException('Falta el procesador de videos (blens-config/worker.php).');
    $php = trim((string)@shell_exec('command -v php')) ?: '/usr/bin/php';
    $arg = $tipo === 'entrega' ? ' entrega' : '';
    if ($tipo === 'entrega' && $forzar) $arg .= ' forzar';
    exec('setsid nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($w) . ' ' . (int)$id . $arg . ' > /dev/null 2>&1 &');
}

// Número en formato WhatsApp Argentina (549 + área + número)
function wa_num(string $tel): ?string {
    $d = preg_replace('/\D+/', '', $tel) ?? ''; if ($d === '') return null;
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    if (str_starts_with($d, '54')) return str_starts_with($d, '549') ? $d : '549' . substr($d, 2);
    $d = ltrim($d, '0');
    if (strlen($d) === 12) foreach (str_starts_with($d, '11') ? [2] : [3, 4] as $a) { if (substr($d, $a, 2) === '15') { $d = substr($d, 0, $a) . substr($d, $a + 2); break; } }
    return strlen($d) === 10 ? '549' . $d : null;
}
function wa_url(string $tel, string $texto): ?string { $n = wa_num($tel); return $n ? 'https://wa.me/' . $n . '?text=' . rawurlencode($texto) : null; }

const TIPOS = ['video' => 'Video', 'foto' => 'Foto', 'abono' => 'Abono mensual', 'otro' => 'Otro'];
const MEDIOS = ['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'otro' => 'Otro'];
const MES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
function peso(int $n): string { return '$ ' . number_format($n, 0, ',', '.'); }
function mes(string $d): string { return MES[(int)substr($d, 5, 2)] . ' ' . substr($d, 0, 4); }
function fecha_ok(string $d): bool { $x = DateTime::createFromFormat('Y-m-d', $d); return $x && $x->format('Y-m-d') === $d; }

function nro(array $r): string { return (!empty($r['prueba']) ? 'PRUEBA ' : 'R-') . str_pad((string)($r['numero'] ?? $r['id']), 4, '0', STR_PAD_LEFT); }
function logo_proc(string $rel): string {
    if (!function_exists('exec') || trim((string)@shell_exec('command -v ffmpeg')) === '') throw new RuntimeException('Falta ffmpeg en el servidor.');
    $abs = __DIR__ . '/../'; $dir = $abs . 'media/logos'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(8)) . '.png';
    exec('ffmpeg -v error -y -i ' . escapeshellarg($abs . $rel) . ' -frames:v 1 -vf ' . escapeshellarg("scale='min(600,iw)':-2") . ' ' . escapeshellarg("$dir/$name") . ' 2>&1', $o, $rc);
    rmf($rel);
    if ($rc !== 0 || !is_file("$dir/$name")) throw new RuntimeException('No se pudo procesar el logo. Probá con un PNG o JPG.');
    return $name;
}
function rmlogo(?string $f): void { if ($f) @unlink(__DIR__ . '/../media/logos/' . basename($f)); }
