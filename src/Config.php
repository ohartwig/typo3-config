<?php

declare(strict_types=1);

/*
 * This file is part of the package "typo3-config" by Kai Ole Hartwig.
 */

namespace Moselwal;

use Mfc\OAuth2\ResourceServer\GitLab as UpstreamGitLabResourceServer;
use Moselwal\OAuth2\GitLabResourceServer;
use Mfc\OAuth2\ResourceServer\Registry as OAuth2ResourceServerRegistry;
use Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend;
use Moselwal\KeyValueStore\Locking\KeyValueLockingStrategy;
use Moselwal\KeyValueStore\Session\Backend\KeyValueSessionBackend;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend;
use TYPO3\CMS\Core\Cache\Backend\FileBackend;
use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\CMS\Core\Log\Writer\NullWriter;
use TYPO3\CMS\Core\Log\Writer\PhpErrorLogWriter;
use TYPO3\CMS\Core\Utility\ArrayUtility;

/**
 * Class to use in your configuration files of a TYPO3 project.
 */
class Config implements ConfigInterface
{
    protected ApplicationContext $context;
    protected Typo3Version $version;
    protected string $configPath;
    protected string $varPath;
    protected bool $ddevEnvironment = false;

    public function __construct()
    {
        $this->context = Environment::getContext();
        $this->version = new Typo3Version();
        $this->configPath = Environment::getConfigPath();
        $this->varPath = Environment::getVarPath();
    }

    public function applyDefaults(): self
    {
        // Ensure DB driver is always set (settings.php might be empty after install-tool reset)
        if ('' === (string)($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['driver'] ?? '')) {
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['driver'] = 'pdo_mysql';
        }

        // Include presets by default
        $this
            ->forbidNoCacheQueryParameter()
            ->appendContextToSiteName()
            ->useGraphicsMagick();

        if (php_sapi_name() === 'cli') {
            $this->useCliPreset();
        } elseif ($this->context->isDevelopment() || $this->context->isTesting()) {
            $this->useDevelopmentPreset();
        } elseif ($this->context->isProduction()) {
            if ('' !== self::env('APP_ROOT')) {
                $this->useProductionPreset();
            } else {
                $this->useProductionPresetVHost();
            }
        }
        return $this;
    }

