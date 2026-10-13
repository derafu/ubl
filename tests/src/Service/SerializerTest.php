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

use DateTimeImmutable;
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
use Derafu\Ubl\Oasis\Doc\Quotation;
use Derafu\Ubl\Oasis\Xml\DocumentRegistry;
use Derafu\Ubl\Service\ExtensionContentCodecs;
use Derafu\Ubl\Service\Format\ArrayFormatHandler;
use Derafu\Ubl\Service\Format\EmptyObjects;
use Derafu\Ubl\Service\Format\JsonHandler;
use Derafu\Ubl\Service\Format\XmlHandler;
use Derafu\Ubl\Service\Format\YamlHandler;
use Derafu\Ubl\Service\FormatHandlerFactory;
use Derafu\Ubl\Service\Normalizer;
use Derafu\Ubl\Service\Serializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Serializer::class)]
#[UsesClass(Amount::class)]
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
#[UsesClass(SupplierParty::class)]
#[UsesClass(Text::class)]
#[UsesClass(XmlHandler::class)]
#[UsesClass(YamlHandler::class)]
#[UsesClass(ArrayFormatHandler::class)]
#[UsesClass(EmptyObjects::class)]
#[UsesClass(ExtensionContentCodecs::class)]
#[UsesClass(Normalizer::class)]
#[UsesClass(DocumentRegistry::class)]
final class SerializerTest extends TestCase
{
    /**
     * JSON is the model-based representation of the OASIS note: the apex with the namespaces
     * (`_D`, `_A`, `_B`) and the property named as the document, whose leaves are objects with
     * their content under `_` (numbers as JSON numbers) and their attributes next to it.
     */
    #[Test]
    public function serializesToJsonInTheModelBasedRepresentationOfTheOasisNote(): void
    {
        $json = (new Serializer())->serialize($this->quotation(), 'json');

        $this->assertJsonStringEqualsJsonString(
            '{"_D":"urn:oasis:names:specification:ubl:schema:xsd:Quotation-2",'
            . '"_A":"urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2",'
            . '"_B":"urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2",'
            . '"Quotation":{"ID":{"_":"1"},"IssueDate":{"_":"2026-01-15"},'
            . '"SellerSupplierParty":{"Party":{"PartyName":[{"Name":{"_":"Acme Corp"}}]}},'
            . '"QuotedMonetaryTotal":{"PayableAmount":{"_":100,"currencyID":"EUR"}},'
            . '"QuotationLine":[{"ID":{"_":"1"},"LineItem":{"ID":{"_":"1"},'
            . '"Price":{"PriceAmount":{"_":100,"currencyID":"EUR"}},'
            . '"Item":{"Name":{"_":"Widget"}}}}]}}',
            $json
        );
    }

    /**
     * The same structure as JSON, in YAML's own syntax.
     */
    #[Test]
    public function serializesToYamlWithTheSameStructureAsJson(): void
    {
        $yaml = (new Serializer())->serialize($this->quotation(), 'yaml');

        $this->assertStringStartsWith("_D: 'urn:oasis:names:specification:ubl:schema:xsd:Quotation-2'\n", $yaml);
        $this->assertStringContainsString("\nQuotation:\n", $yaml);
        $this->assertStringContainsString("  SellerSupplierParty:\n", $yaml);
        $this->assertStringContainsString("currencyID: EUR\n", $yaml);
    }

    /**
     * XML delegates to `Xterr\UBL\Xml\XmlSerializer`, never exposed outside `Format\XmlHandler`:
     * this only checks the UBL root element and namespace are the real, official ones.
     */
    #[Test]
    public function serializesToXmlWithTheUblRootElement(): void
    {
        $xml = (new Serializer())->serialize($this->quotation(), 'xml');

        $this->assertStringContainsString(
            '<Quotation xmlns="urn:oasis:names:specification:ubl:schema:xsd:Quotation-2"',
            $xml
        );
        $this->assertStringContainsString('<cbc:ID>1</cbc:ID>', $xml);
    }

    #[Test]
    public function anUnregisteredFormatThrows(): void
    {
        $this->expectException(UnsupportedFormatException::class);

        (new Serializer())->serialize($this->quotation(), 'toml');
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
