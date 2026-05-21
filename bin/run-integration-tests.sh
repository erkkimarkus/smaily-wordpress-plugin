#!/usr/bin/env bash
#
# Drives the integration phpunit suite inside the wp-env wordpress
# container. Integration tests boot real WordPress (wp-load.php) and
# need the wp-env MariaDB + PHP runtime; running phpunit on the host
# would fail to require wp-load.php.
#
# Usage:
#   bash bin/run-integration-tests.sh [--filter Pattern]
#
# Exit codes:
#   0 — all tests passed
#   1 — at least one test failed
#   2 — wp-env not running (start it with `npx @wordpress/env start`)
#

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_PATH_IN_CONTAINER="/var/www/html/wp-content/plugins/smaily-connect"

# Pick `docker` directly when the current user is already in the docker
# group (CI runners, dev machines that have rebooted since `usermod -aG`)
# and fall back to `sg docker` when an interactive shell predates the
# group-add (Erkki's local machine).
if docker ps >/dev/null 2>&1; then
  SG_PREFIX=""
else
  SG_PREFIX="sg docker -c "
fi

DOCKER_LIST_CMD='docker ps --filter name=wp-env- --filter name=-wordpress-1 --format {{.Names}}'

# wp-env names its containers wp-env-<project-hash>-wordpress-1. The
# hash is derived from the project install path; find it dynamically
# rather than hard-coding so this script works across machines.
CONTAINER_NAME=""
if [[ -n "$SG_PREFIX" ]]; then
  candidates=$(sg docker -c "$DOCKER_LIST_CMD" 2>/dev/null)
else
  candidates=$(docker ps --filter 'name=wp-env-' --filter 'name=-wordpress-1' --format '{{.Names}}' 2>/dev/null)
fi
for candidate in $candidates; do
  # Skip the tests-wordpress container — wp-env spawns a separate
  # instance for its own automated tests; we want the dev one.
  if [[ "$candidate" == *-tests-wordpress-1 ]]; then
    continue
  fi
  # We exec into the cli sidecar (which shares the WordPress volume
  # mount) rather than the wordpress container itself, because the
  # cli image has wp-cli + composer pre-installed; the wordpress
  # one is the apache front-end. Either works for `php phpunit`, but
  # cli is the conventional choice for one-off commands.
  CONTAINER_NAME="${candidate/-wordpress-1/-cli-1}"
  break
done

if [[ -z "$CONTAINER_NAME" ]]; then
  echo "wp-env does not appear to be running. Start it first:" >&2
  echo "  npx @wordpress/env start" >&2
  exit 2
fi

echo "Running integration suite inside container: $CONTAINER_NAME"

# We pass through any extra CLI args (e.g. --filter) so a developer
# can iterate on a single test class without re-running the whole
# suite. Each arg is single-quoted into the sg-docker command string
# so the host shell doesn't interpret regex chars (e.g. "|") as
# pipeline separators.
QUOTED_ARGS=""
for arg in "$@"; do
  # Escape single quotes inside the arg before wrapping.
  escaped=${arg//\'/\'\\\'\'}
  QUOTED_ARGS="${QUOTED_ARGS} '${escaped}'"
done

CMD="docker exec \"$CONTAINER_NAME\" \
  php \"$PLUGIN_PATH_IN_CONTAINER/vendor/bin/phpunit\" \
    --configuration \"$PLUGIN_PATH_IN_CONTAINER/phpunit.integration.xml.dist\" \
    ${QUOTED_ARGS}"

if docker ps >/dev/null 2>&1; then
  exec bash -c "$CMD"
else
  exec sg docker -c "$CMD"
fi
