<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use Moselwal\Config;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\Test;

class PresetTest extends ConfigTestCase
{
    use PHPMock;

    #[Test]
    public function useCliPresetSetsDebugFlags(): void
    {
        $instance = new Config();
        $instance->useCliPreset();

        self::assertTrue($GLOBALS['TYPO3_CONF_VARS']['FE']['debug']);
        self::assertTrue($GLOBALS['TYPO3_CONF_VARS']['BE']['debug']);
        self::assertSame('*', $GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask']);
        self::assertSame(1, $GLOBALS['TYPO3_CONF_VARS']['SYS']['displayErrors']);
        self::assertSame(0, $GLOBALS['TYPO3_CONF_VARS']['SYS']['systemLogLevel']);
    }

    #[Test]
    public function useProductionPresetDisablesDebug(): void
    {
        // Ensure LOG writerConfiguration exists for array_replace_recursive
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = [];

        $instance = new Config();
        $instance->useProductionPreset();

        self::assertFalse($GLOBALS['TYPO3_CONF_VARS']['BE']['debug']);
        self::assertFalse($GLOBALS['TYPO3_CONF_VARS']['FE']['debug']);
        self::assertSame('', $GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask']);
        self::assertSame(-1, $GLOBALS['TYPO3_CONF_VARS']['SYS']['displayErrors']);
    }

    #[Test]
    public function useDevelopmentPresetEnablesDebugAndMailpit(): void
    {
        $instance = new Config();
        $instance->useDevelopmentPreset();

        self::assertTrue($GLOBALS['TYPO3_CONF_VARS']['BE']['debug']);
        self::assertTrue($GLOBALS['TYPO3_CONF_VARS']['FE']['debug']);
        self::assertSame('*', $GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask']);
        self::assertSame(1, $GLOBALS['TYPO3_CONF_VARS']['SYS']['displayErrors']);
        // Mailpit configured
        self::assertSame('smtp', $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport']);
        self::assertSame('', $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_password']);
    }

    #[Test]
    public function useProductionPresetVHostUsesFileWriter(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = [];

        $instance = new Config();
        $instance->useProductionPresetVHost();

        self::assertFalse($GLOBALS['TYPO3_CONF_VARS']['BE']['debug']);
        self::assertFalse($GLOBALS['TYPO3_CONF_VARS']['FE']['debug']);

        // VHost preset uses FileWriter instead of PhpErrorLogWriter
        $writerConfig = $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'];
        self::assertArrayHasKey(\TYPO3\CMS\Core\Log\LogLevel::ERROR, $writerConfig);
        self::assertArrayHasKey(
            \TYPO3\CMS\Core\Log\Writer\FileWriter::class,
            $writerConfig[\TYPO3\CMS\Core\Log\LogLevel::ERROR]
        );
    }

    #[Test]
    public function useCliPresetDisablesSSLVerification(): void
    {
        $instance = new Config();
        $instance->useCliPreset();

        self::assertSame(0, $GLOBALS['TYPO3_CONF_VARS']['HTTP']['ssl_verify_host']);
        self::assertSame(0, $GLOBALS['TYPO3_CONF_VARS']['HTTP']['ssl_verify_peer']);
    }

    #[Test]
    public function enableDeprecationLoggingSetsCorrectFlag(): void
    {
        $instance = new Config();
        $instance->enableDeprecationLogging();

        self::assertFalse(
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['TYPO3']['CMS']['deprecations']['writerConfiguration']
            [\TYPO3\CMS\Core\Log\LogLevel::NOTICE]
            ['TYPO3\CMS\Core\Log\Writer\FileWriter']['disabled']
        );
    }

    #[Test]
    public function disableDeprecationLoggingSetsCorrectFlag(): void
    {
        $instance = new Config();
        $instance->disableDeprecationLogging();

        self::assertTrue(
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['TYPO3']['CMS']['deprecations']['writerConfiguration']
            [\TYPO3\CMS\Core\Log\LogLevel::NOTICE]
            ['TYPO3\CMS\Core\Log\Writer\FileWriter']['disabled']
        );
    }

    #[Test]
    public function useDevelopmentPresetSetsLockSSLFalse(): void
    {
        $instance = new Config();
        $instance->useDevelopmentPreset();

        self::assertFalse($GLOBALS['TYPO3_CONF_VARS']['BE']['lockSSL']);
    }

    #[Test]
    public function useProductionPresetSetsExceptionalErrors(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = [];

        $instance = new Config();
        $instance->useProductionPreset();

        $expected = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        self::assertSame($expected, $GLOBALS['TYPO3_CONF_VARS']['SYS']['exceptionalErrors']);
        self::assertSame($expected, $GLOBALS['TYPO3_CONF_VARS']['SYS']['belogErrorReporting']);
    }
}
