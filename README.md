# TYPO3 Analytics Extension

A TYPO3 backend extension that integrates **TYPO3 Analytics** into the TYPO3 site management panel. It lets editors register TYPO3 sites with the TYPO3 Analytics API, monitor registration status, and open the analytics dashboard — all without leaving the TYPO3 backend.

## Requirements

| Component | Version |
|-----------|---------|
| PHP | ^8.2 |
| TYPO3 | ^13.4 or ^14.0 |
| libsodium | PHP extension (bundled since PHP 7.2) |

## What the extension does

The extension adds a **Sites → Analytics** module to the TYPO3 backend. For each configured TYPO3 site it provides:

- **Registration** — enter an e-mail address and register the site with the TYPO3 Analytics API.
- **Status display** — shows the current analytics status fetched via HMAC-authenticated API calls (cached for 24 h, manually refreshable).
- **Dashboard** — opens the TYPO3 Analytics dashboard as an embedded iframe inside the TYPO3 backend.
- **Tracking code injection** — once a site is registered, the TYPO3 Analytics tracking script is automatically injected into every frontend HTML response for that site.

### Encryption

Credentials are encrypted using XChaCha20-Poly1305 via libsodium. On TYPO3 v14+ the built-in `TYPO3\CMS\Core\Crypto\Cipher\CipherService` is used automatically; on v13 an equivalent custom implementation is used, ensuring values remain decryptable after an upgrade.

### Content Security Policy

The extension automatically extends the backend CSP (`frame-src`) to allow the analytics dashboard to be embedded as an iframe inside the TYPO3 backend. The following origins are whitelisted (configured in `Configuration/ContentSecurityPolicies.php`):

| Origin | Purpose |
|--------|---------|
| `https://dashboard.analytics.typo3.com` | Production dashboard |
| `https://stage.dashboard.analytics.typo3.com` | Staging dashboard |

### Module icon

Two icon variants are shipped and selected automatically at runtime based on the installed TYPO3 version:

| File | Used on | Design |
|------|---------|--------|
| `Resources/Public/Icons/Extension.svg` | TYPO3 v14+ | Adaptive — uses `var(--icon-color-accent)` and `currentColor` to follow the backend theme |
| `Resources/Public/Icons/Extension-v13.svg` | TYPO3 v13 | Material Design — solid orange background with white bar-chart bars |

## Installation

```bash
composer require t3g/analytics
```

Activate the extension in the TYPO3 Extension Manager or via:

```bash
vendor/bin/typo3 extension:setup analytics
```

## Configuration reference

All settings are stored under `$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']`. They can be edited in the TYPO3 Extension Manager (Settings → Extension Configuration → analytics) or set in `AdditionalConfiguration.php` / `config/system/additional.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['settingName'] = 'value';
```

### API settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `apiBaseUrl` | string | _(production)_ | Base URL for the analytics management API. Leave empty to use the production default. |
| `analyticsApiBaseUrl` | string | _(production)_ | Base URL for the analytics data API. Leave empty to use the production default. |
| `verifySsl` | bool | `1` | Whether to verify SSL certificates on API requests. Disable (`0`) only for local development with self-signed certificates. |
| `pageAnalyticsCacheTtl` | int | `3600` | Lifetime in seconds for cached page analytics data. |

### Dashboard settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `dashboardPeriods` | string | `7,14,30` | Comma-separated list of period options (in days) shown in all dashboard widget dropdowns and the page performance bar. Must be positive integers. |
| `dashboardDefaultPeriod` | int | `7` | Pre-selected period (in days) for all dashboard widgets. Must be one of the values in `dashboardPeriods`. |

**Example — custom period options:**

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['dashboardPeriods'] = '7,30,90';
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['analytics']['dashboardDefaultPeriod'] = 30;
```

## Modules and widgets

### Backend module — Sites → Analytics

Registered as `site_analytics` (route prefix `site_analytics.*`). Visible in the **Sites** section of the backend module menu.

The module offers per-site views for:
- **Registration** — enter an e-mail address to register a site with the TYPO3 Analytics API.
- **Status** — shows registration status, website ID and API key, with a manual refresh button.
- **Dashboard** — embeds the TYPO3 Analytics web dashboard as an iframe.

### Dashboard widgets

The extension registers two dashboard widget types, each available in a TYPO3 v13 variant (inline dropdowns, AJAX-driven) and a TYPO3 v14+ variant (native widget settings panel).

#### Top Pages widget

Displays the top-visited pages for a configured site over a configurable time period. Results are sorted by page views; a "Show all" link leads to the full analytics dashboard filtered to the same site.

| Variant | Class | Widget ID | TYPO3 version |
|---------|-------|-----------|---------------|
| v13 | `TopPagesWidget` | `dashboard.widget.analyticsTopPages` | ^13.4 |
| v14+ | `TopPagesWidgetV14` | `dashboard.widget.analyticsTopPagesV14` | ^14.0 |

Widget settings (v14+): **Site**, **Period** (days), **Limit** (number of pages shown).

AJAX endpoint (v13): `TopPagesAjaxController` — registered as backend route `ajax_analytics_top_pages`.

#### Site Performance widget

Displays aggregate performance metrics for a configured site over a configurable time period:

| Metric | Tone |
|--------|------|
| Visits | primary |
| Visitors | success |
| Bounce rate | danger |
| Avg. visit duration | info |

Each metric shows the current value and a trend indicator (compared to the previous period of equal length).

| Variant | Class | Widget ID | TYPO3 version |
|---------|-------|-----------|---------------|
| v13 | `SitePerformanceWidget` | `dashboard.widget.analyticsSitePerformance` | ^13.4 |
| v14+ | `SitePerformanceWidgetV14` | `dashboard.widget.analyticsSitePerformanceV14` | ^14.0 |

Widget settings (v14+): **Site**, **Period** (days).

AJAX endpoint (v13): `SitePerformanceAjaxController` — registered as backend route `ajax_analytics_site_performance`.

### Page Performance Bar

An event listener (`PagePerformanceBarListener`) on `ModifyPageLayoutContentEvent` that injects an analytics bar above the page content in the **Page** module. The bar shows per-page metrics for the currently viewed page:

- Page views with sparkline and trend
- Bounce rate with sparkline and trend
- Average time on page with sparkline and trend
- Continuation rate (100 − bounce rate) with sparkline and trend

A period selector (values from `dashboardPeriods`) and a link to the full site analytics dashboard are included. The bar is hidden in language-comparison mode (viewMode = 2).

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
