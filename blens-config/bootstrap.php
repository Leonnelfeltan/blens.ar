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
function saldo_cuenta(PDO $pdo): float {
    $val = str_replace(',', '.', trim((string)ajuste($pdo, 'saldo_cuenta', '0')));
    return round((float)$val, 2);
}
function tna_cuenta(PDO $pdo): float { $v = (float)str_replace(',', '.', trim(ajuste($pdo, 'tna_cuenta', '0'))); return $v > 0 ? $v : 0.0; }
function cobrar_mes(PDO $pdo, string $ym): float {
    $s = $pdo->prepare("SELECT COALESCE(SUM(cantidad*monto_unit),0) FROM trabajos WHERE estado='cobrado' AND fecha_pago IS NOT NULL AND DATE_FORMAT(fecha_pago,'%Y-%m')=? AND prueba=?");
    $s->execute([$ym, modo_prueba($pdo)]); return round((float)$s->fetchColumn(), 2);
}
function gastos_mes(PDO $pdo, string $ym): array {
    $s = $pdo->prepare("SELECT * FROM gastos WHERE DATE_FORMAT(fecha,'%Y-%m')=? AND prueba=? ORDER BY fecha DESC,id DESC");
    $s->execute([$ym, modo_prueba($pdo)]); return $s->fetchAll();
}
function total_gastos_mes(PDO $pdo, string $ym): float {
    $s = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE DATE_FORMAT(fecha,'%Y-%m')=? AND prueba=?");
    $s->execute([$ym, modo_prueba($pdo)]); return round((float)$s->fetchColumn(), 2);
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

// ---- Migración automática: agrega columnas nuevas si faltan (es idempotente y segura) ----
function blens_migrar(?PDO $p = null): void {
    static $hecho = false; if ($hecho) return; $hecho = true;
    try {
        $p ??= blens_db();
        $cols = [];
        foreach ($p->query('SHOW COLUMNS FROM clientes')->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[$c['Field']] = true;
        $nuevas = [
            'portal_visitas'       => 'INT NOT NULL DEFAULT 0', // visitas al portal privado
            'portal_ultima_visita' => 'DATETIME NULL',       // última entrada al portal
        ];
        foreach ($nuevas as $col => $def) if (!isset($cols[$col])) $p->exec("ALTER TABLE clientes ADD COLUMN `$col` $def");
        // Feedback del cliente sobre las entregas del portal
        $colsE = [];
        foreach ($p->query('SHOW COLUMNS FROM entregas')->fetchAll(PDO::FETCH_ASSOC) as $c) $colsE[$c['Field']] = true;
        $nuevasE = [
            'estado_cliente'     => "VARCHAR(20) NOT NULL DEFAULT 'pendiente'",
            'comentario_cliente' => 'TEXT NULL',
            'comentario_fecha'   => 'DATETIME NULL',
            'tipo'               => "VARCHAR(16) NOT NULL DEFAULT 'video'",
        ];
        foreach ($nuevasE as $col => $def) if (!isset($colsE[$col])) $p->exec("ALTER TABLE entregas ADD COLUMN `$col` $def");
        if ($p->query("SHOW TABLES LIKE 'consultas'")->fetch()) {
            $colsC = [];
            foreach ($p->query('SHOW COLUMNS FROM consultas')->fetchAll(PDO::FETCH_ASSOC) as $c) $colsC[$c['Field']] = true;
            if (!isset($colsC['respondida'])) $p->exec('ALTER TABLE consultas ADD COLUMN respondida TINYINT(1) NOT NULL DEFAULT 0');
        }
        // Adjuntos de comprobantes: siempre fuera de /public
        if (!is_dir(COMPROBANTES_DIR)) @mkdir(COMPROBANTES_DIR, 02770, true);
        @chmod(COMPROBANTES_DIR, 02770);
        // Tabla de gastos (registro de salidas de dinero)
        $p->exec("CREATE TABLE IF NOT EXISTS gastos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fecha DATE NOT NULL,
            concepto VARCHAR(160) NOT NULL,
            categoria VARCHAR(60) NOT NULL DEFAULT 'general',
            monto DECIMAL(12,2) NOT NULL DEFAULT 0,
            prueba TINYINT(1) NOT NULL DEFAULT 0,
            comprobante VARCHAR(255) NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX g_fecha (fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Agenda de rodajes, entregas y reuniones
        $p->exec("CREATE TABLE IF NOT EXISTS agenda (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT UNSIGNED NULL,
            tipo ENUM('rodaje','entrega','reunion') NOT NULL DEFAULT 'rodaje',
            titulo VARCHAR(160) NOT NULL,
            fecha DATE NOT NULL,
            hora TIME NULL,
            lugar_nota VARCHAR(255) NULL,
            completado TINYINT(1) NOT NULL DEFAULT 0,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX a_fecha (fecha, completado),
            INDEX a_cliente (cliente_id),
            CONSTRAINT fk_agenda_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Presupuestos rápidos (items_json guarda las filas del detalle)
        $p->exec("CREATE TABLE IF NOT EXISTS presupuestos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            numero VARCHAR(20) NOT NULL DEFAULT '',
            cliente_nombre VARCHAR(120) NOT NULL,
            empresa VARCHAR(120) NOT NULL DEFAULT '',
            telefono VARCHAR(30) NOT NULL DEFAULT '',
            items_json TEXT NOT NULL,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            validez_dias INT NOT NULL DEFAULT 15,
            notas TEXT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'enviado',
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            trabajo_id INT UNSIGNED NULL,
            INDEX p_numero (numero),
            INDEX p_estado (estado),
            INDEX p_trabajo (trabajo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Comprobantes opcionales en cobros (trabajos) y gastos, también para bases existentes
        foreach (['trabajos', 'gastos', 'recibos'] as $tabla) {
            if (!$p->query("SHOW TABLES LIKE '$tabla'")->fetch()) continue;
            $colsT = [];
            foreach ($p->query("SHOW COLUMNS FROM `$tabla`")->fetchAll(PDO::FETCH_ASSOC) as $c) $colsT[$c['Field']] = strtolower((string)$c['Type']);
            if ($tabla !== 'recibos' && !isset($colsT['comprobante'])) $p->exec("ALTER TABLE `$tabla` ADD COLUMN comprobante VARCHAR(255) NULL");
            if ($tabla === 'gastos' && !isset($colsT['prueba'])) $p->exec('ALTER TABLE gastos ADD COLUMN prueba TINYINT(1) NOT NULL DEFAULT 0');
            $moneyCol = $tabla === 'trabajos' ? 'monto_unit' : ($tabla === 'recibos' ? 'total' : 'monto');
            if (isset($colsT[$moneyCol]) && !str_starts_with($colsT[$moneyCol], 'decimal')) $p->exec("ALTER TABLE `$tabla` MODIFY COLUMN `$moneyCol` DECIMAL(12,2) NOT NULL DEFAULT 0");
        }
    } catch (Throwable $e) { error_log('[blens migrar] ' . $e->getMessage()); }
}
function blens_db(): PDO {
    static $p = null; if ($p) return $p; $c = blens_cfg();
    return $p = new PDO('mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
