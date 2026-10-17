#!/bin/bash
# SessionStart hook for Claude Code cloud sessions. Installs dependencies and
# works around the cloud container's network setup so that Jest, PHPUnit and
# wp-env can run. Does nothing outside the cloud.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

HOOK_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$HOOK_DIR/../.." && pwd)}"

export COMPOSER_ALLOW_SUPERUSER=1

# --- Node / wp-env -----------------------------------------------------------

# There is no DNS in the container, so wp-env thinks it is offline. Answer its
# online check and send Node's HTTP requests through the session proxy.
{
  echo "export NODE_USE_ENV_PROXY=1"
  echo "export NODE_OPTIONS=\"\${NODE_OPTIONS:-} --require $HOOK_DIR/wp-env-online.cjs\""
  echo "export COMPOSER_ALLOW_SUPERUSER=1"
} >> "$CLAUDE_ENV_FILE"

npm --prefix "$ROOT" install --ignore-scripts --no-audit --no-fund
npm --prefix "$ROOT/packages/join-flow" install --no-audit --no-fund
npm --prefix "$ROOT/packages/join-e2e" install --no-audit --no-fund

# --- PHP ---------------------------------------------------------------------

# The session's GitHub proxy refuses tarball downloads for third-party repos;
# build them from git instead (see seed-composer-cache.sh).
cd "$ROOT/packages/join-block"
"$HOOK_DIR/seed-composer-cache.sh" composer.lock
composer install --no-interaction

# --- Docker (wp-env) ---------------------------------------------------------

# wp-env needs a Docker daemon; the container doesn't start one by default.
if ! docker info >/dev/null 2>&1; then
  setsid nohup dockerd >/tmp/dockerd.log 2>&1 < /dev/null &
  for _ in $(seq 1 30); do
    docker info >/dev/null 2>&1 && break
    sleep 1
  done
fi

# Containers have no DNS and can't reach the session proxy on 127.0.0.1, so
# forward the Docker bridge gateway to it.
GATEWAY=$(docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}')
FORWARD_PORT=3128
if ! (exec 3<>"/dev/tcp/$GATEWAY/$FORWARD_PORT") 2>/dev/null; then
  setsid nohup node "$HOOK_DIR/docker-proxy-forward.cjs" "$GATEWAY" "$FORWARD_PORT" "$HTTPS_PROXY" \
    >/tmp/docker-proxy-forward.log 2>&1 < /dev/null &
fi
CONTAINER_PROXY="http://$GATEWAY:$FORWARD_PORT"

# wp-env builds its images FROM these two, so rebuild them locally to use the
# forwarded proxy and trust its CA (the proxy re-terminates TLS). Composer in
# the images also hits the GitHub tarball block, so make it install from git.
# Bump IMAGE_PATCH when changing the Dockerfile below to force a rebuild.
IMAGE_PATCH="$CONTAINER_PROXY#2"
CA_CONTEXT=$(mktemp -d)
cp /root/.ccr/ca-bundle.crt "$CA_CONTEXT/proxy-ca.crt"
cat > "$CA_CONTEXT/Dockerfile" <<'EOF'
ARG BASE
FROM ${BASE}
ARG PROXY
ARG PATCH
USER root
ENV https_proxy=${PROXY} HTTPS_PROXY=${PROXY} \
    no_proxy=localhost,127.0.0.1,mysql,tests-mysql,wordpress,tests-wordpress \
    NO_PROXY=localhost,127.0.0.1,mysql,tests-mysql,wordpress,tests-wordpress
COPY proxy-ca.crt /usr/local/share/ca-certificates/proxy-ca.crt
RUN cat /usr/local/share/ca-certificates/proxy-ca.crt >> /etc/ssl/certs/ca-certificates.crt \
 && if [ -d /etc/apt ]; then \
      sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources; \
      echo "Acquire::https::Proxy \"$PROXY\";" > /etc/apt/apt.conf.d/99proxy; \
    fi \
 && if command -v apk >/dev/null; then apk add --no-cache git; fi \
 && mkdir -p /root/.composer \
 && echo '{"config":{"preferred-install":"source"}}' > /root/.composer/config.json
LABEL ck.join.image-patch=${PATCH}
EOF
for image in wordpress:latest wordpress:cli; do
  if [ "$(docker image inspect -f '{{index .Config.Labels "ck.join.image-patch"}}' "$image" 2>/dev/null)" != "$IMAGE_PATCH" ]; then
    docker pull -q "$image"
    docker build -q --build-arg BASE="$image" --build-arg PROXY="$CONTAINER_PROXY" \
      --build-arg PATCH="$IMAGE_PATCH" -t "$image" "$CA_CONTEXT"
  fi
done
rm -rf "$CA_CONTEXT"
