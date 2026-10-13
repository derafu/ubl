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

use Derafu\Ubl\Service\Deserializer;
use Derafu\Ubl\Service\Serializer;
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
    public function everyGeneratedClassIsArraySerializable(): void
    {
        $this->assertNoFileMatches(
            '/^final class \w+\n\{\n/m',
            'is still missing ArraySerializableTrait',
            ['/Xml/', '/Enum/']
        );
    }

    #[Test]
    public function everyGeneratedClassHasPrecomputedArrayMetadata(): void
    {
        $this->assertNoFileMatches(
            '/use ArraySerializableTrait;\n\n(?!    private const ARRAY_METADATA)/',
            'is still missing ARRAY_METADATA (ArraySerializableTrait would have to reflect it at runtime instead)',
            ['/Xml/', '/Enum/']
        );
    }

    #[Test]
    public function everyClassLivesInTheDirectoryOfItsOwnXmlNamespace(): void
    {
        // The generator puts everything that is not `Cbc` into `Cac`; patch-oasis.php moves the
        // few classes of other UBL component families (extension, signature) out of it.
        $namespaceOf = [
            'Cac' => 'CAC',
            'Cbc' => 'CBC',
            'Ext' => 'EXT',
            'Sac' => 'SAC',
            'Sig' => 'SIG',
        ];

        $checked = 0;
        foreach ($namespaceOf as $directory => $constant) {
            foreach (glob(self::oasisDir() . "/$directory/*.php") ?: [] as $path) {
                $source = file_get_contents($path);
                $this->assertNotFalse($source);

                if (preg_match('/#\[XmlType\(localName: \'\w+\', namespace: Ns::(\w+)\)\]/', $source, $match) !== 1) {
                    $this->fail($path . ': has no #[XmlType] with an Ns:: namespace');
                }

                $this->assertSame(
                    $constant,
                    $match[1],
                    $path . ": is declared in the PHP namespace of $directory but its XML namespace is Ns::{$match[1]}"
                );
                $checked++;
            }
        }

        $this->assertGreaterThan(200, $checked, 'the directories were not scanned');
    }

    #[Test]
    public function noCacElementIsTypedAsACbcBasicComponent(): void
    {
        // The generator typed a `cac:` element whose name is also a `cbc:` element's (`Location`,
        // `Condition`) as the text of the `cbc:` one, and the aggregate lost all its content
        // (a `<cac:Location>` read back as `<cac:Location/>`); patch-oasis.php gives them their
        // own class.
        $basic = 'Text|Code|Identifier|Amount|Quantity|Measure|Numeric|BinaryObject';

        $this->assertNoFileMatches(
            '/Ns::CAC, type: (?:' . $basic . ')::class\)\]/',
            'has a list of cac: elements typed as a cbc: basic component'
        );
        $this->assertNoFileMatches(
            '/Ns::CAC\)\]\n    private \?(?:' . $basic . ') \$/',
            'has a cac: element typed as a cbc: basic component'
        );
    }

    #[Test]
    public function aCacElementNamedLikeACbcOneKeepsItsContentThroughXml(): void
    {
        $xml = (string) file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/doc/xml/GoodsItemItinerary/GoodsItemItinerary_001_official_example.xml'
        );

        $serializer = new Serializer();
        $out = $serializer->serialize((new Deserializer())->deserialize($xml, 'xml'), 'xml');

        // The `<cac:Location>` of the transport events holds an address, not text.
        $this->assertStringNotContainsString('<cac:Location/>', $out);
        $this->assertStringContainsString('Neuer Wandrahm 4', $out);
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

    /**
     * @param list<string> $excludePaths Path substrings of files this does not apply to.
     */
    private function assertNoFileMatches(string $pattern, string $message, array $excludePaths = []): void
    {
        foreach (self::oasisFiles() as $file) {
            $path = $file->getPathname();
            foreach ($excludePaths as $exclude) {
                if (str_contains($path, $exclude)) {
                    continue 2;
                }
            }

            $source = file_get_contents($path);
            $this->assertNotFalse($source);

            $this->assertSame(0, preg_match($pattern, $source), $path . ': ' . $message);
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
