<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use Mfc\OAuth2\ResourceServer\GitLab;
use Mfc\OAuth2\ResourceServer\Registry;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\Test;

class GitLabBackendLoginTest extends ConfigTestCase
{
    use PHPMock;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(Registry::class)) {
            self::markTestSkipped('mfc/oauth2 is not installed');
        }

        $this->resetResourceServerRegistry();
    }

    protected function tearDown(): void
    {
        $this->resetResourceServerRegistry();

        parent::tearDown();
    }

    #[Test]
    public function registersTheProviderWhenBothCredentialsResolve(): void
    {
        $this->mockSecrets(['GITLAB_OAUTH_APP_ID' => 'app-id', 'GITLAB_OAUTH_APP_SECRET' => 'app-secret']);

        TestableConfig::initializeWithVersion(14)
            ->useGitLabBackendLogin('https://git.example.com', 'devops/typo3-backend-access');

        self::assertSame(
            [['title' => 'Login mit GitLab', 'identifier' => 'gitlab']],
            Registry::getAvailableResourceServers()
        );
    }

    #[Test]
    public function passesTheConfigurationThroughToTheResourceServer(): void
    {
        $this->mockSecrets(['GITLAB_OAUTH_APP_ID' => 'app-id', 'GITLAB_OAUTH_APP_SECRET' => 'app-secret']);

        TestableConfig::initializeWithVersion(14)
            ->useGitLabBackendLogin(
                // Trailing slash on purpose: the omines provider builds its
                // endpoint URLs by concatenation and would produce a double
                // slash the GitLab router answers with a redirect.
                gitlabServer: 'https://git.example.com/',
                projectName: 'devops/typo3-backend-access',
                adminUserLevel: GitLab::USER_LEVEL_MAINTAINER,
                defaultGroups: '7,8',
                blockExternalUsers: true,
            );

        $arguments = $this->registeredOptions()['arguments'];

        self::assertSame('app-id', $arguments['appId']);
        self::assertSame('app-secret', $arguments['appSecret']);
        self::assertSame('https://git.example.com', $arguments['gitlabServer']);
        self::assertSame('devops/typo3-backend-access', $arguments['projectName']);
        self::assertSame(40, $arguments['gitlabAdminUserLevel']);
        self::assertSame('7,8', $arguments['gitlabDefaultGroups']);
        self::assertTrue($arguments['blockExternalUser']);
        self::assertSame(3, $arguments['gitlabUserOption']);
    }

    #[Test]
    public function enablesTheBackendLoginProviderThroughExtensionConfiguration(): void
    {
        $this->mockSecrets(['GITLAB_OAUTH_APP_ID' => 'app-id', 'GITLAB_OAUTH_APP_SECRET' => 'app-secret']);

        TestableConfig::initializeWithVersion(14)
            ->useGitLabBackendLogin('https://git.example.com', 'devops/typo3-backend-access');

        self::assertSame('1', $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['oauth2']['enableBackendLogin']);
        self::assertSame('0', $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['oauth2']['overrideUser']);
    }

    #[Test]
    public function overrideUserIsOptIn(): void
    {
        $this->mockSecrets(['GITLAB_OAUTH_APP_ID' => 'app-id', 'GITLAB_OAUTH_APP_SECRET' => 'app-secret']);

        TestableConfig::initializeWithVersion(14)
            ->useGitLabBackendLogin('https://git.example.com', 'devops/typo3', overrideUser: true);

        self::assertSame('1', $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['oauth2']['overrideUser']);
    }

    #[Test]
    public function relaxesCookieSameSiteBecauseTheCallbackIsCrossSite(): void
    {
        $this->mockSecrets(['GITLAB_OAUTH_APP_ID' => 'app-id', 'GITLAB_OAUTH_APP_SECRET' => 'app-secret']);
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = 'strict';

        TestableConfig::initializeWithVersion(14)
            ->useGitLabBackendLogin('https://git.example.com', 'devops/typo3-backend-access');

        self::assertSame('lax', $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite']);
    }

    #[Test]
    public function doesNothingWhenTheSecretIsMissing(): void
    {
        $this->mockSecrets(['GITLAB_OAUTH_APP_ID' => 'app-id']);
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = 'strict';

        TestableConfig::initializeWithVersion(14)
            ->useGitLabBackendLogin('https://git.example.com', 'devops/typo3-backend-access');

        // Half a credential pair must not produce a login button that can only
        // fail, and must not have cost us the hardened cookie setting.
        self::assertSame([], Registry::getAvailableResourceServers());
        self::assertSame('strict', $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite']);
        self::assertArrayNotHasKey('oauth2', $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']);
    }

    #[Test]
    public function doesNothingWithoutAnyCredentials(): void
    {
        $this->mockSecrets([]);
        $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite'] = 'strict';

        TestableConfig::initializeWithVersion(14)
            ->useGitLabBackendLogin('https://git.example.com', 'devops/typo3-backend-access');

        self::assertSame([], Registry::getAvailableResourceServers());
        self::assertSame('strict', $GLOBALS['TYPO3_CONF_VARS']['BE']['cookieSameSite']);
    }

    #[Test]
    public function doesNothingWhenTheProjectIsNotConfigured(): void
    {
        $this->mockSecrets(['GITLAB_OAUTH_APP_ID' => 'app-id', 'GITLAB_OAUTH_APP_SECRET' => 'app-secret']);

        // Without a project the resource server throws on the first login
        // attempt instead of denying access, so refuse it here.
        TestableConfig::initializeWithVersion(14)
            ->useGitLabBackendLogin('https://git.example.com', '');

        self::assertSame([], Registry::getAvailableResourceServers());
    }

    #[Test]
    public function readsTheSecretFromTheRunSecretsMount(): void
    {
        $getenv = $this->getFunctionMock('Moselwal', 'getenv');
        $getenv->expects(self::any())->willReturn(false);

        $isReadable = $this->getFunctionMock('Moselwal', 'is_readable');
        $isReadable->expects(self::any())->willReturnCallback(
            static fn(string $path): bool => in_array(
                $path,
                ['/run/secrets/gitlab_oauth_app_id', '/run/secrets/gitlab_oauth_app_secret'],
                true
            )
        );

        $fileGetContents = $this->getFunctionMock('Moselwal', 'file_get_contents');
        $fileGetContents->expects(self::any())->willReturnCallback(
            static fn(string $path): string => $path === '/run/secrets/gitlab_oauth_app_id'
                ? "id-from-mount\n"
                : "secret-from-mount\n"
        );

        TestableConfig::initializeWithVersion(14)
            ->useGitLabBackendLogin('https://git.example.com', 'devops/typo3-backend-access');

        $arguments = $this->registeredOptions()['arguments'];

        self::assertSame('id-from-mount', $arguments['appId']);
        self::assertSame('secret-from-mount', $arguments['appSecret']);
    }

    /**
     * @param array<string, string> $secrets
     */
    private function mockSecrets(array $secrets): void
    {
        $getenv = $this->getFunctionMock('Moselwal', 'getenv');
        $getenv->expects(self::any())->willReturnCallback(
            static fn(string $key): string|false => $secrets[$key] ?? false
        );

        $isReadable = $this->getFunctionMock('Moselwal', 'is_readable');
        $isReadable->expects(self::any())->willReturn(false);
    }

    /**
     * @return array<string, mixed>
     */
    private function registeredOptions(): array
    {
        $registry = new \ReflectionProperty(Registry::class, 'registry');

        return $registry->getValue()['gitlab']['options'];
    }

    private function resetResourceServerRegistry(): void
    {
        $registry = new \ReflectionProperty(Registry::class, 'registry');
        $registry->setValue(null, []);
    }
}
