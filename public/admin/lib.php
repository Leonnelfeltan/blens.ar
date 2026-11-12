<?php
declare(strict_types=1);
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
