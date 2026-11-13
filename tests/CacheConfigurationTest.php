<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use Moselwal\Config;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\DataProvider;

class CacheConfigurationTest extends ConfigTestCase
{
    use PHPMock;

    #[DataProvider('typo3VersionCacheProvider')]
    public function testVersionSpecificCacheRemoval(int $majorVersion, bool $expectPagesection, bool $expectImagesizes): void
    {
        // Mock getenv to provide KEYVALUE_HOST
        $getenv = $this->getFunctionMock('Moselwal', 'getenv');
        $getenv->expects(self::any())->willReturnCallback(function ($key) {
            if ($key === 'KEYVALUE_HOST') {
                return 'redis.local';
            }
            if ($key === 'KEYVALUE_PORT') {
                return '6379';
            }
            return false;
        });

        // Mock class_exists for KeyValue classes
        $classExists = $this->getFunctionMock('Moselwal', 'class_exists');
        $classExists->expects(self::any())->willReturn(false);

        // Mock is_readable for TLS (no certs)
        $isReadable = $this->getFunctionMock('Moselwal', 'is_readable');
        $isReadable->expects(self::any())->willReturn(false);

        // Mock function_exists for apcu
        $functionExists = $this->getFunctionMock('Moselwal', 'function_exists');
        $functionExists->expects(self::any())->willReturn(false);

        // Create a test subclass to inject the mocked version
        $config = TestableConfig::initializeWithVersion($majorVersion);
        $config->autoconfigureCaching();

        $cacheConfigs = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] ?? [];

        if ($expectPagesection) {
            self::assertArrayHasKey('pagesection', $cacheConfigs, "TYPO3 v{$majorVersion} should have pagesection cache");
        } else {
            self::assertArrayNotHasKey('pagesection', $cacheConfigs, "TYPO3 v{$majorVersion} should NOT have pagesection cache");
        }

