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
use Derafu\Ubl\Exception\DeserializationException;
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
     * The base case of the model-based representation of the OASIS note: the content under
     * `_` and the XML attributes next to it. Amounts are JSON numbers.
     */
    #[Test]
    public function aLeafWithAnAttributeKeepsItNextToItsContent(): void
    {
        $amount = (new Amount())->setValue('100')->setCurrencyID('CLP');

        $this->assertSame(['_' => 100, 'currencyID' => 'CLP'], $amount->toArray());
    }

    /**
     * A leaf is always an object, even with no attribute: never a bare scalar.
     */
    #[Test]
    public function aLeafWithoutAnAttributeIsStillAnObject(): void
    {
        $id = (new Identifier())->setValue('Q-1');

        $this->assertSame(['_' => 'Q-1'], $id->toArray());
    }

    /**
     * Only the content of `Amount`, `Quantity`, `Measure` and `Numeric` is a JSON number: the
     * decimal text becomes an int when it is whole, a float otherwise, and a text that is not
     * a number stays as it is. Any other content, even if it looks like a number, is a string.
     */
    #[Test]
    public function onlyNumericContentsAreNumbers(): void
    {
        $this->assertSame(['_' => 100.5, 'currencyID' => 'CLP'], (new Amount())->setValue('100.50')->setCurrencyID('CLP')->toArray());
        $this->assertSame(['_' => 100.0, 'currencyID' => 'CLP'], (new Amount())->setValue('100.00')->setCurrencyID('CLP')->toArray());
        $this->assertSame(['_' => 'abc', 'currencyID' => 'CLP'], (new Amount())->setValue('abc')->setCurrencyID('CLP')->toArray());
        $this->assertSame(['_' => '123'], (new Identifier())->setValue('123')->toArray());
    }

    /**
     * Keys are the UBL names, not the PHP property names; every leaf is an object (the date
     * too, a `DateTimeImmutable` that has no class of its own); an aggregate that is bounded to
     * one is the object itself and a list (unbounded) is an array of them.
     */
    #[Test]
    public function anAggregateIsKeyedByTheUblNamesAndEveryLeafIsAnObject(): void
    {
        $quotation = $this->quotation();

        $this->assertSame([
            'ID' => ['_' => 'Q-1'],
            'IssueDate' => ['_' => '2026-10-10'],
            'QuotedMonetaryTotal' => [
                'PayableAmount' => ['_' => 100, 'currencyID' => 'CLP'],
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
     * `fromArray()` only reads the shape of the note: what asks for an object is not given a
     * bare scalar (turning other shapes into this one is `Service\Normalizer`'s job, not its).
     */
    #[Test]
    public function fromArrayDoesNotAcceptAPlainScalarForALeaf(): void
    {
        $this->expectException(DeserializationException::class);

        Quotation::fromArray(['ID' => 'Q-1']);
    }

    #[Test]
    public function fromArrayDoesNotAcceptAnArrayWhereAnObjectIsExpected(): void
    {
        $this->expectException(DeserializationException::class);

        // The legacy-based form of the note, where everything is an array.
        Quotation::fromArray(['ID' => [['_' => 'Q-1']]]);
    }

    #[Test]
    public function fromArrayDoesNotAcceptAnObjectWhereAnArrayIsExpected(): void
    {
        $this->expectException(DeserializationException::class);

        Quotation::fromArray(['QuotationLine' => ['ID' => ['_' => '1']]]);
    }

    #[Test]
    public function fromArrayReadsTheNumbersAndTheContentOfALeaf(): void
    {
        $quotation = Quotation::fromArray([
            'ID' => ['_' => 'Q-1'],
            'QuotedMonetaryTotal' => ['PayableAmount' => ['_' => 100.5, 'currencyID' => 'CLP']],
        ]);

        $this->assertSame('Q-1', $quotation->getId()?->getValue());
        $this->assertSame('100.5', $quotation->getQuotedMonetaryTotal()->getPayableAmount()->getValue());
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
