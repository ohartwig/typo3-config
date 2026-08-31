<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use Moselwal\Config;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

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

    /**
     * Der Fall, den es vorher nicht gab: TYPO3 bringt von Haus aus
     * `warning => FileWriter` mit. Die alten Tests leerten
     * writerConfiguration vorher und pruefen damit eine Lage, die im Betrieb
     * nie eintritt. Genau deshalb blieb unbemerkt, dass das Preset die
     * Vorgabe nicht verdraengte und im Container weiter Dateien geschrieben
     * wurden.
     */
    #[Test]
    public function useProductionPresetSchaltetDenGeerbtenFileWriterAb(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = [
            \TYPO3\CMS\Core\Log\LogLevel::WARNING => [
                \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [],
            ],
        ];

        (new Config())->useProductionPreset();

        $writer = $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'];

        self::assertTrue(
            $writer[\TYPO3\CMS\Core\Log\LogLevel::WARNING][\TYPO3\CMS\Core\Log\Writer\FileWriter::class]['disabled'],
            'Der von TYPO3 geerbte FileWriter muss abgeschaltet sein - im Container liest die Datei niemand.'
        );
        self::assertTrue(
            $writer[\TYPO3\CMS\Core\Log\LogLevel::ERROR][\TYPO3\CMS\Core\Log\Writer\FileWriter::class]['disabled']
        );
        self::assertFalse(
            $writer[\TYPO3\CMS\Core\Log\LogLevel::ERROR][\TYPO3\CMS\Core\Log\Writer\PhpErrorLogWriter::class]['disabled'],
            'Ab ERROR muss nach stderr geschrieben werden.'
        );
    }

    /**
     * Das CLI-Preset schrieb auf 'var/logs/error.log' - ein Verzeichnis, das
     * TYPO3 nicht kennt (es heisst var/log/) und das auf keinem Mandanten
     * existierte. Jede Ausnahme aus einem Scheduler-Lauf war damit unsichtbar.
     */
    #[Test]
    public function useCliPresetSchreibtNachStdErrUndNichtInEineDatei(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = [
            \TYPO3\CMS\Core\Log\LogLevel::WARNING => [
                \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [],
            ],
        ];

        (new Config())->useCliPreset();

        $writer = $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'];

        self::assertFalse(
            $writer[\TYPO3\CMS\Core\Log\LogLevel::ERROR][\TYPO3\CMS\Core\Log\Writer\PhpErrorLogWriter::class]['disabled']
        );
        foreach ($writer as $level => $writers) {
            if (isset($writers[\TYPO3\CMS\Core\Log\Writer\FileWriter::class])) {
                self::assertTrue(
                    $writers[\TYPO3\CMS\Core\Log\Writer\FileWriter::class]['disabled'],
                    sprintf('FileWriter auf Stufe "%s" muss abgeschaltet sein.', $level)
                );
                self::assertArrayNotHasKey(
                    'logFile',
                    $writers[\TYPO3\CMS\Core\Log\Writer\FileWriter::class],
                    'Kein Dateipfad mehr - schon gar nicht var/logs/, das es nirgends gibt.'
                );
            }
        }
    }

    /**
     * Das Deprecation-Log hat einen eigenen FileWriter. Im Produktions-CLI
     * lief es unbedingt mit und schrieb bei jedem Scheduler-Lauf eine Datei
     * in den Container.
     */
    /**
     * Ein Writer faengt seine Stufe und alles Schwerere. Ein FileWriter auf
     * 'notice' schriebe also weiter mit, obwohl 'warning' abwaerts
     * abgeschaltet ist - deshalb muss jede der acht Stufen abgedeckt sein.
     */
    #[Test]
    public function writeToStdErrDecktAlleAchtStufenAb(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = [
            \TYPO3\CMS\Core\Log\LogLevel::NOTICE => [
                \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [],
            ],
        ];

        (new Config())->useProductionPreset();

        $writer = $GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'];

        $stufen = [
            \TYPO3\CMS\Core\Log\LogLevel::EMERGENCY,
            \TYPO3\CMS\Core\Log\LogLevel::ALERT,
            \TYPO3\CMS\Core\Log\LogLevel::CRITICAL,
            \TYPO3\CMS\Core\Log\LogLevel::ERROR,
            \TYPO3\CMS\Core\Log\LogLevel::WARNING,
            \TYPO3\CMS\Core\Log\LogLevel::NOTICE,
            \TYPO3\CMS\Core\Log\LogLevel::INFO,
            \TYPO3\CMS\Core\Log\LogLevel::DEBUG,
        ];

        foreach ($stufen as $stufe) {
            self::assertTrue(
                $writer[$stufe][\TYPO3\CMS\Core\Log\Writer\FileWriter::class]['disabled'] ?? null,
                sprintf('FileWriter auf Stufe "%s" muss abgeschaltet sein.', $stufe)
            );
        }

        // Ab ERROR nach stderr - das faengt error, critical, alert und
        // emergency mit. Darunter bleibt es still.
        self::assertFalse(
            $writer[\TYPO3\CMS\Core\Log\LogLevel::ERROR][\TYPO3\CMS\Core\Log\Writer\PhpErrorLogWriter::class]['disabled']
        );
        self::assertTrue(
            $writer[\TYPO3\CMS\Core\Log\LogLevel::WARNING][\TYPO3\CMS\Core\Log\Writer\PhpErrorLogWriter::class]['disabled']
        );
    }

    #[Test]
    public function useCliPresetSchaltetDasDeprecationLogImProduktionsbetriebAb(): void
    {
        $this->switchContext('Production');
        (new Config())->useCliPreset();

        self::assertTrue(
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['TYPO3']['CMS']['deprecations']['writerConfiguration'][\TYPO3\CMS\Core\Log\LogLevel::NOTICE]['TYPO3\CMS\Core\Log\Writer\FileWriter']['disabled']
        );
    }

    #[Test]
    public function useCliPresetBehaeltDasDeprecationLogLokal(): void
    {
        $this->switchContext('Development');
        (new Config())->useCliPreset();

        self::assertFalse(
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['TYPO3']['CMS']['deprecations']['writerConfiguration'][\TYPO3\CMS\Core\Log\LogLevel::NOTICE]['TYPO3\CMS\Core\Log\Writer\FileWriter']['disabled']
        );
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

    /**
     * Die Basisklasse startet die Environment im Kontext 'Testing'. Fuer den
     * Produktions-CLI-Pfad muss sie neu gesetzt werden, sonst laeuft der Test
     * durch den lockeren Entwicklungszweig.
     */
    private function switchContext(string $context): void
    {
        Environment::initialize(
            new ApplicationContext($context),
            true,
            false,
            '/tmp/typo3-test',
            '/tmp/typo3-test/public',
            '/tmp/typo3-test/var',
            '/tmp/typo3-test/config',
            '/tmp/typo3-test/public/index.php',
            'UNIX'
        );
    }
}
