<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use Moselwal\SecretResolver\Infrastructure\Factory\BuiltInSecretResolver;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;

/**
 * With moselwal/secret-resolver installed, resolveSecret() asks its built-in
 * cascade first and keeps only the environment and the fallback for itself.
 * Each test in its own process: the stub classes, once loaded, stay.
 */
class SecretResolverDelegationTest extends ConfigTestCase
{
    use PHPMock;

    #[Test]
    #[RunInSeparateProcess]
    public function theSecretResolverAnswerWins(): void
    {
        require __DIR__ . '/Fixtures/secret-resolver-stub.phpstub';
        BuiltInSecretResolver::$values = ['ENCRYPTION_KEY' => ' from-resolver '];
        $this->getFunctionMock('Moselwal', 'getenv')->expects(self::any())
            ->willReturnCallback(static fn (string $k) => $k === 'ENCRYPTION_KEY' ? 'from-env' : false);

        $config = TestableConfig::initializeWithVersion(14);
        self::assertSame('from-resolver', $config->testResolveSecret('ENCRYPTION_KEY'));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function withoutAnAnswerTheEnvironmentAndFallbackFollow(): void
    {
        require __DIR__ . '/Fixtures/secret-resolver-stub.phpstub';
        BuiltInSecretResolver::$values = [];
        $this->getFunctionMock('Moselwal', 'getenv')->expects(self::any())
            ->willReturnCallback(static fn (string $k) => $k === 'MAIL_PASSWORD' ? 'from-env' : false);
        $this->getFunctionMock('Moselwal', 'is_readable')->expects(self::any())->willReturn(false);
        $this->getFunctionMock('Moselwal', 'stream_socket_client')->expects(self::never());

        $config = TestableConfig::initializeWithVersion(14);
        self::assertSame('from-env', $config->testResolveSecret('MAIL_PASSWORD'));
        self::assertSame('fb', $config->testResolveSecret('MAIL_DSN', 'fb'));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function aKeyTheResolverRejectsFallsThrough(): void
    {
        require __DIR__ . '/Fixtures/secret-resolver-stub.phpstub';
        $this->getFunctionMock('Moselwal', 'getenv')->expects(self::any())->willReturn(false);
        $this->getFunctionMock('Moselwal', 'is_readable')->expects(self::any())->willReturn(false);

        $config = TestableConfig::initializeWithVersion(14);
        self::assertSame('fb', $config->testResolveSecret('lower-case-key', 'fb'));
    }
}
