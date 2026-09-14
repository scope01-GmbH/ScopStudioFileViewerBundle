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

namespace Scop\StudioFileViewerBundle\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use Scop\StudioFileViewerBundle\Exception\InvalidPathException;
use Scop\StudioFileViewerBundle\Exception\PathAccessDeniedException;
use Scop\StudioFileViewerBundle\Exception\PathNotFoundException;
use Scop\StudioFileViewerBundle\Service\PathResolver;
use Scop\StudioFileViewerBundle\Tests\FilesystemTestCase;

/**
 * The path jail is the security boundary of this bundle: everything else assumes that a path
 * which came out of resolve() is inside the root. These tests are therefore mostly about the
 * ways out of it.
 */
final class PathResolverTest extends FilesystemTestCase
{
    public function testResolvesRootItself(): void
    {
        $resolver = $this->createResolver();

        self::assertSame($this->root, $resolver->resolve(''));
        self::assertSame($this->root, $resolver->resolve('/'));
    }

    public function testResolvesFileInsideRoot(): void
    {
        $this->writeFile('config/services.yaml');
        $resolver = $this->createResolver();

        self::assertSame($this->root . '/config/services.yaml', $resolver->resolve('config/services.yaml'));
    }

    public function testTreatsLeadingSlashAsRootRelative(): void
    {
        $this->writeFile('composer.json');
        $resolver = $this->createResolver();

        self::assertSame($this->root . '/composer.json', $resolver->resolve('/composer.json'));
    }

    public function testResolvesHarmlessTraversalInsideRoot(): void
    {
        $this->writeFile('composer.json');
        $this->makeDirectory('src');
        $resolver = $this->createResolver();

        self::assertSame($this->root . '/composer.json', $resolver->resolve('src/../composer.json'));
    }

    #[DataProvider('escapingPaths')]
    public function testRejectsPathsEscapingTheRoot(string $path): void
    {
        $resolver = $this->createResolver();

        $this->expectException(InvalidPathException::class);
        $resolver->resolve($path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function escapingPaths(): iterable
    {
        yield 'parent' => ['..'];
        yield 'parent file' => ['../secret.txt'];
        yield 'deep traversal' => ['../../../../etc/passwd'];
        yield 'traversal after descent' => ['src/../../etc/passwd'];
        yield 'leading slash traversal' => ['/../etc/passwd'];
    }

    public function testRejectsNullByte(): void
    {
        $resolver = $this->createResolver();

        $this->expectException(InvalidPathException::class);
        $resolver->resolve("composer\0.json");
    }

    public function testRejectsMissingPath(): void
    {
        $resolver = $this->createResolver();

        $this->expectException(PathNotFoundException::class);
        $resolver->resolve('does/not/exist');
    }

    /**
     * A symlink is the interesting case: the path itself never leaves the root, only the
     * thing it points at does. This is why the containment check runs on the realpath().
     */
    public function testRejectsSymlinkPointingOutsideRoot(): void
    {
        $outside = sys_get_temp_dir() . '/scop-file-viewer-outside-' . bin2hex(random_bytes(4));
        file_put_contents($outside, 'secret');

        try {
            if (!@symlink($outside, $this->root . '/escape')) {
                self::markTestSkipped('Symlinks are not supported in this environment.');
            }

            $resolver = $this->createResolver();

            $this->expectException(PathAccessDeniedException::class);
            $resolver->resolve('escape');
        } finally {
            @unlink($outside);
        }
    }

    public function testAllowsSymlinkStayingInsideRoot(): void
    {
        $this->writeFile('config/real.yaml');

        if (!@symlink($this->root . '/config/real.yaml', $this->root . '/link.yaml')) {
            self::markTestSkipped('Symlinks are not supported in this environment.');
        }

        $resolver = $this->createResolver();

        self::assertSame($this->root . '/config/real.yaml', $resolver->resolve('link.yaml'));
    }

    public function testRejectsExcludedPathAndItsChildren(): void
    {
        $this->writeFile('.git/config');
        $resolver = $this->createResolver(['.git']);

        self::assertTrue($resolver->isExcluded('.git'));
        self::assertTrue($resolver->isExcluded('.git/config'));
        self::assertFalse($resolver->isExcluded('.gitignore'), 'A prefix match must not leak into sibling names.');

        $this->expectException(PathAccessDeniedException::class);
        $resolver->resolve('.git/config');
    }

    /**
     * An excluded directory must stay excluded even when it is reached by another name.
     */
    public function testRejectsSymlinkIntoExcludedPath(): void
    {
        $this->writeFile('.git/config');

        if (!@symlink($this->root . '/.git', $this->root . '/git-alias')) {
            self::markTestSkipped('Symlinks are not supported in this environment.');
        }

        $resolver = $this->createResolver(['.git']);

        $this->expectException(PathAccessDeniedException::class);
        $resolver->resolve('git-alias/config');
    }

    public function testNormalisesSeparatorsAndRedundantSegments(): void
    {
        $resolver = $this->createResolver();

        self::assertSame('config/services.yaml', $resolver->normaliseRelative('config//./services.yaml'));
        self::assertSame('config/services.yaml', $resolver->normaliseRelative('\\config\\services.yaml'));
        self::assertSame('', $resolver->normaliseRelative('/'));
    }

    public function testToRelativeRoundTrips(): void
    {
        $this->writeFile('src/Kernel.php');
        $resolver = $this->createResolver();

        self::assertSame('src/Kernel.php', $resolver->toRelative($this->root . '/src/Kernel.php'));
        self::assertSame('', $resolver->toRelative($this->root));
        self::assertSame('', $resolver->toRelative('/somewhere/else'));
    }

    public function testRejectsNonExistentRoot(): void
    {
        $this->expectException(InvalidPathException::class);
        new PathResolver($this->root . '/nope');
    }

    /**
     * @param list<string> $excluded
     */
    private function createResolver(array $excluded = []): PathResolver
    {
        return new PathResolver($this->root, $excluded);
    }
}
