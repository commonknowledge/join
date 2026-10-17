#!/bin/bash
# Used by session-start.sh in Claude Code cloud sessions only.
# The session's GitHub proxy refuses tarball downloads (api.github.com
# zipballs) for third-party repos, but git fetches work. For each package in
# composer.lock with a GitHub dist, shallow-fetch the locked commit, build the
# same zip with `git archive`, and put it in Composer's download cache under the
# key Composer looks for (sha1 of the dist URL). `composer install` then
# installs every package from the cache as normal.
#
# Exits non-zero if any package can't be seeded. A zip only lands in the cache
# once it is complete and valid, and invalid cached zips are rebuilt.
#
# Usage: seed-composer-cache.sh <path/to/composer.lock>
set -euo pipefail

LOCK="$1"
CACHE_ROOT="$(composer config --global cache-files-dir)"

# Runs in its own bash process per package (via xargs), so it sets its own
# error handling and cleans up after itself.
seed() {
  set -euo pipefail
  local name="$1" url="$2" cache_root="$3"
  local repo="${url#https://api.github.com/repos/}"
  repo="${repo%/zipball/*}"
  local ref="${url##*/}"
  local file="$cache_root/$name/$(printf %s "$url" | sha1sum | cut -d' ' -f1).zip"

  if [ -f "$file" ]; then
    unzip -tqq "$file" >/dev/null 2>&1 && return 0
    echo "Rebuilding invalid cached zip for $name" >&2
    rm -f "$file"
  fi

  # Build next to the final path so the last step is an atomic rename.
  local tmp partial="$file.partial.$$"
  tmp=$(mktemp -d)
  # Expanded now: the trap runs at process exit, after these locals are gone.
  trap "rm -rf $(printf %q "$tmp") $(printf %q "$partial")" EXIT
  mkdir -p "$(dirname "$file")"
  if ! git -C "$tmp" init -q ||
    ! git -C "$tmp" fetch -q --depth 1 "https://github.com/$repo.git" "$ref" ||
    ! git -C "$tmp" archive --format=zip --prefix="${repo#*/}/" FETCH_HEAD -o "$partial" ||
    ! unzip -tqq "$partial" >/dev/null; then
    echo "Failed to seed $name from https://github.com/$repo.git at $ref" >&2
    return 1
  fi
  mv "$partial" "$file"
}
export -f seed

php -r '
  $lock = json_decode(file_get_contents($argv[1]), true);
  foreach (array_merge($lock["packages"], $lock["packages-dev"] ?? []) as $p) {
    $url = $p["dist"]["url"] ?? "";
    if (str_starts_with($url, "https://api.github.com/repos/")) {
      echo $p["name"], " ", $url, "\n";
    }
  }' "$LOCK" |
  xargs -P 8 -L 1 bash -c 'seed "$0" "$1" "'"$CACHE_ROOT"'"'
