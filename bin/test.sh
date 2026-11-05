#!/usr/bin/env bash
# Runs the PHP test suites against the WordPress test site in .test-env/.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
exec php -d auto_prepend_file="$ROOT/tests/php/wp-boot.php" "$ROOT/vendor/bin/phpunit" -c "$ROOT/tests/phpunit.xml.dist" "$@"
