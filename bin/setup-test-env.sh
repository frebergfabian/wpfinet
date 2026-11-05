#!/usr/bin/env bash
# Creates a disposable WordPress test site in .test-env/ (WordPress + SQLite, no MySQL needed).
# Usage: bin/setup-test-env.sh [--fresh]
# Optional env: WP_VERSION (default 7.1.4), WP_CORE_SRC / SQLITE_SRC (local clones to copy instead of git clone),
#               WP_CLI (path to wp-cli.phar), PLUGIN_SRC (plugin directory to link, default ./finet-troubleshoot).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_DIR="$ROOT/.test-env"
WP_VERSION="${WP_VERSION:-7.1.4}"
PLUGIN_SRC="${PLUGIN_SRC:-$ROOT/finet-troubleshoot}"
WP_DIR="$ENV_DIR/wordpress"
if [[ "${1:-}" == "--fresh" ]]; then rm -rf "$ENV_DIR"; fi
mkdir -p "$ENV_DIR"

if [[ ! -f "$WP_DIR/wp-load.php" ]]; then
  if [[ -n "${WP_CORE_SRC:-}" ]]; then cp -r "$WP_CORE_SRC" "$WP_DIR"; rm -rf "$WP_DIR/.git";
  else git clone -q --depth 1 --branch "$WP_VERSION" https://github.com/WordPress/WordPress "$WP_DIR"; rm -rf "$WP_DIR/.git"; fi
fi
SQLITE_PLUGIN="$WP_DIR/wp-content/plugins/sqlite-database-integration"
if [[ ! -f "$SQLITE_PLUGIN/load.php" ]]; then
  TMP="$ENV_DIR/sqlite-src"
  if [[ -n "${SQLITE_SRC:-}" ]]; then rm -rf "$TMP"; cp -r "$SQLITE_SRC" "$TMP";
  elif [[ ! -d "$TMP" ]]; then git clone -q --depth 1 https://github.com/WordPress/sqlite-database-integration "$TMP"; fi
  rm -rf "$SQLITE_PLUGIN"; cp -r "$TMP/packages/plugin-sqlite-database-integration" "$SQLITE_PLUGIN"
  rm -rf "$SQLITE_PLUGIN/wp-includes/database"; mkdir -p "$SQLITE_PLUGIN/wp-includes"
  cp -r "$TMP/packages/mysql-on-sqlite/src" "$SQLITE_PLUGIN/wp-includes/database"
  cp "$SQLITE_PLUGIN/db.copy" "$WP_DIR/wp-content/db.php"
  sed -i "s#'{SQLITE_IMPLEMENTATION_FOLDER_PATH}'#__DIR__.'/plugins/sqlite-database-integration'#g; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" "$WP_DIR/wp-content/db.php"
fi
WP_CLI="${WP_CLI:-$ENV_DIR/wp-cli.phar}"
if [[ ! -f "$WP_CLI" ]]; then curl -sSL -o "$WP_CLI" https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar; fi
cp -f "$WP_CLI" "$ENV_DIR/wp-cli.phar" 2>/dev/null || true

if [[ ! -f "$WP_DIR/wp-config.php" ]]; then
  cat > "$WP_DIR/wp-config.php" <<PHP
<?php
define( 'DB_NAME', 'wordpress' ); define( 'DB_USER', '' ); define( 'DB_PASSWORD', '' ); define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' ); define( 'DB_FILE', 'test.sqlite' );
define( 'AUTH_KEY', 'test-auth-key' ); define( 'SECURE_AUTH_KEY', 'test-secure-auth-key' ); define( 'LOGGED_IN_KEY', 'test-logged-in' ); define( 'NONCE_KEY', 'test-nonce' );
define( 'AUTH_SALT', 'test-auth-salt' ); define( 'SECURE_AUTH_SALT', 'test-secure-auth-salt' ); define( 'LOGGED_IN_SALT', 'test-li-salt' ); define( 'NONCE_SALT', 'test-nonce-salt' );
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', __DIR__ . '/wp-content/debug.log' ); define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true ); define( 'WP_ENVIRONMENT_TYPE', 'local' );
if ( getenv( 'WPFINET_TEST_HTTP_BLOCK' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP
fi
mkdir -p "$WP_DIR/wp-content/database"
ln -sfn "$PLUGIN_SRC" "$WP_DIR/wp-content/plugins/finet-troubleshoot"
WP="php $ENV_DIR/wp-cli.phar --path=$WP_DIR --allow-root"
if ! $WP core is-installed 2>/dev/null; then
  $WP core install --url=http://127.0.0.1:8890 --title="WPFINET Test" --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email >/dev/null
  $WP rewrite structure '/%postname%/' >/dev/null
  $WP option update timezone_string 'Europe/Stockholm' >/dev/null
  $WP option update date_format 'Y-m-d' >/dev/null
  $WP option update time_format 'H:i' >/dev/null
fi
$WP option update home http://127.0.0.1:8890 >/dev/null
$WP option update siteurl http://127.0.0.1:8890 >/dev/null
$WP plugin activate finet-troubleshoot >/dev/null
$WP rewrite flush >/dev/null 2>&1 || true
cp "$WP_DIR/wp-content/database/test.sqlite" "$ENV_DIR/clean.sqlite"
echo "Test site ready: $WP_DIR (snapshot: $ENV_DIR/clean.sqlite)"
