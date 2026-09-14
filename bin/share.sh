#!/usr/bin/env bash
#
# Expose the local API over HTTPS so the front-end developer can use it.
#
# Starts MySQL, the PHP dev server and a Cloudflare quick tunnel, then rewrites
# APP_URL in .env to the tunnel's address and prints what to hand over.
#
# Why a tunnel rather than plain localhost: the refresh cookie is
# `SameSite=None; Secure`, and browsers accept that only over HTTPS. On
# http://localhost the cookie is silently dropped, so cross-origin refresh
# cannot be tested at all. Through the tunnel the browser sees real HTTPS and
# the whole login flow works.
#
# APP_URL matters more than it looks: it is the JWT `iss` claim, and
# JwtHelper::decode() rejects a token whose issuer does not match. Changing it
# invalidates tokens issued under the previous URL — which is fine, nobody is
# holding one.
#
# Usage:  ./bin/share.sh          Ctrl-C stops everything it started.

set -euo pipefail

API_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$API_DIR"

PHP_BIN=/opt/homebrew/opt/php@8.3/bin/php
PORT=8000
ENV_FILE="$API_DIR/.env"
LOG_DIR="${TMPDIR:-/tmp}/rajdhani-share"
mkdir -p "$LOG_DIR"

[ -x "$PHP_BIN" ] || PHP_BIN=$(command -v php)
command -v cloudflared >/dev/null || { echo "cloudflared is not installed:  brew install cloudflared"; exit 1; }
[ -f "$ENV_FILE" ] || { echo "No .env — copy .env.example first."; exit 1; }

# Remember APP_URL so Ctrl-C can put it back; leaving a dead tunnel URL in .env
# would break local work the next morning.
ORIGINAL_APP_URL=$(grep -E '^APP_URL=' "$ENV_FILE" | head -1 | cut -d= -f2- || echo 'http://localhost:8000')
STARTED_SERVER=""
TUNNEL_PID=""

cleanup() {
  echo
  echo "  stopping…"
  [ -n "$TUNNEL_PID" ] && kill "$TUNNEL_PID" 2>/dev/null || true
  [ -n "$STARTED_SERVER" ] && kill "$STARTED_SERVER" 2>/dev/null || true
  set_env APP_URL "$ORIGINAL_APP_URL"
  echo "  APP_URL restored to $ORIGINAL_APP_URL"
  echo "  MySQL is still running — ./bin/mysql8.sh stop  if you want it down."
}
trap cleanup EXIT INT TERM

set_env() {
  local key="$1" value="$2" tmp
  tmp=$(mktemp)
  if grep -qE "^${key}=" "$ENV_FILE"; then
    # The value is a URL with slashes, so use | as the sed delimiter.
    sed "s|^${key}=.*|${key}=${value}|" "$ENV_FILE" > "$tmp"
  else
    cat "$ENV_FILE" > "$tmp"
    printf '%s=%s\n' "$key" "$value" >> "$tmp"
  fi
  mv "$tmp" "$ENV_FILE"
}

echo "  starting MySQL…"
./bin/mysql8.sh start >/dev/null 2>&1 || true

if ! curl -sf "http://127.0.0.1:${PORT}/api/v1/health" >/dev/null 2>&1; then
  echo "  starting the API on :${PORT}…"
  "$PHP_BIN" -S "127.0.0.1:${PORT}" -t public public/index.php > "$LOG_DIR/api.log" 2>&1 &
  STARTED_SERVER=$!
  sleep 2
else
  echo "  API already running on :${PORT}"
fi

curl -sf "http://127.0.0.1:${PORT}/api/v1/health" >/dev/null || {
  echo "  the API is not answering — see $LOG_DIR/api.log"; exit 1; }

echo "  opening the tunnel…"
cloudflared tunnel --url "http://127.0.0.1:${PORT}" > "$LOG_DIR/tunnel.log" 2>&1 &
TUNNEL_PID=$!

PUBLIC_URL=""
for _ in $(seq 1 40); do
  PUBLIC_URL=$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$LOG_DIR/tunnel.log" | head -1 || true)
  [ -n "$PUBLIC_URL" ] && break
  sleep 1
done

[ -n "$PUBLIC_URL" ] || { echo "  the tunnel did not report a URL — see $LOG_DIR/tunnel.log"; exit 1; }

# The issuer must match the URL the client actually called, or every token this
# session mints is rejected by its own verifier.
set_env APP_URL "$PUBLIC_URL"

# The hostname is brand new, so the local resolver may still be holding a
# negative cache entry from before it existed. That is a *local* artefact —
# the URL works from anywhere else — so a failure here is reported as unknown
# rather than as an error, and the tunnel stays up either way.
STATUS=000
for _ in $(seq 1 6); do
  STATUS=$(curl -s -o /dev/null -m 8 -w '%{http_code}' "${PUBLIC_URL}/api/v1/health" 2>/dev/null) || STATUS=000
  [ "$STATUS" = "200" ] && break
  sleep 5
done

if [ "$STATUS" = "200" ]; then
  REACHABILITY="verified — HTTP 200"
else
  REACHABILITY="not verifiable from this machine (local DNS cache); the URL still works elsewhere"
fi

cat <<BANNER

  ─────────────────────────────────────────────────────────────────
   API is public at   ${PUBLIC_URL}/api/v1
   reachability       ${REACHABILITY}
  ─────────────────────────────────────────────────────────────────

   Give your front-end developer:

     VITE_API_URL=${PUBLIC_URL}/api/v1

   They must send credentials on auth calls, or the refresh cookie
   never travels:

     fetch(url, { credentials: 'include' })

   Their origin has to be in CORS_ORIGINS in .env. Currently:
     $(grep -E '^CORS_ORIGINS=' "$ENV_FILE" | cut -d= -f2-)

   Live while this stays open. Ctrl-C stops it and restores APP_URL.
   The URL changes every restart — a named tunnel fixes that, and
   needs a (free) Cloudflare account.

  ─────────────────────────────────────────────────────────────────

BANNER

wait "$TUNNEL_PID"
