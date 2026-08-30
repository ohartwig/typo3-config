<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use Moselwal\OAuth2\GitLabResourceServer;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;

class GitLabResourceServerTest extends ConfigTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(\Mfc\OAuth2\ResourceServer\GitLab::class)) {
            self::markTestSkipped('mfc/oauth2 is not installed');
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);

        parent::tearDown();
    }

    #[Test]
    public function neverBuildsTheDeadTypo3IndexPhpPath(): void
    {
        // The bug this class exists for. Upstream hardcodes /typo3/index.php,
        // an entry script TYPO3 v14 does not ship, so the callback 404s — after
        // the user has already authorized at GitLab. Everything before that
        // point looks healthy, which is why it took a live login to find.
        $GLOBALS['TYPO3_REQUEST'] = new ServerRequest('https://cms.example.com/login');

        self::assertStringNotContainsString('/typo3/index.php', $this->buildRedirectUri());
    }

    #[Test]
    public function carriesTheLoginParametersIntoTheCallback(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = new ServerRequest('https://cms.example.com/login');

        $uri = $this->buildRedirectUri();

        self::assertStringContainsString('loginProvider=1529672977', $uri);
        self::assertStringContainsString('login_status=login', $uri);
        self::assertStringContainsString('resource-server-identifier=gitlab', $uri);
    }

    #[Test]
    public function returnsToTheLoginPathOfTheRunningRequest(): void
    {
        // Dedicated backend host (BE/entryPoint): TYPO3 serves the login at the
        // root of that host, so the callback belongs at /login.
        $GLOBALS['TYPO3_REQUEST'] = new ServerRequest('https://cms.example.com/login');

        self::assertSame('/login', $this->loginPath());
    }

    #[Test]
    public function followsAnInstallationThatKeepsTheBackendUnderTypo3(): void
    {
        // No dedicated backend host: TYPO3 v13+ serves the login at
        // /typo3/login, and the redirect has to follow it there.
        $GLOBALS['TYPO3_REQUEST'] = new ServerRequest('https://www.example.com/typo3/login');

        self::assertSame('/typo3/login', $this->loginPath());
    }

    #[Test]
    public function fallsBackToTheDefaultRouteWhenThePathIsUseless(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = new ServerRequest('https://cms.example.com/');

        self::assertSame('/typo3/login', $this->loginPath());
    }

    #[Test]
    public function fallsBackToTheDefaultRouteWithoutARequest(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);

        self::assertSame('/typo3/login', $this->loginPath());
    }

    private function loginPath(): string
    {
        return $this->invoke('getLoginPath');
    }

    private function buildRedirectUri(string $identifier = 'gitlab'): string
    {
        return $this->invoke('getRedirectUri', $identifier)[0];
    }

    /**
     * Both methods are protected, and the constructor upstream wants
     * credentials plus a live request. Reflection keeps this to the code
     * actually under test.
     *
     * @return mixed
     */
    private function invoke(string $method, mixed ...$arguments)
    {
        $server = (new \ReflectionClass(GitLabResourceServer::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod(GitLabResourceServer::class, $method))->invoke($server, ...$arguments);
    }
}
