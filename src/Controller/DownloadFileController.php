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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Streams a file as an attachment.
 *
 * This is a normal browser navigation rather than a fetch, which the stateful Studio firewall
 * authenticates through the session it already holds. Streaming matters here: it is the one
 * way to get at a file the editor refuses to open - a multi-gigabyte log or a binary - without
 * pulling it through the browser's memory.
 */
final class DownloadFileController extends AbstractFileViewerController
{
    #[Route(
        path: self::ROUTE_PREFIX . '/file/download',
        name: 'scop_studio_file_viewer_file_download',
        methods: ['GET'],
    )]
    public function downloadFile(Request $request): Response
    {
        $this->accessGuard->denyUnlessAdmin($request);

        try {
            $absolutePath = $this->fileViewerService->resolveDownloadPath(
                (string) $request->query->get('path', ''),
            );
        } catch (FileViewerException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], $exception->getStatusCode());
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            basename($absolutePath),
            // Fallback name for clients that cannot handle the UTF-8 form.
            preg_replace('/[^A-Za-z0-9._-]/', '_', basename($absolutePath)) ?? 'download',
        );

        // The path is jailed but still attacker-influenced; serving it as an octet stream
        // keeps the browser from rendering e.g. an HTML file from this origin.
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
