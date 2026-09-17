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

namespace Scop\StudioFileViewerBundle\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Builds a throwaway directory tree per test.
 *
 * The classes under test are deliberately framework free, so these tests need neither a
 * Symfony kernel nor a Pimcore installation - they run against a real filesystem, which is
 * the only way to exercise the parts that matter here (realpath, symlinks, permissions).
 */
abstract class FilesystemTestCase extends TestCase
{
    protected string $root;

    /**
     * @var list<string> directories created next to the root, torn down with it
     */
    private array $outsideDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir() . '/scop-file-viewer-test-' . bin2hex(random_bytes(6));

        if (!mkdir($base, 0777, true) && !is_dir($base)) {
            self::fail(sprintf('Could not create the test directory "%s".', $base));
        }

        // realpath() so comparisons hold on systems where the temp dir is itself a symlink
        // (/var -> /private/var on macOS), which is exactly what the path jail resolves.
        $root = realpath($base);
        self::assertIsString($root);

        $this->root = $root;
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->root);

        foreach ($this->outsideDirectories as $directory) {
            $this->removeRecursively($directory);
        }

        $this->outsideDirectories = [];

        parent::tearDown();
    }

    /**
     * A directory beside the root, for the symlink cases: a deployment's shared var/ or
     * config/ lives next to the release, not inside it.
     */
    protected function makeOutsideDirectory(): string
    {
        $path = sys_get_temp_dir() . '/scop-file-viewer-outside-' . bin2hex(random_bytes(6));

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            self::fail(sprintf('Could not create "%s".', $path));
        }

        $real = realpath($path);
        self::assertIsString($real);

        $this->outsideDirectories[] = $real;

        return $real;
    }

    protected function writeFile(string $relativePath, string $contents = ''): string
    {
        $absolute = $this->root . '/' . $relativePath;
        $directory = \dirname($absolute);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            self::fail(sprintf('Could not create "%s".', $directory));
        }

        file_put_contents($absolute, $contents);

        return $absolute;
    }

    protected function makeDirectory(string $relativePath): string
    {
        $absolute = $this->root . '/' . $relativePath;

        if (!is_dir($absolute) && !mkdir($absolute, 0777, true) && !is_dir($absolute)) {
            self::fail(sprintf('Could not create "%s".', $absolute));
        }

        return $absolute;
    }

    private function removeRecursively(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $this->removeRecursively($path . '/' . $entry);
        }

        @rmdir($path);
    }
}
