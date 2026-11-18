<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI.'); }
date_default_timezone_set('America/Argentina/Buenos_Aires');
require dirname(__DIR__) . '/public/admin/lib.php';

$dry = in_array('--dry-run', $argv, true);

try {
    $pdo = blens_db();
    $hoy = new DateTimeImmutable('today');
    $desde = $hoy->modify('-6 days')->format('Y-m-d');
    $hasta = $hoy->format('Y-m-d');
    $finAgenda = $hoy->modify('+6 days')->format('Y-m-d');

    $s = $pdo->prepare("SELECT COALESCE(SUM(cantidad*monto_unit),0) FROM trabajos WHERE estado='cobrado' AND prueba=? AND fecha_pago BETWEEN ? AND ?");
    $s->execute([modo_prueba($pdo), $desde, $hasta]);
    $cobrado = round((float)$s->fetchColumn(), 2);
    $pendiente = por_cobrar($pdo);

    $s = $pdo->prepare("SELECT a.*, c.nombre AS cliente_nombre, c.empresa AS cliente_empresa FROM agenda a LEFT JOIN clientes c ON c.id=a.cliente_id WHERE a.completado=0 AND a.fecha BETWEEN ? AND ? ORDER BY a.fecha, a.hora IS NULL, a.hora, a.id");
    $s->execute([$hasta, $finAgenda]);
    $eventos = $s->fetchAll();
    $proximo = '';
    if ($eventos) {
        $r = $eventos[0];
        $hora = !empty($r['hora']) ? substr((string)$r['hora'], 0, 5) . ' ' : '';
        $cli = trim((string)($r['cliente_empresa'] ?? '')) ?: trim((string)($r['cliente_nombre'] ?? ''));
        $tipo = AGENDA_TIPOS[$r['tipo']] ?? ucfirst((string)$r['tipo']);
        $proximo = 'Próximo: ' . date('d/m', strtotime((string)$r['fecha'])) . ' ' . $hora . '· ' . $tipo . ': ' . $r['titulo'] . ($cli !== '' ? ' (' . $cli . ')' : '');
    }
    $consultas = consultas_pendientes($pdo);

    $cuerpo = 'Cobrado (últimos 7 días): ' . peso($cobrado) . "\n"
        . 'Pendiente por cobrar: ' . peso($pendiente) . "\n"
        . 'Agenda próximos 7 días: ' . count($eventos) . " evento(s)\n"
        . ($proximo !== '' ? '  ' . $proximo . "\n" : '')
        . 'Consultas sin responder: ' . $consultas;

    $titulo = 'Resumen semanal · blens.ar';
    if ($dry) { echo $titulo, "\n\n", $cuerpo, "\n"; exit(0); }

    blens_ntfy($titulo, $cuerpo, 'default', 'calendar,chart_with_upwards_trend', [
        ['view', 'Ver panel', 'https://blens.ar/admin/'],
        ['view', 'Ver cobros', 'https://blens.ar/admin/cobros.php'],
    ]);
    echo "Resumen semanal enviado.\n";
} catch (Throwable $x) {
    fwrite(STDERR, 'Error: ' . $x->getMessage() . "\n");
    exit(1);
}
