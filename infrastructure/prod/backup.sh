#!/bin/sh
# Creates PRIVATE PLAINTEXT SQL. Encryption/offsite retention are deployment gates.
set -eu
umask 077
script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
. "$script_dir/mysql-common.sh"
validate_database
validate_connection
validate_retention
require_value BACKUP_DIR "${BACKUP_DIR:-}"
mkdir -p -- "$BACKUP_DIR"
private_path "$BACKUP_DIR"
test -d "$BACKUP_DIR" || fail 'BACKUP_DIR is not a directory.'
dump_bin=${MYSQLDUMP_BIN:-mysqldump}
case "${MYSQL_DUMP_FLAVOR:-mysql}" in
  mysql) set -- --set-gtid-purged=OFF ;;
  mariadb) set -- ;;
  *) fail 'MYSQL_DUMP_FLAVOR must be mysql or mariadb.' ;;
esac
temporary_base=$(mktemp "$BACKUP_DIR/$MYSQL_DATABASE-$(date -u +%Y%m%d-%H%M%S)-XXXXXXXX")
tmp=$temporary_base.sql.part
trap 'rm -f -- "$tmp" "$temporary_base"' EXIT HUP INT TERM
mv -- "$temporary_base" "$tmp"
"$dump_bin" --defaults-extra-file="$MYSQL_DEFAULTS_FILE" --single-transaction --routines --events --triggers --no-tablespaces \
  --default-character-set=utf8mb4 --hex-blob "$@" --result-file="$tmp" "$MYSQL_DATABASE"
test -s "$tmp" || fail 'Backup produced an empty file.'
out=${tmp%.part}
test ! -e "$out" || fail 'Backup destination already exists.'
mv -- "$tmp" "$out"
find "$BACKUP_DIR" -maxdepth 1 -type f -name "$MYSQL_DATABASE-????????-??????-????????.sql" -mtime "+$retention" \
  -exec sh -c '
    database=$1; shift
    for file do
      name=${file##*/}; suffix=${name#"$database"-}
      if printf "%s\n" "$suffix" | LC_ALL=C grep -Eq "^[0-9]{8}-[0-9]{6}-[a-zA-Z0-9]{8}\.sql$"; then
        rm -f -- "$file"
      fi
    done
  ' sh "$MYSQL_DATABASE" {} +
printf 'Plaintext backup complete: %s\n' "$out"
