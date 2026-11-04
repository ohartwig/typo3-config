<?php

declare(strict_types=1);

/*
 * This file is part of the package "typo3-config" by Moselwal Digitalagentur GmbH.
 */

namespace Moselwal\OAuth2;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use Mfc\OAuth2\ResourceServer\GitLab;
use Omines\OAuth2\Client\Provider\Gitlab as GitLabOAuthProvider;
use Omines\OAuth2\Client\Provider\GitlabResourceOwner;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashInterface;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * GitLab resource server with a redirect URI that exists.
 *
 * mfc/oauth2 builds its OAuth callback from the literal path
 * `/typo3/index.php`. That entry script is gone: TYPO3 v14 ships no
 * `public/typo3/` directory at all, and an installation that moves the backend
 * onto its own host via `BE/entryPoint` serves the login at `/login` instead.
 * Either way the path is a 404 — and it fails at the worst possible moment,
 * on the way back from GitLab, after the user has already authorized. Nothing
 * before that point complains: the login provider registers, the button
 * renders, the redirect to GitLab is correct.
 *
 * The request being handled here IS the login request, both when sending the
 * user out and when receiving them back, so its own path is the one to return
 * to. It is identical in both directions, which is what OAuth requires of a
 * redirect_uri.
 *
 * Subclassed rather than patched so that upgrading mfc/oauth2 cannot silently
 * drop the fix, and so the four installations need no patch infrastructure of
 * their own. Reported upstream; drop this class once a release carries the fix.
 *
 * The body of getRedirectUri() is upstream's, with one line changed. If
 * mfc/oauth2 changes how it assembles the URI, this must be revisited — the
 * parent offers no narrower seam than the whole method.
 *
 * It also carries the half of the identity rules that lives on the resource
 * server (the other half is GitLabLoginService):
 *
 * - Accounts the login creates are named "gitlab-<GitLab user id>". The id is
 *   immutable and assigned by GitLab, so the name can neither collide with a
 *   locally provisioned account nor be steered by the GitLab user. The human
 *   name goes into realName.
 * - An account that already exists keeps its username, also when overrideUser
 *   re-applies everything else from GitLab.
 * - linkedAccounts maps a local username to the GitLab user id that may take it
 *   over. This is the only way a GitLab login reaches an account it did not
 *   create.
 * - The login is refused unless GitLabLoginService handled it, so losing the
 *   service override (an upstream rename of the service key, say) fails closed
 *   instead of falling back to upstream's lookup by username or e-mail.
 *
 * Where the access level comes from, and with it which token TYPO3 holds:
 *
 * - With a projectName only (upstream's model), the level is the user's access
 *   level on that project, read through the GitLab API with the user's token.
 *   That needs the read_api scope, so every login hands this process a token
 *   that reads the whole API as the user — every repository and, for projects
 *   the user maintains, their CI/CD variables.
 * - With an accessGroup, the level is read from GitLab's OpenID Connect
 *   userinfo (the groups and https://gitlab.org/claims/groups/<role> claims)
 *   and the login asks for "read_user openid" only. The token can read the
 *   user's own profile and nothing else. See accessLevelFromClaims() for the
 *   mapping.
 */
final class GitLabResourceServer extends GitLab
{
    public const USERNAME_PREFIX = 'gitlab-';

    /**
     * Scopes requested when the access level comes from an access group.
     * read_user for the profile upstream reads from /api/v4/user (id, username,
     * the external flag), openid for /oauth/userinfo. No read_api.
     */
    public const ACCESS_GROUP_SCOPES = ['read_user', 'openid'];

    /**
     * Role claims GitLab puts into userinfo, each a list of the full paths of
     * the groups the user is a DIRECT member of with that role.
     */
    private const ROLE_CLAIMS = [
        'https://gitlab.org/claims/groups/owner' => 50,
        'https://gitlab.org/claims/groups/maintainer' => self::USER_LEVEL_MAINTAINER,
        'https://gitlab.org/claims/groups/developer' => self::USER_LEVEL_DEVELOPER,
    ];

    /**
     * GitLab user id => local username that id may be linked to.
     *
     * @var array<int, string>
     */
    private readonly array $linkedAccounts;

    private bool $resolvedByIdOnly = false;

    /**
     * Full path of the GitLab group whose membership governs access, or '' for
     * upstream's project model.
     */
    private readonly string $accessGroup;

    private bool $accessLevelLoaded = false;

    /**
     * @var array{providerName: string, clientId: string, clientSecret: string, domain: string, blockExternalUser: bool}
     */
    private readonly array $settings;

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(array $arguments)
    {
        parent::__construct($arguments);

        $this->accessGroup = trim((string)($arguments['accessGroup'] ?? ''), " \t\n\r\0\x0B/");
        // Upstream keeps these private; the access-group path needs them again.
        $this->settings = [
            'providerName' => (string)($arguments['providerName'] ?? ''),
            'clientId' => (string)($arguments['appId'] ?? ''),
            'clientSecret' => (string)($arguments['appSecret'] ?? ''),
            'domain' => rtrim((string)($arguments['gitlabServer'] ?? ''), '/'),
            'blockExternalUser' => (bool)($arguments['blockExternalUser'] ?? true),
        ];

        $linkedAccounts = [];
        foreach ((array)($arguments['linkedAccounts'] ?? []) as $username => $gitlabUserId) {
            $username = trim((string)$username);
            $gitlabUserId = (int)$gitlabUserId;
            if ($username !== '' && $gitlabUserId > 0) {
                $linkedAccounts[$gitlabUserId] = $username;
            }
        }
        $this->linkedAccounts = $linkedAccounts;
    }

    /**
     * Called by GitLabLoginService when it takes charge of a login request.
     *
     * @internal
     */
    public function confirmResolutionByIdOnly(): void
    {
        $this->resolvedByIdOnly = true;
    }

    /**
     * Local username that this GitLab user was explicitly linked to, if any.
     */
    public function getLinkedAccountUsername(ResourceOwnerInterface $user): ?string
    {
        return $this->linkedAccounts[(int)$user->getId()] ?? null;
    }

    /**
     * Fails closed when the login was not resolved by GitLabLoginService.
     */
    public function userIsActive(ResourceOwnerInterface $user): bool
    {
        if (!$this->resolvedByIdOnly) {
            return false;
        }

        return parent::userIsActive($user);
    }

    /**
     * Username for accounts the login creates: derived from the immutable
     * GitLab user id, never from the user-chosen GitLab username.
     */
    public function getUsernameFromUser(ResourceOwnerInterface $user): string
    {
        return self::USERNAME_PREFIX . (string)$user->getId();
    }

    /**
     * Upstream also rewrites the username on every update. An existing account
     * keeps the name it has: renaming a pre-linked or legacy account would
     * break password login and anything that refers to it by name.
     *
     * @param array<mixed>|null $currentRecord
     * @param array<mixed> $authenticationInformation
     * @return array<string, mixed>
     */
    public function updateUserRecord(
        ResourceOwnerInterface $user,
        ?array $currentRecord = null,
        array $authenticationInformation = [],
        ?PasswordHashInterface $saltingInstance = null
    ): array {
        $record = parent::updateUserRecord($user, $currentRecord, $authenticationInformation, $saltingInstance);

        if (isset($currentRecord['uid']) && (string)($currentRecord['username'] ?? '') !== '') {
            $record['username'] = $currentRecord['username'];
        }

        return $record;
    }
    /**
     * Upstream's method with the scope list made configurable: upstream
     * hard-codes read_api.
     *
     * @return array{0: string, 1: string, 2: Cookie}
     */
    public function getAuthorizationUrl(): array
    {
        if ($this->accessGroup === '') {
            return parent::getAuthorizationUrl();
        }

        [$redirectUri, $nonceCookie] = $this->getRedirectUri($this->settings['providerName'], true);
        if (!$nonceCookie instanceof Cookie) {
            throw new \RuntimeException('The OAuth redirect carries no request-token nonce cookie', 1762280002);
        }

        $provider = new GitLabOAuthProvider([
            'clientId' => $this->settings['clientId'],
            'clientSecret' => $this->settings['clientSecret'],
            'redirectUri' => $redirectUri,
            'domain' => $this->settings['domain'],
        ]);

        return [
            $provider->getAuthorizationUrl(['scope' => self::ACCESS_GROUP_SCOPES]),
            $provider->getState(),
            $nonceCookie,
        ];
    }

    /**
     * In the access-group model, read the access level from GitLab's OpenID
     * Connect userinfo instead of the project API. Anything that goes wrong —
     * GitLab unreachable, the token lacking openid, a userinfo answer for
     * another user — yields level 0, which refuses the login.
     */
    public function loadUserDetails(ResourceOwnerInterface $user): void
    {
        if ($this->accessGroup === '') {
            parent::loadUserDetails($user);
            return;
        }

        if ($this->accessLevelLoaded) {
            return;
        }

        if (!$user instanceof GitlabResourceOwner) {
            throw new \InvalidArgumentException(
                'Resource owner "' . $user->getId() . '" is no suitable GitLab resource owner',
                1762280001
            );
        }

        $accessLevel = 0;
        if (!($this->settings['blockExternalUser'] && $user->isExternal())) {
            $claims = $this->fetchUserInfo($user);
            if ((string)($claims['sub'] ?? '') === (string)$user->getId()) {
                $accessLevel = self::accessLevelFromClaims($claims, $this->accessGroup);
            }
        }

        $this->storeAccessLevel($accessLevel);
        $this->accessLevelLoaded = true;
    }

    /**
     * GitLab access level a userinfo answer grants on $accessGroup.
     *
     * - Owner, Maintainer or Developer of the group or of any ancestor group
     *   (GitLab hands these roles down to subgroups) → 50, 40 or 30, the
     *   highest that applies.
     * - Otherwise any membership of the group or an ancestor (the groups claim)
     *   → 10. userinfo does not tell Guest from Reporter, so a be_groups
     *   mapping for level 20 never applies in this model.
     * - Otherwise 0.
     *
     * Paths compare case-insensitively, as GitLab routes do. Membership of a
     * project, or of a group the access group is shared with, does not count:
     * userinfo does not carry it.
     *
     * @param array<string, mixed> $claims
     * @internal
     */
    public static function accessLevelFromClaims(array $claims, string $accessGroup): int
    {
        $accessGroup = strtolower(trim($accessGroup, '/'));
        if ($accessGroup === '') {
            return 0;
        }

        $covers = static function (mixed $paths) use ($accessGroup): bool {
            foreach (is_array($paths) ? $paths : [] as $path) {
                if (!is_string($path)) {
                    continue;
                }
                $path = strtolower(trim($path, '/'));
                if ($path !== '' && ($path === $accessGroup || str_starts_with($accessGroup, $path . '/'))) {
                    return true;
                }
            }
            return false;
        };

        $level = 0;
        foreach (self::ROLE_CLAIMS as $claim => $roleLevel) {
            if ($roleLevel > $level && $covers($claims[$claim] ?? null)) {
                $level = $roleLevel;
            }
        }

        if ($level === 0 && $covers($claims['groups'] ?? null)) {
            $level = self::USER_LEVEL_GUEST;
        }

        return $level;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchUserInfo(GitlabResourceOwner $user): array
    {
        try {
            $provider = $this->getOAuthProvider();
            $request = $provider->getAuthenticatedRequest(
                'GET',
                $this->settings['domain'] . '/oauth/userinfo',
                $user->getToken()
            );
            $claims = $provider->getParsedResponse($request);
        } catch (\Throwable) {
            return [];
        }

        return is_array($claims) ? $claims : [];
    }

    /**
     * Upstream keeps the loaded level in private state that userIsActive(),
     * userShouldBeAdmin() and updateUserRecord() read. Written in upstream's
     * scope so those keep working unchanged; should upstream rename the
     * property, they read no level and refuse the login.
     */
    private function storeAccessLevel(int $accessLevel): void
    {
        \Closure::bind(function (int $accessLevel): void {
            $this->gitlabProjectPermissions = ['access_level' => $accessLevel];
            $this->userDetailsLoaded = true;
        }, $this, GitLab::class)($accessLevel);
    }

    /**
     * The parameter stays untyped because upstream declares it untyped;
     * narrowing it to string here would break the override.
     *
     * @param mixed $requestToken
     * @return array{0: string, 1: ?\Symfony\Component\HttpFoundation\Cookie}
     */
    protected function getRedirectUri(
        string $resourceServerIdentifier,
        bool $withRequestToken = false,
        $requestToken = ''
    ): array {
        $cookie = null;
        $requestTokenParameter = '';

        if ($withRequestToken) {
            [$requestTokenParameter, $cookie] = $this->getRequestTokenParameter();
        } elseif ($requestToken !== '') {
            $requestTokenParameter = '&' . RequestToken::PARAM_NAME . '=' . $requestToken;
        }

        $redirectUri = GeneralUtility::locationHeaderUrl(
            $this->getLoginPath()
            . '?loginProvider=1529672977'
            . '&login_status=login'
            . '&resource-server-identifier=' . $resourceServerIdentifier
            . $requestTokenParameter
        );

        return [$redirectUri, $cookie];
    }

    /**
     * Path of the backend login route in this installation.
     *
     * Falls back to TYPO3 v13+'s default backend login route when there is no
     * usable request path, which happens only outside a real login request.
     */
    protected function getLoginPath(): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $path = $request instanceof \Psr\Http\Message\ServerRequestInterface
            ? $request->getUri()->getPath()
            : '';

        return $path !== '' && $path !== '/' ? $path : '/typo3/login';
    }
}
