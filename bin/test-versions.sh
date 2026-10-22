#!/usr/bin/env bash
# Runs the checks on several PHP versions in Docker, plus the "PHP too old" message on 7.4.
#
# The repository is mounted read-only and the containers have no network, so the run
# changes nothing on your machine. Docker downloads a PHP image only if you allow it:
#   ALLOW_IMAGE_PULL=1 ./bin/test-versions.sh
# Other PHP versions: PHP_VERSIONS="8.2 8.4" ./bin/test-versions.sh
set -euo pipefail
cd "$(dirname "$0")/.."

die() { printf 'STOP  %s\n' "$1" >&2; exit 2; }
command -v docker >/dev/null 2>&1 || die "Docker is not installed. Run 'php check.php' with a local PHP 8.1 or later instead."
docker info >/dev/null 2>&1 || die "Docker is not running. Start Docker and run the script again."

versions="${PHP_VERSIONS:-8.1 8.2 8.3 8.4 8.5}"
fails=0

# Refuse to download anything unless asked to.
for v in $versions 7.4; do
	if ! docker image inspect "php:${v}-cli" >/dev/null 2>&1 && [ "${ALLOW_IMAGE_PULL:-0}" != "1" ]; then
		die "Image php:${v}-cli is not on this machine. Run again with ALLOW_IMAGE_PULL=1 to download it from Docker Hub."
	fi
done

run_php() {
	docker run --rm --network none --read-only --tmpfs /tmp \
		-v "$PWD":/app:ro -w /app "php:$1-cli" php "${@:2}"
}

for v in $versions; do
	printf '== PHP %s: ' "$v"
	run_php "$v" -r 'echo PHP_VERSION, PHP_EOL;'
	run_php "$v" -l ResponseGate.php >/dev/null || fails=$((fails + 1))
	run_php "$v" -l check.php >/dev/null || fails=$((fails + 1))
	if out=$(run_php "$v" check.php 2>&1); then
		printf '%s\n' "$out" | tail -1
	else
		printf '%s\n' "$out" | grep -v '^PASS' >&2
		fails=$((fails + 1))
	fi
done

printf '== PHP 7.4 (too old, must stop with exit status 2): '
set +e
old=$(run_php 7.4 check.php 2>&1)
status=$?
set -e
printf '%s\n' "$old"
[ "$status" = "2" ] || { echo "FAIL  expected exit status 2, got $status" >&2; fails=$((fails + 1)); }

if [ "$fails" -gt 0 ]; then echo "FAILED: $fails problem(s)." >&2; exit 1; fi
echo "All versions passed."
