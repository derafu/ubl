<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsUbl\Service;

use Derafu\Ubl\Service\Deserializer;
use Derafu\Ubl\Service\Serializer;
use Derafu\Ubl\Service\XmlSchemaValidator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Round-trips every committed fixture under tests/fixtures/<version>/doc/ through Serializer/Deserializer,
 * and every xml and yaml one additionally through XmlSchemaValidator, against the real OASIS XSD.
 * Fixtures are discovered by glob, never listed by hand: adding a new one under
 * tests/fixtures/<version>/doc/<format>/<Document>/ picks it up here automatically, for
 * whichever versions, document types and formats happen to have one today. There is no dedicated json data
 * provider for the hand-authored shape: the json fixtures are the official OASIS ones (the
 * model-based JSON examples of the "UBL JSON Alternative Representation" of 2.1, 2.2 and 2.3,
 * fetched by tools/fetch-oasis-json.php), and the yaml ones are this package's own.
 *
 * A decimal is a JSON number, so "100.00" in XML comes back from JSON as "100": every
 * comparison here treats the numbers by their value (see `canonical()`).
 *
 * Covers many different Oasis\Doc\* classes depending on which fixtures exist, the same reason
 * tests/src/Oasis/GeneratedCodeTest.php is also #[CoversNothing].
 */
#[CoversNothing]
final class FixtureRoundTripTest extends TestCase
{
    use CanonicalizesDataTrait;
    use FixturesTrait;

    /**
     * The fixture's raw content, decoded with nothing but the format's own standard library
     * parser (json_decode()/Yaml::parse(), not a line of this package's own code), must equal
     * what Deserializer::deserialize() extracts from it. This is what would have caught, by
     * itself, a fixture authored with the wrong shape (a list written as a single object, say).
     */
    #[Test]
    #[DataProvider('yamlFixtures')]
    public function yamlFixtureIsReadExactlyAsAuthored(string $version, string $path): void
    {
        $this->assertFidelity($version, $path, 'yaml');
    }

    /**
     * serialize()/deserialize() must be true inverses of each other for this real fixture, not
     * only for the synthetic object SerializerTest/DeserializerTest build by hand.
     */
    #[Test]
    #[DataProvider('yamlFixtures')]
    public function yamlFixtureRoundTrips(string $version, string $path): void
    {
        $this->assertSymmetry($version, $path, 'yaml');
    }

    /**
     * The official OASIS JSON examples are read exactly as the note writes them.
     */
    #[Test]
    #[DataProvider('jsonFixtures')]
    public function jsonFixtureIsReadExactlyAsAuthored(string $version, string $path): void
    {
        $this->assertFidelity($version, $path, 'json');
    }

    #[Test]
    #[DataProvider('jsonFixtures')]
    public function jsonFixtureRoundTrips(string $version, string $path): void
    {
        $this->assertSymmetry($version, $path, 'json');
    }

    #[Test]
    #[DataProvider('xmlFixtures')]
    public function xmlFixtureRoundTrips(string $version, string $path): void
    {
        $this->assertSymmetry($version, $path, 'xml');
    }

    /**
     * The bundled XSDs are not just present, they genuinely accept the real documents they are
     * meant for - proven directly against each one, not assumed from parsing or round-tripping
     * having already succeeded.
     */
    #[Test]
    #[DataProvider('xmlFixtures')]
    public function xmlFixtureValidatesAgainstItsRealSchema(string $version, string $path): void
    {
        $content = (string) file_get_contents($path);

        (new XmlSchemaValidator())->validate($content, $version);

        $this->addToAssertionCount(1);
    }

    /**
     * The yaml fixtures are hand-authored (unlike the xml ones, real official examples): this
     * is what actually proves each one is not just internally consistent (round-trips, reads as
     * authored) but a genuinely valid UBL document - the same guarantee
     * xmlFixtureValidatesAgainstItsRealSchema() already gives the xml fixtures, closing the gap
     * for the minimal, one-per-document-type yaml fixtures under tests/fixtures/<version>/doc/yaml/.
     */
    #[Test]
    #[DataProvider('yamlFixtures')]
    public function yamlFixtureValidatesAgainstItsRealSchema(string $version, string $path): void
    {
        $content = (string) file_get_contents($path);
        $document = (new Deserializer())->deserialize($content, 'yaml', ['version' => $version]);
        $xml = (new Serializer())->serialize($document, 'xml');

        (new XmlSchemaValidator())->validate($xml, $version);

        $this->addToAssertionCount(1);
    }

    /**
     * The official JSON examples are valid UBL documents too: read from JSON and written as
     * XML, they validate against the real XSD.
     */
    #[Test]
    #[DataProvider('jsonFixtures')]
    public function jsonFixtureValidatesAgainstItsRealSchema(string $version, string $path): void
    {
        $content = (string) file_get_contents($path);
        $document = (new Deserializer())->deserialize($content, 'json', ['version' => $version]);
        $xml = (new Serializer())->serialize($document, 'xml');

        (new XmlSchemaValidator())->validate($xml, $version);

        $this->addToAssertionCount(1);
    }

