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
use Derafu\Ubl\Service\Format\ArrayWireFormatTrait;
use Derafu\Ubl\Service\Format\JsonHandler;
use Derafu\Ubl\Service\Format\XmlHandler;
use Derafu\Ubl\Service\Format\YamlHandler;
use Derafu\Ubl\Service\FormatHandlerFactory;
use Derafu\Ubl\Service\Serializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
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
#[UsesTrait(ArrayWireFormatTrait::class)]
final class SerializerTest extends TestCase
{
    /**
     * JSON wraps the document's own toArray() under a single top-level key naming its type,
     * the same convention `yaml` uses so both can be auto-detected on the way back.
     */
    #[Test]
    public function serializesToJsonWrappedUnderTheDocumentTypeKey(): void
    {
        $json = (new Serializer())->serialize($this->quotation(), 'json');

        $this->assertJsonStringEqualsJsonString(
            '{"Quotation":{"ID":"1","IssueDate":"2026-01-15",'
            . '"SellerSupplierParty":{"Party":{"PartyName":[{"Name":"Acme Corp"}]}},'
            . '"QuotedMonetaryTotal":{"PayableAmount":{"@value":"100","@attributes":{"currencyID":"EUR"}}},'
            . '"QuotationLine":[{"ID":"1","LineItem":{"ID":"1",'
            . '"Price":{"PriceAmount":{"@value":"100","@attributes":{"currencyID":"EUR"}}},'
            . '"Item":{"Name":"Widget"}}}]}}',
            $json
        );
    }

    /**
     * Same wrapping convention as JSON, in YAML's own syntax.
     */
    #[Test]
    public function serializesToYamlWrappedUnderTheDocumentTypeKey(): void
    {
        $yaml = (new Serializer())->serialize($this->quotation(), 'yaml');

        $this->assertStringStartsWith("Quotation:\n", $yaml);
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
