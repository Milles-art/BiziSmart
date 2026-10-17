#!/usr/bin/env bash
# Verify actual HTTPS responses from the hosting connection, independently of on-disk hashes.
set -Eeuo pipefail
umask 077
base=/home/afyabgzo/bizismart
release=${1:?Supply the installed release name}
[[ "$release" =~ ^design-[0-9]{8}-[0-9]{6}$ ]] || exit 2
stage="$base/releases/$release"
[[ "$(realpath "$stage")" == "$stage" ]] || exit 2
checks="$stage/https-checks"
mkdir -p "$checks"
count=0
for domain in bizismart.com freshchoice.bizismart.com; do
    while read -r expected file; do
        [[ "$expected" =~ ^[a-f0-9]{64}$ && "$file" != *..* && "$file" != /* ]] || exit 2
        path="/$file"; [[ "$file" != index.html ]] || path=/
        output="$checks/$domain-$(basename "$file")"
        curl -fsS --compressed --max-time 25 "https://$domain$path?release=$release" -o "$output"
        printf '%s  %s\n' "$expected" "$output" | sha256sum -c -
        count=$((count+1))
    done < "$base/BiziSmart-all-pages-design-20261016.sha256"
    for endpoint in health ready; do
        output="$checks/$domain-$endpoint.json"
        curl -fsS --compressed --max-time 25 "https://$domain/api/v1/$endpoint" -o "$output"
        php -r '$body=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if(($body["success"]??false)!==true)exit(1); if($argv[2]==="health" && ($body["data"]["status"]??null)!=="ok")exit(1); if($argv[2]==="ready"){if(($body["data"]["ready"]??false)!==true)exit(1);foreach(["database","migrations","cache"] as $key)if(($body["data"]["checks"][$key]??false)!==true)exit(1);}' "$output" "$endpoint"
    done
done
printf 'Verified %s public HTTPS files and both health/readiness endpoints on both domains.\n' "$count"
