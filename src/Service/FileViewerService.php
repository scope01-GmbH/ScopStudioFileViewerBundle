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

namespace Scop\StudioFileViewerBundle\Service;

use FilesystemIterator;
use Scop\StudioFileViewerBundle\Exception\FileViewerException;
use Scop\StudioFileViewerBundle\Exception\InvalidPathException;
use Scop\StudioFileViewerBundle\Exception\NotWritableException;
use Scop\StudioFileViewerBundle\Exception\PathNotFoundException;
use Scop\StudioFileViewerBundle\Exception\PayloadTooLargeException;
use SplFileInfo;

final class FileViewerService
{
    /**
     * A file is treated as binary when a NUL byte turns up in its first bytes. That is the
     * same heuristic diff/grep use and it keeps the editor from being handed a PNG.
     */
    private const BINARY_SNIFF_LENGTH = 8192;

    public const STATUS_OK = 'ok';

    public const STATUS_TOO_LARGE = 'too_large';

    public const STATUS_BINARY = 'binary';

    public function __construct(
        private readonly PathResolver $pathResolver,
        private readonly bool $writable,
        private readonly int $maxEditableSize,
        private readonly int $tailBytes,
    ) {
    }

    /**
     * @return array{path: string, entries: list<array<string, mixed>>}
     */
    public function listDirectory(string $relativePath): array
    {
        $absolute = $this->pathResolver->resolve($relativePath);

        if (!is_dir($absolute)) {
            throw new InvalidPathException(sprintf('"%s" is not a directory.', $this->pathResolver->toRelative($absolute)));
        }

        $entries = [];

        $iterator = new FilesystemIterator(
            $absolute,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
        );

        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            $relative = $this->pathResolver->toRelative($fileInfo->getPathname());

            if ('' === $relative || $this->pathResolver->isExcluded($relative)) {
                continue;
            }

            $entries[] = $this->describe($fileInfo, $relative);
        }

        usort($entries, static function (array $a, array $b): int {
            if ($a['isDirectory'] !== $b['isDirectory']) {
                return $a['isDirectory'] ? -1 : 1;
            }

            return strnatcasecmp($a['name'], $b['name']);
        });

