<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsUbl\Trait;

use DateTimeImmutable;
use Derafu\Ubl\Oasis\Cac\MonetaryTotal;
use Derafu\Ubl\Oasis\Cac\QuotationLine;
use Derafu\Ubl\Oasis\Cac\SupplierParty;
use Derafu\Ubl\Oasis\Cbc\Amount;
use Derafu\Ubl\Oasis\Cbc\Identifier;
use Derafu\Ubl\Oasis\Doc\Quotation;
use Derafu\Ubl\Trait\ArraySerializableTrait;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversTrait(ArraySerializableTrait::class)]
#[UsesClass(Amount::class)]
#[UsesClass(Identifier::class)]
#[UsesClass(MonetaryTotal::class)]
#[UsesClass(QuotationLine::class)]
#[UsesClass(Quotation::class)]
#[UsesClass(SupplierParty::class)]
final class ArraySerializableTraitTest extends TestCase
{
    /**
     * The base case of the "value with attributes" shape: text plus one XML attribute.
     */
    #[Test]
    public function aLeafWithAnAttributeKeepsItUnderAtAttributes(): void
    {
        $amount = (new Amount())->setValue('100')->setCurrencyID('CLP');

        $this->assertSame(
            ['@value' => '100', '@attributes' => ['currencyID' => 'CLP']],
            $amount->toArray()
        );
    }

    /**
     * Called on its own (not embedded in a parent), toArray() never simplifies to a bare
     * scalar: that is a decision the embedding parent makes, not the leaf itself.
     */
    #[Test]
    public function aLeafWithoutAnAttributeIsStillTheFullShapeOnItsOwn(): void
    {
        $id = (new Identifier())->setValue('Q-1');

        $this->assertSame(['@value' => 'Q-1', '@attributes' => []], $id->toArray());
    }

    /**
     * Four things at once: keys are the UBL names, not the PHP property names; an
     * attributeless leaf (ID) IS simplified here, unlike called on its own above; a leaf with
     * an attribute (PayableAmount) keeps its full shape even nested; a list exports as an
     * array of its items.
     */
    #[Test]
    public function anAggregateIsKeyedByTheUblNamesAndSimplifiesAnAttributelessLeaf(): void
    {
        $quotation = $this->quotation();

        $this->assertSame([
            'ID' => 'Q-1',
            'IssueDate' => '2026-10-10',
            'QuotedMonetaryTotal' => [
                'PayableAmount' => ['@value' => '100', '@attributes' => ['currencyID' => 'CLP']],
            ],
            'QuotationLine' => [[]],
        ], $quotation->toArray());
    }

    /**
     * Not just that jsonSerialize() delegates to toArray(): that json_encode() actually calls
     * it, because the class implements JsonSerializable - the wiring, not only the method.
     */
    #[Test]
    public function jsonSerializeIsTheSameShapeAsToArray(): void
    {
        $quotation = $this->quotation();

        $this->assertSame($quotation->toArray(), $quotation->jsonSerialize());
        $this->assertJsonStringEqualsJsonString(
            (string) json_encode($quotation->toArray()),
            (string) json_encode($quotation)
        );
    }

    /**
     * toArray() and fromArray() have to be true inverses, not just each do something on its
     * own: round-tripping a realistic Quotation must not lose or change any data.
     */
    #[Test]
    public function fromArrayRebuildsAnEquivalentObject(): void
    {
        $quotation = $this->quotation();

        $this->assertSame($quotation->toArray(), Quotation::fromArray($quotation->toArray())->toArray());
    }

    /**
     * toArray() simplifies an attributeless leaf to a bare scalar (see above), so fromArray()
     * has to accept that same simplified shape back, not only the full one.
     */
    #[Test]
    public function fromArrayAcceptsAPlainScalarForAnAttributelessLeaf(): void
    {
        $quotation = Quotation::fromArray(['ID' => 'Q-1']);

        $this->assertSame('Q-1', $quotation->getId()?->getValue());
    }

    /**
     * QuotationLine::$lineItem is required (no default, not nullable): never setting it leaves
     * it uninitialized, not null. toArray() must not try to read it directly.
     */
    #[Test]
    public function aRequiredPropertyNeverSetIsReportedAsAbsentNotAFatalError(): void
    {
        $line = new QuotationLine();

        $this->assertSame([], $line->toArray());
    }

    private function quotation(): Quotation
    {
        return (new Quotation())
            ->setId((new Identifier())->setValue('Q-1'))
            ->setIssueDate(new DateTimeImmutable('2026-10-10'))
            ->setQuotedMonetaryTotal(
                (new MonetaryTotal())->setPayableAmount((new Amount())->setValue('100')->setCurrencyID('CLP'))
            )
            ->setQuotationLines([new QuotationLine()]);
    }
}
