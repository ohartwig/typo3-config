<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use Moselwal\Config;
use Moselwal\Log\RequestIdProcessor;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Log\Writer\DatabaseWriter;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\CMS\Core\Log\Writer\NullWriter;

/**
 * Genau der Bereich, den dieser MR anfasst, war bis hierher ungemessen:
 * addFileLogger, setNullLogger, autoconfigureSolrLogging, useAuditLogging und
 * useRequestCorrelation hatten zusammen keine einzige abgedeckte Zeile.
 *
 * Das ist kein Zufallsbefund. Ein FileWriter auf 'var/logs/error.log' - mit
 * einem Verzeichnisnamen, den es bei TYPO3 nicht gibt - konnte deshalb auf
 * jedem Mandanten ins Leere schreiben, ohne dass ein Test etwas dazu zu sagen
 * hatte. Was hier geprueft wird, ist deshalb nicht nur "der Wert steht im
 * Array", sondern wo er steht: der Pfad im LOG-Baum entscheidet, ob TYPO3 die
 * Konfiguration ueberhaupt findet.
 */
class LoggingConfigurationTest extends ConfigTestCase
{
    #[Test]
    public function derDateinameEntstehtAusDemNamensraum(): void
    {
        (new Config())->addFileLogger('Vendor\\Extension');

        // Der Namensraum ist zugleich der Pfad im LOG-Baum: TYPO3 sucht die
        // Writer unter LOG.Vendor.Extension, nicht unter einem Schluessel
        // 'Vendor\Extension'.
        $writers = $GLOBALS['TYPO3_CONF_VARS']['LOG']['Vendor']['Extension']['writerConfiguration'];

        self::assertSame(
            '/tmp/typo3-test/var/log/vendor_extension.log',
            $writers[LogLevel::DEBUG][FileWriter::class]['logFile'],
        );
    }

    #[Test]
    public function dieDateiLiegtUnterVarLogUndNichtUnterVarLogs(): void
    {
        // Der Fehler, der diesen MR ausgeloest hat: das Verzeichnis heisst bei
        // TYPO3 var/log, Einzahl. Ein Writer auf var/logs/ wurde nirgends
        // angelegt und schrieb still ins Leere.
        (new Config())->addFileLogger('Vendor\\Extension', 'eigene.log');

        $logFile = $GLOBALS['TYPO3_CONF_VARS']['LOG']['Vendor']['Extension']['writerConfiguration'][LogLevel::DEBUG][FileWriter::class]['logFile'];

        self::assertStringStartsWith(Environment::getVarPath() . '/log/', $logFile);
        self::assertStringEndsWith('/eigene.log', $logFile);
    }

    #[Test]
    public function ohneAngabeEntscheidetDerKontextUeberDieStufe(): void
    {
        (new Config())->addFileLogger('Vendor\\Extension');

        self::assertArrayHasKey(
            LogLevel::DEBUG,
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['Vendor']['Extension']['writerConfiguration'],
            'ausserhalb der Produktion wird auf DEBUG geloggt',
        );
    }

    #[Test]
    public function inDerProduktionWirdNurAbErrorGeschrieben(): void
    {
        // Ein DEBUG-Logger im Container schreibt pro Anfrage Dutzende Zeilen in
        // eine Datei, die niemand liest - dieselbe Sorte Kosten, die dieser MR
        // an anderer Stelle abstellt.
        $this->switchContext('Production');

        (new Config())->addFileLogger('Vendor\\Extension');

        $writers = $GLOBALS['TYPO3_CONF_VARS']['LOG']['Vendor']['Extension']['writerConfiguration'];

        self::assertArrayHasKey(LogLevel::ERROR, $writers);
        self::assertArrayNotHasKey(LogLevel::DEBUG, $writers);
    }

    #[Test]
    public function eineAusdrueckicheStufeSchlaegtDenKontext(): void
    {
        $this->switchContext('Production');

        (new Config())->addFileLogger('Vendor\\Extension', 'eigene.log', LogLevel::WARNING);

        self::assertArrayHasKey(
            LogLevel::WARNING,
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['Vendor']['Extension']['writerConfiguration'],
        );
    }

    #[Test]
    public function setNullLoggerLegtEinenNamensraumStill(): void
    {
        (new Config())->setNullLogger('Laut\\Paket');

        $writers = $GLOBALS['TYPO3_CONF_VARS']['LOG']['Laut']['Paket']['writerConfiguration'];

        self::assertSame([], $writers[LogLevel::DEBUG][NullWriter::class]);
    }

    #[Test]
    public function setNullLoggerNimmtEineAbweichendeStufe(): void
    {
        (new Config())->setNullLogger('Laut\\Paket', LogLevel::WARNING);

        $writers = $GLOBALS['TYPO3_CONF_VARS']['LOG']['Laut']['Paket']['writerConfiguration'];

        self::assertArrayHasKey(NullWriter::class, $writers[LogLevel::WARNING]);
        self::assertArrayNotHasKey(LogLevel::DEBUG, $writers);
    }

