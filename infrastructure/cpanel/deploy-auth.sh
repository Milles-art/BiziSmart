#!/usr/bin/env bash
# Existing account only. Reviewed PHP slice; never replace env/vendor/data.
set -Eeuo pipefail
umask 077
base=/home/afyabgzo/bizismart
backend=$base/backend
php=/opt/alt/php85/usr/bin/php
archive=${1:?Supply the private backend ZIP}
checksum=${2:?Supply verified SHA256}
release=${3:?Supply auth-YYYYMMDD-HHMMSS}
[[ "$checksum" =~ ^[a-fA-F0-9]{64}$ && "$release" =~ ^auth-[0-9]{8}-[0-9]{6}$ ]] || exit 2
[[ "$(realpath "$base")" == "$base" && "$(realpath "$backend")" == "$backend" ]] || exit 2
[[ "$(dirname "$(realpath "$archive")")" == "$base" && ! -L "$archive" ]] || exit 2
[[ -f "$backend/artisan" && ! -e "$backend/storage/framework/down" ]] || exit 2
exec 9>"$base/.auth-deploy.lock"
flock -n 9 || exit 3
printf '%s  %s\n' "$checksum" "$archive" | sha256sum -c -
# An actual, recent encrypted/offsite configuration+data backup is mandatory.
"$php" -r '$s=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if(empty($s["encrypted"])||empty($s["signed"])||empty($s["offsite_verified"])||empty($s["contains_application_key"])||time()-strtotime($s["created_at"])>1800)exit(4);echo "Recent encrypted/offsite backup verified.\n";' "$base/encrypted-backups/last-success.json"
stage=$base/releases/$release
[[ ! -e "$stage" ]] || exit 2
mkdir -p "$stage" "$base/backups"
unzip -Z1 "$archive" >"$stage/archive-list.txt"
while IFS= read -r name; do
    [[ "$name" != *..* && "$name" != /* ]] || exit 4
    [[ "$name" == manifest.json || "$name" =~ ^(app/[A-Za-z0-9_/]+\.php|config/(auth|mail)\.php|routes/api\.php|database/migrations/2026_10_16_00004[23]_[a-z_]+\.php)$ ]] || exit 4
done <"$stage/archive-list.txt"
unzip -q "$archive" -d "$stage/source"
"$php" -r '$m=json_decode(file_get_contents($argv[1]."/manifest.json"),true,512,JSON_THROW_ON_ERROR);if(count($m)!==40)exit(4);foreach($m as $p=>$h){if(!preg_match("/^[A-Za-z0-9_\/.]+$/D",$p)||str_contains($p,"..")||!hash_equals($h,hash_file("sha256",$argv[1]."/".$p)))exit(4);echo $p,"\n";}' "$stage/source" >"$stage/files.txt"
while IFS= read -r file; do
    "$php" -l "$stage/source/$file" >/dev/null
    [[ ! -L "$backend/$file" ]] || exit 4
    [[ "$(realpath -m "$backend/$(dirname "$file")")/" == "$backend/"* ]] || exit 4
    if [[ -f "$backend/$file" ]]; then printf '%s\n' "$file" >>"$stage/existing.txt"; else printf '%s\n' "$file" >>"$stage/new.txt"; fi
done <"$stage/files.txt"
backup=$base/backups/$release-backend.tar.gz
tar -czf "$backup" -C "$backend" -T "$stage/existing.txt"
chmod 600 "$backup"
sha256sum "$backup" >"$backup.sha256"
changed=0
rollback() {
    result=$?
    trap - ERR
    if [[ "$changed" == 1 ]]; then
        tar -xzf "$backup" -C "$backend"
        if [[ -f "$stage/new.txt" ]]; then while IFS= read -r file; do rm -f -- "$backend/$file"; done <"$stage/new.txt"; fi
        (cd "$backend" && "$php" artisan optimize:clear && "$php" artisan up) || true
    fi
    printf 'Auth release failed. Automatic code rollback applies only before reopening; additive schema is retained. Inspect private release files before any further action.\n' >&2
    exit "$result"
}
trap rollback ERR
changed=1
(cd "$backend" && "$php" artisan down --retry=30)
while IFS= read -r file; do
    mkdir -p "$backend/$(dirname "$file")"
    install -m 0644 "$stage/source/$file" "$backend/$file"
done <"$stage/files.txt"
(cd "$backend" && "$php" artisan optimize:clear && "$php" artisan migrate --force && "$php" artisan view:cache && "$php" artisan up)
# Once users may enroll, never silently restore legacy authentication code.
# A failed post-open smoke check requires explicit diagnosis with new code kept.
changed=0
curl -fsS --max-time 25 https://freshchoice.bizismart.com/api/v1/ready >"$stage/readiness-after.json"
"$php" -r '$m=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);foreach($m as$p=>$h)if(!hash_equals($h,hash_file("sha256",$argv[2]."/".$p)))exit(5);echo "All40 installed PHP hashes match.\n";' "$stage/source/manifest.json" "$backend"
changed=0
trap - ERR
printf 'Auth backend deployed: %s\nPrivate code rollback: %s\nNo account credentials assigned or messages sent.\n' "$release" "$backup"
