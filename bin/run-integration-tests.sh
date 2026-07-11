#!/usr/bin/env bash
#
# Drives the integration phpunit suite inside the wp-env cli container.
# Integration tests boot real WordPress (wp-load.php) and need the
# wp-env MariaDB + PHP runtime; running phpunit on the host would fail
# to require wp-load.php.
#
# The suite boots the DEV site's WordPress (the port-8888 site in the
# `…-cli-1` container), so a full run overwrites the dev site's
# `smly_rec_*` options with fixture values (`re-fixture.test`, fixture
# tenant "MiuMjau") — the F3-53 / LESSONS §2.17 failure mode that twice
# destroyed the real sandbox engine connection. This wrapper therefore
# (PRO-1240):
#   1. snapshots the dev site's `smly_rec_*` options to a durable file
#      OUTSIDE the repo (~/.local/state/smaily-connect/, mode 600 — it
#      contains the encrypted API key) before the suite, never
#      overwriting a good snapshot with a fixture/empty one;
#   2. after the suite (even on failure — EXIT trap), restores the
#      options secret-safely (JSON piped over STDIN into
#      `docker exec -i … wp eval-file bin/restore-smly-rec-options.php`,
#      never on a command line) and prints the restored tenant_name,
#      warning loudly on "MiuMjau" (production) or a fixture value.
#
# Usage:
#   bash bin/run-integration-tests.sh [--filter Pattern]
#
# Exit codes (suite's own exit code is preserved):
#   0 — all tests passed
#   1 — at least one test failed
#   2 — wp-env not running (start it with `npx @wordpress/env start`)
#

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_PATH_IN_CONTAINER="/var/www/html/wp-content/plugins/smaily-connect"

# Durable snapshot home — outside the repo, survives /tmp cleanup.
STATE_DIR="${HOME}/.local/state/smaily-connect"
SNAPSHOT_FILE="${STATE_DIR}/smly_rec_snapshot.json"
SNAPSHOT_PREV="${SNAPSHOT_FILE%.json}.prev.json"

# Pick `docker` directly when the current user is already in the docker
# group (CI runners, dev machines that have rebooted since `usermod -aG`)
# and fall back to `sg docker` when an interactive shell predates the
# group-add (Erkki's local machine).
if docker ps >/dev/null 2>&1; then
  DOCKER_DIRECT=1
else
  DOCKER_DIRECT=0
fi

# Run a docker command string either directly or through `sg docker`.
# STDIN/STDOUT pass through both paths, so callers may pipe into or out
# of this (the secret-safe restore relies on the STDIN passthrough).
run_docker() {
  if [[ "$DOCKER_DIRECT" == "1" ]]; then
    bash -c "$1"
  else
    sg docker -c "$1"
  fi
}

# wp-env names its containers wp-env-<project-hash>-wordpress-1. The
# hash is derived from the project install path; find it dynamically
# rather than hard-coding so this script works across machines.
CONTAINER_NAME=""
candidates=$(run_docker "docker ps --filter name=wp-env- --filter name=-wordpress-1 --format {{.Names}}" 2>/dev/null || true)
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

# ---------------------------------------------------------------------------
# smly_rec_* snapshot / restore (PRO-1240)
# ---------------------------------------------------------------------------

# Classify a snapshot JSON file by its NON-SECRET fields. Prints one line:
#   GOOD|FIXTURE|PRODUCTION|DISCONNECTED|EMPTY tenant=<name> base=<url>
# Reads only tenant/base/connected — never touches the api_key value.
classify_snapshot() {
  php -r '
    $rows = json_decode( (string) @file_get_contents( $argv[1] ), true );
    if ( ! is_array( $rows ) || $rows === array() ) { echo "EMPTY tenant= base="; exit; }
    $o = array();
    foreach ( $rows as $r ) {
      if ( is_array( $r ) && isset( $r["option_name"] ) ) {
        $o[ $r["option_name"] ] = (string) ( $r["option_value"] ?? "" );
      }
    }
    $tenant = $o["smly_rec_tenant_name"] ?? "";
    $base   = $o["smly_rec_engine_base_url"] ?? "";
    $conn   = $o["smly_rec_connected"] ?? "";
    $tail   = " tenant=" . $tenant . " base=" . $base;
    if ( $conn === "" || $conn === "0" )                { echo "DISCONNECTED" . $tail; exit; }
    if ( strpos( $base, ".test" ) !== false )           { echo "FIXTURE" . $tail; exit; }
    if ( $tenant === "MiuMjau" )                        { echo "PRODUCTION" . $tail; exit; }
    echo "GOOD" . $tail;
  ' "$1"
}

# Restore source decided during the snapshot phase; empty = skip restore.
RESTORE_SOURCE=""

