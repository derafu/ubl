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
use Derafu\Ubl\Exception\ValidationException;
use Derafu\Ubl\Oasis\Cac\Item;
use Derafu\Ubl\Oasis\Cac\LineItem;
use Derafu\Ubl\Oasis\Cac\MonetaryTotal;
use Derafu\Ubl\Oasis\Cac\Party;
use Derafu\Ubl\Oasis\Cac\PartyName;
use Derafu\Ubl\Oasis\Cac\QuotationLine;
use Derafu\Ubl\Oasis\Cac\SupplierParty;
use Derafu\Ubl\Oasis\Cbc\Amount;
use Derafu\Ubl\Oasis\Cbc\Identifier;
use Derafu\Ubl\Oasis\Cbc\Text;
use Derafu\Ubl\Oasis\Doc\Quotation;
use Derafu\Ubl\Oasis\Xml\DocumentRegistry;
use Derafu\Ubl\Service\ExtensionContentCodecs;
use Derafu\Ubl\Service\Format\XmlHandler;
use Derafu\Ubl\Service\FormatHandlerFactory;
use Derafu\Ubl\Service\RootElementResolver;
use Derafu\Ubl\Service\Serializer;
use Derafu\Ubl\Service\XmlSchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(XmlSchemaValidator::class)]
#[UsesClass(Amount::class)]
#[UsesClass(DocumentRegistry::class)]
#[UsesClass(FormatHandlerFactory::class)]
#[UsesClass(Identifier::class)]
#[UsesClass(Item::class)]
#[UsesClass(LineItem::class)]
#[UsesClass(MonetaryTotal::class)]
#[UsesClass(Party::class)]
#[UsesClass(PartyName::class)]
#[UsesClass(Quotation::class)]
#[UsesClass(QuotationLine::class)]
#[UsesClass(RootElementResolver::class)]
#[UsesClass(Serializer::class)]
#[UsesClass(SupplierParty::class)]
#[UsesClass(Text::class)]
#[UsesClass(ValidationException::class)]
#[UsesClass(XmlHandler::class)]
#[UsesClass(ExtensionContentCodecs::class)]
final class XmlSchemaValidatorTest extends TestCase
{
    /**
     * QuotationLine is required (at least one), so this document - missing it entirely - is
     * well-formed XML but not a valid Quotation: a case the generated code's own required-field
     * guards never catch, since those only run when building the object, not against the real
     * XSD's full structural rules (sequence, cardinality) at once.
     */
    #[Test]
    public function aWellFormedButIncompleteDocumentThrows(): void
    {
        $xml = (new Serializer())->serialize($this->quotationWithNoLines(), 'xml');

        $this->expectException(ValidationException::class);

        (new XmlSchemaValidator())->validate($xml);
    }

    #[Test]
    public function aCompleteDocumentValidates(): void
    {
        $xml = (new Serializer())->serialize($this->quotation(), 'xml');

        (new XmlSchemaValidator())->validate($xml);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function malformedXmlThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new XmlSchemaValidator())->validate('not xml at all <<<');
    }

    #[Test]
    public function anUnknownXmlRootElementThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new XmlSchemaValidator())->validate('<Bogus xmlns="urn:x"/>');
    }

    /**
     * The failing validation exposes the real libxml errors, not just a flat message: a
     * consumer can inspect line/column/code for each, not only read prose.
     */
    #[Test]
    public function theExceptionExposesTheStructuredLibxmlErrors(): void
    {
        $xml = (new Serializer())->serialize($this->quotationWithNoLines(), 'xml');

        try {
            (new XmlSchemaValidator())->validate($xml);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->getErrors());
        }
    }

    private function quotation(): Quotation
    {
        return $this->quotationWithNoLines()->setQuotationLines([
            (new QuotationLine())
                ->setId((new Identifier())->setValue('1'))
                ->setLineItem(
                    (new LineItem())
                        ->setId((new Identifier())->setValue('1'))
                        ->setItem(new Item())
                ),
        ]);
    }

    private function quotationWithNoLines(): Quotation
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
            );
    }
}
