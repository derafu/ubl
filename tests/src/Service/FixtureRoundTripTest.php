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
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Round-trips every committed fixture under tests/fixtures/doc/ through Serializer/Deserializer.
 * Fixtures are discovered by glob, never listed by hand: adding a new one under
 * tests/fixtures/doc/<format>/<Document>/ picks it up here automatically, for whichever
 * document types and formats happen to have one today. There is no dedicated json data
 * provider: no json fixture is committed (see CLAUDE.md on why), so json is only exercised
 * with real data here by the format-hopping chain below, which starts and ends at a real,
 * committed xml fixture.
 *
 * Covers many different Oasis\Doc\* classes depending on which fixtures exist, the same reason
 * tests/src/Oasis/GeneratedCodeTest.php is also #[CoversNothing].
 */
#[CoversNothing]
final class FixtureRoundTripTest extends TestCase
{
    /**
     * The fixture's raw content, decoded with nothing but the format's own standard library
     * parser (json_decode()/Yaml::parse(), not a line of this package's own code), must equal
     * what Deserializer::deserialize() extracts from it. This is what would have caught, by
     * itself, a fixture authored with the wrong shape (a list written as a single object, say).
     */
    #[Test]
    #[DataProvider('yamlFixtures')]
    public function yamlFixtureIsReadExactlyAsAuthored(string $path): void
    {
        $this->assertFidelity($path, 'yaml');
    }

    /**
     * serialize()/deserialize() must be true inverses of each other for this real fixture, not
     * only for the synthetic object SerializerTest/DeserializerTest build by hand.
     */
    #[Test]
    #[DataProvider('yamlFixtures')]
    public function yamlFixtureRoundTrips(string $path): void
    {
        $this->assertSymmetry($path, 'yaml');
    }

    #[Test]
    #[DataProvider('xmlFixtures')]
    public function xmlFixtureRoundTrips(string $path): void
    {
        $this->assertSymmetry($path, 'xml');
    }

    /**
     * Walks every fixture of every format in one pass: this is the superset of the two tests
     * above, kept separate so a failure here always also fails a format-specific test that
     * names the format outright - this one is for a glance at "is everything, everywhere, OK".
     */
    #[Test]
    #[DataProvider('allFixtures')]
    public function everyFixtureRoundTrips(string $path, string $format): void
    {
        $this->assertSymmetry($path, $format);
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
    public function officialXmlExampleSurvivesEveryFormatHop(string $path): void
    {
        $content = (string) file_get_contents($path);
        $serializer = new Serializer();
        $deserializer = new Deserializer();

        $original = $deserializer->deserialize($content, 'xml');

        $fromJson = $deserializer->deserialize(
            $serializer->serialize($original, 'json'),
            'json'
        );
        $fromYaml = $deserializer->deserialize(
            $serializer->serialize($fromJson, 'yaml'),
            'yaml'
        );
        $final = $deserializer->deserialize(
            $serializer->serialize($fromYaml, 'xml'),
            'xml'
        );

        $this->assertSame($this->toArray($original), $this->toArray($final), $path);
        $this->assertSame(serialize($original), serialize($final), $path);
    }

    private function assertFidelity(string $path, string $format): void
    {
        $content = (string) file_get_contents($path);
        $raw = $format === 'json' ? json_decode($content, true) : Yaml::parse($content);

        if (!is_array($raw)) {
            $this->fail("$path did not decode to an array.");
        }

        $key = array_key_first($raw);
        if ($key === null) {
            $this->fail("$path decoded to an empty array.");
        }

        $document = (new Deserializer())->deserialize($content, $format);

        $this->assertSame($raw[$key], $this->toArray($document), $path);
    }

    private function assertSymmetry(string $path, string $format): void
    {
        $content = (string) file_get_contents($path);
        $deserializer = new Deserializer();

        $document = $deserializer->deserialize($content, $format);
        $again = $deserializer->deserialize(
            (new Serializer())->serialize($document, $format),
            $format
        );

        $this->assertSame($this->toArray($document), $this->toArray($again), $path);
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
     * @return array<string, array{0: string}>
     */
    public static function yamlFixtures(): array
    {
        return self::glob('yaml');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function xmlFixtures(): array
    {
        return self::glob('xml');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function allFixtures(): array
    {
        $cases = [];
        foreach (['yaml', 'json', 'xml'] as $format) {
            foreach (self::glob($format) as $label => $case) {
                $cases["$format/$label"] = [$case[0], $format];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string}>
     */
    private static function glob(string $format): array
    {
        $dir = dirname(__DIR__, 3) . "/tests/fixtures/doc/$format";
        $cases = [];
        foreach (glob("$dir/*/*.$format") ?: [] as $file) {
            $cases[basename(dirname($file)) . '/' . basename($file)] = [$file];
        }

        return $cases;
    }
}