    /**
     * Walks every fixture of every format in one pass: this is the superset of the two tests
     * above, kept separate so a failure here always also fails a format-specific test that
     * names the format outright - this one is for a glance at "is everything, everywhere, OK".
     */
    #[Test]
    #[DataProvider('allFixtures')]
    public function everyFixtureRoundTrips(string $version, string $path, string $format): void
    {
        $this->assertSymmetry($version, $path, $format);
    }

    /**
     * The one check the format-specific round trips above cannot do: they never feed one
     * format's decoded data into another format's encoder, so a disagreement between, say,
     * json_decode() and Yaml::parse() on how to read the same value would slip past all of
     * them. Starting from a real official XML example and hopping xml -> json -> yaml -> xml
     * exercises exactly that, and the data must come back exactly as it started.
     *
     * Compares the two *objects* via PHP's own native serialize(), not their toArray(): a bug
     * shared by both toArray() calls (silently dropping the same field both times, say) would
     * make a toArray()-based comparison pass even though data was actually lost. serialize()
     * walks the full object graph by its real property values, recursively, using none of this
     * package's own code, so it cannot share that blind spot - and, unlike assertEquals() on
     * two distinct instances, php-cs-fixer's php_unit_strict rule cannot rewrite a plain string
     * comparison into the identity-checking assertSame(object, object) that would always fail.
     *
     * The toArray() comparison right before it proves nothing serialize() does not already: it
     * is a pure function of the same object state serialize() just compared, so it cannot differ
     * once that comparison has already passed. It is here only so a real future failure shows a
     * readable diff (named UBL fields) before the far less readable one serialize() would print
     * (every private property, null-byte-prefixed) - which is what made the one real bug found
     * here take a whole separate debugging script to even read.
     */
    #[Test]
    #[DataProvider('xmlFixtures')]
    public function officialXmlExampleSurvivesEveryFormatHop(string $version, string $path): void
    {
        $content = (string) file_get_contents($path);
        $serializer = new Serializer();
        $deserializer = new Deserializer();

        $options = ['version' => $version];

        $original = $deserializer->deserialize($content, 'xml', $options);

        $fromJson = $deserializer->deserialize(
            $serializer->serialize($original, 'json'),
            'json',
            $options
        );
        $fromYaml = $deserializer->deserialize(
            $serializer->serialize($fromJson, 'yaml'),
            'yaml',
            $options
        );
        $finalXml = $serializer->serialize($fromYaml, 'xml');
        $final = $deserializer->deserialize($finalXml, 'xml', $options);

        (new XmlSchemaValidator())->validate($finalXml, $version);

        $this->assertSame($this->canonical($this->toArray($original)), $this->canonical($this->toArray($final)), $path);
        $this->assertSame($this->canonicalSerialize($original), $this->canonicalSerialize($final), $path);
    }

    private function assertFidelity(string $version, string $path, string $format): void
    {
        $content = (string) file_get_contents($path);
        $raw = $format === 'json' ? json_decode($content, true) : Yaml::parse($content);

        if (!is_array($raw)) {
            $this->fail("$path did not decode to an array.");
        }

        // The document is the one key that is not a namespace of the apex (`_D`, `_A`...).
        $keys = array_values(array_filter(array_keys($raw), static fn ($key): bool => !str_starts_with((string) $key, '_')));
        if (count($keys) !== 1) {
            $this->fail("$path does not have exactly one document key.");
        }

        $document = (new Deserializer())->deserialize($content, $format, ['version' => $version]);

        $this->assertSame($this->canonical($raw[$keys[0]]), $this->canonical($this->toArray($document)), $path);
    }

    private function assertSymmetry(string $version, string $path, string $format): void
    {
        $content = (string) file_get_contents($path);
        $deserializer = new Deserializer();
        $options = ['version' => $version];

        $document = $deserializer->deserialize($content, $format, $options);
        $again = $deserializer->deserialize(
            (new Serializer())->serialize($document, $format),
            $format,
            $options
        );

        $this->assertSame($this->canonical($this->toArray($document)), $this->canonical($this->toArray($again)), $path);
    }

    /**
     * PHP's own `serialize()` of the object graph, with the decimal texts by their value
     * ("100.00" and "100" are the same decimal).
     */
    private function canonicalSerialize(object $document): string
    {
        return (string) preg_replace_callback(
            '/s:\d+:"(-?\d+(?:\.\d+)?)";/',
            static fn (array $match): string => 'n:' . (float) $match[1] . ';',
            serialize($document)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $document): array
    {
        if (!is_object($document) || !method_exists($document, 'toArray')) {
            $this->fail('Deserialized value is not array-serializable: ' . get_debug_type($document));
        }

        return $document->toArray();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function yamlFixtures(): array
    {
        return self::fixtures('yaml');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function jsonFixtures(): array
    {
        return self::fixtures('json');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function xmlFixtures(): array
    {
        return self::fixtures('xml');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function allFixtures(): array
    {
        $cases = [];
        foreach (['yaml', 'json', 'xml'] as $format) {
            foreach (self::fixtures($format) as $label => [$version, $path]) {
                $cases["$format/$label"] = [$version, $path, $format];
            }
        }

        return $cases;
    }
}
