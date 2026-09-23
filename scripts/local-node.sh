#!/usr/bin/env bash
# Dev helper: run the Local node in the background against its OWN database (default r007_local), API on 0.0.0.0:8080, Reverb on 0.0.0.0:8081.
#   scripts/local-node.sh seed     # migrate:fresh + r007:demo-seed (DESTROYS the dev DB)
#   scripts/local-node.sh start    # nohup r007:run --reverb (logs in storage/logs/local-node.log)
#   scripts/local-node.sh stop|status|restart
#   scripts/local-node.sh device-code [FACILITY_CODE]   # one-time device registration code (24 h)
#   scripts/local-node.sh artisan <cmd...>              # any artisan command with the node's env
# Environment (all optional): LOCAL_NODE_DB, LOCAL_NODE_PORT, LOCAL_NODE_REVERB_PORT, DB_USERNAME, DB_PASSWORD.
set -euo pipefail
cd "$(dirname "$0")/.."
export PATH="/opt/homebrew/opt/mysql@8.4/bin:$PATH"

DB="${LOCAL_NODE_DB:-r007_local}"
PORT="${LOCAL_NODE_PORT:-8080}"
RPORT="${LOCAL_NODE_REVERB_PORT:-8081}"
PIDFILE="storage/local-node.pid"
LOG="storage/logs/local-node.log"
SECRET_FILE="storage/local-node.reverb-secret"
[ -s "$SECRET_FILE" ] || php -r 'echo bin2hex(random_bytes(16));' > "$SECRET_FILE"

# Deterministic demo site (App\Support\Demo\DemoIds::site()).
SITE_ID="$(php -r 'require "vendor/autoload.php"; echo App\Support\Demo\DemoIds::site();')"
LAN_IP="$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || hostname -I 2>/dev/null | awk '{print $1}' || echo 127.0.0.1)"

export APP_ENV=local APP_NODE=local SITE_ID DB_CONNECTION=mysql DB_DATABASE="$DB" DB_USERNAME="${DB_USERNAME:-root}" DB_PASSWORD="${DB_PASSWORD:-}"
export REDIS_DB=4 REDIS_CACHE_DB=5 REDIS_PREFIX=r007_local_ CACHE_STORE=redis QUEUE_CONNECTION=redis SESSION_DRIVER=array
# REVERB_HOST loopback => GET /system/info advertises the host the client used to reach the API (LAN IP, or 10.0.2.2 from the Android emulator)
export BROADCAST_CONNECTION=reverb REVERB_APP_SECRET="$(cat "$SECRET_FILE")" REVERB_HOST=127.0.0.1 REVERB_PORT="$RPORT" REVERB_SERVER_PORT="$RPORT" REVERB_SERVER_HOST=0.0.0.0
export API_PORT="$PORT" APP_URL="http://$LAN_IP:$PORT" CORS_ALLOWED_ORIGINS='*'

running() { [ -s "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; }

case "${1:-status}" in
  seed)
    mysql -h "${DB_HOST:-127.0.0.1}" -u root -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
    php artisan config:clear >/dev/null
    php artisan r007:demo-seed --fresh
    ;;
  start)
    if running; then echo "already running (pid $(cat "$PIDFILE"))"; exit 0; fi
    php artisan config:clear >/dev/null
    mkdir -p storage/logs
    # New session (pid == process group id) so `stop` can take down serve workers, queue, scheduler and Reverb together.
    nohup perl -MPOSIX -e 'POSIX::setsid(); exec @ARGV' php artisan r007:run --reverb --host=0.0.0.0 --port="$PORT" >> "$LOG" 2>&1 &
    echo $! > "$PIDFILE"
    for i in $(seq 1 30); do curl -fs "http://127.0.0.1:$PORT/api/v1/system/info" >/dev/null && break; sleep 1; done
    echo "started pid $(cat "$PIDFILE"): http://$LAN_IP:$PORT/api/v1 (reverb :$RPORT), db=$DB"
    ;;
  stop)
    if running; then
      kill -TERM -- "-$(cat "$PIDFILE")" 2>/dev/null || kill "$(cat "$PIDFILE")" || true
      sleep 2
      kill -KILL -- "-$(cat "$PIDFILE")" 2>/dev/null || true
      rm -f "$PIDFILE"
      echo stopped
    else echo "not running"; fi
    ;;
  restart) "$0" stop; "$0" start ;;
  artisan) shift; php artisan "$@" ;;   # run any artisan command against the node's DB/Redis, e.g.: scripts/local-node.sh artisan r007:device-code --facility=RESTAURANT
  device-code) php artisan r007:device-code --facility="${2:-}" ;;
  status)
    if running; then echo "running pid $(cat "$PIDFILE") -> http://$LAN_IP:$PORT/api/v1 (db=$DB)"; else echo "not running"; fi
    ;;
  *) echo "usage: $0 seed|start|stop|restart|status"; exit 2 ;;
esac
