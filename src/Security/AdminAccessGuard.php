<?php

declare(strict_types=1);

/*
 * Scop Studio File Viewer Bundle
 *
 * This source file is available under the MIT license.
 * Full copyright and license information is available in
 * LICENSE which is distributed with this source code.
 *
 * @copyright Copyright (c) scope01 GmbH (https://scope01.com)
 * @license   MIT
 */

namespace Scop\StudioFileViewerBundle\Security;

use Pimcore\Model\User;
use Pimcore\Security\User\User as SecurityUser;
use Pimcore\Tool\Authentication;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Reading and writing arbitrary files on the server is an administrator capability: it can
 * reach .env, config and anything else the PHP process can touch. The routes already sit
 * behind the Studio firewall, but that only establishes *a* logged in Pimcore user, so the
 * admin check is done here and does not depend on a project's access_control rules.
 */
final class AdminAccessGuard
{
    public function __construct(
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function denyUnlessAdmin(Request $request): User
    {
        $user = $this->resolveUser($request);

        if (!$user instanceof User || !$user->isAdmin()) {
            throw new AccessDeniedHttpException('The file viewer is available to Pimcore admin users only.');
        }

        return $user;
    }

    private function resolveUser(Request $request): ?User
    {
        $securityUser = $this->tokenStorage?->getToken()?->getUser();

        if ($securityUser instanceof SecurityUser) {
            return $securityUser->getUser();
        }

        return Authentication::authenticateSession($request);
    }
}
