#!/bin/sh
# Restore trusted plaintext SQL into an EXISTING, explicitly confirmed database.
# SQL is executable code; this operator tool must never accept untrusted uploads.
set -eu
umask 077
script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
. "$script_dir/mysql-common.sh"
validate_database
validate_connection
require_value BACKUP_FILE "${BACKUP_FILE:-}"
test -f "$BACKUP_FILE" && test -s "$BACKUP_FILE" || fail 'BACKUP_FILE must be a nonempty SQL file.'
private_path "$BACKUP_FILE"
mysql_bin=${MYSQL_BIN:-mysql}
# Target provisioning/grants are separate; never CREATE or DROP a database here.
"$mysql_bin" --defaults-extra-file="$MYSQL_DEFAULTS_FILE" --binary-mode=1 --default-character-set=utf8mb4 \
  --database="$MYSQL_DATABASE" < "$BACKUP_FILE"
printf 'Restore completed into %s; application/data verification is still required.\n' "$MYSQL_DATABASE"
