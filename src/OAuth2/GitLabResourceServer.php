<?php

declare(strict_types=1);

/*
 * This file is part of the package "typo3-config" by Moselwal Digitalagentur GmbH.
 */

namespace Moselwal\OAuth2;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use Mfc\OAuth2\ResourceServer\GitLab;
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
 */
final class GitLabResourceServer extends GitLab
{
    public const USERNAME_PREFIX = 'gitlab-';

    /**
     * GitLab user id => local username that id may be linked to.
     *
     * @var array<int, string>
     */
    private readonly array $linkedAccounts;

    private bool $resolvedByIdOnly = false;

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(array $arguments)
    {
        parent::__construct($arguments);

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
