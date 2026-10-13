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

use Derafu\Ubl\Exception\ValidationException;
use Derafu\Ubl\Service\Deserializer;
use Derafu\Ubl\Service\JsonSchemaValidator;
use Derafu\Ubl\Service\Serializer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The JSON (and YAML, which is the same thing) that this package writes is the model-based
 * representation of the "UBL JSON Alternative Representation" (OASIS Committee Note 01, for UBL
 * 2.1, 2.2 and 2.3: the versions it exists for),
 * and these are the tests of that: the official JSON schemas of the note (bundled in
 * resources/schemas/json/2.1/, what JsonSchemaValidator uses) and its official examples
 * (tests/fixtures/<version>/doc/json/), both fetched by tools/fetch-oasis-json.php.
 *
 * What is written is checked against the schema of its document, and the official XML and JSON
 * examples (the same documents in two syntaxes) have to give the same data.
 *
 * One of the official examples, OrderResponse, is not valid against the official schemas:
 * the note writes an empty element (`<cac:Item/>`) as `{"_": ""}` and the schema does not
 * accept it. What this package writes for it, `{}`, does.
 */
#[CoversNothing]
final class OasisJsonTest extends TestCase
{
    use CanonicalizesDataTrait;
    use FixturesTrait;

    /**
     * Every document that the official JSON examples have, read and written again as JSON,
     * validates against the schema of its document type.
     */
    #[Test]
    #[DataProvider('jsonFixtures')]
    public function whatIsWrittenFromAnOfficialJsonExampleValidatesAgainstTheSchema(string $version, string $path): void
    {
        $document = (new Deserializer())->deserialize((string) file_get_contents($path), 'json', ['version' => $version]);

        $this->assertValid((new Serializer())->serialize($document, 'json'), $version, $path);
    }

    /**
     * The same for the official XML examples: the JSON written from them is valid.
     */
    #[Test]
    #[DataProvider('xmlFixtures')]
    public function theJsonWrittenFromAnOfficialXmlExampleValidatesAgainstTheSchema(string $version, string $path): void
    {
        $document = (new Deserializer())->deserialize((string) file_get_contents($path), 'xml', ['version' => $version]);

        $this->assertValid((new Serializer())->serialize($document, 'json'), $version, $path);
    }

    /**
     * Also for the YAML fixtures of this package, as the JSON they are.
     */
    #[Test]
    #[DataProvider('yamlFixtures')]
    public function theJsonWrittenFromAYamlFixtureValidatesAgainstTheSchema(string $version, string $path): void
    {
        $document = (new Deserializer())->deserialize((string) file_get_contents($path), 'yaml', ['version' => $version]);

        $this->assertValid((new Serializer())->serialize($document, 'json'), $version, $path);
    }

    /**
     * The official XML example and the official JSON example of a document are the same data:
     * the XML, written as JSON, is the JSON example.
     */
    #[Test]
    #[DataProvider('xmlFixturesWithAnOfficialJson')]
    public function anOfficialXmlExampleWrittenAsJsonIsTheOfficialJsonExample(string $version, string $path): void
    {
        $jsonPath = self::jsonOf($path);

        $fromXml = json_decode(
            (new Serializer())->serialize(
                (new Deserializer())->deserialize((string) file_get_contents($path), 'xml', ['version' => $version]),
                'json'
            ),
            true
        );
        $official = json_decode((string) file_get_contents($jsonPath), true);

        // `_E`, the namespace of the extensions, is in the apex of the note's tool whenever the
        // XML declares it, extensions or not (2.3's ExportCustomsDeclaration): it is only
        // documentary, and this package writes it when there are extensions.
        if (is_array($official) && is_array($fromXml)) {
            unset($official['_E'], $fromXml['_E']);
        }

        $this->assertSame($this->trimmed($this->canonical($official)), $this->trimmed($this->canonical($fromXml)), $path);
    }

    /**
     * The XML reader (`xterr/php-ubl`) trims the text of every element, and the JSON examples
     * keep what the note's tool took from the XML: the 2.2 `PriorInformationNotice` example has a
     * URI with a trailing space in its XML (an `xs:anyURI` collapses whitespace, so nothing is
     * lost there). The comparison between the two is by the text without the margins.
     */
    private function trimmed(mixed $data): mixed
    {
        if (is_array($data)) {
            return array_map(fn (mixed $item): mixed => $this->trimmed($item), $data);
        }

        return is_string($data) ? trim($data) : $data;
    }

    private function assertValid(string $json, string $version, string $path): void
    {
        $data = json_decode($json, true);

        try {
            (new JsonSchemaValidator())->validate(is_array($data) ? $data : [], $version);
        } catch (ValidationException $e) {
            $this->fail($path . ': ' . $e->getMessage());
        }

        $this->addToAssertionCount(1);
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
        return self::fixturesWithJsonSchemas('xml');
    }

    /**
     * The XML examples that OASIS also has a JSON example of (it ships a few of the JSON ones as
     * empty files).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function xmlFixturesWithAnOfficialJson(): array
    {
        return array_filter(
            self::fixturesWithJsonSchemas('xml'),
            static fn (array $case): bool => is_file(self::jsonOf($case[1]))
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function yamlFixtures(): array
    {
        return self::fixturesWithJsonSchemas('yaml');
    }
}
