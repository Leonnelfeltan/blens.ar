<?php
declare(strict_types=1);

const PRESU_UNIDADES = ['audiovisual' => 'Audiovisual', 'web' => 'Desarrollo Web / Apps'];
const PRESU_ESTADOS = ['borrador' => 'Borrador', 'enviado' => 'Enviado', 'aceptado' => 'Aceptado', 'rechazado' => 'Rechazado'];
const PRESU_PLANTILLAS = [
    'reels' => [
        'nombre' => 'Pack Reels Mensual',
        'unidad_negocio' => 'audiovisual',
        'items' => [
            ['d' => 'Reels para redes (paquete mensual de 4 piezas)', 'c' => 4, 'p' => 180000],
        ],
        'condiciones' => 'Incluye guion, grabación y edición. Seña del 50% para confirmar el pack. Entrega estimada: 10 a 15 días hábiles.',
    ],
    'comercial' => [
        'nombre' => 'Producción Comercial',
        'unidad_negocio' => 'audiovisual',
        'items' => [
            ['d' => 'Video comercial / institucional (1 pieza)', 'c' => 1, 'p' => 250000],
        ],
        'condiciones' => 'Incluye guion, grabación en locación y edición. Seña del 50% para reservar fecha. Entrega estimada: 15 a 20 días hábiles.',
    ],
    'landing' => [
        'nombre' => 'Landing Page / Web Corporativa',
        'unidad_negocio' => 'web',
        'items' => [
            ['d' => 'Landing page / web corporativa (hasta 5 secciones)', 'c' => 1, 'p' => 320000],
        ],
        'condiciones' => 'Incluye diseño, desarrollo, versionado móvil y alta inicial. Seña del 50% para iniciar. Entrega estimada: 10 a 15 días hábiles.',
    ],
    'sistema' => [
        'nombre' => 'Sistema Web / App a Medida',
        'unidad_negocio' => 'web',
        'items' => [
            ['d' => 'Sistema web / app a medida (módulo inicial)', 'c' => 1, 'p' => 550000],
        ],
        'condiciones' => 'El alcance final se define en la reunión de relevamiento. Seña del 50% para iniciar. Entrega estimada: 30 a 45 días hábiles.',
    ],
];

