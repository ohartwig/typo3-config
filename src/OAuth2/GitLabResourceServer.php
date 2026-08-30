<?php

declare(strict_types=1);

/*
 * This file is part of the package "typo3-config" by Moselwal Digitalagentur GmbH.
 */

namespace Moselwal\OAuth2;

use Mfc\OAuth2\ResourceServer\GitLab;
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
 * The body below is upstream's, with one line changed. If mfc/oauth2 changes
 * how it assembles the URI, this must be revisited — the parent offers no
 * narrower seam than the whole method.
 */
final class GitLabResourceServer extends GitLab
{
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
