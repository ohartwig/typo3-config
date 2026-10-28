# Upgrade Guide

## GitLab-Backend-Login: Zuordnung nur noch per GitLab-User-ID (5.x)

Betrifft nur Installationen, die `useGitLabBackendLogin()` aufrufen. Am Aufruf
muss nichts geaendert werden.

- Ein GitLab-Login wird keinem bestehenden Backend-Konto mehr ueber Benutzername
  oder E-Mail zugeordnet, sondern nur noch ueber `oauth_identifier`
  (`gitlab|<GitLab-User-ID>`). Bereits verknuepfte Konten funktionieren weiter.
- Neue Konten heissen `gitlab-<GitLab-User-ID>` statt wie der GitLab-Benutzer.
- `overrideUser` ist jetzt standardmaessig an: Admin-Flag und Gruppen folgen bei
  jedem Login dem GitLab-Access-Level. Wer das alte Verhalten braucht, setzt
  `overrideUser: false`.
- Lokal angelegte Konten, die auch per GitLab erreichbar sein sollen, werden
  ueber `linkedAccounts: ['<benutzername>' => <GitLab-User-ID>]` verknuepft.
- Nach dem Update: Konten mit gesetztem `oauth_identifier` pruefen, deren
  Verknuepfung nicht vom GitLab-Login selbst stammt (siehe README, Abschnitt
  "Welches Konto ein GitLab-Login bekommt").

## 3.x → 4.0

Version 4.0 entfernt das Singleton-Pattern aus `Moselwal\Config`. `Config` ist
jetzt eine reguläre, instanziierbare Klasse — kein verstecktes globales State
mehr. Das macht das Paket sicher für FrankenPHP-Worker-Mode (`MAX_REQUESTS > 1`)
und für jede andere Umgebung mit langlebigen PHP-Prozessen.

### Breaking Changes

- `Moselwal\Config::initialize(bool $applyDefaults = true)` wurde entfernt
- `Moselwal\Config::get()` wurde entfernt
- `Moselwal\Config::$instance` (static property) wurde entfernt
- `Moselwal\ConfigInterface::initialize()` und `::get()` wurden entfernt
- Der Konstruktor ist jetzt `public` (vorher `private`)

### Migration in `config/config.php` deines Projekts

**Vorher (v3.x):**

```php
<?php
use Moselwal\Config;

Config::initialize()
    ->loadCoreSecrets()
    ->autoconfigureCaching()
    ->useReverseProxy('*');
```text

**Nachher (v4.0):**

```php
<?php
use Moselwal\Config;

(new Config())
    ->applyDefaults()
    ->loadCoreSecrets()
    ->autoconfigureCaching()
    ->useReverseProxy('*');
```

Wenn du `applyDefaults()` nicht willst (vorher `Config::initialize(false)`),
einfach weglassen:

```php
(new Config())
    ->useProductionPreset()
    ->loadCoreSecrets();
```text

### Migration für Konsumenten von `Config::get()`

`Config::get()` ging vorher davon aus, dass `Config::initialize()` schon einmal
aufgerufen wurde. Wenn du `Config::get()` in mehreren Dateien benutzt hast
(z.B. in `config/system/additional.php`), musst du die `$config`-Instanz selbst
durchreichen oder neu konstruieren:

**Vorher:**

```php
// config/config.php
Config::initialize();

// config/system/additional.php
Config::get()->setPhpSettings(['memory_limit' => '256M']);
```

**Nachher:**

```php
// config/config.php
return (new Config())
    ->applyDefaults();

// config/system/additional.php
(new Config())
    ->setPhpSettings(['memory_limit' => '256M']);
```text

`new Config()` ist günstig — nur 4 Property-Initialisierungen aus dem TYPO3
Environment.

### Idempotenz-Fix

`appendContextToSiteName()` ist jetzt idempotent: mehrfache Aufrufe hängen den
Context-Suffix nur einmal an. Vorher hätte dreifaches Aufrufen (z.B. in einem
FrankenPHP-Worker, der `config/config.php` über mehrere Requests evaluiert)
zu `"site - Development - Development - Development"` geführt.

### Warum?

Static State (`Config::$instance`) überlebt einzelne Requests in PHP-Worker-
Modi (FrankenPHP, RoadRunner, Swoole). Das führte zu zwei realen Risiken:

1. Konfigurations-State aus Request N "leakt" in Request N+1 weiter.
2. Nicht-idempotente Mutationen (wie `sitename .= ' - ' . context`) doppelten
   sich pro Request.

Eine instanziierbare Klasse hat per Definition keinen Class-Level-State und ist
damit Worker-sicher. Die FrankenPHP-Extension (`moselwal/frankenphp`) braucht
keinen `Config::reset()`-Workaround mehr.
