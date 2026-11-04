<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Token\AccessToken;
use Mfc\OAuth2\ResourceServer\GitLab;
use Moselwal\OAuth2\GitLabResourceServer;
use Omines\OAuth2\Client\Provider\Gitlab as GitLabOAuthProvider;
use Omines\OAuth2\Client\Provider\GitlabResourceOwner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * AUD-038-F03: the GitLab login without read_api.
 *
 * In the project model the user's token must read the project member API, so
 * the login asks for read_api — a token that reads the whole GitLab API as the
 * user. With an access group the level comes from OpenID Connect userinfo and
 * the login asks for "read_user openid" only.
 */
class GitLabAccessGroupTest extends ConfigTestCase
{
    private const GROUP = 'development/sites/site-access';

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $sentRequests = [];

    private ?ContainerInterface $originalContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(GitLab::class)) {
            self::markTestSkipped('mfc/oauth2 is not installed');
        }

        $this->originalContainer = (new \ReflectionProperty(GeneralUtility::class, 'container'))->getValue();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('k', 96);
        $request = (new ServerRequest('https://cms.example.test/login', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $GLOBALS['TYPO3_REQUEST'] = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(GeneralUtility::class, 'container'))->setValue(null, $this->originalContainer);
        unset($GLOBALS['TYPO3_REQUEST']);

        parent::tearDown();
    }

    #[Test]
    public function theAuthorizeUrlOfAnAccessGroupLoginCarriesNoReadApi(): void
    {
        [$url] = $this->resourceServer(self::GROUP)->getAuthorizationUrl();

        $scopes = $this->scopesOf($url);
        self::assertNotContains('read_api', $scopes);
        self::assertNotContains('api', $scopes);
        self::assertSame(['read_user', 'openid'], $scopes);
        self::assertStringStartsWith('https://git.example.test/oauth/authorize?', $url);
    }

    #[Test]
    public function theProjectModelStillAsksForReadApi(): void
    {
        // Documents the trade-off the access group removes.
        [$url] = $this->resourceServer('')->getAuthorizationUrl();

        self::assertContains('read_api', $this->scopesOf($url));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function claims(): iterable
    {
        $owner = 'https://gitlab.org/claims/groups/owner';
        $maintainer = 'https://gitlab.org/claims/groups/maintainer';
        $developer = 'https://gitlab.org/claims/groups/developer';

        yield 'no membership' => [['groups' => ['other/group']], 0];
        yield 'no claims at all' => [[], 0];
        yield 'direct member without a role claim (Guest or Reporter)' => [['groups' => [self::GROUP]], GitLab::USER_LEVEL_GUEST];
        yield 'member of an ancestor' => [['groups' => ['development']], GitLab::USER_LEVEL_GUEST];
        yield 'Developer of the group' => [['groups' => [self::GROUP], $developer => [self::GROUP]], GitLab::USER_LEVEL_DEVELOPER];
        yield 'Maintainer of the group' => [[$maintainer => [self::GROUP]], GitLab::USER_LEVEL_MAINTAINER];
        yield 'Owner of an ancestor' => [[$owner => ['development']], 50];
        yield 'highest role wins' => [[$developer => [self::GROUP], $maintainer => ['development/sites']], GitLab::USER_LEVEL_MAINTAINER];
        yield 'paths compare case-insensitively' => [[$maintainer => ['Development/Sites/Site-Access']], GitLab::USER_LEVEL_MAINTAINER];
        yield 'Maintainer of a subgroup grants nothing above' => [[$maintainer => [self::GROUP . '/child']], 0];
        yield 'a sibling with a shared prefix grants nothing' => [[$maintainer => ['development/sites/site'], 'groups' => ['development/sites/site-access-old']], 0];
        yield 'Maintainer elsewhere, member here' => [[$maintainer => ['other'], 'groups' => [self::GROUP]], GitLab::USER_LEVEL_GUEST];
        yield 'malformed claim values are ignored' => [[$maintainer => 'development', 'groups' => [42, null]], 0];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[Test]
    #[DataProvider('claims')]
    public function mapsUserinfoClaimsToAnAccessLevel(array $claims, int $expected): void
    {
        self::assertSame($expected, GitLabResourceServer::accessLevelFromClaims($claims, self::GROUP));
    }

    #[Test]
    public function aMaintainerOfTheAccessGroupBecomesAdminThroughUserinfo(): void
    {
        $server = $this->resourceServer(self::GROUP);
        $this->answerUserinfo($server, new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'sub' => '4242',
            'https://gitlab.org/claims/groups/maintainer' => [self::GROUP],
        ], JSON_THROW_ON_ERROR)));
        $server->confirmResolutionByIdOnly();

        $user = $this->gitlabUser(4242);
        self::assertTrue($server->userIsActive($user));
        self::assertTrue($server->userShouldBeAdmin($user));

        // One request, to userinfo, with the user's token, and no project API.
        self::assertCount(1, $this->sentRequests);
        $request = $this->sentRequests[0]['request'];
        self::assertSame('https://git.example.test/oauth/userinfo', (string)$request->getUri());
        self::assertSame('Bearer user-token', $request->getHeaderLine('Authorization'));
    }

    #[Test]
    public function aDeveloperIsActiveButNoAdmin(): void
    {
        $server = $this->resourceServer(self::GROUP);
        $this->answerUserinfo($server, new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'sub' => '4242',
            'groups' => [self::GROUP],
            'https://gitlab.org/claims/groups/developer' => [self::GROUP],
        ], JSON_THROW_ON_ERROR)));
        $server->confirmResolutionByIdOnly();

        $user = $this->gitlabUser(4242);
        self::assertTrue($server->userIsActive($user));
        self::assertFalse($server->userShouldBeAdmin($user));
    }

    /**
     * @return iterable<string, array{Response}>
     */
    public static function unusableUserinfo(): iterable
    {
        $json = ['Content-Type' => 'application/json'];
        yield 'userinfo of another user' => [new Response(200, $json, '{"sub":"1","https://gitlab.org/claims/groups/owner":["development"]}')];
        yield 'token without openid' => [new Response(403, $json, '{"error":"insufficient_scope"}')];
        yield 'GitLab down' => [new Response(502, [], 'bad gateway')];
    }

    #[Test]
    #[DataProvider('unusableUserinfo')]
    public function anUnusableUserinfoAnswerRefusesTheLogin(Response $response): void
    {
        $server = $this->resourceServer(self::GROUP);
        $this->answerUserinfo($server, $response);
        $server->confirmResolutionByIdOnly();

        $user = $this->gitlabUser(4242);
        self::assertFalse($server->userIsActive($user));
        self::assertFalse($server->userShouldBeAdmin($user));
    }

    #[Test]
    public function anExternalUserIsRefusedWithoutAskingGitLab(): void
    {
        $server = $this->resourceServer(self::GROUP);
        $this->answerUserinfo($server, new Response(200, [], '{"sub":"4242","https://gitlab.org/claims/groups/owner":["development"]}'));
        $server->confirmResolutionByIdOnly();

        self::assertFalse($server->userIsActive($this->gitlabUser(4242, external: true)));
        self::assertCount(0, $this->sentRequests);
    }

    private function resourceServer(string $accessGroup): GitLabResourceServer
    {
        // BackendEntryPointResolver is all the redirect URI needs from core.
        $resolver = new BackendEntryPointResolver();
        GeneralUtility::setContainer(new class ($resolver) implements ContainerInterface {
            public function __construct(private readonly BackendEntryPointResolver $resolver) {}

            public function get(string $id): object
            {
                return $this->resolver;
            }

            public function has(string $id): bool
            {
                return $id === BackendEntryPointResolver::class;
            }
        });

        return new GitLabResourceServer([
            'providerName' => 'gitlab',
            'appId' => 'app-id',
            'appSecret' => 'app-secret',
            'gitlabServer' => 'https://git.example.test',
            'gitlabAdminUserLevel' => GitLab::USER_LEVEL_MAINTAINER,
            'gitlabDefaultGroups' => '0',
            'gitlabUserOption' => 3,
            'blockExternalUser' => true,
            'projectName' => $accessGroup === '' ? 'development/sites/site-websites' : '',
            'accessGroup' => $accessGroup,
        ]);
    }

    private function answerUserinfo(GitLabResourceServer $server, Response $response): void
    {
        $this->sentRequests = [];
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($this->sentRequests));

        $provider = new GitLabOAuthProvider(
            ['clientId' => 'app-id', 'clientSecret' => 'app-secret', 'redirectUri' => 'https://cms.example.test/login', 'domain' => 'https://git.example.test'],
            ['httpClient' => new Client(['handler' => $stack])]
        );
        (new \ReflectionProperty(GitLab::class, 'oauthProvider'))->setValue($server, $provider);
    }

    private function gitlabUser(int $id, bool $external = false): GitlabResourceOwner
    {
        return new GitlabResourceOwner(
            ['id' => $id, 'username' => 'user' . $id, 'email' => 'u@example.test', 'name' => 'User', 'external' => $external],
            new AccessToken(['access_token' => 'user-token'])
        );
    }

    /**
     * @return list<string>
     */
    private function scopesOf(string $url): array
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        return explode(' ', (string)($query['scope'] ?? ''));
    }
}
