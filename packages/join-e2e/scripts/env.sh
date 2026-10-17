#!/usr/bin/env bash
# Manages the WordPress instance the E2E suite runs against (docker-compose.yml).
#
# Usage:
#   scripts/env.sh start     Start WordPress on http://localhost:8889 and install it
#   scripts/env.sh stop      Stop the containers, keeping the database
#   scripts/env.sh destroy   Remove the containers and all data
#   scripts/env.sh wp ...    Run a WP-CLI command, e.g. scripts/env.sh wp option get home
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

URL="http://localhost:8889"

wp() {
  docker compose --progress quiet run --rm --no-deps -T cli wp "$@"
}

case "${1:-}" in
  start)
    docker compose up -d --wait
    # The wordpress container copies WordPress into the shared volume on first
    # start; wait for it before running WP-CLI against it.
    for _ in $(seq 1 60); do
      wp core version >/dev/null 2>&1 && break
      sleep 1
    done
    if ! wp core is-installed 2>/dev/null; then
      wp core install --url="$URL" --title="Join E2E" \
        --admin_user=admin --admin_password=password \
        --admin_email=admin@example.org --skip-email
      wp rewrite structure '/%postname%/'
    fi
    wp plugin activate join-block
    ;;
  stop)
    docker compose stop
    ;;
  destroy)
    docker compose down --volumes
    ;;
  wp)
    shift
    wp "$@"
    ;;
  *)
    sed -n '2,9p' "${BASH_SOURCE[0]}" >&2
    exit 1
    ;;
esac
