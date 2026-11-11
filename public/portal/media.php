<?php
declare(strict_types=1);
require __DIR__ . '/../../blens-config/bootstrap.php';
session_name('blens_cliente'); session_start();
$cid = (int)($_SESSION['cid'] ?? 0);
if (!$cid || !empty($_SESSION['forzar'])) { http_response_code(403); exit; }
$s = blens_db()->prepare('SELECT preview,poster FROM entregas WHERE id=? AND cliente_id=?'); $s->execute([(int)($_GET['e'] ?? 0), $cid]); $r = $s->fetch();
$t = ($_GET['t'] ?? 'v') === 'p' ? 'p' : 'v';
$f = $r ? ($t === 'p' ? $r['poster'] : $r['preview']) : null;
$path = $f ? '/var/www/blens-private/entregas/' . basename($f) : '';
if (!$f || !is_file($path)) { http_response_code(404); exit; }
session_write_close();
$size = filesize($path);
header('Content-Type: ' . ($t === 'p' ? (mime_content_type($path) ?: 'image/jpeg') : 'video/mp4'));
header('Accept-Ranges: bytes'); header('Cache-Control: private, max-age=3600'); header('X-Content-Type-Options: nosniff');
$start = 0; $end = $size - 1;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $end); }
    elseif ($m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
    if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206); header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
$fp = fopen($path, 'rb'); fseek($fp, $start); $left = $end - $start + 1;
while ($left > 0 && !feof($fp)) { $chunk = fread($fp, min(131072, $left)); echo $chunk; $left -= strlen($chunk); flush(); }
fclose($fp);
