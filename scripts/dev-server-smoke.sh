#!/bin/bash
# Starts the join-flow dev server and checks it serves the page, compiles the
# bundle and answers the stubbed REST endpoints. Designers, engineers and
# Claude Code cloud sessions all rely on the dev server, and nothing else in
# CI runs it.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT=3000
BASE="http://localhost:$PORT"
LOG="$(mktemp)"

if curl -fsS -o /dev/null "$BASE/" 2>/dev/null; then
  echo "FAIL: something is already listening on port $PORT; stop it first"
  exit 1
fi

cd "$ROOT/packages/join-flow"
# Run webpack's own binary rather than npx so $! is the server process.
# --no-color keeps the log greppable: webpack colours its output on CI.
node node_modules/.bin/webpack serve --config ./webpack/dev.js --no-color >"$LOG" 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT

fail() {
  echo "FAIL: $1"
  echo "--- dev server output ---"
  cat "$LOG"
  exit 1
}

# webpack-dev-server holds requests until the first compile finishes, so
# waiting for the page also waits for the bundle.
for _ in $(seq 1 120); do
  if curl -fsS -o /dev/null "$BASE/" 2>/dev/null; then
    break
  fi
  kill -0 "$SERVER_PID" 2>/dev/null || fail "dev server exited"
  sleep 1
done

PAGE="$(curl -fsS "$BASE/")" || fail "page did not load"
grep -q 'src="bundle.js"' <<<"$PAGE" ||
  fail "page does not include bundle.js"
echo "ok: page"

BUNDLE="$(curl -fsS "$BASE/bundle.js")" || fail "bundle.js did not load"
grep -q 'webpack/hot' <<<"$BUNDLE" ||
  fail "bundle.js did not compile with hot reloading"
echo "ok: bundle"

# The server answers before webpack has written its summary, so wait for it.
for _ in $(seq 1 30); do
  grep -qE "compiled (successfully|with)" "$LOG" && break
  sleep 1
done
grep -q "compiled successfully" "$LOG" ||
  fail "webpack reported errors or warnings"
echo "ok: compiled without errors"

[ "$(curl -fsS -X POST -H 'Content-Type: application/json' -d '{"a":1}' \
  "$BASE/join/v1/join")" = "{}" ] ||
  fail "stubbed REST endpoint did not answer"
echo "ok: stubbed REST endpoint"
