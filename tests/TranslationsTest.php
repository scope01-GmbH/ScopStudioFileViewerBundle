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
     */
    public function testCatalogueMatchesTheKeysUsedInTheFrontend(): void
    {
        $used = $this->keysUsedInFrontend();

        self::assertNotEmpty($used, 'No translation keys were found in the frontend sources.');

        $available = array_keys($this->loadCatalogue(self::BASE_LOCALE));

        self::assertSame([], array_values(array_diff($used, $available)), 'Keys used in the UI but not translated.');
        self::assertSame([], array_values(array_diff($available, $used)), 'Translated keys that the UI never uses.');
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
