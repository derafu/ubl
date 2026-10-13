<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsUbl\Oasis;

use DateTimeImmutable;
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
use Derafu\Ubl\Oasis\Ext\ExtensionContent;
use Derafu\Ubl\Oasis\Ext\UBLExtension;
use Derafu\Ubl\Oasis\Ext\UBLExtensions;
use Derafu\Ubl\Oasis\Xml\DocumentRegistry;
use Derafu\Ubl\Service\Deserializer;
use Derafu\Ubl\Service\ExtensionContentCodecs;
use Derafu\Ubl\Service\Format\EmptyObjects;
use Derafu\Ubl\Service\Format\JsonHandler;
use Derafu\Ubl\Service\Format\XmlHandler;
use Derafu\Ubl\Service\Format\YamlHandler;
use Derafu\Ubl\Service\FormatHandlerFactory;
use Derafu\Ubl\Service\Normalizer;
use Derafu\Ubl\Service\RootElementResolver;
use Derafu\Ubl\Service\Serializer;
use Derafu\Ubl\Service\XmlSchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * `ext:ExtensionContent` is UBL's generic mechanism for attaching content this package's own
 * model does not define (a signature, a Peppol extension, a consumer's own custom field) to
 * any document, via a real XSD wildcard (`xsd:any namespace="##other"`). These tests prove the
 * whole path works for a consumer adding their own field this way - not just that it serializes
 * to XML, but that it survives every format this package supports and still validates against
 * the real OASIS XSD, the same bar `FixtureRoundTripTest` holds every other field to.
 */
#[CoversClass(ExtensionContent::class)]
#[UsesClass(Amount::class)]
#[UsesClass(Deserializer::class)]
#[UsesClass(DocumentRegistry::class)]
#[UsesClass(FormatHandlerFactory::class)]
#[UsesClass(Identifier::class)]
#[UsesClass(Item::class)]
#[UsesClass(JsonHandler::class)]
#[UsesClass(EmptyObjects::class)]
#[UsesClass(LineItem::class)]
#[UsesClass(MonetaryTotal::class)]
#[UsesClass(Party::class)]
#[UsesClass(PartyName::class)]
#[UsesClass(Quotation::class)]
#[UsesClass(QuotationLine::class)]
#[UsesClass(RootElementResolver::class)]
#[UsesClass(XmlSchemaValidator::class)]
#[UsesClass(Serializer::class)]
#[UsesClass(SupplierParty::class)]
#[UsesClass(Text::class)]
#[UsesClass(UBLExtension::class)]
#[UsesClass(UBLExtensions::class)]
#[UsesClass(XmlHandler::class)]
#[UsesClass(YamlHandler::class)]
#[UsesClass(ExtensionContentCodecs::class)]
#[UsesClass(Normalizer::class)]
final class UblExtensionsTest extends TestCase
{
    /**
     * The array/json/yaml path (`ArraySerializableTrait`, not `Xterr\UBL`) for a wildcard
     * property: a plain list of raw fragment strings in, the same list out - no XML involved.
     */
    #[Test]
    public function extensionContentRoundTripsAsAPlainArrayOfFragments(): void
    {
        $fragment = '<BusinessActivity xmlns="urn:libredte:extensions:business-activity">'
            . 'Venta de software y servicios TI</BusinessActivity>';

        $content = (new ExtensionContent())->setAny([$fragment]);

        $this->assertSame(['any' => [$fragment]], $content->toArray());
        $this->assertSame([$fragment], ExtensionContent::fromArray(['any' => [$fragment]])->getAny());
    }

    /**
     * The real case this exists for: a consumer adding a field UBL does not define (here,
     * `BusinessActivity`, the Chilean "giro", which has no clean equivalent anywhere in UBL's
     * own vocabulary) to a real document, and that document still being a genuinely valid
     * Quotation - not just well-formed XML, checked against the real bundled XSD, the same
     * schema a real UBL consumer would validate against.
     */
    #[Test]
    public function aCustomBusinessActivityExtensionValidatesAgainstTheRealSchema(): void
    {
        $xml = (new Serializer())->serialize($this->quotationWithBusinessActivity(), 'xml');

        $this->assertStringContainsString(
            '<BusinessActivity xmlns="urn:libredte:extensions:business-activity">'
            . 'Venta de software y servicios TI</BusinessActivity>',
            $xml
        );

        (new XmlSchemaValidator())->validate($xml);

        $this->addToAssertionCount(1);
    }

    /**
     * The extension is not just written once: it comes back out exactly as it went in, through
     * every format this package supports in one hop (xml -> json -> yaml -> xml), the same
     * format-hopping guarantee `FixtureRoundTripTest`'s chain test gives every other field.
     */
    #[Test]
    public function aCustomBusinessActivityExtensionSurvivesEveryFormatHop(): void
    {
        $serializer = new Serializer();
        $deserializer = new Deserializer();

        $originalXml = $serializer->serialize($this->quotationWithBusinessActivity(), 'xml');

        $fromJson = $deserializer->deserialize($serializer->serialize(
            $deserializer->deserialize($originalXml, 'xml'),
            'json'
        ), 'json');
        $fromYaml = $deserializer->deserialize($serializer->serialize($fromJson, 'yaml'), 'yaml');
        $finalXml = $serializer->serialize($fromYaml, 'xml');

        (new XmlSchemaValidator())->validate($finalXml);

        $final = $deserializer->deserialize($finalXml, 'xml');
        $this->assertInstanceOf(Quotation::class, $final);

        $this->assertSame($this->quotationWithBusinessActivity()->toArray(), $final->toArray());
    }

    private function quotationWithBusinessActivity(): Quotation
    {
        $extension = (new UBLExtension())
            ->setName((new Text())->setValue('BusinessActivity'))
            ->setExtensionContent(
                (new ExtensionContent())->setAny([
                    '<BusinessActivity xmlns="urn:libredte:extensions:business-activity">'
                    . 'Venta de software y servicios TI</BusinessActivity>',
                ])
            );

        return (new Quotation())
            ->setUblExtensions((new UBLExtensions())->setUblExtensions([$extension]))
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
                            ->setItem(new Item())
                    ),
            ]);
    }
}
