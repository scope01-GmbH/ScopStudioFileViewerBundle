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

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ConfigController extends AbstractFileViewerController
{
    #[Route(
        path: self::ROUTE_PREFIX . '/config',
        name: 'scop_studio_file_viewer_config',
        methods: ['GET'],
    )]
    public function getConfig(Request $request): JsonResponse
    {
        return $this->respond($request, fn (): array => $this->fileViewerService->getConfig());
    }
}
