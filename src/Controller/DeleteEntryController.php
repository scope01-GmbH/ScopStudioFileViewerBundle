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

use Scop\StudioFileViewerBundle\Exception\InvalidPathException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class DeleteEntryController extends AbstractFileViewerController
{
    #[Route(
        path: self::ROUTE_PREFIX . '/entry',
        name: 'scop_studio_file_viewer_entry_delete',
        methods: ['DELETE'],
    )]
    public function deleteEntry(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            // A query parameter rather than a body: a DELETE body is legal but awkward for
            // proxies and for the Studio base query, and the path is the whole request.
            $path = $request->query->get('path');

            if (!\is_string($path)) {
                throw new InvalidPathException('A "path" query parameter is required.');
            }

            return $this->fileViewerService->deleteEntry($path);
        });
    }
}
