#!/usr/bin/env bash
# php -l on every plugin file (run with the PHP binary you deploy, e.g. PHP 8.5 on the IIS server).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FAIL=0
while IFS= read -r -d '' f; do php -l "$f" >/dev/null || FAIL=1; done < <(find "$ROOT/finet-troubleshoot" -name '*.php' -print0)
[[ $FAIL == 0 ]] && echo "PHP lint OK ($(php -r 'echo PHP_VERSION;'))"
exit $FAIL
