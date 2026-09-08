<?php

declare(strict_types=1);

namespace Moselwal;

interface ConfigInterface
{
public function applyDefaults(): self;
public function appendContextToSiteName(): self;
public function useCliPreset(): self;
public function useProductionPreset(): self;
public function useProductionPresetVHost(): self;
public function useDevelopmentPreset(): self;
public function useFileFill(): self;
/** @param array<string, int> $forbiddenKeys */
public function useConfigLoader(array $forbiddenKeys = []): self;
/** Cache key of the env-override cache: .env mtime, BUILD_DATE, context and a fingerprint of every TYPO3__* variable. */
public function configLoaderCacheIdentifier(): string;
public function useGraphicsMagick(string $path = '/usr/bin/'): self;
public function useImageMagick(string $path = '/usr/bin/'): self;
public function setImageQuality(int $jpeg, ?int $webp = null, ?int $avif = null, ?int $heif = null): self;
public function setImageColorspace(string $colorspace): self;
public function allowImageFileExtensions(string ...$extensions): self;
public function useMailpit(string $host = 'localhost', ?int $port = null): self;
/**
 * @param array<string, array<string, mixed>> $additionalCachesKeyValue
 * @param array<string, array<string, mixed>> $additionalCachesAPCU
 */
public function autoconfigureCaching(array $additionalCachesKeyValue = [], array $additionalCachesAPCU = [], string $keyvaluePassword = ''): self;
/** @param array<int, string> $excludeCaches */
public function useClusterFileBackend(array $excludeCaches = ['core', 'assets', 'database_schema']): self;
/** @param array<int, string>|null $applyForCaches */
public function setAlternativeCachePath(string $path, ?array $applyForCaches = null): self;
/** @param array<string, string> $settings */
public function setPhpSettings(array $settings): self;
/** @param array<string, mixed> $keyValuePairs */
public function setConfigPathValues(string $configPath, array $keyValuePairs): self;

/** @param array<string, mixed>|null $options */
public function initializeDatabaseConnection(?array $options = null, string $connectionName = 'Default'): self;
public function allowNoCacheQueryParameter(): self;
public function forbidNoCacheQueryParameter(): self;
public function useReverseProxy(string $trustedIPs = '*'): self;
public function useAuditLogging(): self;
public function useRequestCorrelation(): self;
public function useShorterCacheLifetime(int $seconds = 3600): self;
public function useNoCacheDebugHeaders(): self;
public function useBackendEntryPoint(string $entryPoint, ?string $cookieDomain = null): self;
public function allowInvalidCacheHashQueryParameter(): self;
public function forbidInvalidCacheHashQueryParameter(): self;
public function excludeQueryParameterForCacheHashCalculation(string $queryParameter): self;
/** @param array<int, string> $queryParameters */
public function excludeQueryParametersForCacheHashCalculation(array $queryParameters): self;
public function enableDeprecationLogging(): self;
public function disableDeprecationLogging(): self;
public function configureExceptionHandlers(string $productionExceptionHandlerClassName, string $debugExceptionHandlerClassName): self;
public function autoconfigureSolrLogging(string $fileName = 'solr.log', ?string $forceLogLevel = null): self;
public function addFileLogger(string $namespace, ?string $fileName = null, ?string $logLevel = null): self;
public function setNullLogger(string $namespace, string $logLevel = \TYPO3\CMS\Core\Log\LogLevel::DEBUG): self;
public function loadCoreSecrets(?string $dbUser = null, ?string $dbPassword = null, ?string $encryptionKey = null, ?string $installToolPassword = null): self;
public function loadMailSecrets(?string $mailPassword = null, ?string $mailUsername = null, ?string $mailDSN = null): self;
public function useGitLabBackendLogin(string $gitlabServer, string $projectName, int $adminUserLevel = 40, string $defaultGroups = '0', bool $blockExternalUsers = true, bool $overrideUser = false, string $label = 'Login mit GitLab', ?string $appId = null, ?string $appSecret = null): self;
}
