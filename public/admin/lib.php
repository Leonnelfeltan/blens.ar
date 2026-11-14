<?php
declare(strict_types=1);
require_once __DIR__ . '/../../blens-config/bootstrap.php';
blens_migrar();                                    // migración automática de columnas nuevas (idempotente)

/* ---- Acreditación diaria de intereses (estilo Naranja X) ----
   Es idempotente: se puede ejecutar muchas veces por día y nunca duplica intereses.
   Guarda en 'ajustes': tna_ultima_fecha (Y-m-d), tna_ganado_mes (acumulado del mes) y tna_mes_actual (Y-m). */
function blens_acreditar_tna(?PDO $pdo = null): void {
    try {
        $pdo ??= blens_db();
        $hoy = date('Y-m-d'); $mes = date('Y-m');
        $saldo = saldo_cuenta($pdo); $tna = tna_cuenta($pdo);
        $ult = trim((string)ajuste($pdo, 'tna_ultima_fecha', ''));
        $mesAct = trim((string)ajuste($pdo, 'tna_mes_actual', ''));
        $ganado = (float)str_replace(',', '.', (string)ajuste($pdo, 'tna_ganado_mes', '0'));
        $cambioMes = ($mesAct !== $mes);
        if ($cambioMes) { $ganado = 0.0; set_ajuste($pdo, 'tna_mes_actual', $mes); set_ajuste($pdo, 'tna_ganado_mes', '0'); }  // mes nuevo: se reinicia el acumulado
        if ($ult === '') { set_ajuste($pdo, 'tna_ultima_fecha', $hoy); return; }   // primera vez: arranca desde hoy
        if ($hoy <= $ult) return;                                        // ya acreditado hasta hoy
        if ($saldo <= 0 || $tna <= 0) { set_ajuste($pdo, 'tna_ultima_fecha', $hoy); return; }
        $dias = (int)floor((strtotime($hoy) - strtotime($ult)) / 86400);
        if ($dias > 31) $dias = 31;                                      // tope: si el panel estuvo mucho tiempo sin abrirse
        if ($dias < 1) { set_ajuste($pdo, 'tna_ultima_fecha', $hoy); return; }
        $f = $tna / 100 / 365;
        for ($i = 0; $i < $dias; $i++) { $interes = $saldo * $f; $saldo += $interes; $ganado += $interes; }
        set_ajuste($pdo, 'saldo_cuenta', (string)round($saldo));
        set_ajuste($pdo, 'tna_ganado_mes', (string)round($ganado, 2));
        set_ajuste($pdo, 'tna_ultima_fecha', $hoy);
    } catch (Throwable $x) { error_log('[blens tna] ' . $x->getMessage()); }
}

