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

use Scop\StudioFileViewerBundle\Exception\InvalidPathException;
use Scop\StudioFileViewerBundle\Exception\NotWritableException;
use Scop\StudioFileViewerBundle\Exception\PathAccessDeniedException;
use Scop\StudioFileViewerBundle\Exception\PayloadTooLargeException;
use Scop\StudioFileViewerBundle\Service\FileViewerService;
use Scop\StudioFileViewerBundle\Service\PathResolver;
use Scop\StudioFileViewerBundle\Tests\FilesystemTestCase;

final class FileViewerServiceTest extends FilesystemTestCase
{
    private const MAX_EDITABLE_SIZE = 1024;

    private const TAIL_BYTES = 256;

    public function testListsDirectoriesBeforeFilesAndSortsNaturally(): void
    {
        $this->makeDirectory('src');
        $this->makeDirectory('Config');
        $this->writeFile('composer.json');
        $this->writeFile('README.md');

        $listing = $this->createService()->listDirectory('');
        $names = array_column($listing['entries'], 'name');

        self::assertSame(['Config', 'src', 'composer.json', 'README.md'], $names);
        self::assertSame('', $listing['path']);
    }

    public function testListingHidesExcludedPaths(): void
    {
        $this->writeFile('.git/config');
        $this->writeFile('composer.json');

        $listing = $this->createService(excluded: ['.git'])->listDirectory('');

        self::assertSame(['composer.json'], array_column($listing['entries'], 'name'));
    }

    public function testListingDirectoryRejectsAFile(): void
    {
        $this->writeFile('composer.json');

        $this->expectException(InvalidPathException::class);
        $this->createService()->listDirectory('composer.json');
    }

    public function testReadsTextFile(): void
    {
        $this->writeFile('config/services.yaml', "services:\n    _defaults:\n");

        $file = $this->createService()->readFile('config/services.yaml');

        self::assertSame(FileViewerService::STATUS_OK, $file['status']);
        self::assertSame("services:\n    _defaults:\n", $file['content']);
        self::assertTrue($file['editable']);
        self::assertFalse($file['truncated']);
    }

    public function testReadingDirectoryIsRejected(): void
    {
        $this->makeDirectory('src');

        $this->expectException(InvalidPathException::class);
        $this->createService()->readFile('src');
    }

    public function testReadingExcludedPathIsRejected(): void
    {
        $this->writeFile('.git/config', 'x');

        $this->expectException(PathAccessDeniedException::class);
        $this->createService(excluded: ['.git'])->readFile('.git/config');
    }

    /**
     * The whole point of the size guard: an oversized file must come back described but
     * unread, so the browser is never handed a multi-gigabyte log.
     */
    public function testOversizedFileIsDescribedButNotLoaded(): void
    {
        $this->writeFile('var/big.log', str_repeat("a\n", self::MAX_EDITABLE_SIZE));

        $file = $this->createService()->readFile('var/big.log');

        self::assertSame(FileViewerService::STATUS_TOO_LARGE, $file['status']);
        self::assertNull($file['content']);
        self::assertFalse($file['editable']);
        self::assertSame(self::MAX_EDITABLE_SIZE, $file['maxEditableSize']);
    }

    public function testTailReturnsBoundedContentFromTheEnd(): void
    {
        $lines = '';
        for ($i = 0; $i < 500; $i++) {
            $lines .= sprintf("line %03d padding padding\n", $i);
        }
        $this->writeFile('var/big.log', $lines);

        $file = $this->createService()->readFile('var/big.log', tail: true);
        $content = (string) $file['content'];

        self::assertSame(FileViewerService::STATUS_TOO_LARGE, $file['status']);
        self::assertTrue($file['truncated']);
        self::assertLessThanOrEqual(self::TAIL_BYTES, \strlen($content));
        self::assertStringContainsString('line 499', $content, 'The tail must come from the end of the file.');
        self::assertStringStartsWith('line ', $content, 'The tail must start on a line boundary.');
    }

    public function testTailOfSmallFileIsNotRequested(): void
    {
        $this->writeFile('small.txt', "one\ntwo\n");

        $file = $this->createService()->readFile('small.txt', tail: true);

        self::assertSame(FileViewerService::STATUS_OK, $file['status']);
        self::assertSame("one\ntwo\n", $file['content']);
        self::assertFalse($file['truncated']);
    }

    public function testBinaryFileIsDetectedAndNotLoaded(): void
    {
        $this->writeFile('public/logo.png', "\x89PNG\r\n\x1a\n\x00\x00binary");

        $file = $this->createService()->readFile('public/logo.png');

        self::assertSame(FileViewerService::STATUS_BINARY, $file['status']);
        self::assertNull($file['content']);
    }

