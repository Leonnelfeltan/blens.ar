#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BOOTSTRAP="$ROOT/blens-config/bootstrap.php"
HOST="blens.ar"
ORIGIN="https://$HOST"
CURL=(curl -ksS --max-time 15 --resolve "$HOST:443:127.0.0.1")
TMP="$(mktemp -d)"
TOKEN="$(php -r 'echo bin2hex(random_bytes(24));')"
SESSION_PHP="$ROOT/public/admin/__smoke_${TOKEN}.php"
SESSION_NAME="$(basename "$SESSION_PHP")"
SID=""

cleanup() {
  if [ -n "$SID" ]; then
    "${CURL[@]}" --max-time 5 -o /dev/null "$ORIGIN/admin/$SESSION_NAME?token=$TOKEN&cleanup=1" >/dev/null 2>&1 || true
  fi
  rm -f "$SESSION_PHP"
  rm -rf "$TMP"
}
trap cleanup EXIT

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

[ -f "$BOOTSTRAP" ] || fail "No existe $BOOTSTRAP"

cat > "$SESSION_PHP" <<'PHP'
<?php
require __DIR__ . '/../../blens-config/bootstrap.php';
if (!hash_equals(__SMOKE_TOKEN__, (string)($_GET['token'] ?? ''))) { http_response_code(404); exit; }
session_start();
if (($_GET['cleanup'] ?? '') === '1') {
  $_SESSION = [];
  session_destroy();
  @unlink(__FILE__);
  echo 'cleaned';
  exit;
}
$_SESSION['ok'] = true;
$_SESSION['t'] = 'smoke-' . time();
echo session_id();
PHP
php -r '$f=$argv[1]; $t=$argv[2]; $s=file_get_contents($f); file_put_contents($f, str_replace("__SMOKE_TOKEN__", var_export($t, true), $s));' "$SESSION_PHP" "$TOKEN"

echo "== Smoke test blens.ar =="

CANON="$(php -r '
require $argv[1];
$pdo = blens_db();
$mode = (string)$pdo->query("SELECT @@SESSION.sql_mode")->fetchColumn();
if (stripos($mode, "ONLY_FULL_GROUP_BY") === false) { fwrite(STDERR, "sql_mode sin ONLY_FULL_GROUP_BY\n"); exit(1); }
$fin = fin_resumen($pdo, date("Y-m"));
echo peso($fin["cobrado"]), "\n", peso($fin["por_cobrar"]), "\n", peso($fin["neto"]), "\n";
' "$BOOTSTRAP")" || fail "No se pudieron calcular los KPIs canónicos"
mapfile -t KPI < <(printf '%s\n' "$CANON")
[ "${#KPI[@]}" -eq 3 ] || fail "Salida de KPIs inesperada"
echo "  OK  ONLY_FULL_GROUP_BY activo · mes $(date +%Y-%m)"

SID="$("${CURL[@]}" "$ORIGIN/admin/$SESSION_NAME?token=$TOKEN")" || fail "No se pudo crear la sesión temporal"
[[ "$SID" =~ ^[a-f0-9]{32}$ ]] || fail "ID de sesión inválido: $SID"

check_route() {
  local path="$1" label="$2" cookie="${3:-}" code body="$TMP/body"
  local headers=()
  [ -n "$cookie" ] && headers=(-H "Cookie: PHPSESSID=$cookie")
  code="$("${CURL[@]}" "${headers[@]}" -o "$body" -w '%{http_code}' "$ORIGIN$path")" || fail "curl falló en $path"
  [ "$code" = "200" ] || fail "$label: HTTP $code (esperado 200)"
  if grep -Eqi 'Fatal error|SQLSTATE|Warning:' "$body"; then fail "$label: cadena de error en la respuesta"; fi
  printf '  OK  %-24s %s\n' "$label" "$path"
}

echo "-- Rutas públicas --"
check_route "/" "home"
check_route "/api.php" "api"
check_route "/sitemap.xml" "sitemap"
check_route "/robots.txt" "robots"
echo "-- Portal --"
check_route "/portal/" "portal"
echo "-- Admin --"
check_route "/admin/" "admin-login"
check_route "/admin/cobros.php" "admin-cobros" "$SID"
check_route "/admin/clientes.php" "admin-clientes" "$SID"
check_route "/admin/agenda.php" "admin-agenda" "$SID"
check_route "/admin/consultas.php" "admin-consultas" "$SID"

"${CURL[@]}" -H "Cookie: PHPSESSID=$SID" -o "$TMP/index.html" "$ORIGIN/admin/" >/dev/null
"${CURL[@]}" -H "Cookie: PHPSESSID=$SID" -o "$TMP/cobros.html" "$ORIGIN/admin/cobros.php" >/dev/null

kpi_value() {
  php -r '
  $html = @file_get_contents($argv[1]);
  if ($html === false) exit(1);
  $pattern = "~<span class=\\\"k\\\">" . preg_quote($argv[2], "~") . "</span>\\s*<b[^>]*>(.*?)</b>~s";
  if (!preg_match($pattern, $html, $m)) exit(1);
  echo trim(strip_tags($m[1]));
  ' "$1" "$2"
}

check_kpi() {
  local label="$1" file="$2" html_label="$3" expected="$4" value
  value="$(kpi_value "$file" "$html_label")" || fail "$label: no se pudo extraer el KPI"
  [ "$value" = "$expected" ] || fail "$label: esperado '$expected', obtenido '$value'"
  printf '  OK  %-24s %s\n' "$label" "$value"
}

echo "-- KPIs index.php vs cobros.php --"
check_kpi "index cobrado real" "$TMP/index.html" "Cobrado real" "${KPI[0]}"
check_kpi "cobros cobrado real" "$TMP/cobros.html" "Cobrado real" "${KPI[0]}"
check_kpi "index saldos pendientes" "$TMP/index.html" "Por cobrar (saldos)" "${KPI[1]}"
check_kpi "cobros saldos pendientes" "$TMP/cobros.html" "Por cobrar (Saldos pendientes)" "${KPI[1]}"
check_kpi "index resultado neto" "$TMP/index.html" "Resultado Neto" "${KPI[2]}"
check_kpi "cobros resultado neto" "$TMP/cobros.html" "Resultado Neto" "${KPI[2]}"

echo "TODO EN VERDE"
