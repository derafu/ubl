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

use Derafu\Ubl\Service\Normalizer;
use Derafu\Ubl\V21\Oasis\Doc\Quotation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Normalizer::class)]
final class NormalizerTest extends TestCase
{
    /**
     * It is the place where other shapes will be accepted, and for now it accepts none: what it
     * is given is what it returns.
     */
    #[Test]
    public function returnsTheSameData(): void
    {
        $data = ['ID' => ['_' => 'Q-1'], 'QuotationLine' => [[]]];

        $this->assertSame($data, (new Normalizer())->normalize($data, Quotation::class));
    }
}
