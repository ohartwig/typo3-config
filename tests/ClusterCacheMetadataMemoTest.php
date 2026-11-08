<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use Moselwal\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * CLUSTER_CACHE_METADATA_MEMO switches on the backend's cross-request
 * metadata memo, and only that, and only for a backend that knows it.
 */
final class ClusterCacheMetadataMemoTest extends ConfigTestCase
{
    protected function tearDown(): void
    {
        putenv('CLUSTER_CACHE_METADATA_MEMO');
        parent::tearDown();
    }

    /**
     * @return array<string, array{string|false, bool}>
     */
    public static function switchValues(): array
    {
        return [
            'unset' => [false, false],
            'empty' => ['', false],
            'zero' => ['0', false],
            'off' => ['off', false],
            'one' => ['1', true],
            'true' => ['true', true],
            'ON with spaces' => [' ON ', true],
            'yes' => ['yes', true],
        ];
    }

    #[DataProvider('switchValues')]
    public function testTheSwitchForwardsTheOption(string|false $value, bool $expected): void
    {
        putenv(false === $value ? 'CLUSTER_CACHE_METADATA_MEMO' : 'CLUSTER_CACHE_METADATA_MEMO=' . $value);

        self::assertSame($expected, $this->assetsOptions(supportsMemo: true)['crossRequestMetadataMemo'] ?? false);
    }

    public function testCachesAreMovedToTheClusterBackend(): void
    {
        $this->assetsOptions(supportsMemo: false);

        self::assertSame(
            'Moselwal\\Typo3ClusterCache\\Infrastructure\\Cache\\Backend\\ClusterFileBackend',
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['assets']['backend'],
        );
        self::assertSame(
            VariableFrontend::class,
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['assets']['frontend'],
            'the frontend the consumer chose is kept',
        );
    }

    public function testABackendWithoutTheOptionDoesNotGetIt(): void
    {
        putenv('CLUSTER_CACHE_METADATA_MEMO=1');

        self::assertArrayNotHasKey(
            'crossRequestMetadataMemo',
            $this->assetsOptions(supportsMemo: false),
            'an older backend would reject the unknown option',
        );
    }

    public function testTheInstalledBackendIsAskedAboutTheOption(): void
    {
        $config = new class () extends Config {
            public function supports(): bool
            {
                return $this->clusterFileBackendSupportsMetadataMemo();
            }

            public function installed(): bool
            {
                return $this->clusterFileBackendInstalled();
            }
        };

        // Not installed in this package's test setup: neither installed nor supporting.
        self::assertFalse($config->installed());
        self::assertFalse($config->supports());
    }

    /**
     * @return array<string, mixed>
     */
    private function assetsOptions(bool $supportsMemo): array
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['assets'] = [
            'frontend' => VariableFrontend::class,
            'backend' => SimpleFileBackend::class,
            'options' => [],
        ];
        $config = new class ($supportsMemo) extends Config {
            public function __construct(private readonly bool $supportsMemo)
            {
                parent::__construct();
            }

            protected function clusterFileBackendInstalled(): bool
            {
                return true;
            }

            protected function clusterFileBackendSupportsMetadataMemo(): bool
            {
                return $this->supportsMemo;
            }
        };
        $config->useClusterFileBackend([]);

        /** @var array<string, mixed> $options */
        $options = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['assets']['options'];

        return $options;
    }
}