    #[Test]
    public function solrLoggtInEineEigeneDatei(): void
    {
        (new Config())->autoconfigureSolrLogging();

        $writers = $GLOBALS['TYPO3_CONF_VARS']['LOG']['ApacheSolrForTypo3']['Solr']['writerConfiguration'];

        self::assertStringEndsWith(
            '/log/solr.log',
            $writers[LogLevel::DEBUG][FileWriter::class]['logFile'],
        );
    }

    #[Test]
    public function solrsErzwungeneStufeGiltAuchAusserhalbDerProduktion(): void
    {
        (new Config())->autoconfigureSolrLogging('solr.log', LogLevel::ERROR);

        $writers = $GLOBALS['TYPO3_CONF_VARS']['LOG']['ApacheSolrForTypo3']['Solr']['writerConfiguration'];

        self::assertArrayHasKey(LogLevel::ERROR, $writers);
        self::assertArrayNotHasKey(LogLevel::DEBUG, $writers);
    }

    #[Test]
    public function auditLoggingSchreibtInDateiUndDatenbank(): void
    {
        // Zwei Ziele mit verschiedenen Lesern: die Datei fuer CrowdSec und die
        // Fehlersuche von Hand, sys_log fuer das Backend-Protokoll und ein
        // SIEM. Faellt eines davon weg, ist der Nachweis eines Brute-Force-
        // Versuchs nur noch halb da.
        (new Config())->useAuditLogging();

        $backend = $GLOBALS['TYPO3_CONF_VARS']['LOG']['TYPO3']['CMS']['Backend']['Authentication']['writerConfiguration'];

        self::assertSame('auth', $backend[LogLevel::NOTICE][FileWriter::class]['logFileInfix']);
        self::assertSame('sys_log', $backend[LogLevel::NOTICE][DatabaseWriter::class]['logTable']);
    }

    #[Test]
    public function auditLoggingDecktBeideAuthentifizierungswegeAb(): void
    {
        // Der Backend-Login laeuft ueber die eine Komponente, jede andere
        // Anmeldung ueber AbstractUserAuthentication. Nur eine davon zu
        // bestuecken hiesse, die Haelfte der Fehlversuche nicht zu sehen.
        (new Config())->useAuditLogging();

        $log = $GLOBALS['TYPO3_CONF_VARS']['LOG']['TYPO3']['CMS'];

        self::assertArrayHasKey('writerConfiguration', $log['Backend']['Authentication']);
        self::assertArrayHasKey('writerConfiguration', $log['Core']['Authentication']['AbstractUserAuthentication']);
    }

    #[Test]
    public function auditLoggingLaesstBestehendeLogKonfigurationStehen(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['Fremd']['Paket']['writerConfiguration'] = ['bleibt' => true];

        (new Config())->useAuditLogging();

        self::assertSame(
            ['bleibt' => true],
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['Fremd']['Paket']['writerConfiguration'],
        );
    }

    #[Test]
    public function dieRequestIdHaengtGlobalUndNichtProKomponente(): void
    {
        // Eine Korrelations-ID, die nur an manchen Eintraegen haengt, laesst
        // genau die Luecken, die man beim Nachverfolgen braucht.
        (new Config())->useRequestCorrelation();

        $processors = $GLOBALS['TYPO3_CONF_VARS']['LOG']['processorConfiguration'];

        self::assertSame([], $processors[LogLevel::DEBUG][RequestIdProcessor::class]);
    }

    #[Test]
    public function bestehendeProzessorenBleibenErhalten(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG']['processorConfiguration'][LogLevel::DEBUG]['Fremd\\Prozessor'] = ['a' => 1];

        (new Config())->useRequestCorrelation();

        $processors = $GLOBALS['TYPO3_CONF_VARS']['LOG']['processorConfiguration'][LogLevel::DEBUG];

        self::assertSame(['a' => 1], $processors['Fremd\\Prozessor']);
        self::assertArrayHasKey(RequestIdProcessor::class, $processors);
    }

    #[Test]
    public function dieLoggerGebenSichSelbstZurueck(): void
    {
        $config = new Config();

        self::assertSame($config, $config->addFileLogger('Vendor\\Extension'));
        self::assertSame($config, $config->setNullLogger('Laut\\Paket'));
        self::assertSame($config, $config->useAuditLogging());
        self::assertSame($config, $config->useRequestCorrelation());
        self::assertSame($config, $config->autoconfigureSolrLogging());
    }

    /**
     * Die Basisklasse startet die Environment im Kontext 'Testing'. Fuer den
     * Produktionszweig muss sie neu gesetzt werden, sonst laeuft der Test
     * durch den lockeren Entwicklungspfad.
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
