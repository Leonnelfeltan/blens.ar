<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');
const CLAVE_INICIAL = 'blens123'; // clave histórica: se rechaza si alguien intenta elegirla
const COMPROBANTES_DIR = __DIR__ . '/comprobantes'; // privado, fuera de public/
// ---- Bloqueo por intentos fallidos de login (5 fallos en 15 min => 15 min de bloqueo, por IP) ----
const LOGIN_MAX = 5, LOGIN_VENTANA = 900, LOGIN_BLOQUEO = 900;
function blens_lock_file(string $scope): string {
    $d = '/var/www/blens-private/intentos'; if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d . '/' . hash('sha256', $scope . '|' . ($_SERVER['REMOTE_ADDR'] ?? '')) . '.json';
}
function blens_lock_leer(string $f): array { $d = json_decode((string)@file_get_contents($f), true); return is_array($d) ? $d : ['n' => 0, 't' => time(), 'hasta' => 0]; }
function blens_bloqueado(string $scope): int { $d = blens_lock_leer(blens_lock_file($scope)); return max(0, (int)($d['hasta'] ?? 0) - time()); }
function blens_fallo(string $scope): void {
    $f = blens_lock_file($scope); $d = blens_lock_leer($f);
    if (time() - (int)$d['t'] > LOGIN_VENTANA) $d = ['n' => 0, 't' => time(), 'hasta' => 0];
    $d['n']++; if ($d['n'] >= LOGIN_MAX) $d['hasta'] = time() + LOGIN_BLOQUEO;
    @file_put_contents($f, json_encode($d), LOCK_EX);
}
function blens_ok(string $scope): void { @unlink(blens_lock_file($scope)); }
// Clave temporal fácil de dictar: tres palabras al azar + dos números.
function blens_clave_nueva(): string {
    $adj = ['sol', 'luna', 'mar', 'rio', 'flor', 'paz', 'luz', 'nube', 'monte', 'valle', 'campo', 'fuego', 'tierra', 'cielo', 'viento', 'piedra', 'verde', 'azul', 'rojo', 'blanco', 'negro', 'dulce', 'fresco', 'claro', 'alto', 'bajo', 'rapido', 'suave', 'firme', 'nuevo'];
    $sus = ['casa', 'mesa', 'silla', 'puerta', 'ventana', 'libro', 'lapiz', 'papel', 'cafe', 'pan', 'queso', 'vino', 'agua', 'fruta', 'perro', 'gato', 'pajaro', 'arbol', 'barco', 'tren', 'auto', 'bici', 'camino', 'puente', 'jardin', 'playa', 'bosque', 'ciudad', 'pueblo', 'tiempo'];
    $p = fn(array $a): string => $a[random_int(0, count($a) - 1)];
    return $p($adj) . '-' . $p($sus) . '-' . $p($sus) . random_int(10, 99);
}
function blens_cfg(): array { static $c = null; return $c ??= require __DIR__ . '/config.php'; }

