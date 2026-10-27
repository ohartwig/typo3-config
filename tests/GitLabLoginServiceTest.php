<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Token\AccessToken;
use Mfc\OAuth2\ResourceServer\GitLab;
use Mfc\OAuth2\ResourceServer\Registry;
use Mfc\OAuth2\Services\OAuth2LoginService;
use Moselwal\OAuth2\GitLabLoginService;
use Moselwal\OAuth2\GitLabResourceServer;
use Moselwal\Tests\Fixtures\SqliteConnection;
use Omines\OAuth2\Client\Provider\GitlabResourceOwner;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Crypto\PasswordHashing\BcryptPasswordHash;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\DriverMiddlewareService;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Schema\SchemaCollection;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Service\DependencyOrderingService;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The GitLab login against a real (SQLite) be_users table: which account a
 * GitLab identity ends up in, and with which rights.
 *
 * The GitLab API is not called. The access level a login would read from the
 * governing project is put into the resource server directly.
 */
class GitLabLoginServiceTest extends ConfigTestCase
{
    use PHPMock;

    private const ADMIN_UID = 7;

    private string $databaseFile = '';

    private mixed $originalRequest = null;

    private ?PackageManager $originalPackageManager = null;

    private ?ContainerInterface $originalContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(OAuth2LoginService::class) || !extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('mfc/oauth2 or pdo_sqlite is not available');
        }

        $this->originalRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://cms.example.test/typo3/login', 'GET'))
            ->withQueryParams(['resource-server-identifier' => 'gitlab'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['availablePasswordHashAlgorithms'] = [BcryptPasswordHash::class];
        $GLOBALS['TYPO3_CONF_VARS']['BE']['passwordHashing'] = ['className' => BcryptPasswordHash::class, 'options' => ['cost' => 10]];

        $this->databaseFile = tempnam(sys_get_temp_dir(), 'typo3-config-oauth2-') ?: '';
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = [
            'driver' => 'pdo_sqlite',
            'path' => $this->databaseFile,
            'wrapperClass' => SqliteConnection::class,
        ];
        $this->resetConnectionPool();
        $this->provideCoreServices();
        $this->createTables();

        $packageManager = new \ReflectionProperty(ExtensionManagementUtility::class, 'packageManager');
        $this->originalPackageManager = $packageManager->isInitialized() ? $packageManager->getValue() : null;
        $packageManagerStub = $this->createStub(PackageManager::class);
        $packageManagerStub->method('isPackageActive')->willReturn(false);
        ExtensionManagementUtility::setPackageManager($packageManagerStub);

        $this->resetRegistry();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(GeneralUtility::class, 'container'))->setValue(null, $this->originalContainer);
        $this->resetRegistry();
        $this->resetConnectionPool();
        if ($this->databaseFile !== '' && is_file($this->databaseFile)) {
            unlink($this->databaseFile);
        }
        if ($this->originalPackageManager !== null) {
            ExtensionManagementUtility::setPackageManager($this->originalPackageManager);
        }
        $GLOBALS['TYPO3_REQUEST'] = $this->originalRequest;

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function membersImpersonatingAnAdministrator(): iterable
    {
        yield 'Guest with the administrator\'s username' => [GitLab::USER_LEVEL_GUEST, 'victim', 'other@example.test'];
        yield 'Guest with the administrator\'s e-mail' => [GitLab::USER_LEVEL_GUEST, 'nobody', 'victim@example.test'];
        yield 'Developer with the administrator\'s username' => [GitLab::USER_LEVEL_DEVELOPER, 'victim', 'other@example.test'];
        yield 'Developer with the administrator\'s e-mail' => [GitLab::USER_LEVEL_DEVELOPER, 'nobody', 'victim@example.test'];
    }

    #[Test]
    #[DataProvider('membersImpersonatingAnAdministrator')]
    public function aMatchingUsernameOrEmailNeverReachesAnExistingAccount(int $accessLevel, string $username, string $email): void
    {
        $this->insertUser(['uid' => self::ADMIN_UID, 'username' => 'victim', 'email' => 'victim@example.test', 'admin' => 1]);

        $service = $this->loginService();
        $record = $this->login($service, $this->gitlabUser(4242, $username, $email), $accessLevel);

        self::assertNotNull($record);
        self::assertNotSame(self::ADMIN_UID, (int)$record['uid']);
        self::assertSame('gitlab-4242', $record['username']);
        self::assertSame(0, (int)$record['admin']);
        self::assertSame('gitlab|4242', $record['oauth_identifier']);

        // The administrator's account is untouched and cannot be authenticated
        // with this GitLab session.
        $admin = $this->fetchUser(self::ADMIN_UID);
        self::assertSame('', $admin['oauth_identifier']);
        self::assertSame(1, (int)$admin['admin']);
        self::assertSame(100, $service->authUser($admin));
        self::assertSame(200, $service->authUser($record));
    }

    #[Test]
    public function anAccountLinkedByIdentifierKeepsWorkingAndKeepsItsName(): void
    {
        $this->insertUser(['uid' => 9, 'username' => 'operator', 'email' => 'op@example.test', 'admin' => 1, 'oauth_identifier' => 'gitlab|1']);

        $service = $this->loginService();
        $record = $this->login($service, $this->gitlabUser(1, 'renamed-on-gitlab', 'new@example.test'), 50);

        self::assertNotNull($record);
        self::assertSame(9, (int)$record['uid']);
        self::assertSame('operator', $record['username']);
        self::assertSame(1, (int)$record['admin']);
        self::assertSame(1, $this->countUsers());
        self::assertSame(200, $service->authUser($record));
    }

    #[Test]
    public function aDemotionInGitLabRemovesAdminAndRemapsGroupsOnTheNextLogin(): void
    {
        $this->insertGroup(['uid' => 3, 'title' => 'Developers', 'gitlabGroup' => '30']);

        $first = $this->login($this->loginService(), $this->gitlabUser(55, 'maintainer', 'm@example.test'), GitLab::USER_LEVEL_MAINTAINER);
        self::assertNotNull($first);
        self::assertSame(1, (int)$first['admin']);

        $this->resetRegistry();
        $second = $this->login($this->loginService(), $this->gitlabUser(55, 'maintainer', 'm@example.test'), GitLab::USER_LEVEL_DEVELOPER);

        self::assertNotNull($second);
        self::assertSame((int)$first['uid'], (int)$second['uid']);
        self::assertSame(0, (int)$second['admin']);
        self::assertSame('3', (string)$second['usergroup']);
        self::assertSame(0, (int)$this->fetchUser((int)$first['uid'])['admin']);
    }

    #[Test]
    public function anIdentityWhoseAccountIsDisabledInTypo3IsRefusedInsteadOfGettingANewOne(): void
    {
        $this->insertUser(['uid' => 11, 'username' => 'gitlab-77', 'oauth_identifier' => 'gitlab|77', 'disable' => 1]);

        $record = $this->login($this->loginService(), $this->gitlabUser(77, 'someone', 's@example.test'), GitLab::USER_LEVEL_MAINTAINER);

        self::assertNull($record);
        self::assertSame(1, $this->countUsers());
        self::assertSame(1, (int)$this->fetchUser(11)['disable']);
    }

    #[Test]
    public function aLinkedAccountsEntryLinksTheLocalAccountToThatGitLabIdOnly(): void
    {
        $this->insertUser(['uid' => 5, 'username' => 'operator', 'email' => 'op@example.test', 'admin' => 1]);

        // Somebody else, carrying the account's name on GitLab: no link.
        $other = $this->login($this->loginService(['operator' => 1]), $this->gitlabUser(2, 'operator', 'op@example.test'), GitLab::USER_LEVEL_GUEST);
        self::assertNotNull($other);
        self::assertNotSame(5, (int)$other['uid']);
        self::assertSame('', $this->fetchUser(5)['oauth_identifier']);

        // The GitLab user the operator listed: linked, name kept.
        $this->resetRegistry();
        $linked = $this->login($this->loginService(['operator' => 1]), $this->gitlabUser(1, 'ops-on-gitlab', 'ops@example.test'), GitLab::USER_LEVEL_MAINTAINER);
        self::assertNotNull($linked);
        self::assertSame(5, (int)$linked['uid']);
        self::assertSame('operator', $linked['username']);
        self::assertSame('gitlab|1', $linked['oauth_identifier']);
    }

    #[Test]
    public function aLinkedAccountsEntryDoesNotTakeAnAccountLinkedToAnotherIdentity(): void
    {
        $this->insertUser(['uid' => 5, 'username' => 'operator', 'admin' => 1, 'oauth_identifier' => 'gitlab|3']);

        $record = $this->login($this->loginService(['operator' => 1]), $this->gitlabUser(1, 'x', 'x@example.test'), GitLab::USER_LEVEL_MAINTAINER);

        self::assertNotNull($record);
        self::assertNotSame(5, (int)$record['uid']);
        self::assertSame('gitlab|3', $this->fetchUser(5)['oauth_identifier']);
    }

    #[Test]
    public function upstreamsServiceCannotAuthenticateAgainstThisResourceServer(): void
    {
        // Should the service override ever be lost, the login must fail
        // closed rather than fall back to upstream's lookup.
        $this->insertUser(['uid' => 21, 'username' => 'gitlab-8', 'oauth_identifier' => 'gitlab|8']);

        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['overrideUser' => '1']);

        $service = new OAuth2LoginService(
            new ResponseFactory(),
            $extensionConfiguration,
            new ConnectionPool(),
            $this->eventDispatcher()
        );
        $service->setLogger(new NullLogger());
        $this->registerResourceServer([]);

        $user = $this->gitlabUser(8, 'x', 'x@example.test');
        (fn() => $this->initializeResourceServer($GLOBALS['TYPO3_REQUEST']))->call($service);
        $this->setAccessLevel(Registry::getResourceServerInstance('gitlab'), GitLab::USER_LEVEL_MAINTAINER);
        $this->answerResourceOwnerRequestsWith(Registry::getResourceServerInstance('gitlab'), $user);
        $this->setAccessToken($service);

        self::assertSame(100, $service->authUser($this->fetchUser(21)));
    }

    /**
     * @param array<string, int> $linkedAccounts
     */
    private function loginService(array $linkedAccounts = []): GitLabLoginService
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['overrideUser' => '1', 'backendUserRealNameFormat' => '%name%']);

        $service = new GitLabLoginService(new ResponseFactory(), $extensionConfiguration, new ConnectionPool(), $this->eventDispatcher());
        $service->setLogger(new NullLogger());
        $service->initAuth('getUserBE', ['status' => 'login'], [
            'db_user' => [
                'table' => 'be_users',
                'username_column' => 'username',
                'check_pid_clause' => '',
                'enable_clause' => 'disable = 0',
            ],
        ], null);

        $this->registerResourceServer($linkedAccounts);

        return $service;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function login(GitLabLoginService $service, GitlabResourceOwner $user, int $accessLevel): ?array
    {
        (fn() => $this->initializeResourceServer($GLOBALS['TYPO3_REQUEST']))->call($service);
        $this->setAccessLevel(Registry::getResourceServerInstance('gitlab'), $accessLevel);
        $this->answerResourceOwnerRequestsWith(Registry::getResourceServerInstance('gitlab'), $user);
        $this->setAccessToken($service);

        return (fn() => $this->findOrCreateUserByResourceOwner($user))->call($service);
    }

    /**
     * @param array<string, int> $linkedAccounts
     */
    private function registerResourceServer(array $linkedAccounts): void
    {
        Registry::addServer('gitlab', 'GitLab', GitLabResourceServer::class, [
            'enabled' => true,
            'arguments' => [
                'appId' => 'app-id',
                'appSecret' => 'app-secret',
                'gitlabServer' => 'https://git.example.test',
                'gitlabAdminUserLevel' => GitLab::USER_LEVEL_MAINTAINER,
                'gitlabDefaultGroups' => '0',
                'gitlabUserOption' => 3,
                'blockExternalUser' => true,
                'projectName' => 'group/project',
                'linkedAccounts' => $linkedAccounts,
            ],
        ]);
    }

    /**
     * authUser() asks the OAuth provider for the resource owner again; answer
     * with the given user instead of calling GitLab.
     */
    private function answerResourceOwnerRequestsWith(object $resourceServer, GitlabResourceOwner $user): void
    {
        $provider = $this->createStub(AbstractProvider::class);
        $provider->method('getResourceOwner')->willReturn($user);
        (new \ReflectionProperty(GitLab::class, 'oauthProvider'))->setValue($resourceServer, $provider);
    }

    private function setAccessLevel(object $resourceServer, int $accessLevel): void
    {
        (new \ReflectionProperty(GitLab::class, 'gitlabProjectPermissions'))->setValue($resourceServer, ['access_level' => $accessLevel]);
        (new \ReflectionProperty(GitLab::class, 'userDetailsLoaded'))->setValue($resourceServer, true);
    }

    private function setAccessToken(OAuth2LoginService $service): void
    {
        $token = new AccessToken(['access_token' => 'token']);
        (new \ReflectionProperty(OAuth2LoginService::class, 'accessToken'))->setValue($service, $token);
    }

    private function gitlabUser(int $id, string $username, string $email): GitlabResourceOwner
    {
        return new GitlabResourceOwner(
            ['id' => $id, 'username' => $username, 'email' => $email, 'name' => 'User ' . $id, 'external' => false],
            new AccessToken(['access_token' => 'token'])
        );
    }

    private function eventDispatcher(): EventDispatcherInterface
    {
        return new class () implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
    }

    private function connection(): Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
    }

    private function createTables(): void
    {
        $connection = $this->connection();
        $connection->executeStatement(
            'CREATE TABLE be_users (
                uid INTEGER PRIMARY KEY AUTOINCREMENT,
                pid INTEGER NOT NULL DEFAULT 0,
                tstamp INTEGER NOT NULL DEFAULT 0,
                crdate INTEGER NOT NULL DEFAULT 0,
                deleted INTEGER NOT NULL DEFAULT 0,
                disable INTEGER NOT NULL DEFAULT 0,
                starttime INTEGER NOT NULL DEFAULT 0,
                endtime INTEGER NOT NULL DEFAULT 0,
                username VARCHAR(50) NOT NULL DEFAULT \'\',
                password VARCHAR(100) NOT NULL DEFAULT \'\',
                admin INTEGER NOT NULL DEFAULT 0,
                usergroup TEXT,
                email VARCHAR(255) NOT NULL DEFAULT \'\',
                realName VARCHAR(80) NOT NULL DEFAULT \'\',
                options INTEGER NOT NULL DEFAULT 0,
                oauth_identifier VARCHAR(255) NOT NULL DEFAULT \'\'
            )'
        );
        $connection->executeStatement(
            'CREATE TABLE be_groups (
                uid INTEGER PRIMARY KEY AUTOINCREMENT,
                pid INTEGER NOT NULL DEFAULT 0,
                deleted INTEGER NOT NULL DEFAULT 0,
                title VARCHAR(50) NOT NULL DEFAULT \'\',
                gitlabGroup VARCHAR(255) NOT NULL DEFAULT \'\'
            )'
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertUser(array $row): void
    {
        $this->insert('be_users', $row + ['oauth_identifier' => '', 'email' => '', 'admin' => 0]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertGroup(array $row): void
    {
        $this->insert('be_groups', $row);
    }

    /**
     * Plain SQL on purpose: Connection::insert() and friends consult TYPO3's
     * schema cache, which needs a booted cache manager. The code under test
     * goes through the QueryBuilder, which does not.
     *
     * @param array<string, mixed> $row
     */
    private function insert(string $table, array $row): void
    {
        $this->connection()->executeStatement(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $table,
                implode(', ', array_keys($row)),
                implode(', ', array_fill(0, count($row), '?'))
            ),
            array_values($row)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchUser(int $uid): array
    {
        $row = $this->connection()->executeQuery('SELECT * FROM be_users WHERE uid = ?', [$uid])->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function countUsers(): int
    {
        return (int)$this->connection()->executeQuery('SELECT COUNT(*) FROM be_users')->fetchOne();
    }

    /**
     * The few core services the database layer pulls through makeInstance()
     * and that cannot be built without a booted TYPO3. The schema factory
     * knows no tables, so no TCA-driven restriction applies; the code under
     * test passes its enable clause explicitly, as TYPO3 does at login.
     */
    private function provideCoreServices(): void
    {
        $this->originalContainer = (new \ReflectionProperty(GeneralUtility::class, 'container'))->getValue();

        $services = [
            DriverMiddlewareService::class => new DriverMiddlewareService(new DependencyOrderingService()),
            TcaSchemaFactory::class => new class () extends TcaSchemaFactory {
                public function __construct()
                {
                    $this->schemata = new SchemaCollection([]);
                }
            },
        ];

        GeneralUtility::setContainer(new class ($services) implements ContainerInterface {
            /**
             * @param array<string, object> $services
             */
            public function __construct(private readonly array $services) {}

            public function get(string $id): object
            {
                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        });
    }

    private function resetConnectionPool(): void
    {
        (new \ReflectionProperty(ConnectionPool::class, 'connections'))->setValue(null, []);
    }

    private function resetRegistry(): void
    {
        (new \ReflectionProperty(Registry::class, 'registry'))->setValue(null, []);
    }
}
