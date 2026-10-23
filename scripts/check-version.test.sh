#!/bin/bash

# Tests for check-version.sh. Run with: ./scripts/check-version.test.sh

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CHECK="$SCRIPT_DIR/check-version.sh"
FIXTURES=$(mktemp -d)
trap 'rm -rf "$FIXTURES"' EXIT
FAILURES=0

# make_fixture <join.php version> <readme.txt stable tag> <index.tsx release> <changelog heading> [changelog line]
make_fixture() {
    local root
    root=$(mktemp -d "$FIXTURES/project.XXXXXX")
    mkdir -p "$root/packages/join-block" "$root/packages/join-flow/src"
    cat > "$root/packages/join-block/join.php" <<EOF
<?php

/**
 * Plugin Name:     Common Knowledge Join Flow
 * Version:         $1
 */
EOF
    cat > "$root/packages/join-block/readme.txt" <<EOF
=== Common Knowledge Join Flow ===
Stable tag: $2

== Changelog ==

= $4 =
${5-* Something changed}

= 1.0.0 =
* First release

== Upgrade Notice ==

= $1 =
* An upgrade notice, not a changelog entry
EOF
    cat > "$root/packages/join-flow/src/index.tsx" <<EOF
init({
    release: "$3"
});
EOF
    echo "$root"
}

expect_pass() {
    local name="$1" root="$2" expected="$3" output
    if output=$("$CHECK" "$root" 2>&1) && [ "$output" = "$expected" ]; then
        echo "ok - $name"
    else
        echo "not ok - $name: expected success printing '$expected', got: $output"
        FAILURES=$((FAILURES + 1))
    fi
}

expect_fail() {
    local name="$1" root="$2" message="$3" output
    if output=$("$CHECK" "$root" 2>&1); then
        echo "not ok - $name: expected failure, got: $output"
        FAILURES=$((FAILURES + 1))
    elif [[ "$output" != *"$message"* ]]; then
        echo "not ok - $name: expected message containing '$message', got: $output"
        FAILURES=$((FAILURES + 1))
    else
        echo "ok - $name"
    fi
}

expect_pass "matching versions with a changelog entry" \
    "$(make_fixture 1.2.3 1.2.3 1.2.3 1.2.3)" "1.2.3"

expect_fail "readme.txt stable tag differs" \
    "$(make_fixture 1.2.3 1.2.2 1.2.3 1.2.3)" "Version numbers disagree"

expect_fail "index.tsx release differs" \
    "$(make_fixture 1.2.3 1.2.3 1.2.2 1.2.3)" "Version numbers disagree"

expect_fail "no changelog entry for the version" \
    "$(make_fixture 1.2.3 1.2.3 1.2.3 1.2.2)" "no changelog entry for 1.2.3"

expect_fail "changelog heading with no entries" \
    "$(make_fixture 1.2.3 1.2.3 1.2.3 1.2.3 "")" "no changelog entry for 1.2.3"

expect_fail "version header is not X.Y.Z" \
    "$(make_fixture 1.2 1.2 1.2 1.2)" "Could not read an X.Y.Z version"

expect_pass "real project files" "$SCRIPT_DIR/.." \
    "$(sed -n 's/^Stable tag: *//p' "$SCRIPT_DIR/../packages/join-block/readme.txt")"

if [ "$FAILURES" -gt 0 ]; then
    echo "$FAILURES test(s) failed"
    exit 1
fi
echo "All tests passed"
