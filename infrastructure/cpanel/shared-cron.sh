#!/bin/sh
# Install privately outside public_html. Schedule this script once every five minutes.
set -eu
umask 077
: "${BIZISMART_BACKEND:?Set absolute private backend path}"
: "${BIZISMART_PHP:?Set absolute PHP8.3+ CLI path}"
case "$BIZISMART_BACKEND" in /*) ;; *) echo 'Backend path must be absolute.' >&2; exit 1;; esac
case "$BIZISMART_PHP" in /*) ;; *) echo 'PHP path must be absolute.' >&2; exit 1;; esac
[ -f "$BIZISMART_BACKEND/artisan" ] && [ -x "$BIZISMART_PHP" ] || exit 1
command -v timeout >/dev/null 2>&1 || { echo 'Host must provide GNU timeout for a bounded cron run.' >&2; exit 1; }
cd "$BIZISMART_BACKEND"
# Foreground process group: no detached worker. Hard outer limit also covers startup/DB waits.
case "${1:-tasks}" in
  tasks) exec timeout --signal=TERM --kill-after=2s 50s "$BIZISMART_PHP" artisan bizismart:shared-run ;;
  queue) exec timeout --signal=TERM --kill-after=2s 25s "$BIZISMART_PHP" artisan queue:work database --once --sleep=0 --timeout=20 --tries=1 --memory=128 ;;
  *) echo 'Only tasks or queue modes are allowed.' >&2; exit 1 ;;
esac
