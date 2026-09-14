#!/usr/bin/env bash
# Local MySQL 8.0 for development, deliberately isolated from any other MySQL on
# this machine.
#
# Why this exists: macOS Homebrew ships MySQL 9.x, and this machine already runs
# 9.6.0 on the default port 3306 with datadir /opt/homebrew/var/mysql. The
# production target is MySQL 8 (or MariaDB 10.6+, doc 19 deviation 3). Authoring
# a schema against 9.x risks 9-only syntax reaching a host that cannot run it —
# the same version-skew trap that cost us a day under the withdrawn Node stack.
#
# So: 8.0 gets its own datadir and its own port. Nothing here touches 9.6.

set -euo pipefail
BIN=/opt/homebrew/opt/mysql@8.0/bin
DATADIR=/opt/homebrew/var/mysql8-rajdhani
PORT=3307
SOCKET=/tmp/mysql8-rajdhani.sock
PIDFILE="$DATADIR/rajdhani.pid"

case "${1:-}" in
  start)
    if [ -S "$SOCKET" ] && "$BIN/mysqladmin" --socket="$SOCKET" ping >/dev/null 2>&1; then
      echo "already running on port $PORT"; exit 0
    fi
    "$BIN/mysqld_safe" --datadir="$DATADIR" --port="$PORT" --socket="$SOCKET" \
      --pid-file="$PIDFILE" --mysqlx=OFF >/dev/null 2>&1 &
    for _ in $(seq 1 30); do
      "$BIN/mysqladmin" --socket="$SOCKET" ping >/dev/null 2>&1 && { echo "started on port $PORT"; exit 0; }
      sleep 1
    done
    echo "failed to start; see $DATADIR/*.err" >&2; exit 1
    ;;
  stop)
    "$BIN/mysqladmin" --socket="$SOCKET" -u root shutdown 2>/dev/null && echo "stopped" || echo "not running"
    ;;
  cli)
    shift; exec "$BIN/mysql" --socket="$SOCKET" -u root "$@"
    ;;
  status)
    "$BIN/mysqladmin" --socket="$SOCKET" ping 2>/dev/null && "$BIN/mysql" --socket="$SOCKET" -u root -e "SELECT VERSION() AS version, @@port AS port;" \
      || echo "not running"
    ;;
  *) echo "usage: $0 {start|stop|cli|status}" >&2; exit 2 ;;
esac
