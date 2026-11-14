<?php
declare(strict_types=1);
// Recordatorio matutino de la agenda. Uso: php blens-config/recordatorio_diario.php [--dry-run]
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI.'); }
require __DIR__ . '/../public/admin/lib.php';

$dry = in_array('--dry-run', $argv, true);
$pdo = blens_db();
$rows = $pdo->query("SELECT a.*, c.nombre AS cliente_nombre, c.empresa AS cliente_empresa
    FROM agenda a LEFT JOIN clientes c ON c.id=a.cliente_id
    WHERE a.completado=0 AND a.fecha=CURDATE()
    ORDER BY a.hora IS NULL, a.hora, a.id")->fetchAll();
if (!$rows) {
    echo "Sin eventos pendientes para hoy (" . date('d/m/Y') . ").\n";
    exit(0);
}
$lineas = [];
foreach ($rows as $r) {
    $hora = !empty($r['hora']) ? substr((string)$r['hora'], 0, 5) . ' · ' : '';
    $tipo = AGENDA_TIPOS[$r['tipo']] ?? ucfirst((string)$r['tipo']);
    $cli = trim((string)($r['cliente_empresa'] ?? '')) ?: trim((string)($r['cliente_nombre'] ?? ''));
    $nota = trim((string)($r['lugar_nota'] ?? ''));
    $lineas[] = $hora . $tipo . ': ' . $r['titulo'] . ($cli !== '' ? ' (' . $cli . ')' : '') . ($nota !== '' ? ' — ' . $nota : '');
}
$cuerpo = 'Hoy tenés ' . count($rows) . ' evento(s) pendiente(s):' . "\n\n" . implode("\n", $lineas);
if ($dry) {
    echo "[dry-run] Agenda de hoy · blens.ar\n\n" . $cuerpo . "\n";
    exit(0);
}
blens_ntfy('Agenda de hoy · blens.ar', $cuerpo, 'high', 'calendar', ['view, Ver agenda, https://blens.ar/admin/agenda.php']);
echo "Recordatorio enviado con " . count($rows) . " evento(s).\n";
