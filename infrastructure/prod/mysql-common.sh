#!/bin/sh
# Shared validation only; source this file from backup.sh/restore.sh.
fail() { printf '%s\n' "$1" >&2; exit 1; }
require_value() { test -n "$2" || fail "Missing required environment variable $1."; }
validate_database() {
  require_value MYSQL_DATABASE "${MYSQL_DATABASE:-}"
  case "$MYSQL_DATABASE" in *[!a-zA-Z0-9_]*|[0-9_]*|'') fail 'MYSQL_DATABASE must start with a letter and contain only letters, digits and underscores.';; esac
  test "${#MYSQL_DATABASE}" -le 64 || fail 'MYSQL_DATABASE exceeds 64 characters.'
  test "${BIZISMART_BACKUP_CONFIRM_DB:-}" = "$MYSQL_DATABASE" || fail 'Set BIZISMART_BACKUP_CONFIRM_DB to the exact target database.'
}
private_path() {
  test ! -L "$1" || fail 'Private paths must not be symbolic links.'
  owner=$(stat -c '%u' "$1" 2>/dev/null) || owner=$(stat -f '%u' "$1") || fail 'Cannot inspect private path owner.'
  mode=$(stat -c '%a' "$1" 2>/dev/null) || mode=$(stat -f '%Lp' "$1") || fail 'Cannot inspect private path permissions.'
  test "$owner" = "$(id -u)" || fail 'Private paths must belong to the current account.'
  if test -d "$1"; then
    test "$mode" = 700 || fail 'Backup directories must have mode 700.'
  else
    case "$mode" in 400|600) ;; *) fail 'Connection/dump files must have mode 600 or 400.';; esac
  fi
}
validate_connection() {
  require_value MYSQL_DEFAULTS_FILE "${MYSQL_DEFAULTS_FILE:-}"
  case "$MYSQL_DEFAULTS_FILE" in /*) ;; *) fail 'MYSQL_DEFAULTS_FILE must be an absolute path.';; esac
  test -f "$MYSQL_DEFAULTS_FILE" && test -r "$MYSQL_DEFAULTS_FILE" || fail 'MYSQL_DEFAULTS_FILE must be a readable private MySQL option file.'
  private_path "$MYSQL_DEFAULTS_FILE"
}
validate_retention() {
  retention=${RETENTION_DAYS:-30}
  case "$retention" in *[!0-9]*|'') fail 'RETENTION_DAYS must be a positive integer.';; esac
  test "$retention" -ge 1 && test "$retention" -le 36500 || fail 'RETENTION_DAYS must be between 1 and 36500.'
}
