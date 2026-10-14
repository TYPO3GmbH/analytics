# TYPO3 Analytics Extension

A TYPO3 backend extension that integrates **TYPO3 Analytics** into the TYPO3 site management panel. It lets editors register TYPO3 sites with the TYPO3 Analytics API, monitor registration status, and open the analytics dashboard without leaving the TYPO3 backend.

## Contents

- [Installation](#installation)
- [Configuration](#configuration)
  - [Development settings](#development-settings)
  - [Dashboard settings](#dashboard-settings)
- [Local development with DDEV](#local-development-with-ddev)
- [Compatibility notes](#compatibility-notes)


### Encryption

Credentials are encrypted using XChaCha20-Poly1305 via libsodium. On TYPO3 v14+ the built-in `TYPO3\CMS\Core\Crypto\Cipher\CipherService` is used automatically; on v13 an equivalent custom implementation is used, ensuring values remain decryptable after upgrading.


## Installation

### Composer (recommended)

```bash
composer require t3g/analytics
```

Activate the extension in the TYPO3 Extension Manager or via:

```bash
vendor/bin/typo3 extension:setup analytics
```

### TER / Classic mode

In a non-Composer TYPO3 installation, search for **analytics** in **Admin Tools → Extensions** and install it from there. 
Make sure the **Dashboard** system extension is active before installing.

## Configuration

### Development settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `demoData` | bool | `0` | When enabled, replaces analytics API calls with static demo data. Only takes effect in a TYPO3 `Development` application context. |

### Dashboard settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `dashboardPeriods` | string | `7,14,30` | Comma-separated list of time period options (in days) shown in all dashboard widget dropdowns and the page performance bar. Must be positive integers. |
| `dashboardDefaultPeriod` | int | `7` | Pre-selected time period (in days) for all dashboard widgets. Must be one of the values in `dashboardPeriods`. |

**Example — custom time period:**

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['dashboardPeriods'] = '7,30,90';
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['dashboardDefaultPeriod'] = 30;
```

### Cache settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `pageAnalyticsCacheTtl` | int | `3600` | Lifetime in seconds for analytics data caches. Increase this value to reduce API calls in high-traffic backend installations. |

**Example — longer cache lifetime:**

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['pageAnalyticsCacheTtl'] = 7200;
```


## Local development with DDEV

The repository ships a fully automated DDEV environment that spins up a dummy TYPO3 installation with five sub-domain sites.

### Prerequisites

- [DDEV](https://ddev.readthedocs.io/) installed
- Docker running

### Start the environment

```bash
ddev start
```

On first start DDEV automatically runs the setup script. You will be prompted to choose the TYPO3 major version:

```
TYPO3 version to install (13/14) [13]:
```

The script will:

1. Create a fresh TYPO3 project in `.Build/dummy-typo3/` using `typo3/cms-base-distribution`
2. Drop and recreate the database for a clean slate
3. Require and symlink this extension from the repository root
4. Run `vendor/bin/typo3 setup` non-interactively
5. Create five root pages (Site 1–5)
6. Write site configurations for five subdomains
7. Remove the `FIRST_INSTALL` flag so the backend is immediately accessible

### Available URLs after setup

| URL | Description |
|-----|-------------|
| `https://analytics.ddev.site/typo3` | TYPO3 backend |
| `https://site1.analytics.ddev.site/` | Frontend site 1 |
| `https://site2.analytics.ddev.site/` | Frontend site 2 |
| `https://site3.analytics.ddev.site/` | Frontend site 3 |
| `https://site4.analytics.ddev.site/` | Frontend site 4 |
| `https://site5.analytics.ddev.site/` | Frontend site 5 |
| `https://analytics.ddev.site:1337` | Documentation preview |

**Backend credentials:** `admin` / `Admin1234!`

### Re-run setup manually

```bash
ddev exec composer dummy-typo3
```

### Reset everything

```bash
# Remove the dummy installation (keeps DDEV containers)
rm -rf .Build/dummy-typo3

# Then restart — setup runs automatically
ddev restart
```

### Run an additional environment in parallel

You can run an additional environment next to the existing one, for example to test with another TYPO3 version.
Use another checkout of the repository for this, for example a clone or a `git worktree`.

Two DDEV projects with the same name cannot run at the same time.
The project name in `.ddev/config.yaml` is `analytics`.
Do not change `.ddev/config.yaml`.
Set a different name in a local override file instead.

1. In the other checkout, create the file `.ddev/config.local.yaml`:

   ```yaml
   name: analytics-v14
   override_config: true
   additional_fqdns:
       - site1.analytics-v14.ddev.site
       - site2.analytics-v14.ddev.site
       - site3.analytics-v14.ddev.site
       - site4.analytics-v14.ddev.site
       - site5.analytics-v14.ddev.site
   ```

   `override_config: true` replaces the list `additional_fqdns`.
   Without it, DDEV adds the new host names to the host names from `.ddev/config.yaml`.

2. Run `ddev start` in the other checkout.
3. Select the TYPO3 version when the setup script asks for it.

The setup script reads the host name from DDEV.
Thus, the URLs of the additional environment use the new name, for example `https://analytics-v14.ddev.site/typo3`.
Git ignores the file `.ddev/config.local.yaml`.

### Documentation preview

The [ddev-typo3-docs](https://github.com/TYPO3-Documentation/ddev-typo3-docs) add-on renders the `.rst` files in `Documentation/` with the same renderer used by docs.typo3.org. It starts automatically with `ddev start`/`ddev restart` and watches for changes.

Open **https://analytics.ddev.site:1337** and edit files under `Documentation/` — the preview re-renders automatically on save.

### Code quality

```bash
# PHP CS Fixer (dry-run)
composer t3g:cgl

# PHP CS Fixer (fix)
composer t3g:cgl:fix

# PHPStan
composer t3g:phpstan

# Rector (dry-run)
composer t3g:rector:dry-run

# Rector (apply)
composer t3g:rector:fix
```

### Tests

```bash
# All tests
composer t3g:test

# Unit tests only
composer t3g:test:unit

# Functional tests (SQLite, no external DB required)
composer t3g:test:functional
```

Unit tests use the TYPO3 testing framework with an in-process bootstrap. Functional tests use SQLite via `pdo_sqlite` — no external database server is required.

## Compatibility notes

### TYPO3 v13

- `ext_emconf.php` is required and evaluated by TYPO3.
- The custom sodium-based cipher is used for credential encryption.
- Module access is controlled via `'access' => 'user'`.
- The Material Design icon (`Extension-v13.svg`) is used automatically.

### TYPO3 v14

- `ext_emconf.php` is deprecated ([Feature #108345](https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/14.2/Feature-108345-No-ext-em-conf-in-classic-mode.html)). Extension metadata is read from `composer.json` (`extra.typo3/cms`).
- The built-in `TYPO3\CMS\Core\Crypto\Cipher\CipherService` is used automatically.
- Module access uses the gate-registry pattern; `'user,group'` is no longer valid — `'user'` is used instead.
- The adaptive theme icon (`Extension.svg`) is used automatically.
