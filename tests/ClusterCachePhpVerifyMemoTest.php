<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use Moselwal\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;

/**
 * CLUSTER_CACHE_PHP_VERIFY_MEMO switches on the backend's cross-request PHP
 * payload verification, independently of the metadata memo, and only for a
 * backend that knows the option.
 */
final class ClusterCachePhpVerifyMemoTest extends ConfigTestCase
{
    protected function tearDown(): void
    {
        putenv('CLUSTER_CACHE_PHP_VERIFY_MEMO');
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
            'garbage' => ['maybe', false],
            'one' => ['1', true],
            'true' => ['true', true],
            'ON with spaces' => [' ON ', true],
            'yes' => ['yes', true],
        ];
    }

    #[DataProvider('switchValues')]
    public function testTheSwitchForwardsTheOption(string|false $value, bool $expected): void
    {
        putenv(false === $value ? 'CLUSTER_CACHE_PHP_VERIFY_MEMO' : 'CLUSTER_CACHE_PHP_VERIFY_MEMO=' . $value);

        $options = $this->typoscriptOptions(supports: true);

        self::assertSame($expected, $options['crossRequestPhpVerification'] ?? false);
        self::assertArrayNotHasKey('crossRequestMetadataMemo', $options, 'the metadata memo has its own switch');
    }

    public function testBothSwitchesTogether(): void
    {
        putenv('CLUSTER_CACHE_PHP_VERIFY_MEMO=1');
        putenv('CLUSTER_CACHE_METADATA_MEMO=1');

        $options = $this->typoscriptOptions(supports: true);

        self::assertTrue($options['crossRequestPhpVerification'] ?? false);
        self::assertTrue($options['crossRequestMetadataMemo'] ?? false);
    }

    public function testABackendWithoutTheOptionDoesNotGetIt(): void
    {
        putenv('CLUSTER_CACHE_PHP_VERIFY_MEMO=1');

        self::assertArrayNotHasKey(
            'crossRequestPhpVerification',
            $this->typoscriptOptions(supports: false),
            'an older backend would reject the unknown option',
        );
    }

    public function testTheInstalledBackendIsAskedAboutTheOption(): void
    {
        $config = new class () extends Config {
            public function supports(): bool
            {
                return $this->clusterFileBackendSupportsPhpVerifyMemo();
            }
        };

        // Not installed in this package's test setup.
        self::assertFalse($config->supports());
    }

    /**
     * @return array<string, mixed>
     */
    private function typoscriptOptions(bool $supports): array
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['typoscript'] = [
            'frontend' => PhpFrontend::class,
            'backend' => SimpleFileBackend::class,
            'options' => [],
        ];
        $config = new class ($supports) extends Config {
            public function __construct(private readonly bool $supports)
            {
                parent::__construct();
            }

            protected function clusterFileBackendInstalled(): bool
            {
                return true;
            }

            protected function clusterFileBackendSupportsMetadataMemo(): bool
            {
                return $this->supports;
            }

            protected function clusterFileBackendSupportsPhpVerifyMemo(): bool
            {
                return $this->supports;
            }
        };
        $config->useClusterFileBackend([]);

        /** @var array<string, mixed> $options */
        $options = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['typoscript']['options'];

        return $options;
    }
}
