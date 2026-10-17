#!/bin/sh
# No database access: all MySQL commands use this test's fake executable.
set -eu
case "$(uname -s)" in MINGW*|MSYS*|CYGWIN*) echo 'POSIX ACL/runtime regression requires a real Unix filesystem; use test-helpers.ps1 on Windows.' >&2; exit 77;; esac
umask 077
script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
test_root=$(mktemp -d "${TMPDIR:-/tmp}/bizismart-helper-test-XXXXXXXX")
trap 'rm -f -- "$test_root"/backups/* "$test_root"/client.cnf "$test_root"/fake-mysql "$test_root"/payload "$test_root"/calls "$test_root"/output; rmdir "$test_root/backups" "$test_root"' EXIT HUP INT TERM
mkdir "$test_root/backups"
export MYSQL_DEFAULTS_FILE="$test_root/client.cnf" MYSQL_DATABASE=bizismart_prod_fake BACKUP_DIR="$test_root/backups"
export BIZISMART_BACKUP_CONFIRM_DB="$MYSQL_DATABASE" RETENTION_DAYS=30
export MYSQL_DUMP_FLAVOR=mysql
export FAKE_SQL_FILE="$test_root/payload" FAKE_LOG="$test_root/calls" FAKE_MODE=success
printf '%s\n' '[client]' 'password="synthetic only & ! 茶"' > "$MYSQL_DEFAULTS_FILE"
printf '%s\n' '-- café 中文 🛒' "INSERT INTO example VALUES ('Mteja 茶');" > "$FAKE_SQL_FILE"
cat > "$test_root/fake-mysql" <<'FAKE'
#!/bin/sh
set -eu
case "$1" in --defaults-extra-file=*) ;; *) exit 19;; esac
printf '%s\n' called >> "$FAKE_LOG"
for arg in "$@"; do
  case "$arg" in --password*) exit 19;; --result-file=*)
    has_gtid=0
    for option in "$@"; do case "$option" in --set-gtid-purged=OFF) has_gtid=1;; esac; done
    case "$MYSQL_DUMP_FLAVOR:$has_gtid" in mysql:1|mariadb:0) ;; *) exit 18;; esac
    cp "$FAKE_SQL_FILE" "${arg#--result-file=}"
    test "$FAKE_MODE" != faildump || exit 23
    exit 0;;
  esac
done
cmp -s - "$FAKE_SQL_FILE" || exit 24
test "$FAKE_MODE" != failrestore || exit 25
FAKE
chmod 600 "$MYSQL_DEFAULTS_FILE" "$FAKE_SQL_FILE"
chmod 700 "$test_root/fake-mysql"
export MYSQLDUMP_BIN="$test_root/fake-mysql" MYSQL_BIN="$test_root/fake-mysql"
fail() { echo "Helper test failed: $1" >&2; exit 1; }
old="$BACKUP_DIR/$MYSQL_DATABASE-20000101-000000-Abc12345.sql"
manual="$BACKUP_DIR/$MYSQL_DATABASE-manual.sql"
unrelated="$BACKUP_DIR/$MYSQL_DATABASE-abcdefgh-ijklmn-zyxwvuts.sql"
printf '%s\n' old > "$old"; printf '%s\n' manual > "$manual"
printf '%s\n' unrelated > "$unrelated"
touch -t 200001010000 "$old" "$manual" "$unrelated"
export FAKE_MODE=faildump
if sh "$script_dir/backup.sh" > "$test_root/output" 2>&1; then fail 'native dump failure accepted'; fi
test -f "$old" || fail 'failed backup pruned good file'
test "$(find "$BACKUP_DIR" -name '*.sql' | wc -l | tr -d ' ')" = 3 || fail 'partial backup was published'
test "$(find "$BACKUP_DIR" -name '*.part' | wc -l | tr -d ' ')" = 0 || fail 'partial dump was not cleaned'
export FAKE_MODE=success
sh "$script_dir/backup.sh" > "$test_root/output" 2>&1 || { cat "$test_root/output" >&2; fail 'success path failed'; }
test ! -e "$old" && test -f "$manual" && test -f "$unrelated" || fail 'retention scope incorrect'
BACKUP_FILE=$(find "$BACKUP_DIR" -name '*.sql' ! -name '*-manual.sql' ! -name '*-abcdefgh-ijklmn-zyxwvuts.sql')
export BACKUP_FILE
cmp -s "$BACKUP_FILE" "$FAKE_SQL_FILE" || fail 'dump bytes changed'
sh "$script_dir/restore.sh" > "$test_root/output" 2>&1 || fail 'restore bytes changed'
export FAKE_MODE=failrestore
if sh "$script_dir/restore.sh" > "$test_root/output" 2>&1; then fail 'native restore failure accepted'; fi
grep -q 'Restore completed' "$test_root/output" && fail 'failed restore reported success'
calls=$(wc -l < "$FAKE_LOG")
export BIZISMART_BACKUP_CONFIRM_DB=''
if sh "$script_dir/restore.sh" > "$test_root/output" 2>&1; then fail 'unconfirmed arbitrary production name accepted'; fi
export MYSQL_DATABASE='invalid`name' BIZISMART_BACKUP_CONFIRM_DB='invalid`name'
if sh "$script_dir/restore.sh" > "$test_root/output" 2>&1; then fail 'SQL identifier injection accepted'; fi
export MYSQL_DATABASE=bizismart_prod_fake BIZISMART_BACKUP_CONFIRM_DB=bizismart_prod_fake RETENTION_DAYS=-1
if sh "$script_dir/backup.sh" > "$test_root/output" 2>&1; then fail 'negative retention accepted'; fi
export RETENTION_DAYS=30
chmod 644 "$MYSQL_DEFAULTS_FILE"
if sh "$script_dir/backup.sh" > "$test_root/output" 2>&1; then fail 'world-readable credentials accepted'; fi
chmod 600 "$MYSQL_DEFAULTS_FILE"
export MYSQL_DUMP_FLAVOR=mariadb
sh "$script_dir/backup.sh" > "$test_root/output" 2>&1 || fail 'MariaDB flavor retained unsupported MySQL GTID option'
export MYSQL_DUMP_FLAVOR=invalid
if sh "$script_dir/backup.sh" > "$test_root/output" 2>&1; then fail 'invalid dump flavor accepted'; fi
export MYSQL_DUMP_FLAVOR=mysql
chmod 755 "$BACKUP_DIR"
if sh "$script_dir/backup.sh" > "$test_root/output" 2>&1; then fail 'unsafe staging directory accepted'; fi
chmod 700 "$BACKUP_DIR"
test "$(wc -l < "$FAKE_LOG")" = "$calls" || fail 'validation failure executed native tool'
echo 'Production helper guard/failure/publication/retention/Unicode tests passed; fake processes only, no database access.'
