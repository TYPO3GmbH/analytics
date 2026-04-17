#!/bin/bash
set -euo pipefail

PROJECT_ROOT="/var/www/html"
DUMMY_DIR="$PROJECT_ROOT/.Build/dummy-typo3"
DDEV_PRIMARY_URL="${DDEV_PRIMARY_URL:-}"
if [ -n "$DDEV_PRIMARY_URL" ]; then
  DDEV_HOST="${DDEV_PRIMARY_URL#*://}"
  DDEV_HOST="${DDEV_HOST%%/*}"
else
  DDEV_HOST="analytics.ddev.site"
fi

echo "Project root:        $PROJECT_ROOT"
echo "Dummy TYPO3 dir:     $DUMMY_DIR"
echo "Detected DDEV host:  $DDEV_HOST"

# ---------------------------------------------------------------------------
# 1. Create Composer project (once)
# ---------------------------------------------------------------------------
mkdir -p "$PROJECT_ROOT/.Build"

if [ ! -f "$DUMMY_DIR/composer.json" ]; then
  echo "--- Creating TYPO3 base distribution ---"

  if [ -d "$DUMMY_DIR" ]; then
    echo "ERROR: Broken directory found. Remove it on the host first:"
    echo "  rm -rf .Build/dummy-typo3"
    exit 1
  fi

  # Ask which TYPO3 major version to install
  read -rp "TYPO3 version to install (13/14) [13]: " TYPO3_VERSION_INPUT
  TYPO3_VERSION="${TYPO3_VERSION_INPUT:-13}"
  if [[ "$TYPO3_VERSION" != "13" && "$TYPO3_VERSION" != "14" ]]; then
    echo "Invalid version '$TYPO3_VERSION'. Must be 13 or 14. Defaulting to 13."
    TYPO3_VERSION="13"
  fi
  echo "Installing TYPO3 v${TYPO3_VERSION}…"

  # Wipe the database so TYPO3 setup starts on a clean slate
  echo "--- Clearing database ---"
  mysql --host=db --user=db --password=db -e "DROP DATABASE IF EXISTS db; CREATE DATABASE db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  echo "Database cleared."

  composer create-project "typo3/cms-base-distribution:^${TYPO3_VERSION}.0" "$DUMMY_DIR" --no-interaction --no-install
else
  echo "TYPO3 base distribution already exists, skipping creation."
fi

cd "$DUMMY_DIR"

# ---------------------------------------------------------------------------
# 2. Configure Composer & install
# ---------------------------------------------------------------------------
echo "--- Configuring Composer ---"
composer config platform.php 8.4.0
composer config --json repositories.analytics '{"type":"path","url":"/var/www/html","options":{"symlink":true}}'

