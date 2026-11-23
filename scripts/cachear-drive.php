<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/blens-config/bootstrap.php';
set_time_limit(0);
$id = (int)($argv[1] ?? 0);
$forzar = in_array('--force', $argv, true);
if ($id <= 0) { fwrite(STDERR, "Uso: php cachear-drive.php <proyecto_id> [--force]\n"); exit(1); }
$pdo = blens_db(); blens_migrar($pdo);
$rel = blens_drive_cachear_video($pdo, $id, $forzar);
echo $rel ? "OK $id $rel\n" : "FAIL $id\n";
exit($rel ? 0 : 1);
