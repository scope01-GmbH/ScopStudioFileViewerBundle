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
use Scop\StudioFileViewerBundle\Exception\AlreadyExistsException;
use Scop\StudioFileViewerBundle\Exception\InvalidPathException;
use Scop\StudioFileViewerBundle\Exception\NotWritableException;
use Scop\StudioFileViewerBundle\Exception\PathAccessDeniedException;
use Scop\StudioFileViewerBundle\Exception\PathNotFoundException;
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

    public function testCreatesFileInSubdirectory(): void
    {
        $this->makeDirectory('config');

        $entry = $this->createService()->createFile('config', 'services.yaml');

        self::assertFileExists($this->root . '/config/services.yaml');
        self::assertSame('config/services.yaml', $entry['path']);
        self::assertFalse($entry['isDirectory']);
        self::assertSame(0, $entry['size'], 'A new file starts empty.');
    }

    public function testCreatesFileAtRoot(): void
    {
        $entry = $this->createService()->createFile('', 'README.md');

        self::assertFileExists($this->root . '/README.md');
        self::assertSame('README.md', $entry['path']);
    }

    public function testCreatesDirectory(): void
    {
        $entry = $this->createService()->createDirectory('', 'var');

        self::assertDirectoryExists($this->root . '/var');
        self::assertTrue($entry['isDirectory']);
        self::assertSame('var', $entry['path']);
    }

    public function testCreatedFileIsImmediatelyReadable(): void
    {
        $service = $this->createService();
        $service->createFile('', 'notes.txt');

        $file = $service->readFile('notes.txt');

        self::assertSame(FileViewerService::STATUS_OK, $file['status']);
        self::assertSame('', $file['content']);
        self::assertTrue($file['editable']);
    }

    public function testCreateRejectsExistingEntry(): void
    {
        $this->writeFile('composer.json');

        $this->expectException(AlreadyExistsException::class);
        $this->createService()->createFile('', 'composer.json');
    }

    /**
     * A dangling symlink occupies the name without file_exists() reporting it, so creating
     * "over" it would silently write through the link.
     */
    public function testCreateRejectsNameTakenByDanglingSymlink(): void
    {
        if (!@symlink($this->root . '/missing-target', $this->root . '/link.txt')) {
            self::markTestSkipped('Symlinks are not supported in this environment.');
        }

        $this->expectException(AlreadyExistsException::class);
        $this->createService()->createFile('', 'link.txt');
    }

    /**
     * The name is a single segment; a separator would let a caller escape the parent it
     * passed and create entries anywhere under the root.
     */
    #[DataProvider('invalidNames')]
    public function testCreateRejectsInvalidName(string $name): void
    {
        $this->expectException(InvalidPathException::class);
        $this->createService()->createFile('', $name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'dot' => ['.'];
        yield 'parent' => ['..'];
        yield 'slash' => ['sub/file.txt'];
        yield 'traversal' => ['../escaped.txt'];
        yield 'backslash' => ['sub\\file.txt'];
        yield 'nul byte' => ["bad\0name"];
        yield 'newline' => ["bad\nname"];
        yield 'too long' => [str_repeat('a', 256)];
    }

    public function testCreateRejectsTraversalInParentPath(): void
    {
        $this->expectException(InvalidPathException::class);
        $this->createService()->createFile('../..', 'escaped.txt');
    }

    public function testCreateRejectsNonDirectoryParent(): void
    {
        $this->writeFile('composer.json');

        $this->expectException(InvalidPathException::class);
        $this->createService()->createFile('composer.json', 'child.txt');
    }

    public function testCreateRejectsExcludedParent(): void
    {
        $this->makeDirectory('.git');

        $this->expectException(PathAccessDeniedException::class);
        $this->createService(excluded: ['.git'])->createDirectory('.git', 'hooks');
    }

    public function testCreateRejectsNameThatWouldBeExcluded(): void
    {
        $this->expectException(PathAccessDeniedException::class);
        $this->createService(excluded: ['.git'])->createDirectory('', '.git');
    }

    public function testCreateRefusedWhenReadOnly(): void
    {
        $this->expectException(NotWritableException::class);
        $this->createService(writable: false)->createFile('', 'notes.txt');
    }

    public function testCreateTrimsSurroundingWhitespace(): void
    {
        $entry = $this->createService()->createFile('', '  notes.txt  ');

        self::assertSame('notes.txt', $entry['name']);
        self::assertFileExists($this->root . '/notes.txt');
    }

    public function testRenamesFileInPlace(): void
    {
        $this->writeFile('src/Old.php', '<?php');

        $entry = $this->createService()->renameEntry('src/Old.php', 'New.php');

        self::assertSame('New.php', $entry['name']);
        self::assertSame('src/New.php', $entry['path']);
        self::assertFileDoesNotExist($this->root . '/src/Old.php');
        self::assertStringEqualsFile($this->root . '/src/New.php', '<?php');
    }

    public function testRenamesDirectoryWithItsContents(): void
    {
        $this->writeFile('src/old/deep/file.txt', 'kept');

        $entry = $this->createService()->renameEntry('src/old', 'new');

        self::assertTrue($entry['isDirectory']);
        self::assertSame('src/new', $entry['path']);
        self::assertStringEqualsFile($this->root . '/src/new/deep/file.txt', 'kept');
    }

    public function testRenameToTheSameNameIsANoop(): void
    {
        $this->writeFile('notes.txt', 'kept');

        $entry = $this->createService()->renameEntry('notes.txt', 'notes.txt');

        self::assertSame('notes.txt', $entry['path']);
        self::assertStringEqualsFile($this->root . '/notes.txt', 'kept');
    }

    public function testRenameRejectsExistingTarget(): void
    {
        $this->writeFile('a.txt');
        $this->writeFile('b.txt');

        $this->expectException(AlreadyExistsException::class);
        $this->createService()->renameEntry('a.txt', 'b.txt');
    }

    #[DataProvider('invalidNames')]
    public function testRenameRejectsInvalidName(string $name): void
    {
        $this->writeFile('notes.txt');

        $this->expectException(InvalidPathException::class);
        $this->createService()->renameEntry('notes.txt', $name);
    }

    public function testRenameRefusesTheRoot(): void
    {
        $this->expectException(InvalidPathException::class);
        $this->createService()->renameEntry('', 'elsewhere');
    }

    public function testRenameRejectsExcludedEntry(): void
    {
        $this->writeFile('.git/config');

        $this->expectException(PathAccessDeniedException::class);
        $this->createService(excluded: ['.git'])->renameEntry('.git', 'git-backup');
    }

    public function testRenameRejectsNameThatWouldBeExcluded(): void
    {
        $this->makeDirectory('repo');

        $this->expectException(PathAccessDeniedException::class);
        $this->createService(excluded: ['.git'])->renameEntry('repo', '.git');
    }

    public function testRenameRefusedWhenReadOnly(): void
    {
        $this->writeFile('notes.txt');

        $this->expectException(NotWritableException::class);
        $this->createService(writable: false)->renameEntry('notes.txt', 'other.txt');
    }

    /**
     * A symlink has to be renamed as the link, not as whatever it points at.
     */
    public function testRenameMovesTheSymlinkAndNotItsTarget(): void
    {
        $this->writeFile('target.txt', 'target');
        symlink($this->root . '/target.txt', $this->root . '/link.txt');

        $this->createService()->renameEntry('link.txt', 'renamed-link.txt');

        self::assertTrue(is_link($this->root . '/renamed-link.txt'));
        self::assertFileExists($this->root . '/target.txt');
        self::assertFileDoesNotExist($this->root . '/link.txt');
    }

    public function testDeletesFile(): void
    {
        $this->writeFile('src/notes.txt');

        $result = $this->createService()->deleteEntry('src/notes.txt');

        self::assertSame('src/notes.txt', $result['path']);
        self::assertFalse($result['isDirectory']);
        self::assertFileDoesNotExist($this->root . '/src/notes.txt');
        self::assertDirectoryExists($this->root . '/src');
    }

    public function testDeletesDirectoryWithEverythingInside(): void
    {
        $this->writeFile('var/cache/deep/file.txt');
        $this->makeDirectory('var/cache/empty');

        $result = $this->createService()->deleteEntry('var/cache');

        self::assertTrue($result['isDirectory']);
        self::assertDirectoryDoesNotExist($this->root . '/var/cache');
        self::assertDirectoryExists($this->root . '/var');
    }

    public function testDeleteRefusesTheRoot(): void
    {
        $this->writeFile('keep.txt');

        try {
            $this->createService()->deleteEntry('');
            self::fail('Deleting the root should have been refused.');
        } catch (InvalidPathException) {
            self::assertFileExists($this->root . '/keep.txt');
        }
    }

    public function testDeleteRefusesTheRootViaTraversal(): void
    {
        $this->makeDirectory('src');

        $this->expectException(InvalidPathException::class);
        $this->createService()->deleteEntry('src/..');
    }

    public function testDeleteRejectsMissingEntry(): void
    {
        $this->expectException(PathNotFoundException::class);
        $this->createService()->deleteEntry('nope.txt');
    }

    public function testDeleteRejectsExcludedPath(): void
    {
        $this->writeFile('.git/config');

        $this->expectException(PathAccessDeniedException::class);
        $this->createService(excluded: ['.git'])->deleteEntry('.git/config');
    }

    public function testDeleteRejectsPathOutsideTheJail(): void
    {
        $this->expectException(InvalidPathException::class);
        $this->createService()->deleteEntry('../../etc/passwd');
    }

    public function testDeleteRefusedWhenReadOnly(): void
    {
        $this->writeFile('notes.txt');

        $this->expectException(NotWritableException::class);
        $this->createService(writable: false)->deleteEntry('notes.txt');
    }

    /**
     * Deleting a link must unlink it, never walk into the directory it points at.
     */
    public function testDeleteUnlinksSymlinkWithoutTouchingItsTarget(): void
    {
        $this->writeFile('shared/keep.txt', 'keep');
        $this->makeDirectory('var');
        symlink($this->root . '/shared', $this->root . '/var/link');

        $result = $this->createService()->deleteEntry('var/link');

        self::assertFalse($result['isDirectory']);
        self::assertFalse(is_link($this->root . '/var/link'));
        self::assertStringEqualsFile($this->root . '/shared/keep.txt', 'keep');
    }

    public function testDeleteDoesNotFollowSymlinksInsideADeletedDirectory(): void
    {
        $this->writeFile('shared/keep.txt', 'keep');
        $this->makeDirectory('var/cache');
        symlink($this->root . '/shared', $this->root . '/var/cache/link');

        $this->createService()->deleteEntry('var/cache');

        self::assertDirectoryDoesNotExist($this->root . '/var/cache');
        self::assertStringEqualsFile($this->root . '/shared/keep.txt', 'keep');
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
