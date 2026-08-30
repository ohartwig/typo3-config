# TYPO3 Config — Fluent Configuration Library

[![TYPO3 14](https://img.shields.io/badge/TYPO3-14.x-orange.svg)](https://get.typo3.org/)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Fluent PHP API for environment-specific TYPO3 configuration. Provides context-based presets, secure secret management, caching, logging, mailer setup, and TLS/mTLS auto-configuration.

## Features

- **Context-Based Presets** — Auto-configuration for Production, Development, CLI, and Testing environments
- **Secret Resolution Cascade** — Secure secret loading from `_FILE` env vars → `/run/secrets/` → `getenv()` → fallback
- **Caching Auto-Configuration** — Redis/Valkey (via `moselwal/keyvalue-store`), APCu, or file backends
- **TLS/mTLS Auto-Configuration** — Automatic certificate discovery for database and Redis connections
- **Mailer Setup** — SMTP and Mailpit configuration helpers
- **GitLab-Backend-Login** — OAuth2-Login-Provider fuer das Backend (via `mfc/oauth2`)
- **Logging Presets** — Context-aware logging configuration
- **Image Engine** — ImageMagick and GraphicsMagick configuration
- **Fluent Interface** — Chainable API for readable configuration
- **TYPO3 14.x** — getestet auf der aktuellen LTS-Linie

## Installation

```bash
composer require moselwal/typo3-config
```text

## Quick Start

In your `config/config.php`:

```php
<?php

use Moselwal\Config;

Config::initialize()
    ->loadCoreSecrets()
    ->loadMailSecrets()
    ->autoconfigureCaching();
```

## Secret Management

Secrets are resolved through a cascading lookup:

1. File path from env var (`DB_PASSWORD_FILE`)
2. Default secret file (`/run/secrets/db_password`)
3. Direct env var (`getenv('DB_PASSWORD')`)
4. Fallback parameter (optional)

```bash
# Docker secrets or Kubernetes mounts
echo "supersecret" > /run/secrets/db_password
```text

```php
Config::get()->loadCoreSecrets();
```

No secrets need to be committed to Git or stored in `.env` files.

## GitLab-Login fuers Backend

`useGitLabBackendLogin()` haengt das TYPO3-Backend an eine GitLab-Instanz. Voraussetzung
ist die Extension [`mfc/oauth2`](https://packagist.org/packages/mfc/oauth2) — ohne sie
macht die Methode nichts.

```php
Config::initialize()
    ->loadCoreSecrets()
    ->useGitLabBackendLogin(
        gitlabServer: 'https://git.example.com',
        projectName: 'devops/typo3-backend-access',
        adminUserLevel: 40,   // ab Maintainer wird der Benutzer TYPO3-Admin
    );
```

Die App-ID und das App-Secret kommen aus derselben Kaskade wie alle anderen Secrets —
`GITLAB_OAUTH_APP_ID_FILE` → `/run/secrets/gitlab_oauth_app_id` → `getenv()` → Parameter,
analog fuer `GITLAB_OAUTH_APP_SECRET`. Nichts davon gehoert in Git oder in eine `.env`.

**Die Methode ist bewusst still.** Fehlt die Extension, fehlt eines der beiden Secrets
oder fehlt der Projektpfad, passiert gar nichts: kein Login-Button, keine geaenderte
Cookie-Konfiguration. Der Aufruf ist damit auch auf Instanzen unbedenklich, hinter denen
kein GitLab steht — er schaltet sich selbst frei, sobald die Secrets vorhanden sind.

### Wer darf rein

Die Berechtigung haengt an **einem** GitLab-Projekt (`projectName`):

| GitLab | TYPO3 |
|---|---|
| kein Mitglied (Access Level 0) | Login wird abgelehnt |
| Access Level > 0 | Login erlaubt |
| Access Level ≥ `adminUserLevel` | TYPO3-Admin |
| `external`-Flag | abgelehnt, solange `blockExternalUsers` gesetzt ist |

Feinere Zuordnung laeuft ueber `be_groups`: Die Extension legt dort das Feld `gitlabGroup`
an. Traegt man dort ein GitLab-Access-Level ein (10 Guest, 20 Reporter, 30 Developer,
40 Maintainer), bekommt jeder Benutzer mit diesem Level die Gruppe beim Login zugewiesen.
Greift kein Mapping, gilt `defaultGroups`.

`overrideUser` (Default aus) schreibt bei **jedem** Login `admin`, `disable`, `starttime`
und `endtime` aus GitLab zurueck. Eingeschaltet heisst das: ein in TYPO3 gesperrter
Redakteur wird durch einen GitLab-Login wieder entsperrt. Wer das einschaltet, muss
Sperren konsequent in GitLab abbilden.

### Redirect-URI

Die URI, die in der GitLab-Application eingetragen wird, ist der **Login-Pfad
dieser Installation**, nicht `/typo3/index.php`:

| Setup | Redirect-URI |
|---|---|
| eigener Backend-Host (`BE/entryPoint`) | `https://cms.example.com/login` |
| Backend unter der Hauptdomain | `https://www.example.com/typo3/login` |

Ohne Query-String eintragen. GitLab (Doorkeeper) vergleicht die Query nur, wenn
die registrierte URI selbst eine hat — und die Extension haengt bei jedem Login
einen anderen `request-token` an.

`mfc/oauth2` selbst baut den Rueckweg auf den festverdrahteten Pfad
`/typo3/index.php`. Dieses Einstiegsskript gibt es in TYPO3 v14 nicht mehr; der
Callback laeuft dort in einen 404, **nachdem** der Benutzer bei GitLab schon
zugestimmt hat. Bis davor sieht alles gesund aus. `useGitLabBackendLogin()`
registriert deshalb `Moselwal\OAuth2\GitLabResourceServer`, eine Ableitung, die
den Pfad aus dem laufenden Request nimmt. Sobald ein `mfc/oauth2`-Release den
Fehler behebt, kann die Klasse weg.

### Was die Methode sonst noch anfasst

- `BE/cookieSameSite` wird auf `lax` gesetzt. Der OAuth2-Rueckweg von GitLab ist eine
  Cross-Site-Navigation; unter `strict` haelt der Browser das Nonce-Cookie zurueck und
  der RequestToken-Check schlaegt jedes Mal fehl.
- `EXTENSIONS/oauth2` wird gesetzt, damit `mfc/oauth2` den Login-Provider registriert.
  Das spart den Klick im Install-Tool und haelt die Konfiguration im Code.

Passwort- und Passkey-Login bleiben unveraendert. GitLab kommt als dritter Weg dazu und
ersetzt die anderen beiden nicht — faellt die GitLab-Instanz aus, kommt man weiterhin
ins Backend.

## Available Presets

| Method | Description |
|--------|-------------|
| `applyDefaults()` | Auto-selects preset based on TYPO3 context |
| `useCliPreset()` | Optimized for CLI calls with debug output |
| `useDevelopmentPreset()` | Development environment settings |
| `useProductionPreset()` | Production with APP_ROOT (container) |
| `useProductionPresetVHost()` | Production with VHost-based setup |

## Usage Example

```php
Config::get()
    ->useGraphicsMagick()
    ->useMailpit()
    ->autoconfigureCaching()
    ->setPhpSettings([
        'memory_limit' => '512M',
        'max_execution_time' => 120,
    ])
    ->setConfigPathValues('SYS', [
        'defaultScheme' => 'https',
    ]);
```text

## TYPO3 Version Compatibility

| TYPO3 Version | Status |
|---------------|--------|
| v11 | Supported |
| v12 | Supported (`pagesection` cache auto-removed) |
| v13 | Supported (`imagesizes` cache auto-removed) |
| v14 | Supported |

## Architecture

```
src/
├── Config.php              # Main class (Singleton, Fluent API)
└── ConfigInterface.php     # Public contract interface
tests/
├── ConfigTestCase.php      # Base test class (singleton reset, $GLOBALS isolation)
├── TestableConfig.php      # Test subclass for version injection
└── ...                     # 35+ tests with >120 assertions
```text

- **Singleton pattern** via `Config::initialize()` / `Config::get()`
- **Namespace**: `Moselwal\` → `src/` (PSR-4)
- Late static binding (`new static()`) for project-specific extensions

## Development

```bash
composer install
composer test              # PHPUnit tests
composer test:coverage     # Tests with coverage report
composer phpstan           # PHPStan Level 5 static analysis
```

## Dependencies

| Package | Type | Purpose |
|---------|------|---------|
| `moselwal/keyvalue-store` | Optional | Redis/Valkey cache and session backends |
| `moselwal/dev` | Dev | Shared QA tooling |

## Related

- [keyvalue-store](../keyvalue-store) — Redis/Valkey integration used by the caching auto-configuration

## License

MIT — see [LICENSE](LICENSE) for details.
