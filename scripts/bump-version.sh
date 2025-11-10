#!/bin/bash

set -e

print_error() {
    echo "ERROR: $1" >&2
}

print_warning() {
    echo "WARNING: $1" >&2
}

print_step() {
    echo "$1"
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

README_TXT="$PROJECT_ROOT/packages/join-block/readme.txt"
JOIN_PHP="$PROJECT_ROOT/packages/join-block/join.php"
INDEX_TSX="$PROJECT_ROOT/packages/join-flow/src/index.tsx"

if [ ! -f "$README_TXT" ]; then
    print_error "readme.txt not found at $README_TXT"
    exit 1
fi

if [ ! -f "$JOIN_PHP" ]; then
    print_error "join.php not found at $JOIN_PHP"
    exit 1
fi

if [ ! -f "$INDEX_TSX" ]; then
    print_error "index.tsx not found at $INDEX_TSX"
    exit 1
fi

CURRENT_VERSION=$(grep "^Stable tag:" "$README_TXT" | sed 's/Stable tag: //')

if [ -z "$CURRENT_VERSION" ]; then
    print_error "Could not read current version from readme.txt"
    exit 1
fi

print_step "Current version: $CURRENT_VERSION"

if [ -z "$1" ]; then
    IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT_VERSION"
    NEW_PATCH=$((PATCH + 1))
    NEW_VERSION="$MAJOR.$MINOR.$NEW_PATCH"
    print_step "No version specified, bumping patch version to: $NEW_VERSION"
else
    NEW_VERSION="$1"
    
    if ! [[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        print_error "Version must be in format X.Y.Z (e.g., 1.3.4)"
        exit 1
    fi
fi

echo ""
echo "Enter changelog entries for version $NEW_VERSION (one per line, empty line to finish):"
CHANGELOG_ENTRIES=()
while IFS= read -r line; do
    if [ -z "$line" ]; then
        break
    fi
    CHANGELOG_ENTRIES+=("$line")
done

if [ ${#CHANGELOG_ENTRIES[@]} -eq 0 ]; then
    print_warning "No changelog entries provided"
fi

print_step "Updating version to $NEW_VERSION in 3 files..."

print_step "1. Updating readme.txt (Stable tag and changelog)"
sed -i.bak "s/^Stable tag: .*/Stable tag: $NEW_VERSION/" "$README_TXT"

if [ ${#CHANGELOG_ENTRIES[@]} -gt 0 ]; then
    CHANGELOG_LINE=$(grep -n "^== Changelog ==" "$README_TXT" | cut -d: -f1)
    
    if [ -z "$CHANGELOG_LINE" ]; then
        print_error "Could not find Changelog section in readme.txt"
        rm -f "$README_TXT.bak"
        exit 1
    fi
    
    INSERT_LINE=$((CHANGELOG_LINE + 2))
    
    {
        head -n "$CHANGELOG_LINE" "$README_TXT"
        echo ""
        echo "= $NEW_VERSION ="
        for entry in "${CHANGELOG_ENTRIES[@]}"; do
            echo "* $entry"
        done
        tail -n +$INSERT_LINE "$README_TXT"
    } > "$README_TXT.tmp"
    
    mv "$README_TXT.tmp" "$README_TXT"
fi

rm -f "$README_TXT.bak"

print_step "2. Updating join.php (Version)"
sed -i.bak "s/^ \* Version: .*/ * Version:         $NEW_VERSION/" "$JOIN_PHP"
rm -f "$JOIN_PHP.bak"

print_step "3. Updating index.tsx (Sentry release)"
sed -i.bak "s/release: \".*\"/release: \"$NEW_VERSION\"/" "$INDEX_TSX"
rm -f "$INDEX_TSX.bak"

print_step "Version bump complete!"
echo ""
echo "Files updated:"
echo "  - packages/join-block/readme.txt"
echo "  - packages/join-block/join.php"
echo "  - packages/join-flow/src/index.tsx"
echo ""
echo "Next steps:"
echo "  1. Review changes: git diff"
echo "  2. Commit: git add -A && git commit -m 'Bump version to $NEW_VERSION'"
echo "  3. Tag: git tag $NEW_VERSION"
echo "  4. Push: git push && git push --tags"

