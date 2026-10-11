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
use Derafu\Ubl\Oasis\Cac\MonetaryTotal;
use Derafu\Ubl\Oasis\Cac\QuotationLine;
use Derafu\Ubl\Oasis\Cac\SupplierParty;
use Derafu\Ubl\Oasis\Cbc\Amount;
use Derafu\Ubl\Oasis\Cbc\Identifier;
use Derafu\Ubl\Oasis\Doc\Quotation;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Xterr\UBL\Xml\XmlSerializer;

#[CoversClass(Quotation::class)]
#[UsesClass(Amount::class)]
#[UsesClass(Identifier::class)]
#[UsesClass(MonetaryTotal::class)]
#[UsesClass(QuotationLine::class)]
#[UsesClass(SupplierParty::class)]
final class QuotationTest extends TestCase
{
    #[Test]
    public function settingQuotationLinesWithTheRightTypeWorks(): void
    {
        $quotation = (new Quotation())->setQuotationLines([new QuotationLine()]);

        $this->assertCount(1, $quotation->getQuotationLines());
    }

    #[Test]
    public function settingQuotationLinesWithTheWrongTypeThrows(): void
    {
        // Through reflection, as a deserializer would call it: nothing upstream can declare
        // the array's type then, which is exactly what the check below has to catch.
        $setQuotationLines = new ReflectionMethod(Quotation::class, 'setQuotationLines');

        $this->expectException(InvalidArgumentException::class);

        $setQuotationLines->invoke(new Quotation(), ['not a QuotationLine']);
    }

    #[Test]
    public function issueTimeSerializesAsATimeNotADate(): void
    {
        $quotation = (new Quotation())
            ->setIssueDate(new DateTimeImmutable('2026-10-10'))
            ->setIssueTime(new DateTimeImmutable('2026-10-10 11:22:33+00:00'))
            ->setSellerSupplierParty(new SupplierParty())
            ->setQuotedMonetaryTotal(
                (new MonetaryTotal())->setPayableAmount((new Amount())->setValue('1'))
            );

        $xml = (new XmlSerializer())->serialize($quotation);

        $this->assertStringContainsString('<cbc:IssueTime>11:22:33+00:00</cbc:IssueTime>', $xml);
    }
}
