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
use Derafu\Ubl\Exception\DeserializationException;
use Derafu\Ubl\Exception\ValidationException;
use Derafu\Ubl\Service\Deserializer;
use Derafu\Ubl\V21\Oasis\Doc\Invoice;
use Derafu\Ubl\V21\Oasis\Doc\Order;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `validate` option of `Deserializer::deserialize()`, in the three formats, against the
 * official examples (the same Invoice, in XML and in JSON) and what is derived from them.
 */
#[CoversNothing]
final class DeserializerValidationTest extends TestCase
{
    private const DIRECTORY = __DIR__ . '/../../fixtures/2.1/doc';

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function officialInvoices(): array
    {
        return [
            'xml' => ['xml', self::DIRECTORY . '/xml/Invoice/Invoice_001_official_example.xml'],
            'json' => ['json', self::DIRECTORY . '/json/Invoice/Invoice_001_official_example.json'],
        ];
    }

    #[Test]
    #[DataProvider('officialInvoices')]
    public function aValidDocumentIsDeserializedWithValidation(string $format, string $path): void
    {
        $document = (new Deserializer())->deserialize(
            (string) file_get_contents($path),
            $format,
            options: [DeserializerInterface::VALIDATE => true]
        );

        $this->assertSame('Invoice', (new \ReflectionClass($document))->getShortName());
    }

    #[Test]
    public function aValidYamlDocumentIsDeserializedWithValidation(): void
    {
        $path = glob(self::DIRECTORY . '/yaml/Invoice/*.yaml')[0] ?? '';

        $document = (new Deserializer())->deserialize(
            (string) file_get_contents($path),
            'yaml',
            options: ['validate' => true]
        );

        $this->assertSame('Invoice', (new \ReflectionClass($document))->getShortName());
    }

    #[Test]
    public function aJsonDocumentThatDoesNotValidateThrowsOnlyIfValidationIsRequested(): void
    {
        $path = self::DIRECTORY . '/json/OrderResponse/OrderResponse_001_official_example.json';
        $content = (string) file_get_contents($path);

        // Without validation it is read, since `{"_": ""}` is an empty element all the same.
        $document = (new Deserializer())->deserialize($content, 'json');
        $this->assertSame('OrderResponse', (new \ReflectionClass($document))->getShortName());

        $this->expectException(ValidationException::class);
        (new Deserializer())->deserialize($content, 'json', options: ['validate' => true]);
    }

    #[Test]
    public function anXmlDocumentThatDoesNotValidateThrows(): void
    {
        $xml = (string) file_get_contents(self::DIRECTORY . '/xml/Invoice/Invoice_001_official_example.xml');
        $xml = (string) preg_replace('#<cbc:IssueDate>.*?</cbc:IssueDate>#s', '', $xml);

        $this->expectException(ValidationException::class);

        (new Deserializer())->deserialize($xml, 'xml', options: ['validate' => true]);
    }

    #[Test]
    #[DataProvider('officialInvoices')]
    public function theClassOptionAndValidationWorkTogether(string $format, string $path): void
    {
        $content = (string) file_get_contents($path);

        $document = (new Deserializer())->deserialize($content, $format, [
            'class' => Invoice::class,
            'validate' => true,
        ]);
        $this->assertInstanceOf(Invoice::class, $document);

        $this->expectException(DeserializationException::class);
        (new Deserializer())->deserialize($content, $format, ['class' => Order::class, 'validate' => true]);
    }

    #[Test]
    public function anUnknownOptionThrows(): void
    {
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Unknown deserialization option "validte"');

        (new Deserializer())->deserialize('{}', 'json', options: ['validte' => true]);
    }
}