        return [
            'path' => $this->pathResolver->toRelative($absolute),
            'entries' => $entries,
        ];
    }

    /**
     * Reads a file for the editor.
     *
     * Oversized and binary files are not an error: the response carries the metadata and a
     * status, so the UI can explain why it is not showing the content instead of loading a
     * 900 MB log into the browser. $tail requests the last tail_bytes of such a file.
     *
     * @return array<string, mixed>
     */
    public function readFile(string $relativePath, bool $tail = false): array
    {
        $absolute = $this->pathResolver->resolve($relativePath);

        if (!is_file($absolute)) {
            throw new InvalidPathException(sprintf('"%s" is not a file.', $this->pathResolver->toRelative($absolute)));
        }

        if (!is_readable($absolute)) {
            throw new PathNotFoundException(sprintf('"%s" cannot be read.', $this->pathResolver->toRelative($absolute)));
        }

        clearstatcache(true, $absolute);

        $relative = $this->pathResolver->toRelative($absolute);
        $size = (int) filesize($absolute);
        $meta = $this->describe(new SplFileInfo($absolute), $relative);

        $meta['content'] = null;
        $meta['truncated'] = false;
        $meta['editable'] = false;
        $meta['maxEditableSize'] = $this->maxEditableSize;
        $meta['tailBytes'] = $this->tailBytes;

        if ($this->isBinary($absolute)) {
            $meta['status'] = self::STATUS_BINARY;

            return $meta;
        }

        if ($size > $this->maxEditableSize) {
            $meta['status'] = self::STATUS_TOO_LARGE;

            if ($tail) {
                $meta['content'] = $this->readTail($absolute, $size);
                $meta['truncated'] = true;
            }

            return $meta;
        }

        $content = file_get_contents($absolute);

        if (false === $content) {
            throw new PathNotFoundException(sprintf('"%s" cannot be read.', $relative));
        }

        $meta['status'] = self::STATUS_OK;
        $meta['content'] = $content;
        $meta['editable'] = $this->writable && $meta['writable'];

        return $meta;
    }

    /**
     * Overwrites an existing file. Creating, renaming and deleting is deliberately out of
     * scope - the viewer edits what is there.
     *
     * @return array<string, mixed>
     */
    public function writeFile(string $relativePath, string $content): array
    {
        if (!$this->writable) {
            throw new NotWritableException('The file viewer is configured read-only.');
        }

        $absolute = $this->pathResolver->resolve($relativePath);
        $relative = $this->pathResolver->toRelative($absolute);

        if (!is_file($absolute)) {
            throw new InvalidPathException(sprintf('"%s" is not a file.', $relative));
        }

        if (!is_writable($absolute)) {
            throw new NotWritableException(sprintf('"%s" is not writable.', $relative));
        }

        if (\strlen($content) > $this->maxEditableSize) {
            throw new PayloadTooLargeException(sprintf(
                'The content exceeds the configured maximum of %d bytes.',
                $this->maxEditableSize,
            ));
        }

        if ($this->isBinary($absolute)) {
            throw new InvalidPathException(sprintf('"%s" is a binary file and cannot be edited.', $relative));
        }

        $this->writeAtomically($absolute, $content);

        clearstatcache(true, $absolute);

        return $this->readFile($relative);
    }

    /**
     * Resolves a path for download. Unlike readFile() this allows any size and any content
     * type - streaming a binary or a huge log is exactly what download is for - but it stays
     * inside the same path jail and still refuses directories.
     *
     * @throws FileViewerException
     */
    public function resolveDownloadPath(string $relativePath): string
    {
        $absolute = $this->pathResolver->resolve($relativePath);

        if (!is_file($absolute)) {
            throw new InvalidPathException(sprintf('"%s" is not a file.', $this->pathResolver->toRelative($absolute)));
        }

        if (!is_readable($absolute)) {
            throw new PathNotFoundException(sprintf('"%s" cannot be read.', $this->pathResolver->toRelative($absolute)));
        }

        return $absolute;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'root' => $this->pathResolver->getRoot(),
            'writable' => $this->writable,
            'maxEditableSize' => $this->maxEditableSize,
            'tailBytes' => $this->tailBytes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(SplFileInfo $fileInfo, string $relative): array
    {
        $isDirectory = $fileInfo->isDir();

        return [
            'name' => $fileInfo->getFilename(),
            'path' => $relative,
            'isDirectory' => $isDirectory,
            'size' => $isDirectory ? null : $fileInfo->getSize(),
            'modified' => $fileInfo->getMTime(),
            'readable' => $fileInfo->isReadable(),
            'writable' => $this->writable && $fileInfo->isWritable(),
        ];
    }

    private function isBinary(string $absolutePath): bool
    {
        $handle = fopen($absolutePath, 'rb');

        if (false === $handle) {
            return false;
        }

        $sample = fread($handle, self::BINARY_SNIFF_LENGTH);
        fclose($handle);

        if (false === $sample || '' === $sample) {
            return false;
        }

        return str_contains($sample, "\0");
    }

    /**
     * Reads the last tail_bytes of a file and drops the first (very likely partial) line, so
     * the preview always starts at a line boundary.
     */
    private function readTail(string $absolutePath, int $size): string
    {
        $handle = fopen($absolutePath, 'rb');

        if (false === $handle) {
            throw new PathNotFoundException('The file cannot be read.');
        }

        $offset = max(0, $size - $this->tailBytes);

        if ($offset > 0) {
            fseek($handle, $offset);
        }

        $content = stream_get_contents($handle);
        fclose($handle);

        if (false === $content) {
            return '';
        }

        if ($offset > 0) {
            $newline = strpos($content, "\n");
            $content = false === $newline ? $content : substr($content, $newline + 1);
        }

        return $content;
    }

    /**
     * Writes through a temporary file in the same directory so that a failed or partial write
     * cannot leave a half-written config or source file behind. The temp file inherits the
     * original's permissions, which rename() would otherwise replace with the default mask.
     */
    private function writeAtomically(string $absolutePath, string $content): void
    {
        $directory = \dirname($absolutePath);
        $temporary = tempnam($directory, '.scop-file-viewer-');

        if (false === $temporary) {
            throw new NotWritableException('A temporary file could not be created next to the target file.');
        }

        try {
            if (false === file_put_contents($temporary, $content)) {
                throw new NotWritableException('The file could not be written.');
            }

            $permissions = fileperms($absolutePath);

            if (false !== $permissions) {
                @chmod($temporary, $permissions & 0777);
            }

            if (!@rename($temporary, $absolutePath)) {
                throw new NotWritableException('The file could not be replaced.');
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
