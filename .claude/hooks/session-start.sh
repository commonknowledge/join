#!/bin/bash
# SessionStart hook for Claude Code cloud sessions. Installs dependencies and
# starts Docker so that Jest, PHPUnit and the E2E suite can run. Does nothing
# outside the cloud.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

HOOK_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$HOOK_DIR/../.." && pwd)}"

export COMPOSER_ALLOW_SUPERUSER=1
echo "export COMPOSER_ALLOW_SUPERUSER=1" >> "$CLAUDE_ENV_FILE"

# --- Node --------------------------------------------------------------------

npm --prefix "$ROOT" install --ignore-scripts --no-audit --no-fund
npm --prefix "$ROOT/packages/join-flow" install --no-audit --no-fund
npm --prefix "$ROOT/packages/join-e2e" install --no-audit --no-fund

# The cloud image ships a Chromium for Playwright; use it rather than
# downloading the exact build the pinned Playwright version expects.
CHROMIUM=$(ls -d /opt/pw-browsers/chromium-*/chrome-linux/chrome 2>/dev/null | sort -V | tail -1 || true)
if [ -n "$CHROMIUM" ]; then
  echo "export PLAYWRIGHT_CHROMIUM_EXECUTABLE=$CHROMIUM" >> "$CLAUDE_ENV_FILE"
fi

# --- PHP ---------------------------------------------------------------------

# The session's GitHub proxy refuses tarball downloads for third-party repos;
# build them from git instead (see seed-composer-cache.sh).
cd "$ROOT/packages/join-block"
"$HOOK_DIR/seed-composer-cache.sh" composer.lock
composer install --no-interaction

# --- Docker (E2E WordPress) --------------------------------------------------

# The container doesn't start a Docker daemon by default.
if ! docker info >/dev/null 2>&1; then
  # A restarted session can leave PID files from daemons that no longer run,
  # and dockerd then refuses to start ("process with PID ... is still running").
  for pidfile in /var/run/docker.pid /var/run/docker/containerd/containerd.pid; do
    if [ -f "$pidfile" ] && ! kill -0 "$(cat "$pidfile")" 2>/dev/null; then
      rm -f "$pidfile"
    fi
  done
  setsid nohup dockerd >/tmp/dockerd.log 2>&1 < /dev/null &
  for _ in $(seq 1 30); do
    docker info >/dev/null 2>&1 && break
    sleep 1
  done
  if ! docker info >/dev/null 2>&1; then
    echo "Docker daemon did not start within 30s. Last lines of /tmp/dockerd.log:" >&2
    tail -n 20 /tmp/dockerd.log >&2 || true
    exit 1
  fi
fi
