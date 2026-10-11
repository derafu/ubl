<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsUbl\Translation;

use Derafu\Translation\Lint\TranslationAudit;
use Derafu\Ubl\Translation\UblTranslationResourceProvider;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The package is translated: every message has its Spanish translation, the
 * catalogue has nothing the code does not use, every message can be checked by
 * reading the code, and every exception the package throws by hand is
 * translatable.
 *
 * `src/Oasis` is generated and patched, never hand-written: its `validate*()`
 * helpers throw a plain `\InvalidArgumentException`, matching the generator's
 * own output, not Derafu's translatable exceptions. That is allowed here by
 * name, the same way any package allows an exception of a dependency that must
 * be thrown as it is.
 */
#[CoversClass(UblTranslationResourceProvider::class)]
final class UblMessagesTest extends TestCase
{
    public function testThePackageIsTranslated(): void
    {
        $report = (new TranslationAudit())->audit(
            dirname(__DIR__, 3) . '/src',
            new UblTranslationResourceProvider(),
            allowedThrowables: [InvalidArgumentException::class]
        );

        $this->assertSame([], $report->describe($report->dynamicMessages));
        $this->assertSame([], $report->describe($report->missingTranslations));
        $this->assertSame([], $report->describe($report->notUsedBySources));
        $this->assertSame([], $report->describe($report->notTranslatable));
    }
}
