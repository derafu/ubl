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
 * representation of "UBL 2.1 JSON Alternative Representation" v2.0 (OASIS Committee Note 01),
 * and these are the tests of that: the official JSON schemas of the note (bundled in
 * resources/schemas/json/2.1/, what JsonSchemaValidator uses) and its official examples
 * (tests/fixtures/doc/json/), both fetched by tools/fetch-oasis-json.php.
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

    /**
     * Every document that the official JSON examples have, read and written again as JSON,
     * validates against the schema of its document type.
     */
    #[Test]
    #[DataProvider('jsonFixtures')]
    public function whatIsWrittenFromAnOfficialJsonExampleValidatesAgainstTheSchema(string $path): void
    {
        $document = (new Deserializer())->deserialize((string) file_get_contents($path), 'json');

        $this->assertValid((new Serializer())->serialize($document, 'json'), $path);
    }

    /**
     * The same for the official XML examples: the JSON written from them is valid.
     */
    #[Test]
    #[DataProvider('xmlFixtures')]
    public function theJsonWrittenFromAnOfficialXmlExampleValidatesAgainstTheSchema(string $path): void
    {
        $document = (new Deserializer())->deserialize((string) file_get_contents($path), 'xml');

        $this->assertValid((new Serializer())->serialize($document, 'json'), $path);
    }

    /**
     * Also for the YAML fixtures of this package, as the JSON they are.
     */
    #[Test]
    #[DataProvider('yamlFixtures')]
    public function theJsonWrittenFromAYamlFixtureValidatesAgainstTheSchema(string $path): void
    {
        $document = (new Deserializer())->deserialize((string) file_get_contents($path), 'yaml');

        $this->assertValid((new Serializer())->serialize($document, 'json'), $path);
    }

    /**
     * The official XML example and the official JSON example of a document are the same data:
     * the XML, written as JSON, is the JSON example.
     */
    #[Test]
    #[DataProvider('xmlFixtures')]
    public function anOfficialXmlExampleWrittenAsJsonIsTheOfficialJsonExample(string $path): void
    {
        $jsonPath = str_replace(['/xml/', '.xml'], ['/json/', '.json'], $path);
        $this->assertFileExists($jsonPath);

        $fromXml = json_decode(
            (new Serializer())->serialize((new Deserializer())->deserialize((string) file_get_contents($path), 'xml'), 'json'),
            true
        );
        $official = json_decode((string) file_get_contents($jsonPath), true);

        $this->assertSame($this->canonical($official), $this->canonical($fromXml), $path);
    }

    private function assertValid(string $json, string $path): void
    {
        $data = json_decode($json, true);

        try {
            (new JsonSchemaValidator())->validate(is_array($data) ? $data : []);
        } catch (ValidationException $e) {
            $this->fail($path . ': ' . $e->getMessage());
        }

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function jsonFixtures(): array
    {
        return self::glob('json');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function xmlFixtures(): array
    {
        return self::glob('xml');
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
    private static function glob(string $format): array
    {
        $dir = dirname(__DIR__, 2) . "/fixtures/doc/$format";
        $cases = [];
        foreach (glob("$dir/*/*.$format") ?: [] as $file) {
            $cases[basename(dirname($file)) . '/' . basename($file)] = [$file];
        }

        return $cases;
    }
}
