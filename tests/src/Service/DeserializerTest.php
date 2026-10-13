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

use ArrayObject;
use DateTimeImmutable;
use Derafu\Ubl\Contract\NormalizerInterface;
use Derafu\Ubl\Exception\DeserializationException;
use Derafu\Ubl\Exception\UnsupportedFormatException;
use Derafu\Ubl\Oasis\Cac\Item;
use Derafu\Ubl\Oasis\Cac\LineItem;
use Derafu\Ubl\Oasis\Cac\MonetaryTotal;
use Derafu\Ubl\Oasis\Cac\Party;
use Derafu\Ubl\Oasis\Cac\PartyName;
use Derafu\Ubl\Oasis\Cac\Price;
use Derafu\Ubl\Oasis\Cac\QuotationLine;
use Derafu\Ubl\Oasis\Cac\SupplierParty;
use Derafu\Ubl\Oasis\Cbc\Amount;
use Derafu\Ubl\Oasis\Cbc\Identifier;
use Derafu\Ubl\Oasis\Cbc\Text;
use Derafu\Ubl\Oasis\Doc\Order;
use Derafu\Ubl\Oasis\Doc\Quotation;
use Derafu\Ubl\Oasis\Xml\DocumentRegistry;
use Derafu\Ubl\Service\Deserializer;
use Derafu\Ubl\Service\ExtensionContentCodecs;
use Derafu\Ubl\Service\Format\ArrayFormatHandler;
use Derafu\Ubl\Service\Format\EmptyObjects;
use Derafu\Ubl\Service\Format\JsonHandler;
use Derafu\Ubl\Service\Format\XmlHandler;
use Derafu\Ubl\Service\Format\YamlHandler;
use Derafu\Ubl\Service\FormatHandlerFactory;
use Derafu\Ubl\Service\Normalizer;
use Derafu\Ubl\Service\RootElementResolver;
use Derafu\Ubl\Service\Serializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(Deserializer::class)]
#[UsesClass(Amount::class)]
#[UsesClass(DocumentRegistry::class)]
#[UsesClass(FormatHandlerFactory::class)]
#[UsesClass(Identifier::class)]
#[UsesClass(Item::class)]
#[UsesClass(JsonHandler::class)]
#[UsesClass(LineItem::class)]
#[UsesClass(MonetaryTotal::class)]
#[UsesClass(Party::class)]
#[UsesClass(PartyName::class)]
#[UsesClass(Price::class)]
#[UsesClass(Quotation::class)]
#[UsesClass(QuotationLine::class)]
#[UsesClass(RootElementResolver::class)]
#[UsesClass(Serializer::class)]
#[UsesClass(SupplierParty::class)]
#[UsesClass(XmlHandler::class)]
#[UsesClass(YamlHandler::class)]
#[UsesClass(ArrayFormatHandler::class)]
#[UsesClass(EmptyObjects::class)]
#[UsesClass(ExtensionContentCodecs::class)]
#[UsesClass(Normalizer::class)]
#[UsesClass(Text::class)]
final class DeserializerTest extends TestCase
{
    /**
     * Content type is auto-detected in every format: JSON/YAML from their single top-level
     * key, XML from its root element - `serialize()` and `deserialize()` are true inverses for
     * all three without ever passing a class.
     */
    #[Test]
    public function roundTripsThroughEveryFormatWithoutAnExplicitClass(): void
    {
        $serializer = new Serializer();
        $deserializer = new Deserializer();
        $quotation = $this->quotation();

        foreach (['json', 'yaml', 'xml'] as $format) {
            $content = $serializer->serialize($quotation, $format);
            $result = $deserializer->deserialize($content, $format);

            $this->assertInstanceOf(Quotation::class, $result);
            $this->assertSame($quotation->toArray(), $result->toArray());
        }
    }

    /**
     * The `class` option is an assertion: the type is always resolved from the content, and if
     * it is not the one that is asked for, it throws. In every format.
     */
    #[Test]
    public function theClassOptionIsCheckedInEveryFormat(): void
    {
        $serializer = new Serializer();
        $deserializer = new Deserializer();

        foreach (['json', 'yaml', 'xml'] as $format) {
            $content = $serializer->serialize($this->quotation(), $format);

            $result = $deserializer->deserialize($content, $format, ['class' => Quotation::class]);
            $this->assertInstanceOf(Quotation::class, $result);
            $this->assertSame($this->quotation()->toArray(), $result->toArray());

            try {
                $deserializer->deserialize($content, $format, ['class' => Order::class]);
                $this->fail("A Quotation must not be accepted as an Order ($format).");
            } catch (DeserializationException $e) {
                $this->assertStringContainsString(Quotation::class, $e->getMessage());
                $this->assertStringContainsString(Order::class, $e->getMessage());
            }
        }
    }

