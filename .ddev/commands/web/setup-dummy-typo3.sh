#!/bin/bash
set -euo pipefail

PROJECT_ROOT="/var/www/html"
DUMMY_DIR="$PROJECT_ROOT/.Build/dummy-typo3"
DDEV_PRIMARY_URL="${DDEV_PRIMARY_URL:-}"
if [ -n "$DDEV_PRIMARY_URL" ]; then
  DDEV_HOST="${DDEV_PRIMARY_URL#*://}"
  DDEV_HOST="${DDEV_HOST%%/*}"
else
  DDEV_HOST="ext-analytics.ddev.site"
fi

TRUSTED_HOSTS_PATTERN="^${DDEV_HOST//./\\.}$"

echo "Project root: $PROJECT_ROOT"
echo "Dummy TYPO3 directory: $DUMMY_DIR"
echo "Detected DDEV host: $DDEV_HOST"
echo "Trusted hosts pattern: $TRUSTED_HOSTS_PATTERN"

mkdir -p "$PROJECT_ROOT/.Build"

if [ ! -f "$DUMMY_DIR/composer.json" ]; then
  echo "Dummy TYPO3 is missing or incomplete."

  if [ -d "$DUMMY_DIR" ]; then
    echo "Please remove the broken directory on the host machine first:"
    echo "  rm -rf .Build/dummy-typo3"
    exit 1
  fi

  echo "Creating TYPO3 dummy project ..."
  composer create-project typo3/cms-base-distribution:^13.4 "$DUMMY_DIR" --no-interaction --no-install
else
  echo "TYPO3 dummy project already exists and looks valid, skipping creation."
fi

cd "$DUMMY_DIR"

echo "Setting Composer PHP platform to 8.4 ..."
composer config platform.php 8.4.0

echo "Writing .env file ..."
cat > "$DUMMY_DIR/.env" <<EOF
TYPO3_CONTEXT=Development/DDEV
TYPO3_PATH_APP_ROOT=$DUMMY_DIR
TYPO3_DB_HOST=db
TYPO3_DB_USER=db
TYPO3_DB_PASSWORD=db
TYPO3_DB_NAME=db
EOF

echo "Configuring local path repository for the extension ..."
composer config --json repositories.analytics '{"type":"path","url":"/var/www/html","options":{"symlink":true}}'

echo "Requiring local extension package ..."
composer require t3g/analytics:dev-main@dev --no-interaction --no-update

echo "Installing Composer dependencies ..."
composer install --no-interaction

echo "Creating FIRST_INSTALL marker ..."
mkdir -p "$DUMMY_DIR/public"
if [ ! -e "$DUMMY_DIR/public/FIRST_INSTALL" ]; then
  touch "$DUMMY_DIR/public/FIRST_INSTALL"
  echo "FIRST_INSTALL marker created."
else
  echo "FIRST_INSTALL marker already exists, skipping."
fi

echo "Writing TYPO3 system configuration ..."
mkdir -p "$DUMMY_DIR/config/system"

cat > "$DUMMY_DIR/config/system/additional.php" <<'PHP'
<?php

defined('TYPO3') or die();

$databaseHost = getenv('TYPO3_DB_HOST') ?: 'db';
$databaseUser = getenv('TYPO3_DB_USER') ?: 'db';
$databasePassword = getenv('TYPO3_DB_PASSWORD') ?: 'db';
$databaseName = getenv('TYPO3_DB_NAME') ?: 'db';

$GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = [
    'driver' => 'mysqli',
    'host' => $databaseHost,
    'port' => 3306,
    'dbname' => $databaseName,
    'user' => $databaseUser,
    'password' => $databasePassword,
    'charset' => 'utf8mb4',
];

$GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '^analytics\.ddev\.site$';
PHP

echo "Writing site configuration for a blank start page ..."
mkdir -p "$DUMMY_DIR/config/sites/main"

cat > "$DUMMY_DIR/config/sites/main/config.yaml" <<'YAML'
base: /
errorHandling: { }
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
    base: /de
    locale: de_DE.UTF-8
    navigationTitle: German
    flag: de
rootPageId: 1
routes: { }
YAML

echo "Dummy TYPO3 is ready."
echo "Open the DDEV URL in your browser on the host machine and complete the TYPO3 installer."
echo "If needed, run: ddev launch"
