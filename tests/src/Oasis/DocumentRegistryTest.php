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

use Derafu\Ubl\Oasis\Doc\Quotation;
use Derafu\Ubl\Oasis\Xml\DocumentRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocumentRegistry::class)]
#[UsesClass(Quotation::class)]
final class DocumentRegistryTest extends TestCase
{
    #[Test]
    public function classForResolvesTheClassOfAKnownRootElement(): void
    {
        $this->assertSame(
            Quotation::class,
            DocumentRegistry::classFor('urn:oasis:names:specification:ubl:schema:xsd:Quotation-2', 'Quotation')
        );
    }

    #[Test]
    public function classForReturnsNullForAnUnknownElement(): void
    {
        $this->assertNull(DocumentRegistry::classFor('urn:example:nothing', 'Nothing'));
    }

    #[Test]
    public function rootElementForResolvesTheRootElementOfAKnownClass(): void
    {
        $this->assertSame(
            [
                'namespace' => 'urn:oasis:names:specification:ubl:schema:xsd:Quotation-2',
                'localName' => 'Quotation',
            ],
            DocumentRegistry::rootElementFor(Quotation::class)
        );
    }

    #[Test]
    public function rootElementForReturnsNullForAClassThatIsNotADocumentRoot(): void
    {
        $this->assertNull(DocumentRegistry::rootElementFor(self::class));
    }
}
