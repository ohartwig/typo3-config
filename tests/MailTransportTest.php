<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use Moselwal\Config;
use PHPUnit\Framework\Attributes\Test;

/**
 * A DSN that is configured and never read is worse than no DSN, because it reads
 * like the feature is wired. Until 2026-08-22 loadMailSecrets() set MAIL/dsn and
 * left MAIL/transport at TYPO3's 'sendmail' default, so every production site
 * built on this package posted its mail to a binary the container does not have.
 */
final class MailTransportTest extends ConfigTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // TYPO3's own default, so the assertions below say "unchanged" rather
        // than "whatever happened to be there".
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = ['transport' => 'sendmail'];
        putenv('MAIL_DSN');
        putenv('MAIL_USERNAME');
        putenv('MAIL_PASSWORD');
    }

    #[Test]
    public function aResolvedDsnSwitchesTheTransport(): void
    {
        (new Config())->loadMailSecrets(null, null, 'ses+api://default?region=eu-north-1');

        self::assertSame('dsn', $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport']);
        self::assertSame(
            'ses+api://default?region=eu-north-1',
            $GLOBALS['TYPO3_CONF_VARS']['MAIL']['dsn']
        );
    }

    #[Test]
    public function withoutADsnTheTransportIsLeftAlone(): void
    {
        (new Config())->loadMailSecrets('secret', 'user');

        self::assertSame(
            'sendmail',
            $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'],
            'An installation that configures SMTP through transport_smtp_* must keep working unchanged.'
        );
    }

    #[Test]
    public function anEmptyDsnDoesNotSwitchTheTransport(): void
    {
        (new Config())->loadMailSecrets(null, null, '   ');

        self::assertSame('sendmail', $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport']);
    }
}