    /**
     * Append TYPO3_CONTEXT to site name in the TYPO3 backend (idempotent).
     */
    final public function appendContextToSiteName(): self
    {
        if ($this->context->isProduction()) {
            return $this;
        }
        $suffix = ' - ' . (string)$this->context;
        $current = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? '');
        if (!str_ends_with($current, $suffix)) {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] = $current . $suffix;
        }
        return $this;
    }

    /**
     * @param array<string, mixed>|null $options
     */
    final public function initializeDatabaseConnection(?array $options = null, string $connectionName = 'Default'): self
    {
        if ($options === null) {
            return $this;
        }
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'][$connectionName] = array_replace_recursive(
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'][$connectionName],
            $options
        );
        return $this;
    }

    /**
     * @return $this
     */
    final public function useCliPreset(): self
    {
        // Context-aware CLI preset. Previous versions unconditionally turned
        // off TLS verification, set FE/BE debug=true and devIPmask='*' for
        // every CLI invocation. Because applyDefaults() runs useCliPreset()
        // BEFORE the production-context branch, this also fired for the
        // production CLI (e.g. supercronic-driven scheduler), MITM-exposing
        // every outbound HTTP request and leaking debug info on errors.
        //
        // We now only loosen TLS/debug in development & testing contexts.
        // The production-CLI path keeps the production logging defaults the
        // production preset would apply via the regular flow.
        $isLooseCliContext = $this->context->isDevelopment() || $this->context->isTesting();

        if ($isLooseCliContext) {
            $GLOBALS['TYPO3_CONF_VARS']['FE']['debug'] = true;
            $GLOBALS['TYPO3_CONF_VARS']['BE']['debug'] = true;
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask'] = '*';
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['displayErrors'] = 1;
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['errorHandlerErrors'] = E_ALL ^ E_NOTICE;
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['exceptionalErrors'] = 28674;
            // Skip TLS verification only for invalid development
            // certificates. NEVER lower these in production-CLI: doing so
            // makes the scheduler MITM-exploitable.
            // Hint: works only together with a core patch.
            $GLOBALS['TYPO3_CONF_VARS']['HTTP']['ssl_verify_host'] = 0;
            $GLOBALS['TYPO3_CONF_VARS']['HTTP']['ssl_verify_peer'] = 0;
        } else {
            // Production-CLI hardening: keep the production preset defaults
            // even if useProductionPreset() never ran (applyDefaults branches
            // skip the production branch entirely when SAPI=cli).
            $GLOBALS['TYPO3_CONF_VARS']['FE']['debug'] = false;
            $GLOBALS['TYPO3_CONF_VARS']['BE']['debug'] = false;
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask'] = '';
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['displayErrors'] = -1;
        }

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['systemLogLevel'] = 0;

        // Das Deprecation-Log haengt an einem eigenen FileWriter. Es stand
        // hier unbedingt an, also auch im Produktions-CLI: jeder
        // Scheduler-Lauf im Container schrieb var/log/deprecations_*.log,
        // eine Datei, die waechst und die niemand liest. Beide
        // Produktions-Presets schalten das Log ab - nur der CLI-Pfad fiel
        // durchs Raster, weil applyDefaults() bei SAPI=cli gar nicht erst in
        // den Produktionszweig laeuft. Lokal bleibt es an, da ist die Datei
        // gewollt und wird auch gelesen.
        if ($isLooseCliContext) {
            $this->enableDeprecationLogging();
        } else {
            $this->disableDeprecationLogging();
        }

        // Nach stderr, nicht in eine Datei.
        //
        // Hier stand ein FileWriter auf 'var/logs/error.log'. Das Verzeichnis
        // heisst bei TYPO3 var/log/ (Einzahl) und wurde nirgends angelegt -
        // auf keinem Mandanten der Flotte. Der Writer schrieb also ins Leere,
        // und jede Ausnahme aus einem CLI-Lauf (Scheduler, Kommandos) war
        // unsichtbar. Aufgefallen ist das erst, als eine Fehlersuche daran
        // scheiterte, dass es kein Log gab.
        //
        // Eine Datei waere auch bei richtigem Pfad falsch: im Container liest
        // sie niemand, sie waechst mit, und sie ueberlebt den Pod nicht. Was
        // stderr erreicht, sammelt die Log-Pipeline ein.
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = $this->writeToStdErr(
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] ?? []
        );

        return $this;
    }

    /**
     * Alles ab ERROR nach stderr, und keine Datei.
     *
     * Der Fehler war, dass die Presets den FileWriter nie erwaehnten. Sie
     * setzten nur PhpErrorLogWriter, waehrend TYPO3s eigene Vorgabe
     * `warning => FileWriter` unangetastet daneben stehen blieb und weiter
     * Dateien schrieb. Deshalb wird er hier auf jeder Stufe ausdruecklich
     * abgeschaltet.
     *
     * Die erzwungenen Werte stehen als ZWEITES Argument von
     * array_replace_recursive, damit sie die Vorgabe verdraengen statt von ihr
     * verdraengt zu werden. Am konkreten Fehler oben aenderte die Reihenfolge
     * nichts - TYPO3 liefert dort kein 'disabled', an dem sie sich haette
     * reiben koennen. Sie entspricht aber der Absicht: was das Preset setzt,
     * gilt; wer es danach in seiner additional.php anders will, ueberschreibt
     * es dort.
     *
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function writeToStdErr(array $existing): array
    {
        // Alle acht Stufen, nicht nur die vier, die frueher hier standen. Ein
        // Writer faengt seine Stufe UND alles Schwerere - ein FileWriter auf
        // 'notice' schriebe also weiter Dateien, obwohl 'warning' abwaerts
        // abgeschaltet ist. Deshalb wird der FileWriter ueberall ausdruecklich
        // stillgelegt.
        //
        // Der PhpErrorLogWriter genuegt auf ERROR: von dort faengt er error,
        // critical, alert und emergency mit. Genau darauf kommt es an -
        // TYPO3s ProductionExceptionHandler meldet die "Oops"-Ausnahmen aus
        // Inhaltselementen auf Stufe alert.
        //
        // Betrifft nur die Wurzel-Konfiguration. Eigene writerConfiguration
        // unterhalb von LOG.TYPO3.CMS.* - etwa das Auth-Log - bleiben, wie
        // sie sind; die haben ihre eigene Begruendung.
        $forced = [];
        foreach ([LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR] as $level) {
            $forced[$level] = [
                PhpErrorLogWriter::class => ['disabled' => $level !== LogLevel::ERROR],
                FileWriter::class => ['disabled' => true],
            ];
        }
        foreach ([LogLevel::WARNING, LogLevel::NOTICE, LogLevel::INFO, LogLevel::DEBUG] as $level) {
            $forced[$level] = [
                PhpErrorLogWriter::class => ['disabled' => true],
                FileWriter::class => ['disabled' => true],
            ];
        }

        return array_replace_recursive($existing, $forced);
    }

    /**
     * Default settings for production, can be overridden again in each project / production.php
     * @return $this
     */
    final public function useProductionPreset(): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['debug'] = false;
        $GLOBALS['TYPO3_CONF_VARS']['FE']['debug'] = false;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['displayErrors'] = -1;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['belogErrorReporting'] = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['exceptionalErrors'] = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        $this->disableDeprecationLogging();
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = $this->writeToStdErr(
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] ?? []
        );
        return $this;
    }

    /**
     * Default settings for production, can be overridden again in each project / production.php
     * @return $this
     */
    final public function useProductionPresetVHost(): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['debug'] = false;
        $GLOBALS['TYPO3_CONF_VARS']['FE']['debug'] = false;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['displayErrors'] = -1;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['belogErrorReporting'] = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['exceptionalErrors'] = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        $this->disableDeprecationLogging();
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = array_replace_recursive(
            [
                LogLevel::DEBUG => [
                    FileWriter::class => ['disabled' => true],
                ],
                LogLevel::INFO => [
                    FileWriter::class => ['disabled' => true],
                ],
                LogLevel::WARNING => [
                    FileWriter::class => ['disabled' => true],
                ],
                LogLevel::ERROR => [
                    FileWriter::class => ['disabled' => false],
                ],
            ],
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration']
        );
        return $this;
    }

    final public function useDevelopmentPreset(): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['debug'] = true;
        $GLOBALS['TYPO3_CONF_VARS']['FE']['debug'] = true;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask'] = '*';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';
        // Local domains are served over plain HTTP; leaving the backend's HTTPS
        // lock on would make it unreachable there. Set explicitly rather than
        // left unset, so switching to this preset always clears a lock a
        // production preset may have applied first.
        $GLOBALS['TYPO3_CONF_VARS']['BE']['lockSSL'] = false;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['displayErrors'] = 1;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['belogErrorReporting'] = E_ALL;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['exceptionalErrors'] = E_ALL;
        $this->enableDeprecationLogging();
        // Log warnings to files
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'][LogLevel::WARNING] = [
            FileWriter::class => ['disabled' => false],
        ];
        $this->useMailpit();
        $this->useFileFill();
        return $this;
    }

    final public function useFileFill(): self
    {
        // Configure EXT:filefill for local environments without a sync'ed fileadmin folder.
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['filefill']['storages'][1] = [
            [
                'identifier' => 'placeholder',
            ],
        ];
        return $this;
    }

    /**
     * @param array<string, int> $forbiddenKeys
     */
    final public function useConfigLoader(array $forbiddenKeys = ['password' => 1,'transport_smtp_password' => 1, 'encryptionKey' => 1, 'installToolPassword' => 1]): self
    {
        // Automatic Configuration overrides by conventional named environment variables
        // This is always the last place for configuration changes and must be in the end of this file!
        // The Config will be cached!
        if (class_exists(\Helhum\ConfigLoader\CachedConfigurationLoader::class)) {
            $cacheDir = Environment::getVarPath() . '/cache/data';
            $cacheIdentifier = $this->configLoaderCacheIdentifier();
            // Use the TYPO3 project root so the TYPO3 env reader maps TYPO3__* variables correctly
            $configReaderFactory = new \Helhum\ConfigLoader\ConfigurationReaderFactory(\TYPO3\CMS\Core\Core\Environment::getProjectPath());
            $configLoader = new \Helhum\ConfigLoader\CachedConfigurationLoader(
                $cacheDir,
                $cacheIdentifier,
                function () use ($configReaderFactory) {
                    return new \Helhum\ConfigLoader\ConfigurationLoader(
                        [
                            $configReaderFactory->createReader('TYPO3', ['type' => 'env']),
                        ]
                    );
                }
            );

            // SECURITY: filter forbidden keys RECURSIVELY. array_diff_key
            // only looks at the top level, so TYPO3 ENV vars that nest
            // under DB / MAIL / EXTENSIONS ('TYPO3__DB__Connections__Default__password',
            // 'TYPO3__MAIL__transport_smtp_password', …) pass through and
            // get baked into the PHP config cache on disk. Walk every
            // level of the loaded array and unset matching leaves.
            $loaded = $configLoader->load();
            self::removeForbiddenKeysRecursive($loaded, $forbiddenKeys);
            $GLOBALS['TYPO3_CONF_VARS'] = array_replace_recursive(
                $GLOBALS['TYPO3_CONF_VARS'],
                $loaded
            );
        }
        return $this;
    }

    /**
     * Cache identifier for the env-driven configuration overrides.
     *
     * The cached array IS the environment: whatever TYPO3__* variables the
     * process that wrote the cache could see is what every later process
     * reads back. Until 2026-09-08 the key was only .env mtime + BUILD_DATE +
     * context, so the k3s init container (typo3-setup, no
     * TYPO3__EXTENSIONS__* variables) wrote the cache first and the app
     * container, which carries them, silently reused it -- the frankenphp
     * purge kept calling caddy-proxy.internal:2019 although its env said
     * caddy-proxy:443. Measured on kunde-xebro: cache file 187 bytes, written
     * 18 s before the app container started.
     *
     * The fingerprint over every TYPO3__* variable (name and value, sorted)
     * makes two processes with different overrides use two cache entries.
     * getenv() is used rather than $_ENV so the key does not depend on
     * variables_order.
     */
    final public function configLoaderCacheIdentifier(): string
    {
        $overrides = array_filter(
            getenv(),
            static fn (string $name): bool => str_starts_with($name, 'TYPO3__'),
            ARRAY_FILTER_USE_KEY
        );
        ksort($overrides);

        // No .env in the k3s image: filemtime() on a missing file warned on
        // every request before this guard.
        $envFile = Environment::getProjectPath() . '/.env';

        return md5(implode('|', [
            is_file($envFile) ? (string)filemtime($envFile) : '',
            (string)getenv('BUILD_DATE'),
            (string)$this->context,
            md5(serialize($overrides)),
        ]));
    }

    /**
     * Recursively unset keys present in $forbiddenKeys at any depth.
     *
     * @param array<mixed> $haystack
     * @param array<string, mixed> $forbiddenKeys
     */
    private static function removeForbiddenKeysRecursive(array &$haystack, array $forbiddenKeys): void
    {
        foreach ($haystack as $key => &$value) {
            if (isset($forbiddenKeys[$key])) {
                unset($haystack[$key]);
                continue;
            }
            if (is_array($value)) {
                self::removeForbiddenKeysRecursive($value, $forbiddenKeys);
            }
        }
    }

    final public function useImageMagick(string $path = '/usr/bin/'): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor'] = 'ImageMagick';
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path'] = $path;
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path_lzw'] = $path;
        return $this;
    }

    final public function useGraphicsMagick(string $path = '/usr/bin/'): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor'] = 'GraphicsMagick';
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path'] = $path;
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path_lzw'] = $path;
        return $this;
    }

    /**
     * Set image quality for resize/conversion operations.
     *
     * TYPO3 14 supports separate quality settings per output format via
     * `GFX.jpg_quality`, `GFX.webp_quality`, `GFX.avif_quality`. Values are
     * percent (1-100), runtime-validated via assertQualityRange().
     * Default is 85 across all formats; Lighthouse-Audits suggest ~78 as a
     * good compression/visual-quality tradeoff for web.
     *
     * `$webp`, `$avif`, and `$heif` default to `$jpeg` when not explicitly set,
     * so a single argument applies one quality setting to all formats.
     */
    final public function setImageQuality(int $jpeg, ?int $webp = null, ?int $avif = null, ?int $heif = null): self
    {
        $this->assertQualityRange('jpeg', $jpeg);
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['jpg_quality'] = $jpeg;
        $webpQ = $webp ?? $jpeg;
        $this->assertQualityRange('webp', $webpQ);
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['webp_quality'] = $webpQ;
        $avifQ = $avif ?? $jpeg;
        $this->assertQualityRange('avif', $avifQ);
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['avif_quality'] = $avifQ;
        $heifQ = $heif ?? $jpeg;
        $this->assertQualityRange('heif', $heifQ);
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['heif_quality'] = $heifQ;
        return $this;
    }

    /**
     * Set the working colorspace for the image processor.
     *
     * Critical for ImageMagick — `'RGB'` is interpreted as linear-light RGB
     * (gamma 1.0), causing resize operations to produce ~30 % darker output
     * than the source. For correct sRGB-encoded web images, ImageMagick
     * requires `'sRGB'` explicitly. GraphicsMagick interpreted `'RGB'` as
     * sRGB historically; for GM either `'RGB'` or `'sRGB'` produces correct
     * results.
     *
     * Allowed values: `'sRGB'`, `'RGB'`, `'Gray'`, `'CMYK'`.
     */
    final public function setImageColorspace(string $colorspace): self
    {
        $allowed = ['sRGB', 'RGB', 'Gray', 'CMYK'];
        if (!in_array($colorspace, $allowed, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid colorspace "%s". Allowed: %s', $colorspace, implode(', ', $allowed))
            );
        }
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_colorspace'] = $colorspace;
        return $this;
    }

    /**
     * Append file extensions to `GFX.imagefile_ext` (the list of formats TYPO3
     * accepts for FAL uploads and image processing).
     *
     * Duplicates are removed. Use lowercase extension names without leading dot.
     * Example: `->allowImageFileExtensions('heic', 'heif')` adds HEIC/HEIF
     * support (requires ImageMagick with libheif).
     */
    final public function allowImageFileExtensions(string ...$extensions): self
    {
        $current = (string) ($GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] ?? '');
        $list = array_filter(array_map('trim', explode(',', $current)), static fn (string $e): bool => $e !== '');
        foreach ($extensions as $ext) {
            $ext = strtolower(trim($ext, " \t.\n\r\0\x0B"));
            if ($ext !== '' && !in_array($ext, $list, true)) {
                $list[] = $ext;
            }
        }
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] = implode(',', $list);
        return $this;
    }

    /**
     * Validate quality value is in 1..100 range.
     */
    private function assertQualityRange(string $format, int $quality): void
    {
        if ($quality < 1 || $quality > 100) {
            throw new \InvalidArgumentException(
                sprintf('Quality for %s must be 1..100, got %d', $format, $quality)
            );
        }
    }

    final public function useMailpit(string $host = 'localhost', ?int $port = null): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'smtp';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_encrypt'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_password'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_server'] = $host . (null !== $port && 0 !== $port ? ':' . (string)$port : '');
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_username'] = '';
        return $this;
    }

    final public function allowNoCacheQueryParameter(): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['disableNoCacheParameter'] = false;
        return $this;
    }

    final public function forbidNoCacheQueryParameter(): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['disableNoCacheParameter'] = true;
        return $this;
    }

    /**
     * Set a custom backend entry point URL or path.
     *
     * Supports:
     * - Custom path: '/backend' or '/my-admin'
     * - Subdomain:   'https://admin.example.org'
     *
     * When using a subdomain, cookieDomain is automatically set so that
     * backend users can preview frontend pages and use the admin panel.
     *
     * @since TYPO3 13.0
     * @return $this
     */
    /**
     * Configure TYPO3 to trust a reverse proxy (e.g. Caddy, Nginx, HAProxy).
     *
     * Sets reverseProxyIP, reverseProxySSL, and reverseProxyHeaderMultiValue
     * so TYPO3 correctly detects HTTPS, client IPs, and host headers behind
     * a reverse proxy. Without this, Secure cookies won't work and the
     * backend login will fail silently.
     *
     * SECURITY: $trustedIPs default '*' means TYPO3 honours X-Forwarded-For
     * from any source — the public Internet can spoof client IPs and
     * thereby defeat devIPmask, lockToIP, audit-log attribution and any
     * IP-based rate limiter downstream. Pass the actual proxy address
     * (e.g. '172.20.0.0/16' for a docker bridge, '10.0.0.0/8' for a private
     * network, or the explicit proxy IP). The '*' default is kept for
     * backwards-compat with existing setups but emits a deprecation log
     * when used; tighten it project-by-project.
     *
     * @param string $trustedIPs Comma-separated proxy IPs / CIDRs, '*' = any
     * @return $this
     */
    final public function useReverseProxy(string $trustedIPs = '*'): self
    {
        if ('*' === $trustedIPs) {
            // Logged once per request — appears in deploy logs so the
            // wildcard does not stay invisible forever. Switch to an
            // explicit CIDR and the warning disappears.
            error_log(
                'moselwal/typo3-config: useReverseProxy(\'*\') trusts '
                . 'X-Forwarded-For from any source. Pass an explicit CIDR '
                . '(e.g. the docker bridge or the upstream proxy IP) to '
                . 'restore IP-spoofing protection. See security audit M7.'
            );
        }
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP'] = $trustedIPs;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxySSL'] = $trustedIPs;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyHeaderMultiValue'] = 'first';
        return $this;
    }

    /**
     * Aktiviert Audit-Logging fuer Authentication-Events (Failed-Logins,
     * Permission-Denied, MFA-Versuche). Schreibt parallel in FileWriter
     * (var/log/typo3_auth.log) und DatabaseWriter (sys_log mit channel=security),
     * sodass Brute-Force-Attacken nachvollziehbar sind und CrowdSec/SIEM-
     * Systeme eine Auswertbare Quelle haben.
     *
     * Mitigation fuer Pentest 2026-05-20 F-13.
     */
    final public function useAuditLogging(): self
    {
        $writers = [
            \TYPO3\CMS\Core\Log\LogLevel::NOTICE => [
                \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                    'logFileInfix' => 'auth',
                ],
                \TYPO3\CMS\Core\Log\Writer\DatabaseWriter::class => [
                    'logTable' => 'sys_log',
                ],
            ],
        ];
        $components = [
            'TYPO3' => [
                'CMS' => [
                    'Core' => [
                        'Authentication' => [
                            'AbstractUserAuthentication' => [
                                'writerConfiguration' => $writers,
                            ],
                        ],
                    ],
                    'Backend' => [
                        'Authentication' => [
                            'writerConfiguration' => $writers,
                        ],
                    ],
                ],
            ],
        ];
        // Bestehende LOG-Konfig mergen (nicht ueberschreiben).
        $GLOBALS['TYPO3_CONF_VARS']['LOG'] = array_replace_recursive(
            $GLOBALS['TYPO3_CONF_VARS']['LOG'] ?? [],
            $components
        );
        return $this;
    }

    /**
     * Haengt die am Edge erzeugte Request-ID an jeden Log-Eintrag.
     *
     * Traefik vergibt die ID am aeussersten Hop und reicht sie unveraendert
     * durch Caddy an die Anwendung. Ohne diesen Prozessor ist das
     * Anwendungs-Log die einzige Stelle, an der der Faden reisst — und
     * Korrelation bedeutet wieder Zeitstempel-Vergleich von Hand.
     *
     * Registriert global (LOG.processorConfiguration), nicht pro Komponente:
     * eine Korrelations-ID, die nur an manchen Eintraegen haengt, laesst genau
     * die Luecken, die man beim Nachverfolgen braucht.
     */
    final public function useRequestCorrelation(): self
    {
        $processors = [
            \TYPO3\CMS\Core\Log\LogLevel::DEBUG => [
                \Moselwal\Log\RequestIdProcessor::class => [],
            ],
        ];

        // Bestehende Prozessor-Konfig mergen (nicht ueberschreiben).
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['processorConfiguration'] = array_replace_recursive(
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['processorConfiguration'] ?? [],
            $processors
        );

        return $this;
    }

    /**
     * Setzt die Default-Cache-Lifetime fuer FE-Pages auf einen vernuenftigen
     * Wert (Default 1h statt TYPO3-Default 24h). Verhindert dass ein einmal
     * gepoisoneter Cache-Eintrag jahrelang persistiert. Kann von einzelnen
     * Pages via TypoScript `config.cache_period` ueberschrieben werden.
     *
     * Mitigation fuer Pentest 2026-05-20 F-15.
     */
    final public function useShorterCacheLifetime(int $seconds = 3600): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheTimeout'] = max(60, $seconds);
        return $this;
    }

    /**
     * Deaktiviert TYPO3-Debug-Cache-Header (X-TYPO3-Debug-Cache,
     * X-TYPO3-Cache-Tags, X-TYPO3-Cache-Lifetime, X-TYPO3-Parsetime). Diese
     * leaken interne Cache-Metadata + Internal-Tags an die Aussenwelt.
     *
     * Mitigation fuer Pentest 2026-05-20 F-16. Hinweis: Caddy strippt diese
     * Header zusaetzlich am Edge — Defense in Depth.
     */
    final public function useNoCacheDebugHeaders(): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['debug'] = false;
        $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheTimeoutResponseHeader'] = false;
        return $this;
    }

    final public function useBackendEntryPoint(string $entryPoint, ?string $cookieDomain = null): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] = $entryPoint;

        // Only set cookieDomain when explicitly provided.
        // Auto-detection is error-prone with multi-level subdomains.
        // Without cookieDomain, TYPO3 uses the request domain for cookies
        // which is correct for the backend login. For frontend preview by
        // backend users, set cookieDomain explicitly (e.g. '.moselwal.de').
        if ($cookieDomain !== null) {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['cookieDomain'] = $cookieDomain;
        }

        return $this;
    }

    /**
     * @deprecated Removed in TYPO3 14. Use content object exception handlers instead.
     */
    final public function allowInvalidCacheHashQueryParameter(): self
    {
        return $this;
    }

    /**
     * @deprecated Removed in TYPO3 14. Use content object exception handlers instead.
     */
    final public function forbidInvalidCacheHashQueryParameter(): self
    {
        return $this;
    }

    final public function excludeQueryParameterForCacheHashCalculation(string $queryParameter): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = $queryParameter;
        return $this;
    }

    /**
     * @param array<int, string> $queryParameters
     */
    final public function excludeQueryParametersForCacheHashCalculation(array $queryParameters): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'] = array_merge(
            $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'],
            $queryParameters
        );
        return $this;
    }

    final public function enableDeprecationLogging(): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['TYPO3']['CMS']['deprecations']['writerConfiguration'][LogLevel::NOTICE]['TYPO3\CMS\Core\Log\Writer\FileWriter']['disabled'] = false;
        return $this;
    }

    final public function disableDeprecationLogging(): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['TYPO3']['CMS']['deprecations']['writerConfiguration'][LogLevel::NOTICE]['TYPO3\CMS\Core\Log\Writer\FileWriter']['disabled'] = true;
        return $this;
    }

    /**
     * Additional Project-specific methods
     */
    final public function configureExceptionHandlers(string $productionExceptionHandlerClassName, string $debugExceptionHandlerClassName): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['productionExceptionHandler'] = $productionExceptionHandlerClassName;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['debugExceptionHandler'] = $debugExceptionHandlerClassName;
        return $this;
    }

    /**
     * Configures a log file for solr based on the TYPO3 Context, with a separate file for solr.
     *
     * @return $this
     */
    final public function autoconfigureSolrLogging(string $fileName = 'solr.log', ?string $forceLogLevel = null): self
    {
        if ($forceLogLevel !== null) {
            $logLevel = $forceLogLevel;
        } else {
            $logLevel = $this->context->isProduction() ? LogLevel::ERROR : LogLevel::DEBUG;
        }
        return $this->addFileLogger('ApacheSolrForTypo3\\Solr', $fileName, $logLevel);
    }

    /**
     * Shorthand function to add a file logger in a quick manner in the typical log folder.
     * @return $this
     */
    public function addFileLogger(string $namespace, ?string $fileName = null, ?string $logLevel = null): self
    {
        $fileName = $fileName ?? strtolower(str_replace('\\', '_', $namespace)) . '.log';
        if ($logLevel === null) {
            $logLevel = $this->context->isProduction() ? LogLevel::ERROR : LogLevel::DEBUG;
        }
        $logFile = $this->varPath . '/log/' . $fileName;
        $value = [
            'writerConfiguration' => [
                $logLevel => [
                    FileWriter::class => [
                        'logFile' => $logFile,
                    ],
                ],
            ],
        ];
        $GLOBALS['TYPO3_CONF_VARS']['LOG'] = ArrayUtility::setValueByPath($GLOBALS['TYPO3_CONF_VARS']['LOG'], $namespace, $value, '\\');
        return $this;
    }

    /**
     * Disable logging for a specific namespace.
     * @return $this
     */
    final public function setNullLogger(string $namespace, string $logLevel = LogLevel::DEBUG): self
    {
        $value = [
            'writerConfiguration' => [
                $logLevel => [
                    NullWriter::class => [],
                ],
            ],
        ];
        $GLOBALS['TYPO3_CONF_VARS']['LOG'] = ArrayUtility::setValueByPath($GLOBALS['TYPO3_CONF_VARS']['LOG'], $namespace, $value, '\\');
        return $this;
    }

    /**
     * @param array<string, array<string, mixed>> $additionalCachesKeyValue
     * @param array<string, array<string, mixed>> $additionalCachesAPCU
     */
    public function autoconfigureCaching(array $additionalCachesKeyValue = [], array $additionalCachesAPCU = [], string $keyvaluePassword = ''): self
    {
        $redisHost = trim(self::env('KEYVALUE_HOST'));
        if ('' !== $redisHost) {
            $redisPortRaw = trim(self::env('KEYVALUE_PORT'));
            $redisPort = (int)($redisPortRaw !== '' ? $redisPortRaw : '6379');
            if ($redisPort <= 0 || $redisPort > 65535) {
                $redisPort = 6379;
            }

            $keyvaluePassword = $this->resolveSecret('KEYVALUE_PASSWORD', $keyvaluePassword);

            $keyvalueTlsOptions = $this->autoconfigureKeyValueMtlsOptions($redisHost);

            // Locking strategy
            if (class_exists(KeyValueLockingStrategy::class)) {
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][KeyValueLockingStrategy::class] = [
                    'options' => array_replace([
                        'hostname' => $redisHost,
                        'database' => 0,
                        'port' => $redisPort,
                        'persistentConnection' => true,
                        'ttl' => 10,
                    ], $keyvalueTlsOptions),
                ];

                if (!is_null($keyvaluePassword)) {
                    $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][KeyValueLockingStrategy::class]['options']['password'] = $keyvaluePassword;
                }
            }

            // Sessions
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['session'] = [
                'BE' => [
                    'backend' => KeyValueSessionBackend::class,
                    'options' => array_replace([
                        'hostname' => $redisHost,
                        'database' => 1,
                        'port' => $redisPort,
                        'persistentConnection' => true,
                    ], $keyvalueTlsOptions)
                ],
                'FE' => [
                    'backend' => KeyValueSessionBackend::class,
                    'options' => array_replace([
                        'hostname' => $redisHost,
                        'database' => 2,
                        'port' => $redisPort,
                        'persistentConnection' => true,
                    ], $keyvalueTlsOptions)
                ],
            ];

            if (!is_null($keyvaluePassword)) {
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['session']['BE']['options']['password'] = $keyvaluePassword;
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['session']['FE']['options']['password'] = $keyvaluePassword;
            }

            $redisCaches = array_merge(
                [
                    // Core
                    'pages' => [
                        'defaultLifetime' => 86400*30, // 1 month
                        'compression' => true,
                    ],
                    'hash' => [
                        'defaultLifetime' => 86400*30,
                    ],
                    'rootline' => [
                        'defaultLifetime' => 86400*30,
                    ],
                    'imagesizes' => [],
                    'workspaces_cache' => [],
                    // Extensions
                    'preview_renderer_cache' => [],
                    'sg_mail_registerArrayCache' => [],
                    'l10n' => [
                        'defaultLifetime' => 86400*30,
                    ],
                ],
                $additionalCachesKeyValue
            );

            // ClusterFileBackend (moselwal/cluster-file-backend) keeps its
            // central truth in a TYPO3 cache frontend called `cluster_meta`.
            // When the extension is installed, register that frontend on the
            // same KeyValue connection — that is the natural high-throughput
            // backend and avoids a second, duplicate Redis config block in
            // useClusterFileBackend(). `defaultLifetime => 0` means "no TTL
            // at the metadata layer"; the cluster backend manages expiry
            // itself via `expiresAt` in each metadata record.
            if (class_exists(ClusterFileBackend::class) && !isset($redisCaches['cluster_meta'])) {
                $redisCaches['cluster_meta'] = ['defaultLifetime' => 0];
            }

            // pagesection cache was removed in TYPO3 12
            unset($redisCaches['pagesection'], $redisCaches['cache_pagesection']);

            // imagesizes cache was removed in TYPO3 13
            if ($this->version->getMajorVersion() >= 13) {
                unset($redisCaches['imagesizes']);
            }

            $redisDatabase = 3;
            foreach ($redisCaches as $name => $values) {
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$name]['backend']
                    =  KeyValueBackend::class;
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$name]['options'] = array_replace([
                    'database' => $redisDatabase++,
                    'hostname' => $redisHost,
                    'port' => $redisPort,
                    'persistentConnection' => true,
                ], $keyvalueTlsOptions);
                if (isset($values['defaultLifetime'])) {
                    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$name]['options']['defaultLifetime']
                        = $values['defaultLifetime'];
                }
                if (isset($values['compression'])) {
                    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$name]['options']['compression']
                        = $values['compression'];
                }
                if (!is_null($keyvaluePassword)) {
                    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$name]['options']['password'] = $keyvaluePassword;
                }
            }
        }

        if (function_exists('apcu_enabled') && apcu_enabled()) {

            $apcuCaches = array_merge([
                'extbase'        => ['defaultLifetime' => 86400*30],
                'l10n'           => ['defaultLifetime' => 86400*30],
                'ratelimiter'    => [],
                'dashboard_rss'  => ['defaultLifetime' => 86400*30],
                'assets'         => ['defaultLifetime' => 86400*30],
            ],
                $additionalCachesAPCU
            );

            if ('' === self::env('KEYVALUE_HOST')) {
                $apcuCaches = array_merge($apcuCaches, [
                    'pages'                 => ['defaultLifetime' => 86400*30],
                    'hash'                  => ['defaultLifetime' => 86400*30],
                    'rootline'              => ['defaultLifetime' => 86400*30],
                    'imagesizes'            => [],
                    'workspaces_cache'      => [],
                    'preview_renderer_cache'=> [],
                ]);
            }

            foreach ($apcuCaches as $name => $values) {
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$name]['backend']
                    =  \TYPO3\CMS\Core\Cache\Backend\ApcuBackend::class;
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$name]['options'] = [];
                if (isset($values['defaultLifetime'])) {
                    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$name]['options']['defaultLifetime']
                        = $values['defaultLifetime'];
                }
            }
        }

        // Auto-config: when moselwal/cluster-file-backend is installed,
        // move every file-backed cache (except `core`) onto the cluster
        // backend. Silent no-op when the package is not present, so this
        // call is safe in every project.
        $this->useClusterFileBackend();

        return $this;
    }

    /**
     * Re-wires every cache that is currently backed by TYPO3 Core's
     * {@see FileBackend} or {@see SimpleFileBackend} onto the cluster-aware
     * {@see ClusterFileBackend} from `moselwal/cluster-file-backend`. The
     * `core` cache (PHP code cache) is excluded by default because it
     * already ships immutably with the container image and is safely
     * pod-local.
     *
     * Auto-config behaviour: {@see autoconfigureCaching()} calls this
     * method at the end of its own wiring. It is a silent no-op when
     * `moselwal/cluster-file-backend` is not installed
     * (`class_exists(ClusterFileBackend::class) === false`). Once the
     * extension is present in the project, every file-backed cache
     * automatically moves onto the cluster backend without any further
     * configuration — the central truth lives in the metadata cache, while
     * payloads are written to a pod-local emptyDir.
     *
     * Metadata cache (`cluster_meta`): the truth store is *not* reconfigured
     * here. {@see autoconfigureCaching()} already adds `cluster_meta` to its
     * KeyValue cache block whenever Redis/Valkey is available — that is the
     * preferred high-throughput backend and the wiring lives in exactly one
     * place. This method only adds a zero-dependency fallback on
     * {@see Typo3DatabaseBackend} when nothing has registered `cluster_meta`
     * yet, so consumers that skip {@see autoconfigureCaching()} still get a
     * working setup. Don't duplicate the KeyValue config here — it would
     * drift from `autoconfigureCaching()`.
     *
     * Why this works: cluster-file-backend keeps cache validity in the
     * (clustered) metadata cache, while the (large) payload bytes are
     * materialised on each pod's emptyDir. That is the exact contract the
     * TYPO3 file-backed caches need in Kubernetes — every other backend
     * choice in `cacheConfigurations` is left untouched.
     *
     * Environment overrides (all optional):
     *  - `CLUSTER_CACHE_LOCAL_PATH`  — base path for payload stores
     *                                  (default: `<varPath>/cache/cluster`).
     *  - `CLUSTER_CACHE_INSTANCE`    — namespace instance slug
     *                                  (default: `gethostname()` lowercased,
     *                                  fallback `'main'`).
     *  - `CLUSTER_CACHE_ENVIRONMENT` — overrides the auto-derived
     *                                  environment name (`prod` |
     *                                  `staging` | `testing` |
     *                                  `development`).
     *
     * @param array<int, string> $excludeCaches Caches to keep on their
     *     current backend even if file-backed. Defaults are the caches
     *     that TYPO3 instantiates during the FailsafeContainer bootstrap
     *     phase (`Bootstrap::createCache()` direct call sites — see
     *     `BootService::getCoreCache()`, `ServiceProvider::getAssetsCache()`,
     *     `SchemaMigrator`). These caches are constructed *before* the
     *     Symfony container with extension-provided DI mappings exists,
     *     so a backend whose constructor needs the regular DI container
     *     (or the CacheManager) would dead-lock. The PHP code cache
     *     (`core`) also benefits from pod-local file storage for opcache
     *     warm-up. Add more to opt out (e.g. on a cache you intentionally
     *     keep on `SimpleFileBackend` for debugging).
     */
    public function useClusterFileBackend(array $excludeCaches = ['core', 'assets', 'database_schema']): self
    {
        if (!class_exists(ClusterFileBackend::class)) {
            return $this;
        }

        $configurations = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] ?? [];
        $candidates = [];
        foreach ($configurations as $cacheName => $config) {
            if (in_array($cacheName, $excludeCaches, true)) {
                continue;
            }
            $backend = $config['backend'] ?? null;
            if ($backend !== SimpleFileBackend::class && $backend !== FileBackend::class) {
                continue;
            }
            // PhpFrontend caches (typoscript, fluid_template, …) are migrated
            // too: moselwal/cluster-file-backend >= 2.2.0 implements
            // PhpCapableBackendInterface, including marker-prefixed payloads
            // and a `.php`-suffix on the pod-local store so OPcache can pick
            // up the files. On older cluster-file-backend versions this would
            // crash at PhpFrontend's constructor type-check — that is the
            // composer constraint's job to prevent, not ours here.
            $candidates[] = (string)$cacheName;
        }

        if ($candidates === []) {
            // Nothing file-backed to migrate — skip the metadata cache
            // registration too so the consumer's TYPO3 install stays lean.
            return $this;
        }

        $localPathEnv = getenv('CLUSTER_CACHE_LOCAL_PATH');
        $localPathBase = rtrim(is_string($localPathEnv) ? $localPathEnv : '', '/');
        if ($localPathBase === '') {
            $localPathBase = rtrim($this->varPath, '/') . '/cache/cluster';
        }

        $instanceEnv = getenv('CLUSTER_CACHE_INSTANCE');
        $instance = is_string($instanceEnv) ? $instanceEnv : '';
        if ($instance === '') {
            $hostname = gethostname();
            $instance = is_string($hostname) && $hostname !== '' ? strtolower($hostname) : 'main';
        }
        // Constrain to the schema's `[a-z0-9-]{1,64}` — the backend rejects anything else.
        $instance = substr((string)preg_replace('/[^a-z0-9-]/', '-', strtolower($instance)), 0, 64);
        if ($instance === '') {
            $instance = 'main';
        }

        $environmentEnv = getenv('CLUSTER_CACHE_ENVIRONMENT');
        $environment = is_string($environmentEnv) ? $environmentEnv : '';
        if ($environment === '') {
            $environment = match (true) {
                $this->context->isProduction() => 'prod',
                $this->context->isTesting() => 'testing',
                $this->context->isDevelopment() => 'development',
                default => 'development',
            };
        }

        $metadataCacheIdentifier = 'cluster_meta';

        // Zero-dependency fallback for `cluster_meta`. autoconfigureCaching()
        // already registers it on KeyValueBackend when Redis is available
        // (see the `cluster_meta` block there); we only fill the gap when
        // nothing has wired the metadata cache yet — keeps the wiring
        // DRY with a single source of truth for the KeyValue config.
        if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$metadataCacheIdentifier])) {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$metadataCacheIdentifier] = [
                'frontend' => VariableFrontend::class,
                'backend' => Typo3DatabaseBackend::class,
                'options' => [],
                'groups' => ['system'],
            ];
        }

        // Re-wire every file-backed cache. We preserve the existing frontend
        // (the consumer chose `PhpFrontend` or `FluidTemplateCache` for a
        // reason) plus any `defaultLifetime` and `groups` so the override is
        // minimally invasive — only `backend` and `options` get replaced.
        foreach ($candidates as $cacheName) {
            $existing = $configurations[$cacheName];

            $options = [
                'localPath' => $localPathBase . '/' . $cacheName,
                'metadataCacheIdentifier' => $metadataCacheIdentifier,
                'namespace' => [
                    'environment' => $environment,
                    'instance' => $instance,
                ],
            ];
            // Only forward a positive defaultLifetime — the ClusterFileBackend
            // option-schema rejects 0 (which TYPO3 treats as "cache forever").
            // For 0/missing values we let the backend pick its own default
            // (3600s as of v1.0.x), which is the safest cluster-default and
            // matches what `SimpleFileBackend` consumers had in practice.
            $existingLifetime = isset($existing['options']['defaultLifetime'])
                ? (int)$existing['options']['defaultLifetime']
                : 0;
            if ($existingLifetime > 0) {
                $options['defaultLifetimeSeconds'] = $existingLifetime;
            }

            $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$cacheName] = array_replace(
                $existing,
                [
                    'backend' => ClusterFileBackend::class,
                    'options' => $options,
                ],
            );
        }

        return $this;
    }

    /**
     * Resolves a secret based on a single logical key (e.g. "DB_PASSWORD").
     * It tries:
     *  1. File from ENV: ${KEY}_FILE (e.g. DB_PASSWORD_FILE)
     *  2. Default file: /run/secrets/${key_lower}
     *  3. ENV: ${KEY}
     *  4. Optional fallback value
     *
     * @param string $key Logical secret identifier (e.g. "DB_PASSWORD")
     * @param string|null $fallback Optional fallback value
     * @return string|null The resolved secret or null
     */
    final protected function resolveSecret(
        ?string $key = null,
        ?string $fallback = null
    ): ?string {
        if ($key === null) {
            return $fallback;
        }

        $envFileKey = strtoupper($key) . '_FILE';
        $defaultFile = '/run/secrets/' . strtolower($key);

        $envFilePath = trim(self::env($envFileKey));
        if ($envFilePath !== '' && is_readable($envFilePath)) {
            $value = trim((string)file_get_contents($envFilePath));
            if ($value !== '') {
                return $value;
            }
        }

        if (is_readable($defaultFile)) {
            $value = trim((string)file_get_contents($defaultFile));
            if ($value !== '') {
                return $value;
            }
        }

        $envValue = trim(self::env($key));
        if ($envValue !== '') {
            return $envValue;
        }

        if ($fallback !== null && trim($fallback) !== '') {
            return trim($fallback);
        }

        return null;
    }

    /**
     * Auto-configure PDO MySQL TLS/mTLS driver options if certificate files are present.
     *
     * This configures the `DB/Connections/Default/driverOptions` array so PDO uses TLS with
     * client certificate authentication (mTLS) when the files exist.
     *
     * No CA present means no internal TLS in this deployment and the connection
     * is left alone. A readable CA with an unreadable client identity is a fault
     * and throws rather than silently connecting unencrypted.
     *
     * @throws \RuntimeException when internal TLS is in use but the client
     *                            certificate or key cannot be read
     */
    private function autoconfigureDatabaseMtls(string $connectionName = 'Default'): void
    {
        // Resolve certificate paths.
        // 1) Prefer name-based resolution (single logical name -> cert/key in a directory)
        // 2) Fall back to explicit file paths
        // 3) Finally fall back to the conventional defaults in /run/tls

        $tlsDir = rtrim(trim(self::env('DB_SSL_DIR')), '/');
        if ($tlsDir === '') {
            $tlsDir = '/run/tls';
        }

        // Try to infer a reasonable certificate basename.
        // - DB_SSL_NAME allows explicitly setting the client cert basename.
        // - Otherwise we try to derive from the configured DB host (first label),
        //   and finally fall back to "httpd" (your current convention).
        $dbHost = trim(self::env('TYPO3__DB__Connections__Default__host'));
        $hostLabel = $dbHost !== '' ? explode('.', $dbHost, 2)[0] : '';

        $nameCandidates = array_values(array_filter([
            trim(self::env('DB_SSL_NAME')),
            $hostLabel,
            'httpd',
        ], static fn ($v) => $v !== ''));

        $caFile = '';
        $certFile = '';
        $keyFile = '';

        // Name-based resolution: <dir>/ca.crt + <dir>/<name>.crt + <dir>/<name>.key
        foreach ($nameCandidates as $name) {
            $candidateCa = $tlsDir . '/ca.crt';
            $candidateCert = $tlsDir . '/' . $name . '.crt';
            $candidateKey = $tlsDir . '/' . $name . '.key';

            if (self::certIsReadable($candidateCa)
                && self::certIsReadable($candidateCert)
                && self::certIsReadable($candidateKey)
            ) {
                $caFile = $candidateCa;
                $certFile = $candidateCert;
                $keyFile = $candidateKey;
                break;
            }
        }

        // Explicit file path overrides (fallback)
        if ($caFile === '') {
            $caFile = trim(self::env('DB_SSL_CA'));
        }
        if ($certFile === '') {
            $certFile = trim(self::env('DB_SSL_CERT'));
        }
        if ($keyFile === '') {
            $keyFile = trim(self::env('DB_SSL_KEY'));
        }

        // Conventional defaults (final fallback)
        if ($caFile === '') {
            $caFile = '/run/tls/ca.crt';
        }
        if ($certFile === '') {
            $certFile = '/run/tls/httpd.crt';
        }
        if ($keyFile === '') {
            $keyFile = '/run/tls/httpd.key';
        }

        // Same rule as the KeyValue side, and for the same reason. No CA means
        // this deployment does not do internal TLS -- a local compose stack, and
        // an unencrypted connection is correct there.
        if (!self::certIsReadable($caFile)) {
            return;
        }

        // A CA that IS readable says this deployment does mTLS, so a missing
        // client identity is a fault. This one matters MORE than the cache: the
        // server on the other end is MariaDB, which happily accepts an
        // unencrypted connection. valkey refuses one and turns a downgrade into
        // a visible failure; MariaDB would have carried the credentials and
        // every row in the clear, and nothing anywhere would have said so.
        $missing = [];
        if (!self::certIsReadable($certFile)) {
            $missing[] = $certFile;
        }
        if (!self::certIsReadable($keyFile)) {
            $missing[] = $keyFile;
        }
        if ([] !== $missing) {
            throw new \RuntimeException(sprintf(
                'Database mTLS is configured -- %s is readable -- but the client identity is not: %s. '
                . 'Refusing to fall back to an unencrypted connection.',
                $caFile,
                implode(', ', $missing)
            ));
        }

        // PHP 8.5+: Pdo\Mysql constants, fallback to PDO constants for older PHP
        $sslCa = class_exists(\Pdo\Mysql::class) ? \Pdo\Mysql::ATTR_SSL_CA : \PDO::MYSQL_ATTR_SSL_CA;
        $sslCert = class_exists(\Pdo\Mysql::class) ? \Pdo\Mysql::ATTR_SSL_CERT : \PDO::MYSQL_ATTR_SSL_CERT;
        $sslKey = class_exists(\Pdo\Mysql::class) ? \Pdo\Mysql::ATTR_SSL_KEY : \PDO::MYSQL_ATTR_SSL_KEY;

        $driverOptions = [
            $sslCa => $caFile,
            $sslCert => $certFile,
            $sslKey => $keyFile,
        ];

        // Available in mysqlnd; if not defined, we skip it.
        if (class_exists(\Pdo\Mysql::class) && defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')) {
            $driverOptions[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = true;
        } elseif (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $driverOptions[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        // Merge into existing connection options.
        $existing = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'][$connectionName]['driverOptions'] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'][$connectionName]['driverOptions'] = array_replace($existing, $driverOptions);
    }

    /**
     * @param string|null $dbUser
     * @param string|null $dbPassword
     * @param string|null $encryptionKey
     * @param string|null $installToolPassword
     * @return $this
     */
    final public function loadCoreSecrets(
        ?string $dbUser = null,
        ?string $dbPassword = null,
        ?string $encryptionKey = null,
        ?string $installToolPassword = null,
    ): self
    {
        $this->setConfigPathValues('DB/Connections/Default', [
            'user' => $this->resolveSecret('DB_USER', $dbUser),
            'password' => $this->resolveSecret('DB_PASSWORD', $dbPassword),
        ]);

        // Automatically enable TLS/mTLS for the database if certificates are available.
        $this->autoconfigureDatabaseMtls('Default');

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] =
            $this->resolveSecret('ENCRYPTION_KEY', $encryptionKey);

        $GLOBALS['TYPO3_CONF_VARS']['BE']['installToolPassword'] =
            $this->resolveSecret( 'INSTALL_TOOL_PASSWORD', $installToolPassword);

        return $this;
    }

    /**
     * Loads the mail credentials, and switches the transport to the DSN when one
     * is present.
     *
     * The transport line is not a convenience. Until 2026-08-22 this method set
     * MAIL/dsn and nothing else, while MAIL/transport stayed at TYPO3's default
     * of 'sendmail' — and useMailpit(), the only other place that touches the
     * transport, runs solely in useDevelopmentPreset(). Every production site
     * built on this package therefore handed its mail to a sendmail binary that
     * does not exist in the container: no exception, no log line, mail simply
     * gone. A DSN that is configured and never read is worse than none, because
     * it reads like the feature is wired.
     *
     * Only set when a DSN actually resolves. An installation that configures SMTP
     * through transport_smtp_* and leaves MAIL_DSN unset keeps working exactly as
     * before.
     */
    final public function loadMailSecrets(?string $mailPassword = null, ?string $mailUsername = null, ?string $mailDSN = null): self
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_password'] =
            $this->resolveSecret('MAIL_PASSWORD', $mailPassword);

        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_username'] =
            $this->resolveSecret('MAIL_USERNAME', $mailUsername);

        $dsn = $this->resolveSecret('MAIL_DSN', $mailDSN);
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['dsn'] = $dsn;

        if (is_string($dsn) && trim($dsn) !== '') {
            $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'dsn';
        }

        return $this;
    }

    /**
     * Register GitLab as an OAuth2 login provider for the TYPO3 backend.
     *
     * Requires mfc/oauth2. If the extension is missing, or either credential
     * cannot be resolved, this method does nothing at all: no login provider,
     * and cookieSameSite stays on the hardened default. That makes it safe to
     * call unconditionally from any additional.php, including instances that
     * have no GitLab behind them.
     *
     * Authorization hangs off ONE GitLab project. Anyone who is not a member
     * of it cannot reach the backend, because the auth service only returns
     * success for an access level above zero. From $adminUserLevel upwards the
     * user becomes a TYPO3 admin. For anything in between, put the GitLab
     * access level (10/20/30/40) into the "gitlabGroup" field of a be_groups
     * record and the group is assigned on login.
     *
     * Password and passkey login are untouched — this adds a third way in, it
     * does not replace the other two.
     *
     * @param string $gitlabServer Base URL of the GitLab instance, e.g. 'https://git.example.com'
     * @param string $projectName Project path whose membership governs backend access, e.g. 'devops/typo3-backend-access'
     * @param int $adminUserLevel GitLab access level from which the user becomes a TYPO3 admin (10 Guest, 20 Reporter, 30 Developer, 40 Maintainer)
     * @param string $defaultGroups Comma-separated be_groups UIDs used when no gitlabGroup mapping matches
     * @param bool $blockExternalUsers Refuse users carrying GitLab's "external" flag
     * @param bool $overrideUser Re-apply admin/disable/starttime/endtime from GitLab on every login
     * @param string $label Text on the backend login screen
     * @param string|null $appId Fallback when GITLAB_OAUTH_APP_ID cannot be resolved
     * @param string|null $appSecret Fallback when GITLAB_OAUTH_APP_SECRET cannot be resolved
     * @return $this
     */
    final public function useGitLabBackendLogin(
        string $gitlabServer,
        string $projectName,
        int $adminUserLevel = 40,
        string $defaultGroups = '0',
        bool $blockExternalUsers = true,
        bool $overrideUser = false,
        string $label = 'Login mit GitLab',
        ?string $appId = null,
        ?string $appSecret = null,
    ): self {
        if (!class_exists(OAuth2ResourceServerRegistry::class) || !class_exists(UpstreamGitLabResourceServer::class)) {
            return $this;
        }

        $appId = (string)$this->resolveSecret('GITLAB_OAUTH_APP_ID', $appId);
        $appSecret = (string)$this->resolveSecret('GITLAB_OAUTH_APP_SECRET', $appSecret);

        // Half a credential pair is not a configuration. Registering the
        // provider anyway would put a login button on the screen that can only
        // ever fail, and would have loosened cookieSameSite for nothing.
        if ($appId === '' || $appSecret === '') {
            return $this;
        }

        $gitlabServer = rtrim(trim($gitlabServer), '/');
        if ($gitlabServer === '' || $projectName === '') {
            return $this;
        }

        // The OAuth2 callback is a cross-site navigation coming back from
        // GitLab. Under SameSite=strict the browser withholds the nonce cookie
        // that carries the request token, and the callback fails the CSRF
        // check every time. 'lax' still covers the cases strict was chosen for
        // here, because it only relaxes top-level GET navigations.
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = 'lax';

        // ext_localconf.php of mfc/oauth2 reads these through the
        // ExtensionConfiguration API and only registers the login provider
        // when enableBackendLogin is on. additional.php runs before that, so
        // the whole feature stays configuration-driven — nothing to click in
        // the install tool, nothing that can drift per instance.
        $this->setConfigPathValues('EXTENSIONS/oauth2', [
            'enableBackendLogin' => '1',
            'overrideUser' => $overrideUser ? '1' : '0',
            'backendUserRealNameFormat' => '%name%',
        ]);

        OAuth2ResourceServerRegistry::addServer(
            'gitlab',
            $label,
            GitLabResourceServer::class,
            [
                'enabled' => true,
                'arguments' => [
                    'appId' => $appId,
                    'appSecret' => $appSecret,
                    'gitlabServer' => $gitlabServer,
                    'gitlabAdminUserLevel' => $adminUserLevel,
                    'gitlabDefaultGroups' => $defaultGroups,
                    // be_users.options is a bit field: 1 = inherit page mounts
                    // from groups, 2 = inherit file mounts. Without both, the
                    // group mapping above would assign groups that grant the
                    // user nothing.
                    'gitlabUserOption' => 3,
                    'blockExternalUser' => $blockExternalUsers,
                    'projectName' => $projectName,
                ],
            ]
        );

        return $this;
    }

    /**
     * Useful for distributed systems to put caches outside an NFS mount.
     *
     * @param array<int, string>|null $applyForCaches
     * @return $this
     */
    public function setAlternativeCachePath(string $path, ?array $applyForCaches = null): self
    {
        $applyForCaches = $applyForCaches ?? [
            'cache_core',
            'fluid_template',
            'assets',
            'l10n',
        ];
        foreach ($applyForCaches as $cacheName) {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$cacheName]['options']['cacheDirectory'] = $path;
        }
        return $this;
    }

    /**
     * Set PHP configuration settings.
     *
     * This method attempts to set PHP configuration settings using ini_set.
     * If ini_set is disabled (e.g., due to server restrictions), the setting will not be applied,
     * and a warning will be logged.
     *
     * Example:
     * (new \Moselwal\Config())->setPhpSettings([
     *      'max_execution_time' => 1000,
     *      'max_input_time' => 1000,
     *      'post_max_size' => '100M',
     *      'upload_max_filesize' => '100M',
     * ]);
     *
     * @param array<string, string> $settings An associative array of PHP settings.
     * @return $this
     */
    final public function setPhpSettings(array $settings): self
    {
        foreach ($settings as $key => $value) {
            try {
                if (function_exists('ini_set')) {
                    ini_set($key, $value);
                } else {
                    error_log("Unable to set PHP setting $key: ini_set is disabled.");
                }
            } catch (\ErrorException $e) {
                error_log("Error setting PHP configuration for $key: " . $e->getMessage());
            }
        }

        return $this;
    }

    /**
     * Override or append TYPO3 configuration settings for a given path with key-value pairs.
     *
     * Examples:
     * (new \Moselwal\Config())->setConfigPathValues('EXTENSIONS/alterations', ['just-a-test' => 'test']);
     * (new \Moselwal\Config())->setConfigPathValues(
     *   'SYS',
     *   [
     *      'just-a-test' => 'test',
     *      'defaultScheme' => 'https'
     *   ]);
     *
     * @param string $configPath A string representing the path to the configuration option.
     * @param array<string, mixed> $keyValuePairs An associative array of key-value pairs to set within the specified path.
     * @return $this
     */
    public function setConfigPathValues(string $configPath, array $keyValuePairs): self
    {
        $config = &$GLOBALS['TYPO3_CONF_VARS'];

        $configPath = explode('/', $configPath);

        foreach ($configPath as $key => $value) {
            if (!isset($config[$value])) {
                $config[$value] = [];
            }
            $config = &$config[$value];
        }

        $config = array_replace_recursive($config, $keyValuePairs);

        return $this;
    }

    /**
     * `is_readable()` through a cleared stat cache.
     *
     * Kubernetes replaces a projected secret by swapping the `..data` symlink,
     * and PHP caches stat results per path -- the realpath cache included. A
     * FrankenPHP worker outlives such a swap, so it can answer from an entry for
     * the replaced inode and report a perfectly readable certificate as missing.
     *
     * On 2026-09-03 that window was ten seconds wide in `kunde-findready`, right
     * after cert-manager renewed `findready-app-tls`: the certificates read as
     * unreadable, mTLS was skipped, and eight connections were rejected by
     * valkey with `SSL routines::wrong version number` while TYPO3 reported
     * `RedisException: read error on connection to cache:6379`. The swap itself
     * is atomic -- at every instant the path resolves to one complete directory
     * or the other -- so clearing the cache is the whole fix; there is nothing
     * to wait for and no retry here.
     */
    private static function certIsReadable(string $path): bool
    {
        if ('' === $path) {
            return false;
        }

        clearstatcache(true, $path);

        return is_readable($path);
    }

    /**
     * Autoconfigure Redis/Valkey TLS/mTLS options for moselwal/keyvalue-store if certificate files are present.
     *
     * Returns an options array compatible with KeyValueConnectionFactory:
     *  - tls, ca_file, cert_file, key_file, peer_name, verify_peer, verify_peer_name, allow_self_signed
     *
     * Resolution order:
     *  1) Name-based: ${KEYVALUE_SSL_DIR:-/run/tls}/ca.crt + ${name}.crt + ${name}.key
     *     name candidates: KEYVALUE_SSL_NAME, first label of KEYVALUE_HOST, fallback "httpd"
     *  2) Explicit paths: KEYVALUE_SSL_CA / KEYVALUE_SSL_CERT / KEYVALUE_SSL_KEY
     *  3) Conventional defaults: /run/tls/ca.crt + /run/tls/httpd.crt + /run/tls/httpd.key
     *
     * No CA anywhere means the deployment does not do internal TLS at all and an
     * empty array is the right answer -- a local compose stack runs valkey
     * without it. A CA that IS present and a client identity that is not is a
     * fault, and this method says so instead of handing back an empty array:
     * that array drops `tls` and downgrades the connection to plaintext.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when internal TLS is in use but the client
     *                            certificate or key cannot be read
     */
    private function autoconfigureKeyValueMtlsOptions(string $host): array
    {
        $tlsDir = rtrim(trim(self::env('KEYVALUE_SSL_DIR')), '/');
        if ($tlsDir === '') {
            $tlsDir = '/run/tls';
        }

        $hostLabel = $host !== '' ? explode('.', $host, 2)[0] : '';

        $nameCandidates = array_values(array_filter([
            trim(self::env('KEYVALUE_SSL_NAME')),
            $hostLabel,
            'httpd',
        ], static fn ($v) => $v !== ''));

        $caFile = '';
        $certFile = '';
        $keyFile = '';

        foreach ($nameCandidates as $name) {
            $candidateCa = $tlsDir . '/ca.crt';
            $candidateCert = $tlsDir . '/' . $name . '.crt';
            $candidateKey = $tlsDir . '/' . $name . '.key';

            if (self::certIsReadable($candidateCa)
                && self::certIsReadable($candidateCert)
                && self::certIsReadable($candidateKey)
            ) {
                $caFile = $candidateCa;
                $certFile = $candidateCert;
                $keyFile = $candidateKey;
                break;
            }
        }

        if ($caFile === '') {
            $caFile = trim(self::env('KEYVALUE_SSL_CA'));
        }
        if ($certFile === '') {
            $certFile = trim(self::env('KEYVALUE_SSL_CERT'));
        }
        if ($keyFile === '') {
            $keyFile = trim(self::env('KEYVALUE_SSL_KEY'));
        }

        if ($caFile === '') {
            $caFile = '/run/tls/ca.crt';
        }
        if ($certFile === '') {
            $certFile = '/run/tls/httpd.crt';
        }
        if ($keyFile === '') {
            $keyFile = '/run/tls/httpd.key';
        }

        if (!self::certIsReadable($caFile)) {
            // No CA at all: this deployment does not do internal TLS. A local
            // compose stack runs valkey without it, and plaintext is correct there.
            return [];
        }

        // The CA is there, so this deployment DOES do internal TLS. A missing
        // client identity is then a fault, not a configuration. Returning an
        // empty array here would silently downgrade the connection to plaintext:
        // valkey runs tls-port only, so it rejects that with `wrong version
        // number` while TYPO3 reports `read error on connection` from somewhere
        // deep in a Fluid render -- and the `password` below would have gone
        // onto the wire in the clear on any server that did accept it.
        $missing = [];
        if (!self::certIsReadable($certFile)) {
            $missing[] = $certFile;
        }
        if (!self::certIsReadable($keyFile)) {
            $missing[] = $keyFile;
        }
        if ([] !== $missing) {
            throw new \RuntimeException(sprintf(
                'KeyValue mTLS is configured -- %s is readable -- but the client identity is not: %s. '
                . 'Refusing to fall back to an unencrypted connection.',
                $caFile,
                implode(', ', $missing)
            ));
        }

        // peer_name should match the server certificate CN/SAN; allow override.
        $peerName = trim(self::env('KEYVALUE_TLS_PEER_NAME'));
        if ($peerName === '') {
            $peerName = $host;
        }

        return [
            'tls' => true,
            'ca_file' => $caFile,
            'cert_file' => $certFile,
            'key_file' => $keyFile,
            'peer_name' => $peerName,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ];
    }

    /**
     * getenv() as a string, without the short ternary.
     *
     * getenv() signals "not set" with false, and `?: ''` conflated that with
     * an empty value while relying on loose truthiness — so a variable set to
     * "0" read as unset. The explicit check says what is meant and keeps that
     * case intact.
     */
    private static function env(string $key): string
    {
        $value = getenv($key);

        return false === $value ? '' : $value;
    }
}
