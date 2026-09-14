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

use Scop\StudioFileViewerBundle\Exception\InvalidPathException;
use Scop\StudioFileViewerBundle\Exception\PathAccessDeniedException;
use Scop\StudioFileViewerBundle\Exception\PathNotFoundException;

/**
 * Translates client supplied, root-relative paths into absolute paths and guarantees
 * that they stay inside the configured root.
 *
 * Every path that reaches the filesystem goes through resolve(). The check is done on
 * the realpath(), so a symlink pointing out of the root is rejected as well - which is
 * why resolve() only ever accepts paths that already exist.
 */
final class PathResolver
{
    private readonly string $root;

    /**
     * @var list<string> normalised, root-relative paths that are hidden from the viewer
     */
    private readonly array $excludedPaths;

    /**
     * @param list<string> $excludedPaths
     */
    public function __construct(string $rootPath, array $excludedPaths = [])
    {
        $root = realpath($rootPath);

        if (false === $root) {
            throw new InvalidPathException('The configured file viewer root does not exist.');
        }

        $this->root = rtrim($root, \DIRECTORY_SEPARATOR);

        $normalised = [];
        foreach ($excludedPaths as $excludedPath) {
            $excluded = trim(str_replace('\\', '/', $excludedPath), '/');

            if ('' !== $excluded) {
                $normalised[] = $excluded;
            }
        }

        $this->excludedPaths = array_values(array_unique($normalised));
    }

    public function getRoot(): string
    {
        return $this->root;
    }

    /**
     * Resolves a root-relative path to an existing absolute path inside the root.
     *
     * @throws InvalidPathException     the path is syntactically unusable
     * @throws PathNotFoundException    nothing exists at that path
     * @throws PathAccessDeniedException the path escapes the root or is excluded
     */
    public function resolve(string $relativePath): string
    {
        $relative = $this->normaliseRelative($relativePath);

        if ($this->isExcluded($relative)) {
            throw new PathAccessDeniedException('This path is not available in the file viewer.');
        }

        $candidate = '' === $relative
            ? $this->root
            : $this->root . \DIRECTORY_SEPARATOR . str_replace('/', \DIRECTORY_SEPARATOR, $relative);

        $real = realpath($candidate);

        if (false === $real) {
            throw new PathNotFoundException(sprintf('"%s" does not exist.', $relative));
        }

        if (!$this->isInsideRoot($real)) {
            // Reached through a symlink or a traversal sequence that survived normalisation.
            throw new PathAccessDeniedException('This path is outside of the file viewer root.');
        }

        // A symlink may resolve to a location that is inside the root but excluded.
        if ($this->isExcluded($this->toRelative($real))) {
            throw new PathAccessDeniedException('This path is not available in the file viewer.');
        }

        return $real;
    }

    /**
     * Normalises a client supplied path to a root-relative path with "/" separators and
     * no "." or ".." segments. The traversal is resolved here rather than rejected so that
     * harmless input such as "src/../config" still works; resolve() re-checks the result
     * against the root afterwards.
     *
     * @throws InvalidPathException
     */
    public function normaliseRelative(string $relativePath): string
    {
        if (str_contains($relativePath, "\0")) {
            throw new InvalidPathException('The path contains invalid characters.');
        }

        $path = trim(str_replace('\\', '/', $relativePath));

        // An absolute path from the client is treated as root-relative rather than rejected,
        // because the UI addresses the root itself as "/".
        $path = ltrim($path, '/');

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                if ([] === $segments) {
                    throw new InvalidPathException('The path points outside of the file viewer root.');
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * Converts an absolute path inside the root back into a root-relative path.
     */
    public function toRelative(string $absolutePath): string
    {
        if ($absolutePath === $this->root) {
            return '';
        }

        $prefix = $this->root . \DIRECTORY_SEPARATOR;

        if (!str_starts_with($absolutePath, $prefix)) {
            return '';
        }

        return str_replace('\\', '/', substr($absolutePath, \strlen($prefix)));
    }

    public function isExcluded(string $relativePath): bool
    {
        if ('' === $relativePath) {
            return false;
        }

        foreach ($this->excludedPaths as $excluded) {
            if ($relativePath === $excluded || str_starts_with($relativePath, $excluded . '/')) {
                return true;
            }
        }

        return false;
    }

    private function isInsideRoot(string $absolutePath): bool
    {
        return $absolutePath === $this->root
            || str_starts_with($absolutePath, $this->root . \DIRECTORY_SEPARATOR);
    }
}
