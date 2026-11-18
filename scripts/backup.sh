#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="/var/www/blens"
CONFIG="$ROOT/blens-config/config.php"
BOOTSTRAP="$ROOT/blens-config/bootstrap.php"
CONFIG_DIR="$ROOT/blens-config"
BACKUP_DIR="/home/blens/backups"
FECHA="$(date +%F)"
DEST="$BACKUP_DIR/blens-backup-$FECHA.tar.gz"
STAGE=""

alertar() {
  local mensaje="$1"
  php -r 'require $argv[1]; blens_ntfy($argv[2], $argv[3], "high", "warning");' "$BOOTSTRAP" "Backup blens.ar falló" "$mensaje" >/dev/null 2>&1 || true
  printf 'ERROR: %s\n' "$mensaje" >&2
}

fallar() { alertar "$1"; exit 1; }

trap 'if [[ -n "$STAGE" ]]; then rm -rf "$STAGE"; fi' EXIT

[[ -r "$CONFIG" ]] || fallar "No se puede leer $CONFIG"
[[ -r "$BOOTSTRAP" ]] || fallar "No se puede leer $BOOTSTRAP"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

DB_NAME="$(php -r '$c = require $argv[1]; echo (string)$c["db_name"];' "$CONFIG")" || fallar "No se pudo leer db_name de blens-config/config.php"
[[ -n "$DB_NAME" ]] || fallar "db_name está vacío en blens-config/config.php"

NTFY_OK="$(php -r '$c = require $argv[1]; echo trim((string)($c["ntfy_url"] ?? "")) !== "" ? "1" : "0";' "$CONFIG")"
if [[ "$NTFY_OK" != "1" ]]; then printf 'Advertencia: ntfy_url no configurado; las alertas no se enviarán.\n' >&2; fi

STAGE="$(mktemp -d "$BACKUP_DIR/.stage.XXXXXX")"
chmod 700 "$STAGE"
MYCNF="$STAGE/my.cnf"

php -r '$c = require $argv[1]; $f = fopen($argv[2], "w"); if (!$f) exit(1); $p = str_replace(["\\", "\""], ["\\\\", "\\\""], (string)$c["db_pass"]); fwrite($f, "[client]\nhost=" . (string)$c["db_host"] . "\nuser=" . (string)$c["db_user"] . "\npassword=\"" . $p . "\"\n"); fclose($f); chmod($argv[2], 0600);' "$CONFIG" "$MYCNF" || fallar "No se pudieron leer las credenciales MySQL de blens-config/config.php"

DUMP="$STAGE/blens-db-$FECHA.sql"
if ! mysqldump --defaults-extra-file="$MYCNF" --single-transaction --routines --triggers "$DB_NAME" > "$DUMP" 2> "$STAGE/mysqldump.err"; then
  ERR="$(<"$STAGE/mysqldump.err")"
  ERR="${ERR//$'\n'/ }"
  fallar "mysqldump falló: ${ERR:0:300}"
fi
[[ -s "$DUMP" ]] || fallar "El dump MySQL quedó vacío"

cp -a "$CONFIG_DIR" "$STAGE/blens-config" || fallar "No se pudo copiar blens-config"

ARCHIVE="$STAGE/blens-backup-$FECHA.tar.gz"
if ! tar -czf "$ARCHIVE" -C "$STAGE" "blens-config" "blens-db-$FECHA.sql"; then fallar "tar falló al comprimir el backup"; fi
[[ -s "$ARCHIVE" ]] || fallar "El archivo comprimido quedó vacío"
if ! tar -tzf "$ARCHIVE" >/dev/null 2>&1; then fallar "El archivo comprimido está corrupto"; fi
chmod 600 "$ARCHIVE"
mv -f "$ARCHIVE" "$DEST" || fallar "No se pudo mover el backup a $DEST"
[[ -s "$DEST" ]] || fallar "El backup final pesa 0 bytes"

find "$BACKUP_DIR" -maxdepth 1 -name 'blens-backup-*.tar.gz' -mtime +7 -delete || true
printf 'Backup OK: %s (%s bytes)\n' "$DEST" "$(stat -c %s "$DEST")"
