<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\Test;

class SecretResolutionTest extends ConfigTestCase
{
    use PHPMock;

    #[Test]
    public function resolveSecretReturnsFileEnvValue(): void
    {
        $getenv = $this->getFunctionMock('Moselwal', 'getenv');
        $getenv->expects(self::any())->willReturnCallback(function (string $key) {
            if ($key === 'DB_PASSWORD_FILE') {
                return '/tmp/test-secret-file';
            }
            return false;
        });

        $isReadable = $this->getFunctionMock('Moselwal', 'is_readable');
        $isReadable->expects(self::any())->willReturnCallback(function (string $path) {
            return $path === '/tmp/test-secret-file';
        });

        $fileGetContents = $this->getFunctionMock('Moselwal', 'file_get_contents');
        $fileGetContents->expects(self::any())->willReturn('secret-from-file');

        $config = TestableConfig::initializeWithVersion(12);
        $result = $config->testResolveSecret('DB_PASSWORD', 'fallback-value');
        self::assertSame('secret-from-file', $result);
    }

    #[Test]
    public function resolveSecretReturnsDefaultFileValue(): void
    {
        $getenv = $this->getFunctionMock('Moselwal', 'getenv');
        $getenv->expects(self::any())->willReturnCallback(function (string $key) {
            // No _FILE env set, no direct env set
            return false;
        });

        $isReadable = $this->getFunctionMock('Moselwal', 'is_readable');
        $isReadable->expects(self::any())->willReturnCallback(function (string $path) {
            return $path === '/run/secrets/db_password';
        });

        $fileGetContents = $this->getFunctionMock('Moselwal', 'file_get_contents');
        $fileGetContents->expects(self::any())->willReturn('secret-from-default-file');

        $config = TestableConfig::initializeWithVersion(12);
        $result = $config->testResolveSecret('DB_PASSWORD', 'fallback-value');
        self::assertSame('secret-from-default-file', $result);
    }

    #[Test]
    public function resolveSecretReturnsEnvValue(): void
    {
        $getenv = $this->getFunctionMock('Moselwal', 'getenv');
        $getenv->expects(self::any())->willReturnCallback(function (string $key) {
            if ($key === 'DB_PASSWORD') {
                return 'secret-from-env';
            }
            return false;
        });

        $isReadable = $this->getFunctionMock('Moselwal', 'is_readable');
        $isReadable->expects(self::any())->willReturn(false);

        $config = TestableConfig::initializeWithVersion(12);
        $result = $config->testResolveSecret('DB_PASSWORD', 'fallback-value');
        self::assertSame('secret-from-env', $result);
    }

    #[Test]
    public function resolveSecretReturnsFallbackValue(): void
    {
        $getenv = $this->getFunctionMock('Moselwal', 'getenv');
        $getenv->expects(self::any())->willReturn(false);

        $isReadable = $this->getFunctionMock('Moselwal', 'is_readable');
        $isReadable->expects(self::any())->willReturn(false);

        $config = TestableConfig::initializeWithVersion(12);
        $result = $config->testResolveSecret('DB_PASSWORD', 'fallback-value');
        self::assertSame('fallback-value', $result);
    }

    #[Test]
    public function resolveSecretReturnsNullWhenNoSourceAvailable(): void
    {
        $getenv = $this->getFunctionMock('Moselwal', 'getenv');
        $getenv->expects(self::any())->willReturn(false);

        $isReadable = $this->getFunctionMock('Moselwal', 'is_readable');
        $isReadable->expects(self::any())->willReturn(false);

        $config = TestableConfig::initializeWithVersion(12);
        $result = $config->testResolveSecret('DB_PASSWORD');
        self::assertNull($result);
    }

    #[Test]
    public function resolveSecretTrimsWhitespace(): void
    {
        $getenv = $this->getFunctionMock('Moselwal', 'getenv');
        $getenv->expects(self::any())->willReturnCallback(function (string $key) {
            if ($key === 'DB_PASSWORD_FILE') {
                return '/tmp/secret';
            }
            return false;
        });

        $isReadable = $this->getFunctionMock('Moselwal', 'is_readable');
        $isReadable->expects(self::any())->willReturnCallback(function (string $path) {
            return $path === '/tmp/secret';
        });

        $fileGetContents = $this->getFunctionMock('Moselwal', 'file_get_contents');
        $fileGetContents->expects(self::any())->willReturn("  secret-with-whitespace  \n");

        $config = TestableConfig::initializeWithVersion(12);
        $result = $config->testResolveSecret('DB_PASSWORD');
        self::assertSame('secret-with-whitespace', $result);
    }

    #[Test]
    public function resolveSecretSkipsEmptyFile(): void
    {
        $getenv = $this->getFunctionMock('Moselwal', 'getenv');
        $getenv->expects(self::any())->willReturnCallback(function (string $key) {
            if ($key === 'DB_PASSWORD_FILE') {
                return '/tmp/empty-secret';
            }
            if ($key === 'DB_PASSWORD') {
                return 'env-value';
            }
            return false;
        });

        $isReadable = $this->getFunctionMock('Moselwal', 'is_readable');
        $isReadable->expects(self::any())->willReturnCallback(function (string $path) {
            return $path === '/tmp/empty-secret';
        });

        $fileGetContents = $this->getFunctionMock('Moselwal', 'file_get_contents');
        $fileGetContents->expects(self::any())->willReturn('');

        $config = TestableConfig::initializeWithVersion(12);
        $result = $config->testResolveSecret('DB_PASSWORD');
        self::assertSame('env-value', $result);
    }
}
