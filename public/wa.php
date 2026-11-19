<?php
declare(strict_types=1);
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/../blens-config/bootstrap.php';
$num = blens_wa_numero();
$msg = trim((string)($_GET['text'] ?? $_GET['msg'] ?? ''));
$msg = mb_substr($msg, 0, 500);
$url = $num !== '' ? 'https://wa.me/' . $num . ($msg !== '' ? '?text=' . rawurlencode($msg) : '') : '../#contacto';
header('Cache-Control: no-store');
header('Location: ' . $url, true, 302);
