#!/bin/bash

# Checks that the plugin version is the same in every place it is recorded,
# and that readme.txt has a changelog entry for it. Prints the version.
#
# Usage: ./scripts/check-version.sh [project root]

set -e

print_error() {
    echo "ERROR: $1" >&2
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="${1:-$(cd "$SCRIPT_DIR/.." && pwd)}"

README_TXT="$PROJECT_ROOT/packages/join-block/readme.txt"
JOIN_PHP="$PROJECT_ROOT/packages/join-block/join.php"
INDEX_TSX="$PROJECT_ROOT/packages/join-flow/src/index.tsx"

for FILE in "$README_TXT" "$JOIN_PHP" "$INDEX_TSX"; do
    if [ ! -f "$FILE" ]; then
        print_error "$FILE not found"
        exit 1
    fi
done

PHP_VERSION=$(sed -n 's/^ \* Version: *\([^ ]*\) *$/\1/p' "$JOIN_PHP" | head -n 1)
README_VERSION=$(sed -n 's/^Stable tag: *\([^ ]*\) *$/\1/p' "$README_TXT" | head -n 1)
TSX_VERSION=$(sed -n 's/^ *release: "\([^"]*\)".*$/\1/p' "$INDEX_TSX" | head -n 1)

if ! [[ "$PHP_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    print_error "Could not read an X.Y.Z version from the Version header in packages/join-block/join.php (found '$PHP_VERSION')"
    exit 1
fi

if [ "$README_VERSION" != "$PHP_VERSION" ] || [ "$TSX_VERSION" != "$PHP_VERSION" ]; then
    print_error "Version numbers disagree:"
    echo "  packages/join-block/join.php      Version:    $PHP_VERSION" >&2
    echo "  packages/join-block/readme.txt    Stable tag: $README_VERSION" >&2
    echo "  packages/join-flow/src/index.tsx  release:    $TSX_VERSION" >&2
    echo "Run ./scripts/bump-version.sh to update all three together." >&2
    exit 1
fi

CHANGELOG_ENTRIES=$(awk -v heading="= $PHP_VERSION =" '
    /^== Changelog ==/ { in_changelog = 1; next }
    in_changelog && /^== / { exit }
    in_changelog && $0 == heading { in_entry = 1; next }
    in_entry && /^= / { exit }
    in_entry && /^\* / { count++ }
    END { print count + 0 }
' "$README_TXT")

if [ "$CHANGELOG_ENTRIES" -eq 0 ]; then
    print_error "packages/join-block/readme.txt has no changelog entry for $PHP_VERSION"
    echo "Add a '= $PHP_VERSION =' heading with at least one '* ' line under '== Changelog =='." >&2
    exit 1
fi

echo "$PHP_VERSION"
