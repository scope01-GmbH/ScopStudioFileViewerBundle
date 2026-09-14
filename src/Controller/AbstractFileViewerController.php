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

namespace Scop\StudioFileViewerBundle\Controller;

use Scop\StudioFileViewerBundle\Exception\FileViewerException;
use Scop\StudioFileViewerBundle\Security\AdminAccessGuard;
use Scop\StudioFileViewerBundle\Service\FileViewerService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractFileViewerController
{
    /**
     * All routes live under the Studio API prefix so they are covered by the `pimcore_studio`
     * firewall every Studio installation configures, and so the Studio SPA can call them with
     * its own credentials.
     *
     * The container parameter (rather than the literal default `/pimcore-studio/api`) keeps the
     * routes aligned with installations that override `pimcore_studio_backend.url_prefix`: the
     * Studio base query rewrites the literal prefix in a request URL to the configured one, so
     * a hard-coded path here would miss it. Symfony resolves the placeholder when it compiles
     * the route collection.
     */
    public const ROUTE_PREFIX = '%pimcore_studio_backend.url_prefix%/scop-file-viewer';

    public function __construct(
        protected readonly FileViewerService $fileViewerService,
        protected readonly AdminAccessGuard $accessGuard,
    ) {
    }

    /**
     * @param callable(): array<string, mixed> $handler
     */
    protected function respond(Request $request, callable $handler): JsonResponse
    {
        $this->accessGuard->denyUnlessAdmin($request);

        try {
            return new JsonResponse($handler());
        } catch (FileViewerException $exception) {
            return new JsonResponse(
                ['error' => $exception->getMessage()],
                $exception->getStatusCode(),
            );
        }
    }
}