function presu_unidad(?string $u): string { return $u === 'web' ? 'web' : 'audiovisual'; }
function presu_unidad_txt(?string $u): string { return PRESU_UNIDADES[presu_unidad($u)]; }
function presu_estado(?string $e): string { $e = strtolower(trim((string)$e)); return PRESU_ESTADOS[$e] ?? ucfirst($e); }
function presu_token(): string { return bin2hex(random_bytes(32)); }
function presu_link(array $p): string {
    $token = trim((string)($p['token'] ?? ''));
    if ($token === '') return '';
    return rtrim((string)(blens_cfg()['sitio'] ?? 'https://blens.ar'), '/') . '/presupuesto.php?t=' . rawurlencode($token);
}
function presu_condiciones(array $p): string {
    $c = trim(str_replace("\r\n", "\n", (string)($p['condiciones'] ?? '')));
    if ($c !== '') return $c;
    $c = trim(str_replace("\r\n", "\n", (string)($p['notas'] ?? '')));
    return $c !== '' ? $c : 'Seña del 50% para confirmar. Entrega estimada: a coordinar según el alcance.';
}
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
    $l[] = presu_condiciones($p);
    $link = presu_link($p);
    if ($link !== '') { $l[] = ''; $l[] = 'Podés verlo y aprobarlo acá: ' . $link; }
    $l[] = '';
    $l[] = 'Quedo atento. ¡Gracias!';
    return implode("\n", $l);
}
function presu_cliente(PDO $pdo, array $p): array {
    $tel = preg_replace('/\D+/', '', (string)($p['telefono'] ?? '')) ?? '';
    $nombre = mb_substr(trim((string)($p['cliente_nombre'] ?? '')), 0, 80);
    $empresa = mb_substr(trim((string)($p['empresa'] ?? '')), 0, 100);
    $rows = $pdo->query('SELECT id,nombre,empresa,telefono FROM clientes')->fetchAll();
    foreach ($rows as $r) {
        $rt = preg_replace('/\D+/', '', (string)($r['telefono'] ?? '')) ?? '';
        if ($tel !== '' && $rt === $tel) return ['id' => (int)$r['id'], 'creado' => false, 'usuario' => '', 'clave' => ''];
    }
    foreach ($rows as $r) {
        if (mb_strtolower(trim((string)$r['nombre'])) === mb_strtolower($nombre)
            && ($empresa === '' || mb_strtolower(trim((string)$r['empresa'])) === mb_strtolower($empresa))) return ['id' => (int)$r['id'], 'creado' => false, 'usuario' => '', 'clave' => ''];
    }
    $base = mb_strtolower($nombre, 'UTF-8');
    $base = strtr($base, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $base = trim(preg_replace('/[^a-z0-9]+/', '.', $base) ?? '', '.');
    if ($base === '') $base = 'cliente';
    $base = substr($base, 0, 40);
    $usuario = $base; $i = 1; $q = $pdo->prepare('SELECT 1 FROM clientes WHERE usuario=?');
    while (true) {
        $q->execute([$usuario]);
        if (!$q->fetchColumn()) break;
        $usuario = $base . (++$i);
        if (strlen($usuario) > 60) $usuario = substr($base, 0, 50) . $i;
    }
    $clave = blens_clave_nueva();
    $pdo->prepare('INSERT INTO clientes (nombre,empresa,usuario,clave_hash,telefono,activo,cambiar_clave,clave_inicial_hasta) VALUES (?,?,?,?,?,1,1,DATE_ADD(NOW(), INTERVAL 7 DAY))')
        ->execute([$nombre, $empresa, $usuario, password_hash($clave, PASSWORD_DEFAULT), trim((string)($p['telefono'] ?? ''))]);
    return ['id' => (int)$pdo->lastInsertId(), 'creado' => true, 'usuario' => $usuario, 'clave' => $clave];
}
function presu_wa_tel(PDO $pdo, array $p): string {
    $tel = preg_replace('/\D+/', '', (string)($p['telefono'] ?? '')) ?? '';
    if ($tel !== '') return $tel;
    $nombre = mb_strtolower(trim((string)($p['cliente_nombre'] ?? '')));
    $empresa = mb_strtolower(trim((string)($p['empresa'] ?? '')));
    if ($nombre === '') return '';
    foreach ($pdo->query('SELECT nombre,empresa,telefono FROM clientes') as $r) {
        if (mb_strtolower(trim((string)($r['nombre'] ?? ''))) !== $nombre) continue;
        if ($empresa !== '' && mb_strtolower(trim((string)($r['empresa'] ?? ''))) !== $empresa) continue;
        return (string)($r['telefono'] ?? '');
    }
    return '';
}
function presu_asegurar_token(PDO $pdo, int $id): string {
    $q = $pdo->prepare('SELECT token FROM presupuestos WHERE id=?');
    $q->execute([$id]);
    $token = trim((string)$q->fetchColumn());
    if ($token !== '') return $token;
    $upd = $pdo->prepare("UPDATE presupuestos SET token=? WHERE id=? AND (token IS NULL OR token='')");
    for ($i = 0; $i < 12; $i++) {
        $token = presu_token();
        try {
            $upd->execute([$token, $id]);
            if ($upd->rowCount() > 0) return $token;
        } catch (PDOException $x) { if ($i === 11) throw $x; }
    }
    throw new RuntimeException('No se pudo generar el link del presupuesto.');
}
function presu_aceptar_id(PDO $pdo, int $id): array {
    if ($id <= 0) throw new RuntimeException('Presupuesto inexistente.');
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM presupuestos WHERE id=? FOR UPDATE');
        $q->execute([$id]);
        $p = $q->fetch();
        if (!$p) throw new RuntimeException('Presupuesto inexistente.');
        if ((string)$p['estado'] === 'aceptado' && !empty($p['trabajo_id'])) {
            $pdo->commit();
            return ['ok' => true, 'id' => $id, 'idempotente' => true, 'trabajo_id' => (int)$p['trabajo_id'], 'total' => presu_total(presu_items((string)$p['items_json']))];
        }
        if ((string)$p['estado'] !== 'enviado') throw new RuntimeException('Este presupuesto ya no está pendiente de aprobación.');
        $items = presu_items((string)$p['items_json']);
        $total = presu_total($items);
        if (!$items || $total <= 0) throw new RuntimeException('El presupuesto no tiene ítems válidos.');
        $cliente = presu_cliente($pdo, $p);
        $detalle = mb_substr('Presupuesto ' . (string)$p['numero'] . ' · ' . (string)$p['cliente_nombre'], 0, 200);
        $pdo->prepare("INSERT INTO trabajos (cliente_id,fecha,tipo,detalle,cantidad,monto_unit,prueba,estado,unidad_negocio,medio_pago,monto_senia) VALUES (?,?,?,?,1,?,?,'pendiente',?,NULL,0)")
            ->execute([$cliente['id'], date('Y-m-d'), 'otro', $detalle, $total, modo_prueba($pdo), presu_unidad((string)($p['unidad_negocio'] ?? ''))]);
        $tid = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE presupuestos SET estado='aceptado', aceptado_en=NOW(), trabajo_id=? WHERE id=?")->execute([$tid, $id]);
        $pdo->commit();
    } catch (Throwable $x) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $x;
    }
    $nombre = trim((string)($p['empresa'] ?? '')) ?: trim((string)($p['cliente_nombre'] ?? ''));
    $cuerpo = '¡Presupuesto aceptado! ' . $nombre . ' aprobó #' . $id . ' por ' . presu_money($total);
    try {
        blens_push_notify($pdo, 'admin', null, [
            'title' => '¡Presupuesto aceptado!',
            'body' => $cuerpo,
            'url' => '/admin/presupuesto_ver.php?id=' . $id,
            'tag' => 'blens-presu-' . $id,
        ]);
    } catch (Throwable $x) { error_log('[blens presupuestos push] ' . $x->getMessage()); }
    try {
        $base = rtrim((string)(blens_cfg()['sitio'] ?? 'https://blens.ar'), '/');
        $detalle = $cuerpo . (!empty($cliente['creado']) ? "\nAcceso nuevo: " . $cliente['usuario'] . ' · clave temporal: ' . $cliente['clave'] : '');
        blens_ntfy('Presupuesto aceptado · blens.ar', $detalle, 'high', 'white_check_mark', ['view, Ver presupuesto, ' . $base . '/admin/presupuesto_ver.php?id=' . $id]);
    } catch (Throwable $x) { error_log('[blens presupuestos ntfy] ' . $x->getMessage()); }
    return ['ok' => true, 'id' => $id, 'idempotente' => false, 'trabajo_id' => $tid, 'total' => $total, 'cliente' => $nombre, 'nuevo_cliente' => $cliente['creado'], 'usuario' => $cliente['usuario'], 'clave' => $cliente['clave']];
}
