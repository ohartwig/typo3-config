<?php

declare(strict_types=1);

/*
 * This file is part of the package "typo3-config" by Moselwal Digitalagentur GmbH.
 */

namespace Moselwal\OAuth2;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Mfc\OAuth2\ResourceServer\AbstractResourceServer;
use Mfc\OAuth2\Services\OAuth2LoginService;
use Doctrine\DBAL\Query\Expression\CompositeExpression;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Backend auth service that links a GitLab identity by its GitLab user id only.
 *
 * mfc/oauth2 resolves a login by oauth_identifier and, when that finds nothing,
 * falls back to any enabled backend account whose username or e-mail equals
 * the GitLab user's, and links that account. Both values are chosen by the
 * GitLab user, so the fallback decides which existing account — with its
 * admin flag and groups — a project member is logged in as.
 *
 * This service replaces upstream's under upstream's own service key (see
 * Config::useGitLabBackendLogin(), via TYPO3_CONF_VARS/T3_SERVICES), so there
 * is exactly one OAuth auth service and no fallback runs after it. A login is
 * resolved as follows:
 *
 * 1. An enabled account carrying this GitLab identity's oauth_identifier
 *    ("gitlab|<GitLab user id>") is used.
 * 2. If the identity is linked, but only to a disabled or expired account, the
 *    login is refused. Disabling the account in TYPO3 is how an administrator
 *    locks a GitLab user out without touching GitLab; creating a fresh account
 *    instead would undo that.
 * 3. If the GitLab user id is listed in linkedAccounts, the enabled, not yet
 *    linked account of that username is linked. This is the explicit, operator
 *    configured migration path for locally provisioned accounts.
 * 4. Otherwise a new account named "gitlab-<GitLab user id>" is created.
 *
 * Usernames and e-mail addresses are never used to find an account.
 *
 * TYPO3 builds a new service object for every step of one authentication run
 * (getUser, then authUser), and this class is not a shared container service
 * the way upstream's is. What getUser() resolved is therefore handed to the
 * authUser() instance through the authentication object both steps share;
 * see rememberResolvedLogin().
 *
 * Subclassed rather than patched, like GitLabResourceServer, so that an
 * mfc/oauth2 upgrade cannot silently drop it. Only protected upstream methods
 * are overridden; the database access goes through upstream's own helpers.
 */
final class GitLabLoginService extends OAuth2LoginService
{
    private ?AbstractResourceServer $activeResourceServer = null;

    /**
     * The login this instance resolved: resource server, access token and the
     * oauth_identifier of the account it resolved to.
     *
     * @var array{AbstractResourceServer, AccessToken, string}|null
     */
    private ?array $resolvedLogin = null;

    /**
     * Logins resolved by getUser(), keyed by the authentication object of the
     * run (the $pObj every service of the run receives). Weak, so an entry
     * lives exactly as long as that request's authentication object — in a
     * long-running worker nothing carries over to the next request.
     *
     * @var \WeakMap<AbstractUserAuthentication, array{AbstractResourceServer, AccessToken, string}>|null
     */
    private static ?\WeakMap $resolvedLogins = null;

