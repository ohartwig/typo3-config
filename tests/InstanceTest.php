<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use Moselwal\Config;
use PHPUnit\Framework\Attributes\Test;

class InstanceTest extends ConfigTestCase
{
    #[Test]
    public function constructorCreatesInstance(): void
    {
        $instance = new Config();
        self::assertInstanceOf(Config::class, $instance);
    }

    #[Test]
    public function lateStaticBindingWorksWithSubclass(): void
    {
        $instance = new TestableConfig();
        self::assertInstanceOf(TestableConfig::class, $instance);
        self::assertInstanceOf(Config::class, $instance);
    }

    #[Test]
    public function applyDefaultsAppliesPresets(): void
    {
        // Testing context is set in setUp, so applyDefaults will run
        $instance = (new Config())->applyDefaults();
        self::assertInstanceOf(Config::class, $instance);
        // forbidNoCacheQueryParameter() is part of the default chain
        self::assertTrue($GLOBALS['TYPO3_CONF_VARS']['FE']['disableNoCacheParameter']);
    }

    #[Test]
    public function fluentInterfaceReturnsInstance(): void
    {
        $instance = new Config();
        $result = $instance->forbidNoCacheQueryParameter();
        self::assertSame($instance, $result);
    }

    #[Test]
    public function appendContextToSiteNameIsIdempotent(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] = 'Acme';

        $instance = new Config();
        $instance->appendContextToSiteName();
        $instance->appendContextToSiteName();
        $instance->appendContextToSiteName();

        // Should only append the context suffix once, not three times
        self::assertSame('Acme - Testing', $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename']);
    }

    #[Test]
    public function initializeDatabaseConnectionOhneOptionenAendertNichts(): void
    {
        // null ist der Normalfall im Aufrufpfad: die Optionen kommen aus einer
        // Umgebungsvariablen, die auf vielen Mandanten nicht gesetzt ist. Ein
        // Ueberschreiben mit einem leeren Array haette dort die Verbindung
        // zerlegt.
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = ['driver' => 'pdo_mysql'];

        (new Config())->initializeDatabaseConnection(null);

        self::assertSame(['driver' => 'pdo_mysql'], $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']);
    }

    #[Test]
    public function initializeDatabaseConnectionMischtInDieBestehendeVerbindung(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = [
            'driver' => 'pdo_mysql',
            'dbname' => 'typo3',
        ];

        (new Config())->initializeDatabaseConnection(['dbname' => 'anders', 'port' => 3307]);

        self::assertSame(
            ['driver' => 'pdo_mysql', 'dbname' => 'anders', 'port' => 3307],
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'],
        );
    }

    #[Test]
    public function initializeDatabaseConnectionTrifftDieBenannteVerbindung(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = ['driver' => 'pdo_mysql'];
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Zweite'] = ['driver' => 'pdo_mysql'];

        (new Config())->initializeDatabaseConnection(['dbname' => 'zweite'], 'Zweite');

        self::assertSame('zweite', $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Zweite']['dbname']);
        self::assertArrayNotHasKey('dbname', $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']);
    }
}