BRANCH=$(git -C "$PROJECT_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo "main")
echo "Requiring extension from branch: $BRANCH"
composer require "t3g/analytics:dev-${BRANCH}@dev" --no-interaction --no-update

echo "--- Installing Composer dependencies ---"
composer install --no-interaction

# ---------------------------------------------------------------------------
# 3. Write .env
# ---------------------------------------------------------------------------
echo "--- Writing .env ---"
cat > "$DUMMY_DIR/.env" <<EOF
TYPO3_CONTEXT=Development/DDEV
TYPO3_PATH_APP_ROOT=$DUMMY_DIR
TYPO3_DB_HOST=db
TYPO3_DB_USER=db
TYPO3_DB_PASSWORD=db
TYPO3_DB_NAME=db
EOF

# ---------------------------------------------------------------------------
# 4. Run TYPO3 CLI setup (skip if already installed)
# ---------------------------------------------------------------------------
TYPO3_INSTALLED=$(mysql --host=db --user=db --password=db db \
  --skip-column-names --silent \
  -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='db' AND table_name='be_users';" \
  2>/dev/null || echo "0")

if [ "$TYPO3_INSTALLED" = "1" ]; then
  echo "TYPO3 already installed, skipping setup."
else
  echo "--- Running TYPO3 non-interactive setup ---"
  php "$DUMMY_DIR/vendor/bin/typo3" setup \
    --driver=mysqli \
    --host=db \
    --port=3306 \
    --dbname=db \
    --username=db \
    --password=db \
    --admin-username=admin \
    --admin-user-password='Admin1234!' \
    --admin-email='admin@local.test' \
    --project-name='Analytics Demo' \
    --server-type=apache \
    --no-interaction

  # -------------------------------------------------------------------------
  # 5. Create 5 root pages (one per site)
  # -------------------------------------------------------------------------
  echo "--- Creating 5 root pages ---"
  mysql --host=db --user=db --password=db db <<'SQL'
INSERT INTO pages
  (uid, pid, title, doktype, is_siteroot, slug, sorting,
   perms_userid, perms_user, perms_group, perms_everybody,
   hidden, deleted, tstamp, crdate)
VALUES
  (1, 0, 'Site 1', 1, 1, '/', 256, 1, 31, 23, 0, 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (2, 0, 'Site 2', 1, 1, '/', 512, 1, 31, 23, 0, 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (3, 0, 'Site 3', 1, 1, '/', 768, 1, 31, 23, 0, 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (4, 0, 'Site 4', 1, 1, '/', 1024, 1, 31, 23, 0, 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (5, 0, 'Site 5', 1, 1, '/', 1280, 1, 31, 23, 0, 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE title = VALUES(title);

ALTER TABLE pages AUTO_INCREMENT = 6;
SQL
fi

# ---------------------------------------------------------------------------
# 6. Create site configurations (always refreshed)
# ---------------------------------------------------------------------------
echo "--- Writing site configurations ---"
for i in 1 2 3 4 5; do
  SITE_DIR="$DUMMY_DIR/config/sites/site${i}"
  mkdir -p "$SITE_DIR"
  cat > "$SITE_DIR/config.yaml" <<YAML
base: https://site${i}.${DDEV_HOST}/
rootPageId: ${i}
languages:
  - title: English
    enabled: true
    languageId: 0
    base: /
    locale: en_US.UTF-8
    navigationTitle: English
    flag: us
  - title: Deutsch
    enabled: true
    languageId: 1
    base: /de/
    locale: de_DE.UTF-8
    navigationTitle: Deutsch
    flag: de
routes: {}
errorHandling: {}
YAML
  echo "  site${i} → https://site${i}.${DDEV_HOST}/ (rootPageId: ${i})"
done

# Remove any leftover default site config from the CLI setup
rm -rf "$DUMMY_DIR/config/sites/main" 2>/dev/null || true

# ---------------------------------------------------------------------------
# 7. Write system config
# ---------------------------------------------------------------------------
echo "--- Writing system configuration ---"
mkdir -p "$DUMMY_DIR/config/system"
cat > "$DUMMY_DIR/config/system/additional.php" <<PHP
<?php

defined('TYPO3') or die();

\$GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = [
    'driver'   => 'mysqli',
    'host'     => getenv('TYPO3_DB_HOST') ?: 'db',
    'port'     => 3306,
    'dbname'   => getenv('TYPO3_DB_NAME') ?: 'db',
    'user'     => getenv('TYPO3_DB_USER') ?: 'db',
    'password' => getenv('TYPO3_DB_PASSWORD') ?: 'db',
    'charset'  => 'utf8mb4',
];

// Allow all *.${DDEV_HOST} subdomains
\$GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern']
    = '^([a-z0-9-]+\\.)?${DDEV_HOST}$';
PHP

# ---------------------------------------------------------------------------
# 8. Remove FIRST_INSTALL so TYPO3 does not redirect to the installer
# ---------------------------------------------------------------------------
rm -f "$DUMMY_DIR/public/FIRST_INSTALL"

echo ""
echo "✓ Dummy TYPO3 is ready."
echo ""
echo "  Sites:"
for i in 1 2 3 4 5; do
  echo "    https://site${i}.${DDEV_HOST}/"
done
echo ""
echo "  Backend: https://${DDEV_HOST}/typo3  (admin / Admin1234!)"