    /**
     * Upstream's constructor is autowired through mfc/oauth2's Services.yaml.
     * This class is not part of any DI container (typo3-config is a library,
     * not an extension), so TYPO3's makeInstanceService() instantiates it with
     * no arguments and the collaborators are resolved here.
     */
    public function __construct(
        ?ResponseFactoryInterface $responseFactory = null,
        ?ExtensionConfiguration $extensionConfiguration = null,
        ?ConnectionPool $connectionPool = null,
        ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        parent::__construct(
            $responseFactory ?? GeneralUtility::makeInstance(ResponseFactoryInterface::class),
            $extensionConfiguration ?? GeneralUtility::makeInstance(ExtensionConfiguration::class),
            $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class),
            $eventDispatcher ?? GeneralUtility::makeInstance(EventDispatcherInterface::class),
        );
    }

    protected function initializeResourceServer(ServerRequestInterface $request): ?AbstractResourceServer
    {
        $this->activeResourceServer = parent::initializeResourceServer($request);

        if ($this->activeResourceServer instanceof GitLabResourceServer) {
            $this->activeResourceServer->confirmResolutionByIdOnly();
        }

        return $this->activeResourceServer;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findOrCreateUserByResourceOwner(ResourceOwnerInterface $user): ?array
    {
        $resourceServer = $this->activeResourceServer;
        if (!$resourceServer instanceof AbstractResourceServer) {
            return null;
        }

        $oauthIdentifier = $resourceServer->getOAuthIdentifier($user);

        $record = $this->findUserByOauthIdentifier($user);
        if ($record === null || $record === []) {
            if ($this->identifierIsLinkedToAnyAccount($oauthIdentifier)) {
                return null;
            }
            $record = $this->findExplicitlyLinkedAccount($resourceServer, $user);
        }

        if ($record !== null && $record !== []) {
            $this->updateFoundUser($user, $record);
        } else {
            $this->createUser($user);
        }

        // Read back what was written, by identity: upstream returns the record
        // as it was before the update (a demotion would only reach the next
        // request), and re-reads a created account by username.
        $record = $this->findUserByOauthIdentifier($user);
        if ($record === null || $record === []) {
            return null;
        }

        $this->rememberResolvedLogin($resourceServer, (string)($record['oauth_identifier'] ?? ''));

        return $record;
    }

    /**
     * Authenticates only the account this login resolved to.
     *
     * TYPO3 calls authUser() on a fresh service object, so upstream's check
     * (which needs the resource server and access token set by getUser())
     * would always answer 100 and the login would fall through to the password
     * service and fail. The fresh object takes over the login its run resolved
     * — and only for the account carrying that identity: a token obtained for
     * one GitLab user never authenticates another record.
     *
     * @param array<mixed> $userRecord
     */
    public function authUser(array $userRecord): int
    {
        $login = $this->resolvedLogin ?? $this->resolvedLoginOfThisRun();
        if ($login === null) {
            return 100;
        }

        [$resourceServer, $accessToken, $oauthIdentifier] = $login;
        if ($oauthIdentifier === '' || ($userRecord['oauth_identifier'] ?? '') !== $oauthIdentifier) {
            return 100;
        }

        $this->activeResourceServer = $resourceServer;
        $this->setUpstreamState($resourceServer, $accessToken);

        return parent::authUser($userRecord);
    }

    private function rememberResolvedLogin(AbstractResourceServer $resourceServer, string $oauthIdentifier): void
    {
        $accessToken = \Closure::bind(fn(): ?AccessToken => $this->accessToken, $this, OAuth2LoginService::class)();
        if (!$accessToken instanceof AccessToken || $oauthIdentifier === '') {
            return;
        }

        $this->resolvedLogin = [$resourceServer, $accessToken, $oauthIdentifier];
        self::$resolvedLogins ??= new \WeakMap();
        self::$resolvedLogins[$this->pObj] = $this->resolvedLogin;
    }

    /**
     * @return array{AbstractResourceServer, AccessToken, string}|null
     */
    private function resolvedLoginOfThisRun(): ?array
    {
        if (self::$resolvedLogins === null) {
            return null;
        }

        return self::$resolvedLogins[$this->pObj] ?? null;
    }

    private function setUpstreamState(AbstractResourceServer $resourceServer, AccessToken $accessToken): void
    {
        \Closure::bind(function () use ($resourceServer, $accessToken): void {
            $this->resourceServer = $resourceServer;
            $this->accessToken = $accessToken;
        }, $this, OAuth2LoginService::class)();
    }

    /**
     * Never link by username or e-mail. Not called by this class; overridden
     * so that no upstream path that might call it can match a foreign account.
     *
     * @return array<string, mixed>
     */
    protected function findUserByUsernameOrEmail(ResourceOwnerInterface $user): array
    {
        return [];
    }

    /**
     * Whether any account that is not deleted carries this identity, enabled or
     * not. Called only after the enabled lookup came back empty.
     */
    private function identifierIsLinkedToAnyAccount(string $oauthIdentifier): bool
    {
        $table = $this->authInfo['db_user']['table'];
        $queryBuilder = $this->getQueryBuilderForTable($table);

        $count = $queryBuilder
            ->count('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('oauth_identifier', $queryBuilder->createNamedParameter($oauthIdentifier)),
                ...$this->accountScope(false)
            )
            ->executeQuery()
            ->fetchOne();

        return (int)$count > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findExplicitlyLinkedAccount(AbstractResourceServer $resourceServer, ResourceOwnerInterface $user): ?array
    {
        if (!$resourceServer instanceof GitLabResourceServer) {
            return null;
        }

        $username = $resourceServer->getLinkedAccountUsername($user);
        if ($username === null) {
            return null;
        }

        $table = $this->authInfo['db_user']['table'];
        $queryBuilder = $this->getQueryBuilderForTable($table);

        $record = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username)),
                // An account already linked to another identity stays with it.
                $queryBuilder->expr()->eq('oauth_identifier', $queryBuilder->createNamedParameter('')),
                ...$this->accountScope(true)
            )
            ->executeQuery()
            ->fetchAssociative();

        return $record === false ? null : $record;
    }

    /**
     * The storage-folder clause TYPO3 passes for backend users, and with
     * $enabledOnly also its enable clause (disabled, start and end time), as
     * upstream's own lookups apply them.
     *
     * @return list<string|CompositeExpression>
     */
    private function accountScope(bool $enabledOnly): array
    {
        $scope = [];

        $pidClause = QueryHelper::stripLogicalOperatorPrefix((string)($this->authInfo['db_user']['check_pid_clause'] ?? ''));
        if ($pidClause !== '') {
            $scope[] = $pidClause;
        }

        if ($enabledOnly) {
            $enableClause = $this->authInfo['db_user']['enable_clause'] ?? '';
            if ($enableClause instanceof CompositeExpression) {
                $scope[] = $enableClause;
            } elseif (is_string($enableClause) && $enableClause !== '') {
                $scope[] = $enableClause;
            }
        }

        return $scope;
    }
}
