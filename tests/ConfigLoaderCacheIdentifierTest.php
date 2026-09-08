<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use Moselwal\Config;

/**
 * The env-override cache must be keyed by the TYPO3__* variables it was built
 * from. Otherwise a process without an override (k3s init container) writes a
 * cache that a process with the override (app container) reuses, and the
 * override is silently lost -- the frankenphp purge target bug of 2026-09-08.
 */
final class ConfigLoaderCacheIdentifierTest extends ConfigTestCase
{
    private array $envBackup = [];

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        $this->envBackup = [];
        parent::tearDown();
    }

    private function setEnv(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->envBackup)) {
            $this->envBackup[$name] = getenv($name);
        }
        if ($value === null) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }

    public function testIdentifierChangesWhenATypo3OverrideAppears(): void
    {
        $this->setEnv('TYPO3__EXTENSIONS__frankenphp__caddyHost', null);
        $without = (new Config())->configLoaderCacheIdentifier();

        $this->setEnv('TYPO3__EXTENSIONS__frankenphp__caddyHost', 'caddy-proxy');
        $with = (new Config())->configLoaderCacheIdentifier();

        self::assertNotSame($without, $with, 'a new TYPO3__ override must invalidate the cached config');
    }

    public function testIdentifierChangesWhenAnOverrideValueChanges(): void
    {
        $this->setEnv('TYPO3__EXTENSIONS__frankenphp__caddyPort', '2019');
        $first = (new Config())->configLoaderCacheIdentifier();

        $this->setEnv('TYPO3__EXTENSIONS__frankenphp__caddyPort', '443');
        $second = (new Config())->configLoaderCacheIdentifier();

        self::assertNotSame($first, $second);
    }

    public function testIdentifierIsStableForIdenticalEnvironment(): void
    {
        $this->setEnv('TYPO3__EXTENSIONS__frankenphp__caddyHost', 'caddy-proxy');

        self::assertSame(
            (new Config())->configLoaderCacheIdentifier(),
            (new Config())->configLoaderCacheIdentifier()
        );
    }

    public function testUnrelatedVariablesDoNotInfluenceTheIdentifier(): void
    {
        $this->setEnv('SOME_UNRELATED_VARIABLE', 'a');
        $first = (new Config())->configLoaderCacheIdentifier();

        $this->setEnv('SOME_UNRELATED_VARIABLE', 'b');
        $second = (new Config())->configLoaderCacheIdentifier();

        self::assertSame($first, $second, 'only TYPO3__* variables feed the cached config');
    }
}
