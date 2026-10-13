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
use Derafu\Ubl\Service\VersionRegistry;
use Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * Checks that tools/patch-oasis.php's corrections are present in the committed generated model
 * of every version (src/V21/Oasis, src/V22/Oasis...), independent of whether that script was
 * run: a regeneration without it must fail here, not only in the script's own postcondition.
 */
#[CoversNothing]
final class GeneratedCodeTest extends TestCase
{
    #[Test]
    #[DataProvider('versions')]
    public function noValidatorHelperClaimsAnUnprovenElementType(string $version): void
    {
        $this->assertNoFileMatches(
            $version,
            '/@param list<(?!mixed>)[^>]+> \$values\n\s*\*\/\n\s*private static function validate/',
            'still has a non-mixed list<> docblock on a validate*() helper'
        );
    }

    #[Test]
    #[DataProvider('versions')]
    public function everyTimeElementDeclaresATimeFormat(string $version): void
    {
        $this->assertNoFileMatches(
            $version,
            '/#\[XmlElement\(name: \'\w*Time\', namespace: Ns::CBC(?:, required: true)?\)\]/',
            "has a '*Time' XmlElement missing format:"
        );
    }

    #[Test]
    #[DataProvider('versions')]
    public function everyGeneratedClassIsArraySerializable(string $version): void
    {
        $this->assertNoFileMatches(
            $version,
            '/^final class \w+\n\{\n/m',
            'is still missing ArraySerializableTrait',
            ['/Xml/', '/Enum/']
        );
    }

    #[Test]
    #[DataProvider('versions')]
    public function everyGeneratedClassHasPrecomputedArrayMetadata(string $version): void
    {
        $this->assertNoFileMatches(
            $version,
            '/use ArraySerializableTrait;\n\n(?!    private const ARRAY_METADATA)/',
            'is still missing ARRAY_METADATA (ArraySerializableTrait would have to reflect it at runtime instead)',
            ['/Xml/', '/Enum/']
        );
    }

    #[Test]
    #[DataProvider('versions')]
    public function everyClassLivesInTheDirectoryOfItsOwnXmlNamespace(string $version): void
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
            foreach (glob(self::oasisDir($version) . "/$directory/*.php") ?: [] as $path) {
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
    #[DataProvider('versions')]
    public function noCacElementIsTypedAsACbcBasicComponent(string $version): void
    {
        // The generator typed a `cac:` element whose name is also a `cbc:` element's (`Location`,
        // `Condition`) as the text of the `cbc:` one, and the aggregate lost all its content
        // (a `<cac:Location>` read back as `<cac:Location/>`); patch-oasis.php gives them their
        // own class.
        $basic = 'Text|Code|Identifier|Amount|Quantity|Measure|Numeric|BinaryObject';

        $this->assertNoFileMatches(
            $version,
            '/Ns::CAC, type: (?:' . $basic . ')::class(?:, required: true)?\)\]/',
            'has a list of cac: elements typed as a cbc: basic component'
        );
        $this->assertNoFileMatches(
            $version,
            '/Ns::CAC(?:, required: true)?\)\]\n    private \??(?:' . $basic . ') \$/',
            'has a cac: element typed as a cbc: basic component'
        );
    }

    #[Test]
    #[DataProvider('versions')]
    public function noListOfABuiltinTypeIsTypedAsAClass(string $version): void
    {
        // `type: bool::class` is, in a namespace, a class that does not exist, and `instanceof
        // bool` is never true: `ElectronicPaymentUsageIndicator` (2.2 onwards, a list of booleans)
        // rejected every value. patch-oasis.php writes `type: 'bool'` and `is_bool()`.
        $this->assertNoFileMatches(
            $version,
            '/type: (?:bool|int|float|string)::class|instanceof (?:bool|int|float|string)\b/',
            'types a list of a built-in type as a class'
        );
    }

    #[Test]
    #[DataProvider('versions')]
    public function aCacElementNamedLikeACbcOneKeepsItsContentThroughXml(string $version): void
    {
        // The 2.1 example, read by the model of every version (they are backward compatible).
        $xml = (string) file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/2.1/doc/xml/GoodsItemItinerary/GoodsItemItinerary_001_official_example.xml'
        );

        $serializer = new Serializer();
        $out = $serializer->serialize((new Deserializer())->deserialize($xml, 'xml', ['version' => $version]), 'xml');

        // The `<cac:Location>` of the transport events holds an address, not text.
        $this->assertStringNotContainsString('<cac:Location/>', $out);
        $this->assertStringContainsString('Neuer Wandrahm 4', $out);
    }

    #[Test]
    #[DataProvider('versions')]
    public function documentRegistryGuardsItsRegexMatch(string $version): void
    {
        $source = file_get_contents(self::oasisDir($version) . '/Xml/DocumentRegistry.php');
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
    private function assertNoFileMatches(string $version, string $pattern, string $message, array $excludePaths = []): void
    {
        foreach (self::oasisFiles($version) as $file) {
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
    private static function oasisFiles(string $version): Generator
    {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::oasisDir($version))),
            '/\.php$/'
        );

        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);
            yield $file;
        }
    }

    private static function oasisDir(string $version): string
    {
        return dirname(__DIR__, 3) . '/src/V' . str_replace('.', '', $version) . '/Oasis';
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function versions(): array
    {
        $cases = [];
        foreach (VersionRegistry::SUPPORTED as $version) {
            $cases[$version] = [$version];
        }

        return $cases;
    }
}