    public function testEmptyFileIsNotBinary(): void
    {
        $this->writeFile('empty.txt', '');

        self::assertSame(FileViewerService::STATUS_OK, $this->createService()->readFile('empty.txt')['status']);
    }

    public function testWriteReplacesContentAndReturnsFreshState(): void
    {
        $this->writeFile('config/services.yaml', "old\n");

        $result = $this->createService()->writeFile('config/services.yaml', "new\n");

        self::assertSame("new\n", file_get_contents($this->root . '/config/services.yaml'));
        self::assertSame("new\n", $result['content']);
        self::assertSame(FileViewerService::STATUS_OK, $result['status']);
    }

    /**
     * rename() would otherwise give the replacement the umask default, silently loosening or
     * tightening the permissions of whatever was edited.
     */
    public function testWritePreservesPermissions(): void
    {
        $absolute = $this->writeFile('bin/console', "#!/usr/bin/env php\n");
        chmod($absolute, 0755);
        clearstatcache(true, $absolute);

        $this->createService()->writeFile('bin/console', "#!/usr/bin/env php\n// edited\n");
        clearstatcache(true, $absolute);

        self::assertSame('0755', substr(sprintf('%o', fileperms($absolute)), -4));
    }

    public function testWriteLeavesNoTemporaryFilesBehind(): void
    {
        $this->writeFile('config/services.yaml', "old\n");

        $this->createService()->writeFile('config/services.yaml', "new\n");

        self::assertSame([], glob($this->root . '/config/.scop-file-viewer-*') ?: []);
    }

    public function testWriteRejectsOversizedContent(): void
    {
        $this->writeFile('config/services.yaml', "old\n");

        $this->expectException(PayloadTooLargeException::class);
        $this->createService()->writeFile('config/services.yaml', str_repeat('a', self::MAX_EDITABLE_SIZE + 1));
    }

    public function testWriteRejectsBinaryFile(): void
    {
        $this->writeFile('public/logo.png', "\x89PNG\x00\x00");

        $this->expectException(InvalidPathException::class);
        $this->createService()->writeFile('public/logo.png', 'text');
    }

    public function testWriteRejectsDirectory(): void
    {
        $this->makeDirectory('src');

        $this->expectException(InvalidPathException::class);
        $this->createService()->writeFile('src', 'text');
    }

    public function testWriteRejectsExcludedPath(): void
    {
        $this->writeFile('.git/config', "x\n");

        $this->expectException(PathAccessDeniedException::class);
        $this->createService(excluded: ['.git'])->writeFile('.git/config', 'text');
    }

    public function testReadOnlyServiceRefusesWrites(): void
    {
        $this->writeFile('config/services.yaml', "old\n");

        $this->expectException(NotWritableException::class);
        $this->createService(writable: false)->writeFile('config/services.yaml', "new\n");
    }

    public function testReadOnlyServiceReportsFilesAsNotEditable(): void
    {
        $this->writeFile('config/services.yaml', "old\n");

        $file = $this->createService(writable: false)->readFile('config/services.yaml');

        self::assertFalse($file['editable']);
        self::assertFalse($file['writable']);
    }

    public function testDownloadAllowsOversizedAndBinaryFiles(): void
    {
        $this->writeFile('var/big.log', str_repeat("a\n", self::MAX_EDITABLE_SIZE));
        $this->writeFile('public/logo.png', "\x89PNG\x00\x00");

        $service = $this->createService();

        self::assertSame($this->root . '/var/big.log', $service->resolveDownloadPath('var/big.log'));
        self::assertSame($this->root . '/public/logo.png', $service->resolveDownloadPath('public/logo.png'));
    }

    public function testDownloadStaysInsideTheJail(): void
    {
        $this->expectException(InvalidPathException::class);
        $this->createService()->resolveDownloadPath('../../etc/passwd');
    }

    public function testDownloadRejectsDirectory(): void
    {
        $this->makeDirectory('src');

        $this->expectException(InvalidPathException::class);
        $this->createService()->resolveDownloadPath('src');
    }

    public function testConfigReportsEffectiveLimits(): void
    {
        $config = $this->createService()->getConfig();

        self::assertSame($this->root, $config['root']);
        self::assertTrue($config['writable']);
        self::assertSame(self::MAX_EDITABLE_SIZE, $config['maxEditableSize']);
        self::assertSame(self::TAIL_BYTES, $config['tailBytes']);
    }

    /**
     * @param list<string> $excluded
     */
    private function createService(bool $writable = true, array $excluded = []): FileViewerService
    {
        return new FileViewerService(
            new PathResolver($this->root, $excluded),
            $writable,
            self::MAX_EDITABLE_SIZE,
            self::TAIL_BYTES,
        );
    }
}