snapshot_options() {
  if ! command -v php >/dev/null 2>&1; then
    echo "WARNING: no host php — cannot snapshot smly_rec_* options. The suite" >&2
    echo "WARNING: will overwrite the dev site's engine connection (F3-53)." >&2
    return 0
  fi

  umask 077
  mkdir -p "$STATE_DIR"

  local tmp="${STATE_DIR}/smly_rec_snapshot.tmp.json"
  if ! run_docker "docker exec $CONTAINER_NAME wp option list --search='smly_rec_*' --fields=option_name,option_value,autoload --format=json --allow-root" > "$tmp" 2>/dev/null; then
    echo "WARNING: could not read smly_rec_* options from $CONTAINER_NAME — no snapshot taken." >&2
    rm -f "$tmp"
    return 0
  fi
  chmod 600 "$tmp"

  local state
  state=$(classify_snapshot "$tmp")
  echo "smly_rec_* pre-suite state: $state"

  case "$state" in
    GOOD*)
      # Rotate: keep the previous good snapshot as .prev.json.
      if [[ -f "$SNAPSHOT_FILE" ]]; then
        mv -f "$SNAPSHOT_FILE" "$SNAPSHOT_PREV"
      fi
      mv -f "$tmp" "$SNAPSHOT_FILE"
      chmod 600 "$SNAPSHOT_FILE"
      RESTORE_SOURCE="$SNAPSHOT_FILE"
      echo "Snapshotted dev-site smly_rec_* options to $SNAPSHOT_FILE (mode 600)."
      ;;
    FIXTURE*|PRODUCTION*)
      # Never let a fixture (or a production-tenant!) state clobber a good
      # snapshot — a re-fixture.test snapshot is worse than none.
      rm -f "$tmp"
      echo "WARNING: dev site currently holds a ${state%% *} smly_rec_* state — NOT saved as snapshot." >&2
      if [[ -f "$SNAPSHOT_FILE" ]] && [[ "$(classify_snapshot "$SNAPSHOT_FILE")" == GOOD* ]]; then
        RESTORE_SOURCE="$SNAPSHOT_FILE"
        echo "A good previous snapshot exists — the dev site will be restored from it after the suite."
      else
        echo "WARNING: no good previous snapshot at $SNAPSHOT_FILE — nothing to restore after the suite." >&2
        echo "WARNING: the dev site's engine connection will need a fresh SANDBOX setup token (CLAUDE.md live-walk note)." >&2
      fi
      ;;
    *)
      # DISCONNECTED / EMPTY: the dev site has no engine connection right
      # now — likely intentional. Don't auto-reconnect it from an old
      # snapshot, and don't clobber the good snapshot with this state.
      rm -f "$tmp"
      echo "Dev site has no engine connection (${state%% *}) — nothing to snapshot; no restore after the suite."
      if [[ -f "$SNAPSHOT_FILE" ]]; then
        echo "(A previous snapshot exists at $SNAPSHOT_FILE if you want to restore manually — see bin/restore-smly-rec-options.php.)"
      fi
      ;;
  esac
}

restore_options() {
  # Runs from the EXIT trap: must never change the script's exit code, so
  # every step is failure-tolerant.
  if [[ -z "$RESTORE_SOURCE" || ! -f "$RESTORE_SOURCE" ]]; then
    return 0
  fi
  echo ""
  echo "Restoring dev-site smly_rec_* options from $RESTORE_SOURCE ..."
  local out
  # Secret-safe: the snapshot JSON travels over STDIN into the container —
  # never on a command line, never in process args, never echoed.
  if ! out=$(run_docker "docker exec -i $CONTAINER_NAME wp eval-file $PLUGIN_PATH_IN_CONTAINER/bin/restore-smly-rec-options.php --allow-root" < "$RESTORE_SOURCE" 2>&1); then
    echo "WARNING: smly_rec_* restore FAILED — the dev site may be left with fixture values:" >&2
    echo "$out" >&2
    return 0
  fi
  echo "$out"

  local tenant
  tenant=$(printf '%s\n' "$out" | sed -n 's/^tenant_name=//p' | head -1)
  if [[ "$tenant" == "MiuMjau" ]]; then
    echo "" >&2
    echo "!!! WARNING: restored tenant_name is 'MiuMjau' — that is the pilot's PRODUCTION" >&2
    echo "!!! tenant. The dev wp-env must NEVER point at production. Reconnect to the" >&2
    echo "!!! 'Smaily Connect test' sandbox before any send/walk. (CLAUDE.md live-walk note.)" >&2
  elif [[ -z "$tenant" ]] || printf '%s' "$tenant" | grep -qi 'fixture'; then
    echo "" >&2
    echo "!!! WARNING: restored tenant_name looks like a fixture/empty value ('$tenant')." >&2
    echo "!!! The dev site's engine connection is NOT healthy — check the snapshot." >&2
  else
    echo "Restore verified: tenant_name='$tenant'."
  fi
}

snapshot_options
trap restore_options EXIT

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

# -d memory_limit: WP 7.0 core is heavier than 6.9 — the suite exhausted
# the container's 128M default during in-process REST dispatch when the
# baseline moved to WP 7.0 (2026-06-11). 512M gives ample headroom.
CMD="docker exec \"$CONTAINER_NAME\" \
  php -d memory_limit=512M \"$PLUGIN_PATH_IN_CONTAINER/vendor/bin/phpunit\" \
    --configuration \"$PLUGIN_PATH_IN_CONTAINER/phpunit.integration.xml.dist\" \
    ${QUOTED_ARGS}"

# No `exec` here (it would skip the EXIT trap and the restore): run the
# suite, remember its exit code, let the trap restore, then exit with it.
suite_rc=0
run_docker "$CMD" || suite_rc=$?

exit "$suite_rc"