/* ---- Actividad del portal de un cliente ---- */
function portal_info(?array $c): string {
    $v = (int)($c['portal_visitas'] ?? 0); $u = trim((string)($c['portal_ultima_visita'] ?? ''));
    if ($v <= 0 && $u === '') return 'Nunca ingresó';
    $t = 'Visto ' . $v . ' ' . ($v === 1 ? 'vez' : 'veces');
    if ($u !== '') $t .= ' · Último acceso: ' . date('d/m H:i', strtotime($u));
    return $t;
}
/* ---- Mensaje de resumen para mandar por WhatsApp ---- */
function resumen_cobro(array $cl, array $pend, int $total, string $alias, string $cbu): string {
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

/* ---- Comprobantes de cobros y gastos (guardados fuera de /public) ---- */
const COMPROBANTE_MAX = 8 * 1048576;
const COMPROBANTE_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
function comprobante_dir(): string {
    if (!is_dir(COMPROBANTES_DIR)) { @mkdir(COMPROBANTES_DIR, 02770, true); @chmod(COMPROBANTES_DIR, 02770); }
    return COMPROBANTES_DIR;
}
function comprobante_subir(string $campo = 'comprobante'): ?string {
    if (empty($_FILES[$campo]['name'])) return null;
    $u = $_FILES[$campo];
    if (($u['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($u['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('No se pudo subir el comprobante (código ' . (int)$u['error'] . ').');
    if ((int)$u['size'] <= 0) throw new RuntimeException('El comprobante está vacío.');
    if ((int)$u['size'] > COMPROBANTE_MAX) throw new RuntimeException('El comprobante supera el máximo de 8 MB.');
    $ext = strtolower(pathinfo((string)$u['name'], PATHINFO_EXTENSION));
    $permitidas = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'pdf' => ['application/pdf']];
    if (!isset($permitidas[$ext])) throw new RuntimeException('Formato no permitido. Subí una imagen JPG, PNG o WebP, o un PDF.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$u['tmp_name']);
    if (!isset(COMPROBANTE_MIMES[$mime])) throw new RuntimeException('El archivo no es una imagen o PDF válido (' . $mime . ').');
    if (!in_array($mime, $permitidas[$ext], true)) throw new RuntimeException('La extensión del archivo no coincide con su contenido real.');
    $dir = comprobante_dir();
    if (!is_writable($dir)) throw new RuntimeException('La carpeta privada de comprobantes no tiene permisos de escritura.');
    $dest = $dir . '/' . bin2hex(random_bytes(12)) . '.' . COMPROBANTE_MIMES[$mime];
    if (!move_uploaded_file($u['tmp_name'], $dest)) throw new RuntimeException('No se pudo guardar el comprobante.');
    @chmod($dest, 0640);
    return basename($dest);
}
function comprobante_borrar(?string $nombre): void {
    if (!$nombre) return;
    $nombre = basename($nombre);
    if ($nombre === '' || $nombre === '.' || $nombre === '..') return;
    $p = comprobante_dir() . '/' . $nombre;
    if (is_file($p)) @unlink($p);
}
function comprobante_boton(string $tipo, int $id): string {
    $tipo = $tipo === 'gasto' ? 'gasto' : 'cobro';
    $svg = '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4h7l4 4v12H8z" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M15 4v4h4" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>';
    return '<a class="btn g cb" href="ver_comprobante.php?tipo=' . $tipo . '&amp;id=' . $id . '" target="_blank" rel="noopener">' . $svg . 'Ver comprobante</a>';
}

/* ---- Agenda de rodajes, entregas y reuniones ---- */
const AGENDA_TIPOS = ['rodaje' => 'Rodaje', 'entrega' => 'Entrega', 'reunion' => 'Reunión'];
function agenda_pendientes(PDO $pdo): int {
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM agenda WHERE completado=0 AND fecha BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)")->fetchColumn();
    } catch (Throwable $x) { return 0; }
}

/* ---- Presupuestos rápidos ---- */
function presu_money(float $n): string {
    $n = round($n, 2);
    $dec = abs($n - round($n)) < 0.005 ? 0 : 2;
    return '$ ' . number_format($n, $dec, ',', '.');
}
function presu_cant(float $n): string {
    return abs($n - round($n)) < 0.0001 ? (string)(int)round($n) : rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
}
function presu_items(?string $json): array {
    $a = json_decode((string)$json, true);
    if (!is_array($a)) return [];
    $out = [];
    foreach ($a as $it) {
        if (!is_array($it)) continue;
        $d = trim((string)($it['d'] ?? $it['descripcion'] ?? ''));
        $c = (float)($it['c'] ?? $it['cantidad'] ?? 0);
        $p = (float)($it['p'] ?? $it['precio'] ?? 0);
        if ($d === '' || $c <= 0) continue;
        $out[] = ['d' => $d, 'c' => $c, 'p' => $p, 's' => round($c * $p, 2)];
    }
    return $out;
}
function presu_total(array $items): float {
    $t = 0.0;
    foreach ($items as $it) $t += (float)($it['s'] ?? ((float)($it['c'] ?? 0) * (float)($it['p'] ?? 0)));
    return round($t, 2);
}
function presu_wa_texto(array $p): string {
    $items = presu_items((string)($p['items_json'] ?? ''));
    $nombre = trim((string)($p['cliente_nombre'] ?? ''));
    $pila = $nombre !== '' ? explode(' ', $nombre)[0] : '';
    $l = [];
    $l[] = 'Hola' . ($pila !== '' ? ' ' . $pila : '') . ', te paso el presupuesto ' . (string)($p['numero'] ?? '') . ' de blens.ar:';
    $l[] = '';
    foreach ($items as $it) $l[] = '• ' . $it['d'] . ' — ' . presu_cant((float)$it['c']) . ' × ' . presu_money((float)$it['p']) . ' = ' . presu_money((float)$it['s']);
    $l[] = '';
    $l[] = 'Total: ' . presu_money(presu_total($items));
    $l[] = 'Validez: ' . (int)($p['validez_dias'] ?? 15) . ' días';
    if (trim((string)($p['notas'] ?? '')) !== '') { $l[] = ''; $l[] = trim((string)$p['notas']); }
    $l[] = '';
    $l[] = 'Quedo atento. ¡Gracias!';
    return implode("\n", $l);
}

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

const TIPOS = ['video' => 'Video', 'foto' => 'Foto', 'otro' => 'Otro'];
const MEDIOS = ['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'mercadopago' => 'Mercado Pago', 'otro' => 'Otro'];
const MES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
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
