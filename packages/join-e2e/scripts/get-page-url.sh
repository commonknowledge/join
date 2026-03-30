#!/usr/bin/env bash
# Prints the URL for a named E2E test page.
# Usage: ./get-page-url.sh [standard|free]
#   Defaults to "standard".
set -euo pipefail

SLUG="${1:-standard}"
OPTION_KEY="ck_e2e_${SLUG}_page_url"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}/.."

npx wp-env run tests-cli wp option get "${OPTION_KEY}" 2>/dev/null
