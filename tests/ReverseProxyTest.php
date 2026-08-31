<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use Moselwal\Config;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\Test;

/**
 * useReverseProxy() entscheidet, wessen X-Forwarded-For TYPO3 glaubt. Der
 * Vorgabewert '*' glaubt jedem - damit laesst sich devIPmask, lockToIP und
 * jede IP-Zuordnung im Audit-Log von aussen faelschen (Security-Audit M7).
 *
 * Der Wert wird bewusst nicht verboten, sondern gemeldet. Also gehoert die
 * Meldung selbst geprueft: eine Warnung, die niemand ausloest, ist keine.
 */
class ReverseProxyTest extends ConfigTestCase
{
    use PHPMock;

    #[Test]
    public function derProxyWirdFuerBeideRichtungenEingetragen(): void
    {
        $errorLog = $this->getFunctionMock('Moselwal', 'error_log');
        $errorLog->expects(self::never());

        (new Config())->useReverseProxy('10.0.0.0/8');

        self::assertSame('10.0.0.0/8', $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP']);
        self::assertSame('10.0.0.0/8', $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxySSL']);
        self::assertSame('first', $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyHeaderMultiValue']);
    }

    #[Test]
    public function eineAusdrueckicheCidrLoestKeineWarnungAus(): void
    {
        // Die Gegenprobe zum Fall darunter: wer den Proxy benennt, soll nicht
        // bei jedem Deploy eine Warnung lesen muessen, die ihn nicht betrifft.
        $errorLog = $this->getFunctionMock('Moselwal', 'error_log');
        $errorLog->expects(self::never());

        (new Config())->useReverseProxy('172.20.0.0/16');

        self::assertSame('172.20.0.0/16', $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP']);
    }

    #[Test]
    public function derPlatzhalterMeldetSichImDeployLog(): void
    {
        $meldung = null;
        $errorLog = $this->getFunctionMock('Moselwal', 'error_log');
        $errorLog->expects(self::once())->willReturnCallback(function (string $text) use (&$meldung): bool {
            $meldung = $text;
            return true;
        });

        (new Config())->useReverseProxy();

        self::assertNotNull($meldung, 'der Vorgabewert muss sich melden, sonst bleibt er unsichtbar');
        self::assertStringContainsString('X-Forwarded-For', (string)$meldung);
        self::assertSame('*', $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP']);
    }

    #[Test]
    public function useReverseProxyGibtSichSelbstZurueck(): void
    {
        $errorLog = $this->getFunctionMock('Moselwal', 'error_log');
        $errorLog->expects(self::never());

        $config = new Config();

        self::assertSame($config, $config->useReverseProxy('10.0.0.0/8'));
    }
}
