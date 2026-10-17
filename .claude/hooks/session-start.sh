#!/bin/bash
# SessionStart hook for Claude Code cloud sessions. Installs dependencies and
# works around the cloud container's network setup so that Jest, PHPUnit and
# wp-env can run. Does nothing outside the cloud.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

HOOK_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$HOOK_DIR/../.." && pwd)}"

export COMPOSER_ALLOW_SUPERUSER=1

# --- Node / wp-env -----------------------------------------------------------

# There is no DNS in the container, so wp-env thinks it is offline. Answer its
# online check and send Node's HTTP requests through the session proxy.
{
  echo "export NODE_USE_ENV_PROXY=1"
  echo "export NODE_OPTIONS=\"\${NODE_OPTIONS:-} --require $HOOK_DIR/wp-env-online.cjs\""
  echo "export COMPOSER_ALLOW_SUPERUSER=1"
} >> "$CLAUDE_ENV_FILE"

npm --prefix "$ROOT" install --ignore-scripts --no-audit --no-fund
npm --prefix "$ROOT/packages/join-flow" install --no-audit --no-fund
npm --prefix "$ROOT/packages/join-e2e" install --no-audit --no-fund

# --- PHP ---------------------------------------------------------------------

# The session's GitHub proxy refuses tarball downloads for third-party repos,
# but git clones work, so install from source. phpstan/phpstan has no git
# source in composer.lock, so seed Composer's download cache with a zip built
# from a git checkout of the locked commit.
cd "$ROOT/packages/join-block"
PHPSTAN_URL=$(php -r '
  $lock = json_decode(file_get_contents("composer.lock"), true);
  foreach ($lock["packages-dev"] as $p) {
    if ($p["name"] === "phpstan/phpstan") { echo $p["dist"]["url"]; }
  }')
if [ -n "$PHPSTAN_URL" ]; then
  PHPSTAN_REF="${PHPSTAN_URL##*/}"
  CACHE_DIR="$(composer config --global cache-files-dir)/phpstan/phpstan"
  CACHE_FILE="$CACHE_DIR/$(printf %s "$PHPSTAN_URL" | sha1sum | cut -d' ' -f1).zip"
  if [ ! -f "$CACHE_FILE" ]; then
    TMP=$(mktemp -d)
    git -C "$TMP" init -q
    git -C "$TMP" fetch -q --depth 1 https://github.com/phpstan/phpstan.git "$PHPSTAN_REF"
    mkdir -p "$CACHE_DIR"
    git -C "$TMP" archive --format=zip --prefix=phpstan/ FETCH_HEAD -o "$CACHE_FILE"
    rm -rf "$TMP"
  fi
fi
composer install --no-interaction --prefer-source

# --- Docker (wp-env) ---------------------------------------------------------

# wp-env needs a Docker daemon; the container doesn't start one by default.
if ! docker info >/dev/null 2>&1; then
  setsid nohup dockerd >/tmp/dockerd.log 2>&1 < /dev/null &
  for _ in $(seq 1 30); do
    docker info >/dev/null 2>&1 && break
    sleep 1
  done
fi
