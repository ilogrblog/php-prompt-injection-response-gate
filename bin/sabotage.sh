#!/usr/bin/env bash
# Weakens a copy of ResponseGate.php one way at a time, and checks that check.php fails each time.
#
# Everything happens inside a throwaway container: the repository is mounted read-only, the
# weakened copies live in the container's own /tmp, and there is no network. Nothing on your
# machine is changed. Docker downloads the PHP image only if you allow it:
#   ALLOW_IMAGE_PULL=1 ./bin/sabotage.sh
set -euo pipefail
cd "$(dirname "$0")/.."

die() { printf 'STOP  %s\n' "$1" >&2; exit 2; }
command -v docker >/dev/null 2>&1 || die "Docker is not installed."
docker info >/dev/null 2>&1 || die "Docker is not running. Start Docker and run the script again."
image="php:${PHP_VERSION:-8.5}-cli"
if ! docker image inspect "$image" >/dev/null 2>&1 && [ "${ALLOW_IMAGE_PULL:-0}" != "1" ]; then
	die "Image $image is not on this machine. Run again with ALLOW_IMAGE_PULL=1 to download it from Docker Hub."
fi

echo "Weakened copies are written inside a throwaway container only; nothing on this machine changes."
exec docker run --rm --network none --tmpfs /tmp -e SABOTAGE_IN_THROWAWAY_CONTAINER=1 -v "$PWD":/app:ro -w /app "$image" php bin/sabotage.php /app
