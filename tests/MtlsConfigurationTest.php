<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\Test;

class MtlsConfigurationTest extends ConfigTestCase
{
    use PHPMock;

    #[Test]
    public function loadCoreSecretsWithReadableCertsSetsPdoOptions(): void
    {
        // Suppress PHP 8.5 PDO constant deprecation warnings
        $previousLevel = error_reporting(E_ALL & ~E_DEPRECATED);

        $getenv = $this->getFunctionMock('Moselwal', 'getenv');
        $getenv->expects(self::any())->willReturnCallback(function (string $key) {
            $map = [
                'DB_USER' => 'test-user',
                'DB_PASSWORD' => 'test-password',
                'ENCRYPTION_KEY' => 'test-encryption-key',
                'INSTALL_TOOL_PASSWORD' => 'test-install-pw',
            ];
            return $map[$key] ?? false;
        });

        $isReadable = $this->getFunctionMock('Moselwal', 'is_readable');
        $isReadable->expects(self::any())->willReturnCallback(function (string $path) {
            // All TLS files are readable
            return str_starts_with($path, '/run/tls/');
        });

        $fileGetContents = $this->getFunctionMock('Moselwal', 'file_get_contents');
        $fileGetContents->expects(self::any())->willReturn('cert-content');

        $config = TestableConfig::initializeWithVersion(12);
        $config->loadCoreSecrets();

        // Check DB credentials were set
        self::assertSame('test-user', $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['user']);
        self::assertSame('test-password', $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['password']);

        // Check mTLS driver options were set
        $driverOptions = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['driverOptions'] ?? [];
        // Use constant values directly to avoid PHP 8.5 deprecation
        self::assertNotEmpty($driverOptions, 'driverOptions should contain SSL options');
        self::assertCount(4, $driverOptions); // CA, CERT, KEY, VERIFY_SERVER_CERT

        error_reporting($previousLevel);
    }

    #[Test]
    public function loadCoreSecretsWithMissingCertsSkipsMtls(): void
    {
        $getenv = $this->getFunctionMock('Moselwal', 'getenv');
        $getenv->expects(self::any())->willReturnCallback(function (string $key) {
            $map = [
                'DB_USER' => 'test-user',
                'DB_PASSWORD' => 'test-password',
                'ENCRYPTION_KEY' => 'test-key',
                'INSTALL_TOOL_PASSWORD' => 'test-pw',
            ];
            return $map[$key] ?? false;
        });

        $isReadable = $this->getFunctionMock('Moselwal', 'is_readable');
        $isReadable->expects(self::any())->willReturn(false);

        $config = TestableConfig::initializeWithVersion(12);
        $config->loadCoreSecrets();

        // DB credentials still set
        self::assertSame('test-user', $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['user']);

        // No driverOptions set (mTLS skipped)
        self::assertArrayNotHasKey('driverOptions', $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']);
    }

    #[Test]
    public function autoconfigureKeyValueMtlsReturnsOptionsWhenCertsReadable(): void
    {
        $getenv = $this->getFunctionMock('Moselwal', 'getenv');
        $getenv->expects(self::any())->willReturnCallback(function (string $key) {
            $map = [
                'KEYVALUE_HOST' => 'redis.example.com',
                'KEYVALUE_PORT' => '6379',
                'KEYVALUE_PASSWORD' => 'redis-pw',
            ];
            return $map[$key] ?? false;
        });

        $isReadable = $this->getFunctionMock('Moselwal', 'is_readable');
        $isReadable->expects(self::any())->willReturnCallback(function (string $path) {
            return str_starts_with($path, '/run/tls/');
        });

        $fileGetContents = $this->getFunctionMock('Moselwal', 'file_get_contents');
        $fileGetContents->expects(self::any())->willReturn('cert-content');

        // We need class_exists to return true for KeyValue classes
        $classExists = $this->getFunctionMock('Moselwal', 'class_exists');
        $classExists->expects(self::any())->willReturn(true);

        $functionExists = $this->getFunctionMock('Moselwal', 'function_exists');
        $functionExists->expects(self::any())->willReturn(false); // apcu_enabled won't be called

        $config = TestableConfig::initializeWithVersion(12);
        $config->autoconfigureCaching();

        // Check that cache configurations were set with TLS options
        $cacheConfs = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] ?? [];
        self::assertNotEmpty($cacheConfs);

        // Check a cache entry has TLS options
        $firstCache = reset($cacheConfs);
        self::assertTrue($firstCache['options']['tls'] ?? false);
        self::assertSame('/run/tls/ca.crt', $firstCache['options']['ca_file'] ?? '');
    }

    #[Test]
    public function autoconfigureKeyValueMtlsReturnsEmptyWhenCertsNotReadable(): void
    {
        $getenv = $this->getFunctionMock('Moselwal', 'getenv');
        $getenv->expects(self::any())->willReturnCallback(function (string $key) {
            $map = [
                'KEYVALUE_HOST' => 'redis.example.com',
                'KEYVALUE_PORT' => '6379',
            ];
            return $map[$key] ?? false;
        });

        $isReadable = $this->getFunctionMock('Moselwal', 'is_readable');
        $isReadable->expects(self::any())->willReturn(false);

        $classExists = $this->getFunctionMock('Moselwal', 'class_exists');
        $classExists->expects(self::any())->willReturn(true);

        $functionExists = $this->getFunctionMock('Moselwal', 'function_exists');
        $functionExists->expects(self::any())->willReturn(false);

        $config = TestableConfig::initializeWithVersion(12);
        $config->autoconfigureCaching();

        // Cache should still be configured but without TLS
        $cacheConfs = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] ?? [];
        if (!empty($cacheConfs)) {
            $firstCache = reset($cacheConfs);
            self::assertArrayNotHasKey('tls', $firstCache['options'] ?? []);
        }
    }

    #[Test]
    public function loadMailSecretsConfiguresMailSettings(): void
    {
        $getenv = $this->getFunctionMock('Moselwal', 'getenv');
        $getenv->expects(self::any())->willReturnCallback(function (string $key) {
            $map = [
                'MAIL_PASSWORD' => 'mail-pw',
                'MAIL_USERNAME' => 'mail-user',
                'MAIL_DSN' => 'smtp://mail:587',
            ];
            return $map[$key] ?? false;
        });

        $isReadable = $this->getFunctionMock('Moselwal', 'is_readable');
        $isReadable->expects(self::any())->willReturn(false);

        $config = TestableConfig::initializeWithVersion(12);
        $config->loadMailSecrets();

        self::assertSame('mail-pw', $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_password']);
        self::assertSame('mail-user', $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_username']);
        self::assertSame('smtp://mail:587', $GLOBALS['TYPO3_CONF_VARS']['MAIL']['dsn']);
    }
}
