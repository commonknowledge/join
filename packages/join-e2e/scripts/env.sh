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
READY_TIMEOUT="${WP_READY_TIMEOUT:-120}"

wp() {
  if [ -z "$(docker compose ps --status running --quiet cli 2>/dev/null)" ]; then
    echo "The E2E containers aren't running. Start them with: scripts/env.sh start" >&2
    exit 1
  fi
  docker compose exec -T cli wp "$@"
}

# On first start the wordpress container copies WordPress into the shared
# volume and only then writes wp-config.php, so the files can exist before the
# configuration does. Wait until WP-CLI can load the configuration and reach
# the database.
wait_for_wordpress() {
  local deadline=$((SECONDS + READY_TIMEOUT))
  until wp db query 'SELECT 1' >/dev/null 2>&1; do
    if [ "$SECONDS" -ge "$deadline" ]; then
      echo "WordPress was not ready after ${READY_TIMEOUT}s (no wp-config.php or no database connection)." >&2
      echo "Last WP-CLI error:" >&2
      wp db query 'SELECT 1' 2>&1 >/dev/null | tail -5 >&2 || true
      echo "wordpress container logs:" >&2
      docker compose logs --tail 20 wordpress >&2 || true
      exit 1
    fi
    sleep 2
  done
}

case "${1:-}" in
  start)
    docker compose up -d --wait
    wait_for_wordpress
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
