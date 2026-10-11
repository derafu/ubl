<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsUbl\Oasis;

use Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * Checks that tools/patch-oasis.php's corrections are present in the committed src/Oasis,
 * independent of whether that script was run: a regeneration without it must fail here, not
 * only in the script's own postcondition.
 */
#[CoversNothing]
final class GeneratedCodeTest extends TestCase
{
    #[Test]
    public function noValidatorHelperClaimsAnUnprovenElementType(): void
    {
        $this->assertNoFileMatches(
            '/@param list<(?!mixed>)[^>]+> \$values\n\s*\*\/\n\s*private static function validate/',
            'still has a non-mixed list<> docblock on a validate*() helper'
        );
    }

    #[Test]
    public function everyTimeElementDeclaresATimeFormat(): void
    {
        $this->assertNoFileMatches(
            '/#\[XmlElement\(name: \'\w*Time\', namespace: Ns::CBC(?:, required: true)?\)\]/',
            "has a '*Time' XmlElement missing format:"
        );
    }

    #[Test]
    public function documentRegistryGuardsItsRegexMatch(): void
    {
        $source = file_get_contents(self::oasisDir() . '/Xml/DocumentRegistry.php');
        $this->assertNotFalse($source);

        $this->assertStringContainsString(
            "if (preg_match('/^\\{(.+?)\\}(.+)\$/', \$key, \$m) === 1) {",
            $source,
            'DocumentRegistry::rootElementFor() no longer guards its preg_match() result'
        );
    }

    private function assertNoFileMatches(string $pattern, string $message): void
    {
        foreach (self::oasisFiles() as $file) {
            $source = file_get_contents($file->getPathname());
            $this->assertNotFalse($source);

            $this->assertSame(
                0,
                preg_match($pattern, $source),
                $file->getPathname() . ': ' . $message
            );
        }
    }

    /**
     * @return Generator<SplFileInfo>
     */
    private static function oasisFiles(): Generator
    {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::oasisDir())),
            '/\.php$/'
        );

        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);
            yield $file;
        }
    }

    private static function oasisDir(): string
    {
        return dirname(__DIR__, 3) . '/src/Oasis';
    }
}