const SESION_DIAS = 60, SESION_LIFETIME = 5184000;
if (PHP_SAPI !== 'cli') {
    $dirSesiones = '/var/www/blens-private/sessions';
    if (!is_dir($dirSesiones)) @mkdir($dirSesiones, 0700, true);
    if (is_dir($dirSesiones) && is_writable($dirSesiones)) session_save_path($dirSesiones);
    ini_set('session.gc_maxlifetime', (string)SESION_LIFETIME);
    ini_set('session.cookie_lifetime', (string)SESION_LIFETIME);
    session_set_cookie_params([
        'lifetime' => SESION_LIFETIME,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
function blens_session_expire(): void {
    $nombre = session_name();
    $p = session_get_cookie_params();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        setcookie($nombre, '', [
            'expires' => time() - 42000,
            'path' => ($p['path'] ?? '') !== '' ? $p['path'] : '/',
            'domain' => $p['domain'] ?? '',
            'secure' => $p['secure'] ?? true,
            'httponly' => $p['httponly'] ?? true,
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

// ---- Formato de moneda y ajustes guardados en la base ----
function peso(float $n): string {
    $n = round($n, 2);
    $dec = abs($n - round($n)) < 0.005 ? 0 : 2;
    return '$ ' . number_format($n, $dec, ',', '.');
}
function ajuste(PDO $pdo, string $clave, string $def = ''): string {
    try { $s = $pdo->prepare('SELECT valor FROM ajustes WHERE clave=?'); $s->execute([$clave]); $v = $s->fetchColumn(); if ($v !== false && trim((string)$v) !== '') return trim((string)$v); } catch (Throwable $x) { }
    $c = blens_cfg(); $v = trim((string)($c[$clave] ?? ''));
    return $v !== '' ? $v : $def;
}
function set_ajuste(PDO $pdo, string $clave, string $valor): void {
    $pdo->prepare('INSERT INTO ajustes (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)')->execute([$clave, $valor]);
}
function modo_prueba(PDO $pdo): int { return ajuste($pdo, 'modo_prueba', '0') === '1' ? 1 : 0; }

/* ================= Panel financiero ================= */
const FIN_UNIDADES = ['audiovisual' => 'Audiovisual', 'web' => 'Desarrollo Web / Apps'];
const FIN_ESTADOS_PAGO = ['pendiente' => 'Pendiente', 'seniado' => 'Señado / Parcial', 'cobrado' => 'Cobrado total'];
const FIN_MEDIOS_PAGO = ['Transferencia / MP' => 1, 'Efectivo' => 1, 'USD / Cripto' => 1];
const FIN_CATEGORIAS_GASTO = ['Equipos / Insumos' => 1, 'Software / Hosting' => 1, 'Movilidad' => 1, 'Tercerizados' => 1, 'Impuestos / Otros' => 1, 'Operativo' => 1];
const FIN_MEDIO_LEGACY = ['Transferencia / MP' => 'transferencia', 'Efectivo' => 'efectivo', 'USD / Cripto' => 'otro'];
const FIN_MEDIO_LEGACY_NOMBRE = ['transferencia' => 'Transferencia / MP', 'mercadopago' => 'Transferencia / MP', 'efectivo' => 'Efectivo', 'otro' => 'USD / Cripto'];
function saldo_cuenta(PDO $pdo): float {
    $val = str_replace(',', '.', trim((string)ajuste($pdo, 'saldo_cuenta', '0')));
    return round((float)$val, 2);
}
function tna_cuenta(PDO $pdo): float { $v = (float)str_replace(',', '.', trim(ajuste($pdo, 'tna_cuenta', '0'))); return $v > 0 ? $v : 0.0; }
function fin_unidad(?string $u): string { return $u === 'web' ? 'web' : 'audiovisual'; }
function fin_unidad_txt(?string $u): string { return FIN_UNIDADES[fin_unidad($u)]; }
function fin_medio(?string $nuevo, ?string $legacy = null): string {
    $nuevo = trim((string)$nuevo);
    if (isset(FIN_MEDIOS_PAGO[$nuevo])) return $nuevo;
    $legacy = trim((string)$legacy);
    if ($legacy !== '' && isset(FIN_MEDIO_LEGACY_NOMBRE[$legacy])) return FIN_MEDIO_LEGACY_NOMBRE[$legacy];
    return '';
}
function fin_num(float $n): float { return round(max(0, $n), 2); }
function fin_total(array $r): float { return fin_num((float)($r['cantidad'] ?? 0) * (float)($r['monto_unit'] ?? 0)); }
function fin_abonado(array $r): float {
    if ((string)($r['estado'] ?? '') === 'cobrado') return fin_total($r);
    return min(fin_total($r), fin_num((float)($r['monto_senia'] ?? 0)));
}
function fin_saldo(array $r): float { return fin_num(fin_total($r) - fin_abonado($r)); }
function fin_estado(array $r): string {
    if ((string)($r['estado'] ?? '') === 'cobrado' || fin_saldo($r) <= 0) return 'cobrado';
    if (fin_abonado($r) > 0) return 'seniado';
    return 'pendiente';
}
function fin_estado_txt(array $r): string {
    return ['cobrado' => 'Cobrado total', 'seniado' => 'Señado / Parcial', 'pendiente' => 'Pendiente'][fin_estado($r)];
}
function fin_sql_abonado(): string { return "CASE WHEN estado='cobrado' THEN cantidad*monto_unit ELSE LEAST(COALESCE(monto_senia,0),cantidad*monto_unit) END"; }
function fin_sql_saldo(): string { return "CASE WHEN estado='cobrado' THEN 0 ELSE GREATEST(cantidad*monto_unit-COALESCE(monto_senia,0),0) END"; }
function fin_mes_valido(string $ym): string { return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym) ? $ym : date('Y-m'); }
function fin_cobrado_mes(PDO $pdo, string $ym, ?int $clienteId = null): array {
    $where = ['t.prueba=?', "DATE_FORMAT(COALESCE(t.fecha_pago,t.fecha),'%Y-%m')=?"];
    $args = [modo_prueba($pdo), fin_mes_valido($ym)];
    if ($clienteId) { $where[] = 't.cliente_id=?'; $args[] = $clienteId; }
    $s = $pdo->prepare('SELECT t.unidad_negocio,t.medio_pago,t.medio, SUM(' . fin_sql_abonado() . ') AS total FROM trabajos t WHERE ' . implode(' AND ', $where) . ' GROUP BY t.unidad_negocio,t.medio_pago,t.medio');
    $s->execute($args);
    $total = 0.0; $porUnidad = ['audiovisual' => 0.0, 'web' => 0.0]; $porMedio = [];
    foreach ($s->fetchAll() as $r) {
        $m = fin_num((float)$r['total']); $total += $m;
        $porUnidad[fin_unidad((string)($r['unidad_negocio'] ?? ''))] += $m;
        $medio = fin_medio((string)($r['medio_pago'] ?? ''), (string)($r['medio'] ?? ''));
        $porMedio[$medio] = round(($porMedio[$medio] ?? 0) + $m, 2);
    }
    return ['total' => fin_num($total), 'por_unidad' => $porUnidad, 'por_medio' => $porMedio];
}
function cobrar_mes(PDO $pdo, string $ym): float { return fin_cobrado_mes($pdo, $ym)['total']; }
function gastos_mes(PDO $pdo, string $ym): array {
    $s = $pdo->prepare("SELECT * FROM gastos WHERE DATE_FORMAT(fecha,'%Y-%m')=? AND prueba=? ORDER BY fecha DESC,id DESC");
    $s->execute([fin_mes_valido($ym), modo_prueba($pdo)]); return $s->fetchAll();
}
function total_gastos_mes(PDO $pdo, string $ym): float {
    $s = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE DATE_FORMAT(fecha,'%Y-%m')=? AND prueba=?");
    $s->execute([fin_mes_valido($ym), modo_prueba($pdo)]); return round((float)$s->fetchColumn(), 2);
}
function fin_categoria(?string $c): string {
    $c = trim((string)$c);
    return ($c === '' || $c === 'general') ? 'Operativo' : mb_substr($c, 0, 50);
}
function fin_gastos_resumen(PDO $pdo, string $ym): array {
    $total = 0.0; $porCategoria = [];
    foreach (gastos_mes($pdo, $ym) as $g) {
        $m = fin_num((float)$g['monto']); $cat = fin_categoria((string)($g['categoria'] ?? ''));
        $total += $m; $porCategoria[$cat] = round(($porCategoria[$cat] ?? 0) + $m, 2);
    }
    arsort($porCategoria);
    return ['total' => fin_num($total), 'por_categoria' => $porCategoria, 'n' => count(gastos_mes($pdo, $ym))];
}
function fin_pendientes(PDO $pdo, ?int $clienteId = null, ?string $ym = null): array {
    $where = ['t.prueba=?', "t.estado<>'cobrado'", '(' . fin_sql_saldo() . ')>0'];
    $args = [modo_prueba($pdo)];
    if ($clienteId) { $where[] = 't.cliente_id=?'; $args[] = $clienteId; }
    if ($ym) { $where[] = "DATE_FORMAT(t.fecha,'%Y-%m')=?"; $args[] = fin_mes_valido($ym); }
    $s = $pdo->prepare('SELECT t.*, c.empresa,c.nombre,c.telefono, ' . fin_sql_saldo() . ' AS saldo FROM trabajos t LEFT JOIN clientes c ON c.id=t.cliente_id WHERE ' . implode(' AND ', $where) . ' ORDER BY t.fecha,t.id');
    $s->execute($args); return $s->fetchAll();
}
function fin_pendiente_mes(PDO $pdo, string $ym, ?int $clienteId = null): float {
    $t = 0.0; foreach (fin_pendientes($pdo, $clienteId, $ym) as $r) $t += fin_saldo($r); return fin_num($t);
}
function fin_movimientos_mes(PDO $pdo, string $ym): array {
    $ym = fin_mes_valido($ym); $out = [];
    $s = $pdo->prepare('SELECT t.*, c.empresa,c.nombre, ' . fin_sql_abonado() . ' AS pagado, ' . fin_sql_saldo() . ' AS saldo FROM trabajos t LEFT JOIN clientes c ON c.id=t.cliente_id WHERE t.prueba=? AND DATE_FORMAT(COALESCE(t.fecha_pago,t.fecha),\'%Y-%m\')=? HAVING pagado>0 ORDER BY COALESCE(t.fecha_pago,t.fecha),t.id');
    $s->execute([modo_prueba($pdo), $ym]);
    foreach ($s->fetchAll() as $r) {
        $out[] = [
            'fecha' => (string)($r['fecha_pago'] ?: $r['fecha']), 'tipo' => 'Ingreso',
            'concepto' => trim((string)$r['detalle']) !== '' ? (string)$r['detalle'] : ('Trabajo #' . (int)$r['id']),
            'cliente' => trim((string)($r['empresa'] ?? '')) !== '' ? (string)$r['empresa'] : (string)($r['nombre'] ?? ''),
            'categoria' => fin_unidad_txt((string)($r['unidad_negocio'] ?? '')),
            'medio' => fin_medio((string)($r['medio_pago'] ?? ''), (string)($r['medio'] ?? '')),
            'estado' => fin_estado_txt($r), 'monto' => fin_num((float)$r['pagado']), 'saldo' => fin_saldo($r),
        ];
    }
    foreach (gastos_mes($pdo, $ym) as $g) {
        $out[] = [
            'fecha' => (string)$g['fecha'], 'tipo' => 'Egreso', 'concepto' => (string)$g['concepto'],
            'cliente' => '', 'categoria' => fin_categoria((string)($g['categoria'] ?? '')),
            'medio' => '', 'estado' => 'Pagado', 'monto' => fin_num((float)$g['monto']), 'saldo' => 0.0,
        ];
    }
    usort($out, fn(array $a, array $b): int => strcmp($a['fecha'], $b['fecha']));
    return $out;
}
function fin_resumen(PDO $pdo, string $ym): array {
    $ym = fin_mes_valido($ym);
    $cob = fin_cobrado_mes($pdo, $ym); $gas = fin_gastos_resumen($pdo, $ym);
    $pend = fin_pendientes($pdo, null, $ym); $porCobrar = 0.0; foreach ($pend as $r) $porCobrar += fin_saldo($r);
    $saldo = saldo_cuenta($pdo); $tna = tna_cuenta($pdo);
    return [
        'ym' => $ym, 'cobrado' => $cob['total'], 'por_unidad' => $cob['por_unidad'], 'por_medio' => $cob['por_medio'],
        'por_cobrar' => fin_num($porCobrar), 'n_pendientes' => count($pend),
        'gastos' => $gas['total'], 'por_categoria' => $gas['por_categoria'], 'n_gastos' => $gas['n'],
        'neto' => fin_num($cob['total'] - $gas['total']), 'saldo' => $saldo, 'tna' => $tna, 'rend' => rend_estimado($saldo, $tna),
    ];
}
function fin_evolucion(PDO $pdo, int $n = 6, ?string $hasta = null): array {
    $fin = fin_mes_valido((string)$hasta); $meses = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic']; $out = [];
    for ($i = max(0, $n - 1); $i >= 0; $i--) {
        $ym = date('Y-m', strtotime($fin . '-01 -' . $i . ' month'));
        $ing = fin_cobrado_mes($pdo, $ym)['total']; $gas = total_gastos_mes($pdo, $ym);
        $out[] = ['ym' => $ym, 'label' => ($meses[(int)substr($ym, 5, 2)] ?? '') . ' ' . substr($ym, 2, 2), 'ingresos' => $ing, 'gastos' => $gas, 'neto' => fin_num($ing - $gas)];
    }
    return $out;
}
function fin_csv_celda($v): string {
    $s = str_replace(["\r", "\n"], ' ', (string)$v);
    return $s !== '' && preg_match('/^[=+\-@\t]/', $s) ? "'" . $s : $s;
}
function fin_datos_pago_post(array $post, float $total, bool $cero = false): array {
    $total = fin_num($total);
    $estado = strtolower(trim((string)($post['estado_pago'] ?? ($post['estado'] ?? 'pendiente'))));
    if ($cero) $estado = 'cobrado';
    if (!isset(FIN_ESTADOS_PAGO[$estado])) throw new RuntimeException('Estado de pago inválido.');
    $medio = trim((string)($post['medio_pago'] ?? ''));
    if ($medio === '' && isset($post['medio'])) {
        $legacy = trim((string)$post['medio']); $n = array_search($legacy, FIN_MEDIO_LEGACY, true);
        $medio = $n === false ? '' : (string)$n;
    }
    if ($medio !== '' && !isset(FIN_MEDIOS_PAGO[$medio])) throw new RuntimeException('Elegí un medio de pago válido.');
    if ($estado === 'cobrado') $abonado = $total;
    elseif ($estado === 'pendiente') $abonado = 0.0;
    else {
        $abonado = fin_num((float)str_replace(',', '.', trim((string)($post['monto_senia'] ?? '0'))));
        if ($abonado <= 0 || $abonado >= $total) throw new RuntimeException('La seña tiene que ser mayor a cero y menor que el total.');
    }
    if ($abonado > 0 && $medio === '') $medio = 'Transferencia / MP';
    $fpg = null;
    if ($abonado > 0 || $estado === 'cobrado') {
        $fpg = (string)($post['fecha_pago'] ?? '') ?: date('Y-m-d');
        $f = DateTime::createFromFormat('Y-m-d', $fpg);
        if (!$f || $f->format('Y-m-d') !== $fpg) throw new RuntimeException('Fecha de pago inválida.');
    }
    return [
        'estado' => $estado === 'cobrado' ? 'cobrado' : 'pendiente', 'estado_pago' => $estado,
        'abonado' => $abonado, 'medio_pago' => $medio !== '' ? $medio : null,
        'medio' => $medio !== '' ? FIN_MEDIO_LEGACY[$medio] : null, 'fecha_pago' => $fpg,
    ];
}
/* Rendimiento estimado de la billetera según la TNA configurada (interés simple anual) */
function rend_estimado(float $saldo, float $tna): array {
    $f = $tna / 100;
    return [
        'anual'  => round($saldo * $f, 2),
        'mensual' => round($saldo * $f / 12, 2),
        'diario' => round($saldo * $f / 365, 2),
    ];
}

/* ---- Notificaciones push con ntfy.sh ---- */
function blens_ntfy(string $titulo, string $mensaje, string $prioridad = 'default', string $tags = '', array $acciones = []): void {
    try {
        $url = trim((string)(blens_cfg()['ntfy_url'] ?? ''));
        if ($url === '') return;                                   // sin destino configurado: no se hace nada
        if (!function_exists('curl_init')) return;
        $limpio = fn(string $s): string => str_replace(["\r", "\n"], ' ', trim($s));
        $hdr = ['Title: ' . $limpio($titulo)];
        if ($prioridad !== '') $hdr[] = 'Priority: ' . $limpio($prioridad);
        if ($tags !== '') $hdr[] = 'Tags: ' . $limpio($tags);
        $acc = [];
        foreach ($acciones as $a) {
            if (is_array($a)) $a = implode(', ', array_map(fn($x) => (string)$x, $a));
            $a = $limpio((string)$a);
            if ($a !== '') $acc[] = $a;
        }
        if ($acc) $hdr[] = 'Actions: ' . implode('; ', $acc);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $mensaje,
            CURLOPT_HTTPHEADER => $hdr,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false || ($code >= 400)) error_log('[blens ntfy] ' . ($err !== '' ? $err : 'HTTP ' . $code));
    } catch (Throwable $x) { error_log('[blens ntfy] ' . $x->getMessage()); }
}
/* Normaliza un teléfono a formato de WhatsApp (549 + número) */
function blens_wa_num(string $tel): ?string {
    $d = preg_replace('/\D+/', '', $tel) ?? '';
    if ($d === '') return null;
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    if (str_starts_with($d, '54')) return str_starts_with($d, '549') ? $d : '549' . substr($d, 2);
    $d = ltrim($d, '0');
    return strlen($d) === 10 ? '549' . $d : null;
}
function blens_wa_numero(): string { return blens_wa_num((string)(blens_cfg()['wa_negocio'] ?? '')) ?? ''; }
function blens_webp(string $ruta): string {
    $ruta = trim($ruta);
    if ($ruta === '' || str_contains($ruta, '..')) return $ruta;
    if (!preg_match('/\.(jpe?g|png)$/i', $ruta)) return $ruta;
    $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $ruta);
    return is_file(__DIR__ . '/../public/' . ltrim($webp, '/')) ? $webp : $ruta;
}
/* Aviso push de una consulta nueva de la web */
function blens_ntfy_consulta(array $c): void {
    $nombre = trim((string)($c['nombre'] ?? ''));
    $empresa = trim((string)($c['empresa'] ?? ''));
    $servicio = trim((string)($c['servicio'] ?? ''));
    $mensaje = trim((string)($c['mensaje'] ?? ''));
    $cuerpo = "Nombre: " . ($nombre !== '' ? $nombre : '-') . "\n"
        . "Empresa: " . ($empresa !== '' ? $empresa : '-') . "\n"
        . "Servicio: " . ($servicio !== '' ? $servicio : '-') . "\n\n" . $mensaje;
    $acciones = ['view, Ver consultas, https://blens.ar/admin/consultas.php'];
    $n = blens_wa_num((string)($c['telefono'] ?? ''));
    if ($n) $acciones[] = 'view, WhatsApp, https://wa.me/' . $n . '?text=' . rawurlencode('Hola ' . explode(' ', $nombre)[0] . ', te escribimos de blens.ar por tu consulta.');
    blens_ntfy('Nueva consulta · blens.ar', $cuerpo, 'high', 'incoming_envelope', $acciones);
}

function &blens_col_cache(): array { static $cache = []; return $cache; }
function blens_columnas(PDO $p, string $tabla): array {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tabla)) return [];
    $cache =& blens_col_cache();
    if (isset($cache[$tabla])) return $cache[$tabla];
    $s = $p->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->execute([$tabla]);
    $cols = [];
    foreach ($s->fetchAll() as $c) $cols[strtolower((string)$c['COLUMN_NAME'])] = strtolower((string)$c['COLUMN_TYPE']);
    return $cache[$tabla] = $cols;
}
function blens_col_invalidate(string $tabla): void { $cache =& blens_col_cache(); unset($cache[$tabla]); }
function &blens_table_cache(): array { static $cache = []; return $cache; }
function blens_tabla_existe(PDO $p, string $tabla): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tabla)) return false;
    $cache =& blens_table_cache();
    if (isset($cache[$tabla])) return $cache[$tabla];
    $s = $p->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE='BASE TABLE'");
    $s->execute([$tabla]);
    return $cache[$tabla] = (bool)$s->fetchColumn();
}
function blens_tabla_invalidate(string $tabla): void { $cache =& blens_table_cache(); unset($cache[$tabla]); }
function blens_agregar_col(PDO $p, string $tabla, string $col, string $def): void {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tabla) || !preg_match('/^[A-Za-z0-9_]+$/', $col)) return;
    if (isset(blens_columnas($p, $tabla)[strtolower($col)])) return;
    $p->exec("ALTER TABLE `$tabla` ADD COLUMN `$col` $def");
    blens_col_invalidate($tabla);
}
function blens_crear_tabla(PDO $p, string $tabla, string $ddl): void {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tabla)) return;
    if (blens_tabla_existe($p, $tabla)) return;
    $p->exec($ddl);
    blens_tabla_invalidate($tabla); blens_col_invalidate($tabla);
}
function blens_col_nullable(PDO $p, string $tabla, string $col): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tabla) || !preg_match('/^[A-Za-z0-9_]+$/', $col)) return '';
    $s = $p->prepare('SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->execute([$tabla, $col]);
    return strtoupper(trim((string)$s->fetchColumn()));
}
function blens_modificar_col(PDO $p, string $tabla, string $col, string $def, ?string $nullable = null): void {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tabla) || !preg_match('/^[A-Za-z0-9_]+$/', $col)) return;
    $cols = blens_columnas($p, $tabla);
    $key = strtolower($col);
    if (!isset($cols[$key])) return;
    $base = strtolower(trim(explode(' ', trim($def))[0]));
    $cambia = strtolower(trim((string)$cols[$key])) !== $base;
    if ($nullable !== null && blens_col_nullable($p, $tabla, $col) !== strtoupper($nullable)) $cambia = true;
    if (!$cambia) return;
    $p->exec("ALTER TABLE `$tabla` MODIFY COLUMN `$col` $def");
    blens_col_invalidate($tabla);
}
function blens_migrar(?PDO $p = null): void {
    static $intentado = false; if ($intentado) return; $intentado = true;
    $p ??= blens_db();
    $locked = false;
    try {
        $locked = (bool)$p->query("SELECT GET_LOCK('blens_migrar_v2', 5)")->fetchColumn();
        if (!$locked) return;
        $cols = blens_columnas($p, 'clientes');
        $nuevas = [
            'portal_visitas'       => 'INT NOT NULL DEFAULT 0', // visitas al portal privado
            'portal_ultima_visita' => 'DATETIME NULL',       // última entrada al portal
            'gestion_locaciones'   => 'TINYINT(1) NOT NULL DEFAULT 0',
        ];
        foreach ($nuevas as $col => $def) blens_agregar_col($p, 'clientes', $col, $def);
        try {
            $sm = $p->prepare('SELECT valor FROM ajustes WHERE clave=?');
            $sm->execute(['locaciones_flag_haene_v1']);
            if (!$sm->fetchColumn()) {
                $p->exec("UPDATE clientes SET gestion_locaciones=1 WHERE LOWER(nombre) LIKE '%haene%' OR LOWER(empresa) LIKE '%haene%' OR LOWER(nombre) LIKE '%castuariense%' OR LOWER(empresa) LIKE '%castuariense%'");
                set_ajuste($p, 'locaciones_flag_haene_v1', '1');
            }
        } catch (Throwable $x) { error_log('[blens locaciones flag] ' . $x->getMessage()); }
        // Feedback del cliente sobre las entregas del portal
        $colsE = blens_columnas($p, 'entregas');
        $nuevasE = [
            'estado_cliente'     => "ENUM('pendiente','aprobado','ajuste') NOT NULL DEFAULT 'pendiente'",
            'comentario_cliente' => 'TEXT NULL',
            'comentario_fecha'   => 'DATETIME NULL',
            'tipo'               => "VARCHAR(16) NOT NULL DEFAULT 'video'",
        ];
        foreach ($nuevasE as $col => $def) blens_agregar_col($p, 'entregas', $col, $def);
        if (isset($colsE['estado_cliente']) && (stripos($colsE['estado_cliente'], 'enum(') === false || stripos($colsE['estado_cliente'], "'aprobado'") === false)) {
            $p->exec("UPDATE entregas SET estado_cliente='pendiente' WHERE estado_cliente NOT IN ('pendiente','aprobado','ajuste')");
            $p->exec("ALTER TABLE entregas MODIFY COLUMN estado_cliente ENUM('pendiente','aprobado','ajuste') NOT NULL DEFAULT 'pendiente'");
            blens_col_invalidate('entregas');
        }
        if (blens_tabla_existe($p, 'consultas')) {
            blens_agregar_col($p, 'consultas', 'respondida', 'TINYINT(1) NOT NULL DEFAULT 0');
        }
        // Adjuntos de comprobantes: siempre fuera de /public
        if (!is_dir(COMPROBANTES_DIR)) @mkdir(COMPROBANTES_DIR, 02770, true);
        @chmod(COMPROBANTES_DIR, 02770);
        // Tabla de gastos (registro de salidas de dinero)
        blens_crear_tabla($p, 'gastos', "CREATE TABLE IF NOT EXISTS gastos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fecha DATE NOT NULL,
            concepto VARCHAR(160) NOT NULL,
            categoria VARCHAR(50) NOT NULL DEFAULT 'Operativo',
            monto DECIMAL(12,2) NOT NULL DEFAULT 0,
            prueba TINYINT(1) NOT NULL DEFAULT 0,
            comprobante VARCHAR(255) NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX g_fecha (fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Agenda de rodajes, entregas y reuniones
        blens_crear_tabla($p, 'agenda', "CREATE TABLE IF NOT EXISTS agenda (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT UNSIGNED NULL,
            tipo ENUM('rodaje','entrega','reunion') NOT NULL DEFAULT 'rodaje',
            titulo VARCHAR(160) NOT NULL,
            fecha DATE NOT NULL,
            hora TIME NULL,
            lugar_nota VARCHAR(255) NULL,
            descripcion TEXT NULL,
            completado TINYINT(1) NOT NULL DEFAULT 0,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX a_fecha (fecha, completado),
            INDEX a_cliente (cliente_id),
            CONSTRAINT fk_agenda_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        blens_agregar_col($p, 'agenda', 'descripcion', 'TEXT NULL AFTER lugar_nota');
        // Presupuestos rápidos (items_json guarda las filas del detalle)
        blens_crear_tabla($p, 'presupuestos', "CREATE TABLE IF NOT EXISTS presupuestos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            numero VARCHAR(20) NOT NULL DEFAULT '',
            token VARCHAR(64) NULL,
            cliente_nombre VARCHAR(120) NOT NULL,
            empresa VARCHAR(120) NOT NULL DEFAULT '',
            telefono VARCHAR(30) NOT NULL DEFAULT '',
            unidad_negocio VARCHAR(30) NOT NULL DEFAULT 'audiovisual',
            items_json TEXT NULL,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            validez_dias INT NOT NULL DEFAULT 15,
            notas TEXT NULL,
            condiciones TEXT NULL,
            estado ENUM('borrador','enviado','aceptado','rechazado') NOT NULL DEFAULT 'enviado',
            aceptado_en DATETIME NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            trabajo_id INT UNSIGNED NULL,
            UNIQUE KEY uq_presu_token (token),
            INDEX p_numero (numero),
            INDEX p_estado (estado),
            INDEX p_trabajo (trabajo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (blens_tabla_existe($p, 'presupuestos')) {
            foreach (['token' => 'VARCHAR(64) NULL', 'unidad_negocio' => "VARCHAR(30) NOT NULL DEFAULT 'audiovisual'", 'condiciones' => 'TEXT NULL', 'aceptado_en' => 'DATETIME NULL'] as $col => $def) blens_agregar_col($p, 'presupuestos', $col, $def);
            $colsP = blens_columnas($p, 'presupuestos');
            if (isset($colsP['items_json'])) blens_modificar_col($p, 'presupuestos', 'items_json', 'TEXT NULL', 'YES');
            $estadoOk = isset($colsP['estado']) && stripos($colsP['estado'], 'enum(') !== false && stripos($colsP['estado'], "'borrador'") !== false && stripos($colsP['estado'], "'enviado'") !== false && stripos($colsP['estado'], "'aceptado'") !== false && stripos($colsP['estado'], "'rechazado'") !== false;
            if (!$estadoOk) {
                $p->exec("UPDATE presupuestos SET estado='enviado' WHERE estado NOT IN ('borrador','enviado','aceptado','rechazado')");
                $p->exec("ALTER TABLE presupuestos MODIFY COLUMN estado ENUM('borrador','enviado','aceptado','rechazado') NOT NULL DEFAULT 'enviado'");
                blens_col_invalidate('presupuestos');
            }
            $sinToken = $p->query("SELECT id FROM presupuestos WHERE token IS NULL OR token=''")->fetchAll();
            $updToken = $p->prepare("UPDATE presupuestos SET token=? WHERE id=? AND (token IS NULL OR token='')");
            foreach ($sinToken as $row) {
                for ($i = 0; $i < 12; $i++) {
                    try {
                        $updToken->execute([bin2hex(random_bytes(32)), (int)$row['id']]);
                        if ($updToken->rowCount() > 0) break;
                    } catch (PDOException $x) { if ($i === 11) throw $x; }
                }
            }
            $idx = $p->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='presupuestos' AND COLUMN_NAME='token' AND NON_UNIQUE=0 AND SEQ_IN_INDEX=1");
            $idx->execute();
            if ((int)$idx->fetchColumn() === 0) $p->exec('ALTER TABLE presupuestos ADD UNIQUE KEY uq_presu_token (token)');
            $p->exec("UPDATE presupuestos SET unidad_negocio='audiovisual' WHERE unidad_negocio IS NULL OR unidad_negocio=''");
            $p->exec("UPDATE presupuestos SET aceptado_en=creado_en WHERE estado='aceptado' AND aceptado_en IS NULL");
        }
        blens_crear_tabla($p, 'push_subscriptions', "CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tipo ENUM('admin','cliente') NOT NULL,
            cliente_id INT NULL,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            user_agent VARCHAR(255) NULL,
            endpoint_hash CHAR(64) NOT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_push_endpoint (endpoint_hash),
            INDEX idx_push_tipo_cliente (tipo, cliente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        blens_crear_tabla($p, 'cliente_locaciones', "CREATE TABLE IF NOT EXISTS cliente_locaciones (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT UNSIGNED NOT NULL,
            nombre VARCHAR(160) NOT NULL,
            ubicacion VARCHAR(255) NOT NULL,
            acceso VARCHAR(160) NULL,
            administrador VARCHAR(120) NULL,
            telefono_contacto VARCHAR(50) NULL,
            horario_acordado VARCHAR(120) NULL,
            tipo_contenido VARCHAR(120) NULL,
            notas TEXT NULL,
            estado ENUM('pendiente','agendado','grabado') NOT NULL DEFAULT 'pendiente',
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_loc_cliente (cliente_id, id),
            INDEX idx_loc_estado (estado),
            CONSTRAINT fk_locaciones_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Comprobantes opcionales en cobros (trabajos) y gastos, también para bases existentes
        foreach (['trabajos', 'gastos', 'recibos'] as $tabla) {
            if (!blens_tabla_existe($p, $tabla)) continue;
            $colsT = blens_columnas($p, $tabla);
            if ($tabla !== 'recibos') blens_agregar_col($p, $tabla, 'comprobante', 'VARCHAR(255) NULL');
            if ($tabla === 'gastos') blens_agregar_col($p, $tabla, 'prueba', 'TINYINT(1) NOT NULL DEFAULT 0');
            if ($tabla === 'trabajos') {
                foreach (['unidad_negocio' => "VARCHAR(30) NOT NULL DEFAULT 'audiovisual'", 'medio_pago' => 'VARCHAR(40) NULL', 'monto_senia' => 'DECIMAL(12,2) NOT NULL DEFAULT 0'] as $col => $def) {
                    blens_agregar_col($p, $tabla, $col, $def);
                }
            }
            if ($tabla === 'gastos') {
                blens_agregar_col($p, $tabla, 'categoria', "VARCHAR(50) NOT NULL DEFAULT 'Operativo'");
                $colsT = blens_columnas($p, $tabla);
                if (isset($colsT['categoria']) && !str_contains($colsT['categoria'], 'varchar(50)')) {
                    $p->exec("ALTER TABLE gastos MODIFY COLUMN categoria VARCHAR(50) NOT NULL DEFAULT 'Operativo'");
                    blens_col_invalidate($tabla);
                }
                $p->exec("UPDATE gastos SET categoria='Operativo' WHERE categoria='' OR categoria='general'");
            }
            $colsT = blens_columnas($p, $tabla);
            $moneyCol = $tabla === 'trabajos' ? 'monto_unit' : ($tabla === 'recibos' ? 'total' : 'monto');
            if (isset($colsT[$moneyCol]) && !str_starts_with($colsT[$moneyCol], 'decimal')) {
                $p->exec("ALTER TABLE `$tabla` MODIFY COLUMN `$moneyCol` DECIMAL(12,2) NOT NULL DEFAULT 0");
                blens_col_invalidate($tabla);
            }
        }
    } catch (Throwable $e) { error_log('[blens migrar] ' . $e->getMessage()); }
    finally { if ($locked) { try { $p->query("SELECT RELEASE_LOCK('blens_migrar_v2')"); } catch (Throwable $x) {} } }
}
function blens_db(): PDO {
    static $p = null; if ($p) return $p; $c = blens_cfg();
    return $p = new PDO('mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
require_once __DIR__ . '/push.php';
require_once __DIR__ . '/presupuestos.php';

function locaciones_estados(): array {
    return ['pendiente' => 'Pendiente', 'agendado' => 'Agendado', 'grabado' => 'Grabado'];
}
function locaciones_contenidos(): array {
    return ['Reels' => 'Reels', 'Video tour' => 'Video tour', 'Fotos' => 'Fotos', 'Dron' => 'Dron'];
}
function locaciones_maps(string $texto): string {
    $u = trim($texto);
    if (preg_match('~^https?://~i', $u)) return $u;
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($u);
}
function locaciones_fecha_hora(string $texto): array {
    $txt = mb_strtolower($texto, 'UTF-8'); $hoy = new DateTime('today');
    $hora = '';
    if (preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)\b/', $txt, $m)) $hora = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
    elseif (preg_match('/\b(\d{1,2})\s*(a|p)\.?\s*m\.?\b/', $txt, $m)) {
        $hh = (int)$m[1] % 12;
        if ($m[2] === 'p') $hh += 12;
        $hora = sprintf('%02d:00', $hh);
    }
    $fecha = null;
    $crear = function (int $y, int $m, int $d) use ($hoy): ?DateTime {
        if ($y < 100) $y += 2000;
        $f = DateTime::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $y, $m, $d));
        $err = DateTime::getLastErrors();
        if (!$f || ($err && ($err['warning_count'] || $err['error_count']))) return null;
        while ($f < $hoy) $f->modify('+1 year');
        return $f;
    };
    if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $txt, $m)) $fecha = $crear((int)$m[1], (int)$m[2], (int)$m[3]);
    elseif (preg_match('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/', $txt, $m)) $fecha = $crear(isset($m[3]) && $m[3] !== '' ? (int)$m[3] : (int)$hoy->format('Y'), (int)$m[2], (int)$m[1]);
    else {
        $dias = ['lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'miércoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'sábado' => 6, 'domingo' => 7];
        foreach ($dias as $nombre => $n) {
            if (!str_contains($txt, $nombre)) continue;
            $diff = ($n - (int)$hoy->format('N') + 7) % 7;
            if ($diff === 0) $diff = 7;
            $fecha = (clone $hoy)->modify('+' . $diff . ' days');
            break;
        }
    }
    if (!$fecha) $fecha = (clone $hoy)->modify('+1 day');
    return [$fecha->format('Y-m-d'), $hora];
}
function locaciones_push(PDO $pdo, int $clienteId, int $locId, string $nombre, string $accion): void {
    try {
        $q = $pdo->prepare('SELECT nombre,empresa FROM clientes WHERE id=?'); $q->execute([$clienteId]); $c = $q->fetch() ?: [];
        $cliente = trim((string)($c['empresa'] ?? '')) ?: trim((string)($c['nombre'] ?? '')) ?: ('Cliente #' . $clienteId);
        blens_push_notify($pdo, 'admin', null, [
            'title' => ($accion === 'alta' ? 'Nueva propiedad · ' : 'Propiedad modificada · ') . $cliente,
            'body' => $cliente . ' — ' . ($accion === 'alta' ? 'cargó una nueva propiedad: ' : 'modificó una propiedad: ') . $nombre . ' (#' . $locId . ')',
            'url' => '/admin/clientes.php?c=' . $clienteId,
            'tag' => 'blens-locacion-' . $locId . '-' . time(),
        ]);
    } catch (Throwable $x) { error_log('[blens locaciones push] ' . $x->getMessage()); }
}
