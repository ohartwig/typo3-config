<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use Moselwal\Config;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Schalter aus dem Pentest vom 2026-05-20 (F-15, F-16) und die
 * cacheHash-Ausnahmen. Sie sind einzeln unscheinbar, und genau deshalb faellt
 * ein vertauschter oder invertierter Wert hier sonst niemandem auf: keine
 * dieser Methoden hatte bisher eine abgedeckte Zeile.
 */
class FrontendHardeningTest extends ConfigTestCase
{
    #[Test]
    public function dieCacheLifetimeStehtStandardmaessigAufEinerStunde(): void
    {
        // TYPO3s eigener Vorgabewert sind 24 Stunden. Ein einmal vergifteter
        // Cache-Eintrag haelt sich damit einen Tag lang (F-15).
        (new Config())->useShorterCacheLifetime();

        self::assertSame(3600, $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheTimeout']);
    }

    #[Test]
    public function unterEinerMinuteWirdNichtGegangen(): void
    {
        // max(60, ...) ist kein Schoenheitsfehler: eine Lifetime von 0 oder 1
        // Sekunde schaltet den Seiten-Cache praktisch ab, und das faellt erst
        // unter Last auf.
        (new Config())->useShorterCacheLifetime(5);

        self::assertSame(60, $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheTimeout']);
    }

    #[Test]
    public function derVorgabewertLaesstSichNachObenSetzen(): void
    {
        (new Config())->useShorterCacheLifetime(7200);

        self::assertSame(7200, $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheTimeout']);
    }

    #[Test]
    public function dieCacheDebugHeaderVerschwinden(): void
    {
        // X-TYPO3-Debug-Cache und Verwandte verraten interne Cache-Tags nach
        // aussen (F-16). Caddy strippt sie am Rand zusaetzlich - beides, nicht
        // eines von beiden.
        $GLOBALS['TYPO3_CONF_VARS']['FE']['debug'] = true;

        (new Config())->useNoCacheDebugHeaders();

        self::assertFalse($GLOBALS['TYPO3_CONF_VARS']['FE']['debug']);
        self::assertFalse($GLOBALS['TYPO3_CONF_VARS']['FE']['cacheTimeoutResponseHeader']);
    }

    #[Test]
    public function derNoCacheParameterLaesstSichOeffnenUndSchliessen(): void
    {
        // Der Schluessel heisst disableNoCacheParameter - der Wert ist also
        // gegenlaeufig zum Methodennamen. Genau die Sorte Verwechslung, die
        // ein Test festhalten sollte.
        $config = new Config();

        $config->allowNoCacheQueryParameter();
        self::assertFalse($GLOBALS['TYPO3_CONF_VARS']['FE']['disableNoCacheParameter']);

        $config->forbidNoCacheQueryParameter();
        self::assertTrue($GLOBALS['TYPO3_CONF_VARS']['FE']['disableNoCacheParameter']);
    }

    #[Test]
    public function einzelneParameterWerdenAngehaengtUndNichtErsetzt(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'] = ['utm_source'];

        (new Config())->excludeQueryParameterForCacheHashCalculation('gclid');

        self::assertSame(
            ['utm_source', 'gclid'],
            $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'],
        );
    }

    #[Test]
    public function mehrereParameterWerdenAnDieBestehendeListeGehaengt(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'] = ['utm_source'];

        (new Config())->excludeQueryParametersForCacheHashCalculation(['utm_medium', 'fbclid']);

        self::assertSame(
            ['utm_source', 'utm_medium', 'fbclid'],
            $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'],
        );
    }

    #[Test]
    public function derBackendEinstiegLaesstSichVerlegen(): void
    {
        (new Config())->useBackendEntryPoint('/verwaltung');

        self::assertSame('/verwaltung', $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint']);
    }

    #[Test]
    public function ohneAngabeBleibtDieCookieDomainUnangetastet(): void
    {
        // Automatisches Raten ist bei mehrstufigen Subdomains falsch, und eine
        // falsch gesetzte cookieDomain sperrt den Backend-Login aus, ohne eine
        // Fehlermeldung zu hinterlassen.
        (new Config())->useBackendEntryPoint('/verwaltung');

        self::assertArrayNotHasKey('cookieDomain', $GLOBALS['TYPO3_CONF_VARS']['SYS']);
    }

    #[Test]
    public function eineAusdrueckicheCookieDomainWirdGesetzt(): void
    {
        (new Config())->useBackendEntryPoint('https://admin.example.org', '.example.org');

        self::assertSame('.example.org', $GLOBALS['TYPO3_CONF_VARS']['SYS']['cookieDomain']);
    }

    #[Test]
    public function dieAusnahmebehandlerLandenAnIhrenBeidenPlaetzen(): void
    {
        (new Config())->configureExceptionHandlers('Acme\\ProductionHandler', 'Acme\\DebugHandler');

        self::assertSame('Acme\\ProductionHandler', $GLOBALS['TYPO3_CONF_VARS']['SYS']['productionExceptionHandler']);
        self::assertSame('Acme\\DebugHandler', $GLOBALS['TYPO3_CONF_VARS']['SYS']['debugExceptionHandler']);
    }

    #[Test]
    public function dieSchalterGebenSichSelbstZurueck(): void
    {
        $config = new Config();

        self::assertSame($config, $config->useShorterCacheLifetime());
        self::assertSame($config, $config->useNoCacheDebugHeaders());
        self::assertSame($config, $config->allowNoCacheQueryParameter());
        self::assertSame($config, $config->useBackendEntryPoint('/verwaltung'));
        self::assertSame($config, $config->configureExceptionHandlers('A', 'B'));
        self::assertSame($config, $config->excludeQueryParameterForCacheHashCalculation('x'));
        self::assertSame($config, $config->excludeQueryParametersForCacheHashCalculation(['y']));
    }
}
