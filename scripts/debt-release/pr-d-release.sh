#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
RUNNER="${SCRIPT_DIR}/debt-release.php"

if [[ ! -f "${RUNNER}" ]]; then
    printf 'RELEASE_STATUS=BLOCKED\nBLOCKER=RUNNER_NOT_FOUND\n' >&2
    exit 40
fi

cd "${REPOSITORY_ROOT}"
exec php "${RUNNER}" "$@"
