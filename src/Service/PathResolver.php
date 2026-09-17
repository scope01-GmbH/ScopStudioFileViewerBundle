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
 * Every path that reaches the filesystem goes through resolve(), which only ever accepts
 * paths that already exist. The jail is logical rather than physical: a path may not
 * *spell* its way out of the root, but it may follow a symlink the project itself placed
 * inside the tree. Deployments are built out of exactly those - a shared var/, config/ or
 * storage/ directory linked into each release - so refusing them would hide the part of
 * the project an administrator most often needs to look at. A project that wants the jail
 * to be physical sets follow_symlinks to false and gets the stricter realpath() check.
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
    public function __construct(
        string $rootPath,
        array $excludedPaths = [],
        private readonly bool $followSymlinks = true,
    ) {
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
     * The returned path is the one that was asked for, not its realpath(): a symlink is
     * addressed under the name it has in the tree, so toRelative() keeps round-tripping and
     * the UI keeps showing the path the user clicked.
     *
     * @throws InvalidPathException      the path is syntactically unusable
     * @throws PathNotFoundException     nothing exists at that path
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

        if ($this->isInsideRoot($real)) {
            // A symlink may resolve to a location that is inside the root but excluded.
            if ($this->isExcluded($this->toRelative($real))) {
                throw new PathAccessDeniedException('This path is not available in the file viewer.');
            }

            return $candidate;
        }

        // normaliseRelative() has already resolved every "." and ".." segment and refuses a
        // path that walks above the root, so the spelled path is inside the root by
        // construction. The only way its realpath() can be somewhere else is a symlink that
        // lives in the tree - which is the deployment layout this bundle has to be able to
        // browse, not an escape attempt.
        if (!$this->followSymlinks) {
            throw new PathAccessDeniedException('This path is outside of the file viewer root.');
        }

        return $candidate;
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
