#!/usr/bin/env bash
# Existing BiziSmart account only. Preserve every unrelated cron line and private backup.
set -Eeuo pipefail
umask 077
base=/home/afyabgzo/bizismart
backend="$base/backend"
php=/opt/alt/php85/usr/bin/php
wrapper="$base/shared-cron.sh"
[[ "$(id -un)" == afyabgzo && "$(realpath "$base")" == "$base" && "$(realpath "$backend")" == "$backend" ]]
[[ -x "$php" && -f "$backend/artisan" && -f "$wrapper" && ! -L "$wrapper" ]]
command -v timeout >/dev/null
command -v flock >/dev/null
printf '%s  %s\n' 00f468bc54478f6c103a202483dbff295feef6ed77724bca17fda18e6b4eaacc "$wrapper" | sha256sum -c -
cd "$backend"
# This installation must not activate provider messaging. Future credentials need explicit live verification.
"$php" <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (['hosting.profile'=>'shared', 'cache.default'=>'database', 'session.driver'=>'database', 'queue.default'=>'database'] as $key=>$expected) {
    if (config($key) !== $expected) throw new RuntimeException('Runtime profile is not ready.');
}
if ((config('cache.limiter') ?: config('cache.default')) !== 'database' || !function_exists('proc_open')) throw new RuntimeException('Bounded runner prerequisites unavailable.');
foreach (['messaging.whatsapp.token', 'messaging.whatsapp.phone_number_id', 'messaging.sms.key', 'messaging.sms.secret', 'messaging.sms.sender'] as $key) {
    if (config($key)) throw new RuntimeException('Provider configuration present: review live messaging authorization before activation.');
}
$last = Illuminate\Support\Facades\Cache::get('infra:shared-last-run');
if (!$last || ($last['failed'] ?? -1) !== 0 || ($last['completed'] ?? 0) < 1 || Illuminate\Support\Carbon::parse($last['at'])->lt(now()->subMinutes(15))) {
    throw new RuntimeException('A recent successful bounded manual run is required.');
}
echo "Runtime and messaging preflight passed.\n";
PHP
mkdir -p "$base/backups"
exec 9>"$base/.cron-install.lock"
flock -n 9 || { echo 'Another cron installer is active.' >&2; exit 1; }
before=$(mktemp "$base/backups/cron-before-$(date -u +%Y%m%d-%H%M%S)-XXXXXX")
if crontab -l >"$before" 2>"$before.error"; then :; elif grep -qi 'no crontab' "$before.error"; then : >"$before"; else echo 'Cannot read existing crontab.' >&2; exit 1; fi
line="*/5 * * * * BIZISMART_BACKEND=$backend BIZISMART_PHP=$php sh $wrapper >> $backend/storage/logs/shared-cron.log 2>&1"
if grep -Fxq "$line" "$before"; then echo 'Exact BiziSmart cron already installed; no change.'; exit 0; fi
if grep -Ev '^[[:space:]]*(#|$)' "$before" | grep -Eq 'bizismart|artisan|schedule:run|schedule:work'; then echo 'Potential application scheduler already exists: inspect before adding another.' >&2; exit 1; fi
count=$(awk '!/^[[:space:]]*(#|$)/ && !/^[[:space:]]*[A-Za-z_][A-Za-z_0-9]*[[:space:]]*=/ { n++ } END { print n+0 }' "$before")
[[ "$count" -lt 5 ]] || { echo 'Account cron limit reached.' >&2; exit 1; }
after="$before.new"
{ cat "$before"; printf '\n# BiziSmart finite shared runtime; no daemon or minute cron\n%s\n' "$line"; } >"$after"
current="$before.current"
if crontab -l >"$current" 2>"$current.error"; then :; elif grep -qi 'no crontab' "$current.error"; then : >"$current"; else exit 1; fi
cmp -s "$before" "$current" || { echo 'Crontab changed during preflight; refusing overwrite.' >&2; exit 1; }
touch "$backend/storage/logs/shared-cron.log"
chmod 600 "$backend/storage/logs/shared-cron.log"
crontab "$after"
crontab -l >"$before.installed"
cmp -s "$after" "$before.installed" || { echo 'Installed crontab verification failed; inspect private backup.' >&2; exit 1; }
echo 'Installed exactly one BiziSmart five-minute cron entry; existing entries preserved.'
printf 'Private prior crontab: %s\n' "$before"
