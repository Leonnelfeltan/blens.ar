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
function blens_db(): PDO {
    static $p = null; if ($p) return $p; $c = blens_cfg();
    return $p = new PDO('mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
