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
use Scop\StudioFileViewerBundle\Exception\AlreadyExistsException;
use Scop\StudioFileViewerBundle\Exception\FileViewerException;
use Scop\StudioFileViewerBundle\Exception\InvalidPathException;
use Scop\StudioFileViewerBundle\Exception\NotWritableException;
use Scop\StudioFileViewerBundle\Exception\PathAccessDeniedException;
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
     * Creates an empty file inside an existing directory.
     *
     * @return array<string, mixed> the new entry, as listDirectory() would describe it
     */
    public function createFile(string $parentPath, string $name): array
    {
        return $this->createEntry($parentPath, $name, directory: false);
    }

    /**
     * Creates a directory inside an existing directory.
     *
     * @return array<string, mixed>
     */
    public function createDirectory(string $parentPath, string $name): array
    {
        return $this->createEntry($parentPath, $name, directory: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function createEntry(string $parentPath, string $name, bool $directory): array
    {
        if (!$this->writable) {
            throw new NotWritableException('The file viewer is configured read-only.');
        }

        $name = $this->assertValidName($name);

        // resolve() only accepts existing paths, so the parent is validated here and the new
        // entry is appended to the result - which keeps the jail check on the parent.
        $parent = $this->pathResolver->resolve($parentPath);
        $parentRelative = $this->pathResolver->toRelative($parent);

        if (!is_dir($parent)) {
            throw new InvalidPathException(sprintf('"%s" is not a directory.', $parentRelative));
        }

        if (!is_writable($parent)) {
            throw new NotWritableException(sprintf('"%s" is not writable.', $parentRelative));
        }

        $relative = '' === $parentRelative ? $name : $parentRelative . '/' . $name;

        // A new entry inside an excluded directory would be invisible the moment it exists.
        if ($this->pathResolver->isExcluded($relative)) {
            throw new PathAccessDeniedException('This path is not available in the file viewer.');
        }

        $absolute = $parent . \DIRECTORY_SEPARATOR . $name;

        // file_exists() follows symlinks, so is_link() is checked too: a dangling symlink
        // occupies the name without file_exists() reporting it.
        if (file_exists($absolute) || is_link($absolute)) {
            throw new AlreadyExistsException(sprintf('"%s" already exists.', $relative));
        }

        $created = $directory
            ? @mkdir($absolute, 0775)
            : @touch($absolute);

        if (false === $created) {
            throw new NotWritableException(sprintf('"%s" could not be created.', $relative));
        }

        clearstatcache(true, $absolute);

        return $this->describe(new SplFileInfo($absolute), $relative);
    }

    /**
     * The name is a single path segment, never a path: allowing a separator here would let a
     * caller create entries anywhere under the root regardless of the parent it passed.
     *
     * @throws InvalidPathException
     */
    private function assertValidName(string $name): string
    {
        $trimmed = trim($name);

        if ('' === $trimmed) {
            throw new InvalidPathException('The name must not be empty.');
        }

        if (\strlen($trimmed) > 255) {
            throw new InvalidPathException('The name is too long.');
        }

        if ('.' === $trimmed || '..' === $trimmed) {
            throw new InvalidPathException('The name must not be "." or "..".');
        }

        if (str_contains($trimmed, '/') || str_contains($trimmed, '\\')) {
            throw new InvalidPathException('The name must not contain a path separator.');
        }

        // Covers the NUL byte along with every other control character.
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $trimmed)) {
            throw new InvalidPathException('The name contains invalid characters.');
        }

        return $trimmed;
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
