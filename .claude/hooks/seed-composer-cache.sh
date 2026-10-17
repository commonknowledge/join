#!/bin/bash
# Used by session-start.sh in Claude Code cloud sessions only.
# The session's GitHub proxy refuses tarball downloads (api.github.com
# zipballs) for third-party repos, but git fetches work. For each package in
# composer.lock with a GitHub dist, shallow-fetch the locked commit, build the
# same zip with `git archive`, and put it in Composer's download cache under the
# key Composer looks for (sha1 of the dist URL). `composer install` then
# installs every package from the cache as normal.
#
# Usage: seed-composer-cache.sh <path/to/composer.lock>
set -euo pipefail

LOCK="$1"
CACHE_ROOT="$(composer config --global cache-files-dir)"

seed() {
  local name="$1" url="$2" cache_root="$3"
  local repo="${url#https://api.github.com/repos/}"
  repo="${repo%/zipball/*}"
  local ref="${url##*/}"
  local file="$cache_root/$name/$(printf %s "$url" | sha1sum | cut -d' ' -f1).zip"
  [ -f "$file" ] && return 0
  local tmp
  tmp=$(mktemp -d)
  git -C "$tmp" init -q
  git -C "$tmp" fetch -q --depth 1 "https://github.com/$repo.git" "$ref"
  mkdir -p "$(dirname "$file")"
  git -C "$tmp" archive --format=zip --prefix="${repo#*/}/" FETCH_HEAD -o "$file.tmp"
  mv "$file.tmp" "$file"
  rm -rf "$tmp"
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
