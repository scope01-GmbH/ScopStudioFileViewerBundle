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

final class RenameEntryController extends AbstractFileViewerController
{
    #[Route(
        path: self::ROUTE_PREFIX . '/entry',
        name: 'scop_studio_file_viewer_entry_rename',
        methods: ['PATCH'],
    )]
    public function renameEntry(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            $payload = json_decode($request->getContent(), true);

            if (!\is_array($payload) || !\is_string($payload['path'] ?? null) || !\is_string($payload['name'] ?? null)) {
                throw new InvalidPathException('The request body must contain a "path" and a "name" string.');
            }

            return $this->fileViewerService->renameEntry($payload['path'], $payload['name']);
        });
    }
}
