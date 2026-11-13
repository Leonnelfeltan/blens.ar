<?php
const CLAVE_INICIAL = 'blens123'; // clave histórica: se rechaza si alguien intenta elegirla
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

// ---- Formato de moneda y ajustes guardados en la base ----
function peso(int $n): string { return '$ ' . number_format($n, 0, ',', '.'); }
function ajuste(PDO $pdo, string $clave, string $def = ''): string {
    try { $s = $pdo->prepare('SELECT valor FROM ajustes WHERE clave=?'); $s->execute([$clave]); $v = $s->fetchColumn(); if ($v !== false && trim((string)$v) !== '') return trim((string)$v); } catch (Throwable $x) { }
    $c = blens_cfg(); $v = trim((string)($c[$clave] ?? ''));
    return $v !== '' ? $v : $def;
}
function set_ajuste(PDO $pdo, string $clave, string $valor): void {
    $pdo->prepare('INSERT INTO ajustes (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)')->execute([$clave, $valor]);
}

/* ================= Panel financiero ================= */
function saldo_cuenta(PDO $pdo): int { return (int)preg_replace('/\D/', '', ajuste($pdo, 'saldo_cuenta', '0')) ?: 0; }
function tna_cuenta(PDO $pdo): float { $v = (float)str_replace(',', '.', trim(ajuste($pdo, 'tna_cuenta', '0'))); return $v > 0 ? $v : 0.0; }
function cobrar_mes(PDO $pdo, string $ym): int {
    $s = $pdo->prepare("SELECT COALESCE(SUM(cantidad*monto_unit),0) FROM trabajos WHERE estado='cobrado' AND fecha_pago IS NOT NULL AND DATE_FORMAT(fecha_pago,'%Y-%m')=?");
    $s->execute([$ym]); return (int)$s->fetchColumn();
}
function gastos_mes(PDO $pdo, string $ym): array {
    $s = $pdo->prepare("SELECT * FROM gastos WHERE DATE_FORMAT(fecha,'%Y-%m')=? ORDER BY fecha DESC,id DESC");
    $s->execute([$ym]); return $s->fetchAll();
}
function total_gastos_mes(PDO $pdo, string $ym): int {
    $s = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE DATE_FORMAT(fecha,'%Y-%m')=?");
    $s->execute([$ym]); return (int)$s->fetchColumn();
}
/* Rendimiento estimado de la billetera según la TNA configurada */
function rend_estimado(int $saldo, float $tna): array {
    $f = $tna / 100;
    return ['diario' => (int)round($saldo * $f / 365), 'mensual' => (int)round($saldo * $f), 'anual' => (int)round($saldo * $f * 12)];
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
        // Tabla de gastos (registro de salidas de dinero)
        $p->exec("CREATE TABLE IF NOT EXISTS gastos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fecha DATE NOT NULL,
            concepto VARCHAR(160) NOT NULL,
            categoria VARCHAR(60) NOT NULL DEFAULT 'general',
            monto INT NOT NULL DEFAULT 0,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX g_fecha (fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { error_log('[blens migrar] ' . $e->getMessage()); }
}
function blens_db(): PDO {
    static $p = null; if ($p) return $p; $c = blens_cfg();
    return $p = new PDO('mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
