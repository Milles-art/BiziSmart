#!/usr/bin/env bash
# Frontend-only BiziSmart update. Run only after local acceptance; SSH key stays on the operator's computer.
set -Eeuo pipefail
umask 077
base=/home/afyabgzo/bizismart
public=/home/afyabgzo/bizismart.com
archive=${1:?Supply the private absolute ZIP path}
checksum=${2:?Supply its SHA256}
release=${3:?Supply a unique design-YYYYMMDD-HHMMSS release name}
[[ "$checksum" =~ ^[a-fA-F0-9]{64}$ ]] || exit 2
[[ "$release" =~ ^design-[0-9]{8}-[0-9]{6}$ ]] || exit 2
[[ "$(realpath "$base")" == "$base" && "$(realpath "$public")" == "$public" ]] || exit 2
[[ "$(dirname "$(realpath "$archive")")" == "$base" && "$archive" == *.zip ]] || exit 2
test -f "$base/backend/artisan"
test -f "$public/gateway.php"
test -f "$public/.htaccess"
test -f "$public/index.html"
[[ "$(realpath "$public/assets")" == "$public/assets" ]] || exit 2
for file in "$public/index.html" "$public/sw.js" "$public/registerSW.js" "$public/manifest.webmanifest" "$public/bizismart.svg"; do
    [[ ! -L "$file" ]] || { echo 'Refusing a symlink destination.' >&2; exit 2; }
done
exec 9>"$base/.deploy-design.lock"
flock -n 9 || { echo 'Another design deployment holds the lock.' >&2; exit 3; }
printf '%s  %s\n' "$checksum" "$archive" | sha256sum -c -
unzip -tq "$archive"
if unzip -Z1 "$archive" | grep -Ev '^(assets/[^/]+\.(js|css|png|jpg|jpeg|webp|svg|woff2?)|index\.html|bizismart\.svg|manifest\.webmanifest|registerSW\.js|sw\.js|workbox-[A-Za-z0-9_-]+\.js)$'; then
    echo 'Unexpected ZIP path; refusing deployment.' >&2; exit 4
fi
stage="$base/releases/$release"
mkdir -p "$base/releases" "$base/backups"
mkdir "$stage"
unzip -q "$archive" -d "$stage"
(cd "$stage" && sha256sum --strict -c "${archive%.zip}.sha256")
curl -fsS --max-time 25 https://freshchoice.bizismart.com/api/v1/ready >"$stage/readiness-before.json"
sha256sum "$public/gateway.php" "$public/.htaccess" >"$stage/protected-before.sha256"
backup="$base/backups/$release-public.tar.gz"
tar -czf "$backup" -C "$public" .
sha256sum "$backup" >"$backup.sha256"
changed=0
rollback() {
    status=$?
    trap - ERR
    if [[ "$changed" == 1 ]]; then
        echo 'Deployment check failed; restoring previous public files.' >&2
        tar -xzf "$backup" -C "$public"
        echo "Rollback archive: $backup" >&2
    fi
    exit "$status"
}
trap rollback ERR
changed=1
# Hashed assets first; retain older assets for open checkouts and rollback.
mkdir -p "$public/assets"
for asset in "$stage/assets"/*; do
    [[ -f "$asset" && ! -L "$asset" ]] || exit 4
    install -m 0644 "$asset" "$public/assets/$(basename "$asset")"
done
for file in "$stage"/*; do
    [[ -f "$file" ]] || continue
    name=$(basename "$file")
    case "$name" in
        bizismart.svg|manifest.webmanifest|registerSW.js|sw.js|workbox-*.js) install -m 0644 "$file" "$public/$name" ;;
    esac
done
# Rename the entry page on the same filesystem after all referenced assets exist.
install -m 0644 "$stage/index.html" "$public/.index-$release.next"
mv -f "$public/.index-$release.next" "$public/index.html"
(cd "$public" && sha256sum --strict -c "${archive%.zip}.sha256")
sha256sum -c "$stage/protected-before.sha256"
curl -fsS --max-time 25 https://freshchoice.bizismart.com/api/v1/health >"$stage/health-after.json"
curl -fsS --max-time 25 https://freshchoice.bizismart.com/api/v1/ready >"$stage/readiness-after.json"
for domain in bizismart.com freshchoice.bizismart.com; do
    curl -fsS --max-time 25 "https://$domain/?release=$release" >"$stage/$domain-index.html"
    cmp "$stage/index.html" "$stage/$domain-index.html"
done
changed=0
trap - ERR
printf 'Design deployed: %s\nPrivate rollback archive: %s\n' "$release" "$backup"
