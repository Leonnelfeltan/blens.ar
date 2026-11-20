<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI.'); }
date_default_timezone_set('America/Argentina/Buenos_Aires');
require dirname(__DIR__) . '/blens-config/bootstrap.php';

$dry = in_array('--dry-run', $argv, true);
$log = '/var/www/blens-private/agenda-push-cron.log';
$tipos = ['rodaje' => 'Rodaje', 'entrega' => 'Entrega', 'reunion' => 'Reunión'];
$registrar = function (string $mensaje) use ($log): void {
    @file_put_contents($log, date('Y-m-d H:i:s') . ' | ' . $mensaje . "\n", FILE_APPEND | LOCK_EX);
};

try {
    $pdo = blens_db();
    $tomado = (int)$pdo->query("SELECT GET_LOCK('blens_agenda_push', 0)")->fetchColumn();
    if ($tomado !== 1) {
        $registrar('Otra ejecución está en curso; se omite.');
        exit(0);
    }
    try {
        $offset = (new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('P');
        $pdo->exec('SET time_zone = ' . $pdo->quote($offset));
        $s = $pdo->prepare("SELECT a.*, c.nombre AS cliente_nombre, c.empresa AS cliente_empresa
            FROM agenda a LEFT JOIN clientes c ON c.id=a.cliente_id
            WHERE a.completado=0 AND a.fecha=CURDATE() AND a.tipo IN ('rodaje','entrega','reunion')
            ORDER BY a.hora IS NULL, a.hora, a.id");
        $s->execute();
        $eventos = $s->fetchAll();
        if (!$eventos) {
            $mensaje = 'Sin eventos para hoy (' . date('Y-m-d') . '); no se envió push.';
            echo $mensaje, "\n";
            $registrar($mensaje);
            exit(0);
        }
        $lineas = [];
        $horarios = [];
        foreach ($eventos as $r) {
            $hora = trim((string)($r['hora'] ?? '')) !== '' ? substr((string)$r['hora'], 0, 5) : 'sin hora';
            $horarios[] = $hora;
            $tipo = $tipos[(string)($r['tipo'] ?? '')] ?? ucfirst((string)($r['tipo'] ?? 'evento'));
            $titulo = trim((string)($r['titulo'] ?? '')) ?: 'Sin título';
            $cliente = trim((string)($r['cliente_empresa'] ?? '')) ?: trim((string)($r['cliente_nombre'] ?? ''));
            $lineas[] = $hora . ' · ' . $tipo . ': ' . $titulo . ($cliente !== '' ? ' - ' . $cliente : '');
        }
        $cantidad = count($eventos);
        $body = 'Agenda de hoy (' . $cantidad . ' ' . ($cantidad === 1 ? 'evento' : 'eventos') . '): ' . $lineas[0]
            . "\nHorarios: " . implode(', ', $horarios);
        $payload = [
            'title' => 'Agenda de hoy · blens.ar',
            'body' => $body,
            'url' => '/admin/agenda.php',
            'tag' => 'blens-agenda-' . date('Y-m-d'),
        ];
        if ($dry) {
            echo '[dry-run] ', $payload['title'], "\n", $body, "\n";
            $registrar('[dry-run] ' . $cantidad . ' evento(s); push no enviado.');
            exit(0);
        }
        $res = blens_push_notify_detailed($pdo, 'admin', null, $payload);
        $mensaje = 'Agenda: ' . $cantidad . ' evento(s); intentados=' . (int)$res['intentados']
            . ' enviados=' . (int)$res['enviados'] . ' estados=' . implode(',', array_slice($res['estados'], 0, 5));
        if ($res['errores']) $mensaje .= ' errores=' . implode(' | ', array_slice($res['errores'], 0, 2));
        echo $mensaje, "\n";
        $registrar($mensaje);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('blens_agenda_push')");
    }
} catch (Throwable $x) {
    $registrar('Error: ' . $x->getMessage());
    fwrite(STDERR, 'Error: ' . $x->getMessage() . "\n");
    exit(1);
}
