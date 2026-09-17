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
use Pimcore\Bundle\StudioBackendBundle\Perspective\Util\Constant\ContextPermissionGroups;
use Scop\StudioFileViewerBundle\EventSubscriber\StudioContextPermissionsSubscriber;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the translation catalogues against the failure modes that are invisible at build
 * time: a key used in the UI but never translated renders as the raw key, and a placeholder
 * that differs between locales renders as literal "{{path}}" for the users of that locale.
 */
final class TranslationsTest extends TestCase
{
    private const BASE_LOCALE = 'en';

    private const LOCALES = ['en', 'de'];

    /**
     * Everything this bundle renders itself. Keys outside of it belong to a Studio core
     * screen that this bundle only contributes an entry to.
     */
    private const OWN_KEY_PREFIX = 'scop-file-viewer.';

    public function testEveryLocaleFileExistsAndIsFlat(): void
    {
        foreach (self::LOCALES as $locale) {
            $catalogue = $this->loadCatalogue($locale);

            self::assertNotEmpty($catalogue, sprintf('The "%s" catalogue is empty.', $locale));

            foreach ($catalogue as $key => $value) {
                self::assertIsString(
                    $value,
                    sprintf('"%s" in "%s" is nested; the Studio translator only resolves flat dot notation.', $key, $locale),
                );
            }
        }
    }

    public function testEveryLocaleHasTheSameKeysAsEnglish(): void
    {
        $base = array_keys($this->loadCatalogue(self::BASE_LOCALE));

        foreach (self::LOCALES as $locale) {
            if (self::BASE_LOCALE === $locale) {
                continue;
            }

            $keys = array_keys($this->loadCatalogue($locale));

            self::assertSame([], array_values(array_diff($base, $keys)), sprintf('Keys missing from "%s".', $locale));
            self::assertSame([], array_values(array_diff($keys, $base)), sprintf('Keys in "%s" that English does not have.', $locale));
        }
    }

    public function testPlaceholdersMatchAcrossLocales(): void
    {
        $base = $this->loadCatalogue(self::BASE_LOCALE);

        foreach (self::LOCALES as $locale) {
            if (self::BASE_LOCALE === $locale) {
                continue;
            }

            $catalogue = $this->loadCatalogue($locale);

            foreach ($base as $key => $value) {
                self::assertSame(
                    $this->placeholders($value),
                    $this->placeholders((string) $catalogue[$key]),
                    sprintf('Placeholders for "%s" differ between "%s" and "%s".', $key, self::BASE_LOCALE, $locale),
                );
            }
        }
    }

    /**
     * Every key the UI asks for has to exist, and every key shipped has to be used - an
     * orphan is usually a rename that only got applied on one side.
     *
     * Only this bundle's own namespace is compared. A key outside of it is rendered by a
     * Studio core screen from a key that core assembles itself, so it never appears in these
     * sources; {@see testPerspectivePermissionIsLabelledInEveryLocale} covers that one.
     */
    public function testCatalogueMatchesTheKeysUsedInTheFrontend(): void
    {
        $used = $this->keysUsedInFrontend();

        self::assertNotEmpty($used, 'No translation keys were found in the frontend sources.');

        $available = array_values(array_filter(
            array_keys($this->loadCatalogue(self::BASE_LOCALE)),
            static fn (string $key): bool => str_starts_with($key, self::OWN_KEY_PREFIX),
        ));

        self::assertSame([], array_values(array_diff($used, $available)), 'Keys used in the UI but not translated.');
        self::assertSame([], array_values(array_diff($available, $used)), 'Translated keys that the UI never uses.');
    }

    /**
     * The navigation entry is switched on and off per perspective, and the perspective editor
     * labels that checkbox with a key it assembles out of the permission itself. Three places
     * have to agree for a label to appear at all: the permission the frontend puts on the nav
     * item, the one the backend registers, and the translation. When they drift the checkbox
     * shows the raw key - or vanishes, because the editor drops a permission the backend does
     * not know about.
     */
    public function testPerspectivePermissionIsLabelledInEveryLocale(): void
    {
        $module = (string) file_get_contents(
            \dirname(__DIR__) . '/assets/src/modules/file-viewer-module.tsx',
        );

        self::assertSame(
            1,
            preg_match("/const PERSPECTIVE_PERMISSION = '(\w+)\.(\w+)'/", $module, $matches),
            'The frontend module does not declare a PERSPECTIVE_PERMISSION of the form "group.key".',
        );

        [, $group, $key] = $matches;

        self::assertSame(
            StudioContextPermissionsSubscriber::PERMISSION_KEY,
            $key,
            'The permission on the navigation item is not the one the backend registers.',
        );

        self::assertSame(
            ContextPermissionGroups::SYSTEM->value,
            $group,
            'The navigation item and the backend registration disagree about the permission group.',
        );

        $translationKey = sprintf('perspective-editor.form.main-nav-permission.%s.%s', $group, $key);

        foreach (self::LOCALES as $locale) {
            self::assertArrayHasKey(
                $translationKey,
                $this->loadCatalogue($locale),
                sprintf('The perspective editor label is missing from "%s".', $locale),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCatalogue(string $locale): array
    {
        $path = \dirname(__DIR__) . '/src/Resources/translations/studio.' . $locale . '.yaml';

        self::assertFileExists($path);

        return Yaml::parseFile($path) ?? [];
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $value): array
    {
        preg_match_all('/\{\{\s*(\w+)\s*}}/', $value, $matches);

        $found = array_unique($matches[1]);
        sort($found);

        return array_values($found);
    }

    /**
     * @return list<string>
     */
    private function keysUsedInFrontend(): array
    {
        $keys = [];
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/assets/src', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($directory as $file) {
            /** @var \SplFileInfo $file */
            if (!\in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            // t('key') calls plus the nav/widget title key, which is referenced as a constant.
            preg_match_all("/t\('(scop-file-viewer[^']+)'/", $contents, $calls);
            preg_match_all("/'(scop-file-viewer\.[a-z0-9.-]+)'/", $contents, $literals);

            $keys = array_merge($keys, $calls[1], $literals[1]);
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }
}
