#!/usr/bin/env bash
# Builds the join-flow bundle with test data enabled and seeds the E2E
# WordPress instance (scripts/env.sh) with the test pages.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PACKAGE_DIR="${SCRIPT_DIR}/.."
JOIN_FLOW_DIR="${SCRIPT_DIR}/../../join-flow"

echo "--- Building join-flow bundle (USE_TEST_DATA=true) ---"
cd "${JOIN_FLOW_DIR}"
REACT_APP_USE_TEST_DATA=true npm run build

echo "--- Seeding WordPress test environment ---"
cd "${PACKAGE_DIR}"
scripts/env.sh wp eval-file /var/www/html/wp-content/e2e-scripts/setup.php
scripts/env.sh wp rewrite flush --hard

echo "--- Seed complete ---"
