#!/bin/sh
# Post-deploy smoke test. Only touches public endpoints; no credentials needed.
# Usage: BASE_URL=https://api.bizismart.co.tz sh infrastructure/prod/smoke.sh
set -eu
umask 077
BASE_URL="${BASE_URL:?Set BASE_URL, e.g. https://api.bizismart.co.tz}"
BASE_URL=${BASE_URL%/}
if printf '%s\n' "$BASE_URL" | grep -Eq '^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?$'; then
  :
elif printf '%s\n' "$BASE_URL" | grep -Eq '^http://(localhost|127\.0\.0\.1)(:[0-9]{1,5})?$'; then
  test "${BIZISMART_ALLOW_LOCAL_HTTP:-}" = 1 || { echo 'Local HTTP requires BIZISMART_ALLOW_LOCAL_HTTP=1.' >&2; exit 1; }
else
  echo 'BASE_URL requires an HTTPS origin (explicit local HTTP checks only).' >&2; exit 1
fi
probe_file=$(mktemp "${TMPDIR:-/tmp}/bizismart-ready-XXXXXXXX")
trap 'rm -f -- "$probe_file"' EXIT HUP INT TERM
fail() { echo "SMOKE FAIL: $1" >&2; exit 1; }
health=$(curl -fsS --connect-timeout 5 --max-time 15 "$BASE_URL/api/v1/health") || fail "health unreachable"
echo "$health" | grep -q '"status":"ok"' || fail "health body unexpected: $health"
echo "health ok"
code=$(curl -sS --connect-timeout 5 --max-time 15 -o "$probe_file" -w '%{http_code}' "$BASE_URL/api/v1/ready") || fail "ready unreachable"
cat "$probe_file"; echo
test "$code" = "200" || fail "ready returned HTTP $code (migrations/cache/database degraded?)"
grep -q '"ready":true' "$probe_file" || fail "ready body not ready"
echo "ready ok"
echo "SMOKE PASS"
