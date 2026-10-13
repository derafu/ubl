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

use Derafu\Ubl\Contract\DeserializerInterface;
use Derafu\Ubl\Exception\UnsupportedVersionException;
use Derafu\Ubl\Exception\ValidationException;
use Derafu\Ubl\Service\Deserializer;
use Derafu\Ubl\Service\FormatHandlerFactory;
use Derafu\Ubl\Service\Serializer;
use Derafu\Ubl\Service\VersionRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What it is to have more than one UBL version: each one has its own model, the version is
 * asked for (the XML of every 2.x has the same namespaces, so the content does not say it), and
 * what was written for an older version is still valid in a newer one (UBL's versions only add,
 * and relax cardinalities).
 */
#[CoversNothing]
final class MultiVersionTest extends TestCase
{
    use FixturesTrait;

    private const INVOICE = __DIR__ . '/../../fixtures/2.1/doc/xml/Invoice/Invoice_001_official_example.xml';

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

    #[Test]
    #[DataProvider('versions')]
    public function theDocumentIsOfTheClassOfTheVersionThatWasAskedFor(string $version): void
    {
        $document = (new Deserializer())->deserialize(
            (string) file_get_contents(self::INVOICE),
            'xml',
            [DeserializerInterface::VERSION => $version]
        );

        $this->assertSame('Derafu\\Ubl\\V' . str_replace('.', '', $version) . '\\Oasis\\Doc\\Invoice', $document::class);
    }

    #[Test]
    public function withoutAVersionItIsTheDefaultOne(): void
    {
        $content = (string) file_get_contents(self::INVOICE);

        $this->assertSame(
            'Derafu\\Ubl\\V21\\Oasis\\Doc\\Invoice',
            (new Deserializer())->deserialize($content, 'xml')::class
        );

        // The default version is the registry's, which the application sets in its services.
        $deserializer = new Deserializer(new FormatHandlerFactory(versions: new VersionRegistry('2.3')));
        $this->assertSame(
            'Derafu\\Ubl\\V23\\Oasis\\Doc\\Invoice',
            $deserializer->deserialize($content, 'xml')::class
        );
    }

    #[Test]
    #[DataProvider('versions')]
    public function theVersionOfTheDocumentIsTheOneItWasWrittenWith(string $version): void
    {
        $document = (new Deserializer())->deserialize(
            (string) file_get_contents(self::INVOICE),
            'xml',
            ['version' => $version]
        );
        $serializer = new Serializer();
        $expected = $this->toArray($document);

        // The version is not asked for when writing: the class of the document has it.
        foreach (['xml', 'json', 'yaml'] as $format) {
            $content = $serializer->serialize($document, $format);
            $again = (new Deserializer())->deserialize($content, $format, ['version' => $version]);

            $this->assertSame($document::class, $again::class, $format);
            $this->assertSame($expected, $this->toArray($again), $format);
        }
    }