        if ($expectImagesizes) {
            self::assertArrayHasKey('imagesizes', $cacheConfigs, "TYPO3 v{$majorVersion} should have imagesizes cache");
        } else {
            self::assertArrayNotHasKey('imagesizes', $cacheConfigs, "TYPO3 v{$majorVersion} should NOT have imagesizes cache");
        }
    }

    public static function typo3VersionCacheProvider(): array
    {
        // typo3-config v5.x requires typo3/cms-core ^14.0, so only v12+ is
        // exercised here. pagesection is removed unconditionally because
        // every supported version is post-v12.
        return [
            'TYPO3 v12: pagesection removed, imagesizes present' => [12, false, true],
            'TYPO3 v13: both removed' => [13, false, false],
            'TYPO3 v14: both removed' => [14, false, false],
        ];
    }

    /**
     * @param list<string> $installed class names class_exists() reports as present
     * @return array<string, array<string, mixed>>
     */
    private function autoconfigureWith(array $installed, array $env = []): array
    {
        // Callable more than once per test: php-mock allows one enabled mock
        // per function, so the previous call's mocks go first.
        \phpmock\Mock::disableAll();
        $getenv = $this->getFunctionMock('Moselwal', 'getenv');
        $getenv->expects(self::any())->willReturnCallback(static fn ($key) => $env[$key] ?? match ($key) {
            'KEYVALUE_HOST' => 'redis.local',
            'KEYVALUE_PORT' => '6379',
            default => false,
        });
        $classExists = $this->getFunctionMock('Moselwal', 'class_exists');
        $classExists->expects(self::any())->willReturnCallback(
            static fn (string $class): bool => in_array(ltrim($class, '\\'), $installed, true),
        );
        $this->getFunctionMock('Moselwal', 'is_readable')->expects(self::any())->willReturn(false);
        $this->getFunctionMock('Moselwal', 'function_exists')->expects(self::any())->willReturn(false);

        TestableConfig::initializeWithVersion(14)->autoconfigureCaching();

        return $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] ?? [];
    }

    public function testFalS3MetadataCacheGoesToItsOwnKeyValueDatabase(): void
    {
        $caches = $this->autoconfigureWith([
            'Moselwal\\Typo3ClusterCache\\Infrastructure\\Cache\\Backend\\ClusterFileBackend',
            'Koh\\FalS3\\Driver\\DriverRuntimeFactory',
        ]);

        self::assertArrayHasKey('koh_fal_s3_meta', $caches);
        self::assertSame(\Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend::class, $caches['koh_fal_s3_meta']['backend']);
        self::assertSame(86400, $caches['koh_fal_s3_meta']['options']['defaultLifetime']);

        $databases = array_map(static fn (array $c): int => $c['options']['database'], $caches);
        self::assertSame(count($databases), count(array_unique($databases)), 'every KeyValue cache needs a database of its own');
        self::assertGreaterThan($databases['cluster_meta'], $databases['koh_fal_s3_meta'], 'appended last, so no existing cache changes its database');
    }

    public function testFalS3MetadataCacheDoesNotMoveTheExistingDatabases(): void
    {
        $without = $this->autoconfigureWith(['Moselwal\\Typo3ClusterCache\\Infrastructure\\Cache\\Backend\\ClusterFileBackend']);
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] = [];
        $with = $this->autoconfigureWith([
            'Moselwal\\Typo3ClusterCache\\Infrastructure\\Cache\\Backend\\ClusterFileBackend',
            'Koh\\FalS3\\Driver\\DriverRuntimeFactory',
        ]);

        foreach ($without as $name => $cache) {
            self::assertSame($cache['options']['database'], $with[$name]['options']['database'], $name);
        }
    }

    public function testEveryKeyValueCacheHasBoundedTimeouts(): void
    {
        $caches = $this->autoconfigureWith([]);

        self::assertNotSame([], $caches);
        foreach ($caches as $name => $cache) {
            self::assertSame(1.0, $cache['options']['connectTimeout'], $name);
            self::assertSame(2.0, $cache['options']['readTimeout'], $name);
        }
    }

    public function testTimeoutsComeFromTheEnvironmentAndZeroOrJunkFallsBack(): void
    {
        $caches = $this->autoconfigureWith([], ['KEYVALUE_CONNECT_TIMEOUT' => '0.5', 'KEYVALUE_READ_TIMEOUT' => '5']);
        self::assertSame(0.5, $caches['pages']['options']['connectTimeout']);
        self::assertSame(5.0, $caches['pages']['options']['readTimeout']);

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] = [];
        $caches = $this->autoconfigureWith([], ['KEYVALUE_CONNECT_TIMEOUT' => '0', 'KEYVALUE_READ_TIMEOUT' => 'soon']);
        self::assertSame(1.0, $caches['pages']['options']['connectTimeout'], '0 would mean unlimited');
        self::assertSame(2.0, $caches['pages']['options']['readTimeout']);
    }

    public function testTheLockReadTimeoutOutlastsItsBlockingWait(): void
    {
        $this->autoconfigureWith(['Moselwal\\KeyValueStore\\Locking\\KeyValueLockingStrategy']);
        $options = $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies']['Moselwal\\KeyValueStore\\Locking\\KeyValueLockingStrategy']['options'];

        self::assertSame(1.0, $options['connectTimeout']);
        self::assertGreaterThan($options['ttl'], $options['readTimeout'], 'BLPOP blocks for the ttl');
    }

    public function testFalS3MetadataCacheIsAbsentWithoutTheDriver(): void
    {
        self::assertArrayNotHasKey('koh_fal_s3_meta', $this->autoconfigureWith([]));
    }

    public function testUseClusterFileBackendIsNoopWhenExtensionMissing(): void
    {
        // The class is not in the test autoloader, so class_exists() is false.
        // Behaviour contract: silent no-op, configuration untouched.
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['assets'] = [
            'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
            'backend' => \TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend::class,
            'options' => [],
        ];

        $config = new Config();
        $config->useClusterFileBackend();

        self::assertSame(
            \TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend::class,
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['assets']['backend'],
            'useClusterFileBackend() must be a no-op when ClusterFileBackend is not installed',
        );
        self::assertArrayNotHasKey(
            'cluster_meta',
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'],
            'cluster_meta must not be registered when the extension is absent',
        );
    }

    public function testSetAlternativeCachePathCoversTheFourDefaultCaches(): void
    {
        // Der Sinn der Methode ist, die Caches von einem NFS-Mount wegzuholen.
        // Welche vier das ohne Angabe sind, steht nur hier - und wenn die
        // Liste sich aendert, soll das eine Entscheidung sein und kein
        // Nebeneffekt.
        (new Config())->setAlternativeCachePath('/dev/shm/typo3');

        $caches = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'];

        foreach (['cache_core', 'fluid_template', 'assets', 'l10n'] as $name) {
            self::assertSame('/dev/shm/typo3', $caches[$name]['options']['cacheDirectory'], $name);
        }
    }

    public function testSetAlternativeCachePathAcceptsAnExplicitList(): void
    {
        (new Config())->setAlternativeCachePath('/dev/shm/typo3', ['assets']);

        $caches = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'];

        self::assertSame('/dev/shm/typo3', $caches['assets']['options']['cacheDirectory']);
        self::assertArrayNotHasKey('cache_core', $caches);
    }
}