    /**
     * The decoded data goes through the normalizer before `fromArray()`, with the content of
     * the document key and the class it is going to be built as.
     */
    #[Test]
    public function theDataGoesThroughTheNormalizerBeforeFromArray(): void
    {
        $seen = new ArrayObject();
        $normalizer = new class ($seen) implements NormalizerInterface {
            /**
             * @param ArrayObject<int, string> $seen
             */
            public function __construct(private readonly ArrayObject $seen)
            {
            }

            public function normalize(array $data, string $class): array
            {
                $this->seen->append($class);
                // Stands for what a real normalizer would do: here, a bare scalar to an object.
                $data['ID'] = ['_' => $data['ID']];

                return $data;
            }
        };

        $deserializer = new Deserializer(new FormatHandlerFactory([], $normalizer));
        $result = $deserializer->deserialize('{"Quotation":{"ID":"Q-9"}}', 'json');

        $this->assertSame([Quotation::class], $seen->getArrayCopy());
        $this->assertInstanceOf(Quotation::class, $result);
        $this->assertSame('Q-9', $result->getId()?->getValue());
    }

    /**
     * The properties of the apex that start with `_` (`_D`, `_A`, `_B`, `_E`) are documentary:
     * the document is the only other one.
     */
    #[Test]
    public function theNamespacesOfTheApexAreIgnored(): void
    {
        $result = (new Deserializer())->deserialize(
            '{"_D":"x","_A":"y","_B":"z","Quotation":{"ID":{"_":"Q-9"}}}',
            'json'
        );

        $this->assertInstanceOf(Quotation::class, $result);
        $this->assertSame('Q-9', $result->getId()?->getValue());
    }

    #[Test]
    public function aSecondDocumentKeyThrows(): void
    {
        $this->expectException(DeserializationException::class);

        (new Deserializer())->deserialize('{"Quotation":{},"Order":{}}', 'json');
    }

    #[Test]
    public function anUnregisteredFormatThrows(): void
    {
        $this->expectException(UnsupportedFormatException::class);

        (new Deserializer())->deserialize('{}', 'toml');
    }

    #[Test]
    public function malformedJsonThrows(): void
    {
        $this->expectException(DeserializationException::class);

        (new Deserializer())->deserialize('{not valid json', 'json');
    }

    #[Test]
    public function malformedXmlThrows(): void
    {
        $this->expectException(DeserializationException::class);

        (new Deserializer())->deserialize('not xml at all <<<', 'xml');
    }

    #[Test]
    public function anUnknownDocumentTypeKeyThrows(): void
    {
        $this->expectException(DeserializationException::class);

        (new Deserializer())->deserialize(Yaml::dump(['Bogus' => []]), 'yaml');
    }

    #[Test]
    public function anUnknownXmlRootElementThrows(): void
    {
        $this->expectException(DeserializationException::class);

        (new Deserializer())->deserialize('<Bogus xmlns="urn:x"/>', 'xml');
    }

    private function quotation(): Quotation
    {
        return (new Quotation())
            ->setId((new Identifier())->setValue('1'))
            ->setIssueDate(new DateTimeImmutable('2026-01-15'))
            ->setSellerSupplierParty(
                (new SupplierParty())->setParty(
                    (new Party())->setPartyNames([
                        (new PartyName())->setName((new Text())->setValue('Acme Corp')),
                    ])
                )
            )
            ->setQuotedMonetaryTotal(
                (new MonetaryTotal())->setPayableAmount(
                    (new Amount())->setValue('100')->setCurrencyID('EUR')
                )
            )
            ->setQuotationLines([
                (new QuotationLine())
                    ->setId((new Identifier())->setValue('1'))
                    ->setLineItem(
                        (new LineItem())
                            ->setId((new Identifier())->setValue('1'))
                            ->setItem((new Item())->setName((new Text())->setValue('Widget')))
                            ->setPrice(
                                (new Price())->setPriceAmount(
                                    (new Amount())->setValue('100')->setCurrencyID('EUR')
                                )
                            )
                    ),
            ]);
    }
}