    #[Test]
    #[DataProvider('formats')]
    public function aVersionThatIsNotSupportedThrows(string $format): void
    {
        $content = (new Serializer())->serialize(
            (new Deserializer())->deserialize((string) file_get_contents(self::INVOICE), 'xml'),
            $format
        );

        $this->expectException(UnsupportedVersionException::class);

        (new Deserializer())->deserialize($content, $format, ['version' => '2.9']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function formats(): array
    {
        return ['xml' => ['xml'], 'json' => ['json'], 'yaml' => ['yaml']];
    }

    /**
     * A document that a version does not have is not read by it: the `PurchaseReceipt` was
     * introduced by 2.4.
     */
    #[Test]
    public function aDocumentOfALaterVersionIsUnknownToAnEarlierOne(): void
    {
        $xml = (string) file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/2.4/doc/xml/PurchaseReceipt/PurchaseReceipt_001_official_example.xml'
        );

        $this->assertSame(
            'Derafu\\Ubl\\V24\\Oasis\\Doc\\PurchaseReceipt',
            (new Deserializer())->deserialize($xml, 'xml', ['version' => '2.4'])::class
        );

        $this->expectException(\Derafu\Ubl\Exception\DeserializationException::class);
        (new Deserializer())->deserialize($xml, 'xml', ['version' => '2.3']);
    }

    /**
     * Cardinalities are what changes in what a version already had: in 2.5 a street can have
     * more than one line. The model of each version has the shape of its own XSD, so the same
     * XML is a single value in one and a list in the other.
     */
    #[Test]
    public function theModelOfEachVersionHasTheCardinalitiesOfItsOwnSchema(): void
    {
        $content = (string) file_get_contents(self::INVOICE);
        $deserializer = new Deserializer();

        $streetOf = fn (string $version): mixed => $this->at(
            $this->toArray($deserializer->deserialize($content, 'xml', ['version' => $version])),
            'AccountingSupplierParty',
            'Party',
            'PostalAddress',
            'StreetName'
        );

        $this->assertSame(['_' => 'Main street'], $streetOf('2.1'));
        $this->assertSame([['_' => 'Main street']], $streetOf('2.5'));
    }

    /**
     * The content of an amount is a JSON number in every version (what the OASIS note asks),
     * which the model of a version has to know by itself.
     */
    #[Test]
    #[DataProvider('versions')]
    public function anAmountIsAJsonNumberInEveryVersion(string $version): void
    {
        $document = (new Deserializer())->deserialize(
            (string) file_get_contents(self::INVOICE),
            'xml',
            ['version' => $version]
        );

        $data = json_decode((new Serializer())->serialize($document, 'json'), true);
        $this->assertIsArray($data);

        $this->assertSame(
            ['_' => 1436.5, 'currencyID' => 'EUR'],
            $this->at($this->toArray($document), 'LegalMonetaryTotal', 'LineExtensionAmount')
        );
        $this->assertStringContainsString('"LineExtensionAmount":{"_":1436.5,"currencyID":"EUR"}', (string) json_encode($data, JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * What was written for a version is valid in the later ones: every official XML example of
     * a version, read and written again by the model of each later version, validates against
     * the XSD of that version.
     */
    #[Test]
    #[DataProvider('olderExamplesOfLaterVersions')]
    public function anExampleOfAnOlderVersionIsValidInALaterOne(string $from, string $path, string $to): void
    {
        $content = (string) file_get_contents($path);

        $document = (new Deserializer())->deserialize($content, 'xml', ['version' => $to]);
        $xml = (new Serializer())->serialize($document, 'xml');

        (new \Derafu\Ubl\Service\XmlSchemaValidator())->validate($xml, $to);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function olderExamplesOfLaterVersions(): array
    {
        $cases = [];
        foreach (self::fixtures('xml') as $label => [$from, $path]) {
            foreach (VersionRegistry::SUPPORTED as $to) {
                if (version_compare($to, $from, '>')) {
                    $cases["$label -> $to"] = [$from, $path, $to];
                }
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('versionsWithoutJsonSchemas')]
    public function theJsonOfAVersionWithoutOfficialSchemasCannotBeValidated(string $version): void
    {
        $content = (new Serializer())->serialize(
            (new Deserializer())->deserialize((string) file_get_contents(self::INVOICE), 'xml', ['version' => $version]),
            'json'
        );

        // It is read and written all the same: only validating it needs the schema.
        $this->assertSame(
            'Derafu\\Ubl\\V' . str_replace('.', '', $version) . '\\Oasis\\Doc\\Invoice',
            (new Deserializer())->deserialize($content, 'json', ['version' => $version])::class
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("UBL $version");

        (new Deserializer())->deserialize($content, 'json', ['version' => $version, 'validate' => true]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function versionsWithoutJsonSchemas(): array
    {
        return ['2.4' => ['2.4'], '2.5' => ['2.5']];
    }

    #[Test]
    #[DataProvider('versionsWithJsonSchemas')]
    public function theJsonOfAVersionWithOfficialSchemasIsValidated(string $version): void
    {
        $content = (new Serializer())->serialize(
            (new Deserializer())->deserialize((string) file_get_contents(self::INVOICE), 'xml', ['version' => $version]),
            'json'
        );

        $document = (new Deserializer())->deserialize($content, 'json', ['version' => $version, 'validate' => true]);

        $this->assertSame('Derafu\\Ubl\\V' . str_replace('.', '', $version) . '\\Oasis\\Doc\\Invoice', $document::class);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function versionsWithJsonSchemas(): array
    {
        return ['2.1' => ['2.1'], '2.2' => ['2.2'], '2.3' => ['2.3']];
    }

    #[Test]
    #[DataProvider('versions')]
    public function xmlIsValidatedAgainstTheXsdOfTheVersion(string $version): void
    {
        $content = (string) file_get_contents(self::INVOICE);

        $document = (new Deserializer())->deserialize($content, 'xml', ['version' => $version, 'validate' => true]);

        $this->assertSame('Derafu\\Ubl\\V' . str_replace('.', '', $version) . '\\Oasis\\Doc\\Invoice', $document::class);
    }

    /**
     * A list of booleans (`ElectronicPaymentUsageIndicator`, 2.2 onwards) is a list of booleans:
     * the generator typed it as a class that does not exist and a guard that always failed.
     */
    #[Test]
    #[DataProvider('versionsWithAListOfBooleans')]
    public function aListOfBooleansTakesBooleans(string $version): void
    {
        $class = 'Derafu\\Ubl\\V' . str_replace('.', '', $version) . '\\Oasis\\Cac\\PostAwardProcess';
        $data = ['ElectronicPaymentUsageIndicator' => [['_' => true], ['_' => false]]];

        $process = $class::fromArray($data);
        $process->addToElectronicPaymentUsageIndicators(true);

        $this->assertSame([true, false, true], $process->getElectronicPaymentUsageIndicators());
        $this->assertSame(
            [['_' => true], ['_' => false], ['_' => true]],
            $process->toArray()['ElectronicPaymentUsageIndicator']
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function versionsWithAListOfBooleans(): array
    {
        return ['2.2' => ['2.2'], '2.3' => ['2.3'], '2.4' => ['2.4'], '2.5' => ['2.5']];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function at(array $data, string ...$keys): mixed
    {
        $value = $data;
        foreach ($keys as $key) {
            $this->assertIsArray($value);
            $this->assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(object $document): array
    {
        $this->assertTrue(method_exists($document, 'toArray'));

        return $document->toArray();
    }
}
