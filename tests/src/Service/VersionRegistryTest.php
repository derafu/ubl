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

use Derafu\Ubl\Exception\UnsupportedVersionException;
use Derafu\Ubl\Service\Version;
use Derafu\Ubl\Service\VersionRegistry;
use Derafu\Ubl\V22\Oasis\Doc\Invoice as Invoice22;
use Derafu\Ubl\V25\Oasis\Doc\Invoice as Invoice25;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(VersionRegistry::class)]
#[UsesClass(Version::class)]
#[UsesClass(UnsupportedVersionException::class)]
final class VersionRegistryTest extends TestCase
{
    #[Test]
    public function theDefaultVersionIsTheOneOfPeppolBis(): void
    {
        $registry = new VersionRegistry();

        $this->assertSame('2.1', VersionRegistry::DEFAULT);
        $this->assertSame('2.1', $registry->getDefault()->getId());
        $this->assertSame('2.1', $registry->get()->getId());
    }

    #[Test]
    public function theDefaultVersionCanBeChanged(): void
    {
        $registry = new VersionRegistry('2.5');

        $this->assertSame('2.5', $registry->getDefault()->getId());
        $this->assertSame('2.5', $registry->get(null)->getId());
        $this->assertSame('2.1', $registry->get('2.1')->getId());
    }

    #[Test]
    public function aVersionIsTheSameObjectEveryTime(): void
    {
        $registry = new VersionRegistry();

        $this->assertSame($registry->get('2.3'), $registry->get('2.3'));
    }

    #[Test]
    public function everySupportedVersionIsAvailableInOrder(): void
    {
        $ids = array_map(
            static fn ($version): string => $version->getId(),
            (new VersionRegistry())->all()
        );

        $this->assertSame(['2.1', '2.2', '2.3', '2.4', '2.5'], $ids);
        $this->assertSame(VersionRegistry::SUPPORTED, $ids);
    }

    #[Test]
    public function aVersionThatIsNotSupportedThrows(): void
    {
        $this->expectException(UnsupportedVersionException::class);
        $this->expectExceptionMessage('2.9');

        (new VersionRegistry())->get('2.9');
    }

    #[Test]
    public function aVersionThatTheRegistryWasNotGivenThrows(): void
    {
        $registry = new VersionRegistry('2.1', ['2.1', '2.2']);

        $this->assertSame('2.2', $registry->get('2.2')->getId());

        $this->expectException(UnsupportedVersionException::class);
        $registry->get('2.3');
    }

    #[Test]
    public function aDefaultThatIsNotSupportedThrows(): void
    {
        $this->expectException(UnsupportedVersionException::class);

        new VersionRegistry('2.2', ['2.1']);
    }

    #[Test]
    public function aClassBelongsToTheVersionOfItsNamespace(): void
    {
        $registry = new VersionRegistry();

        $this->assertSame('2.2', $registry->forClass(Invoice22::class)->getId());
        $this->assertSame('2.5', $registry->forClass(Invoice25::class)->getId());
    }

    #[Test]
    public function aClassOfNoVersionThrows(): void
    {
        $this->expectException(UnsupportedVersionException::class);

        (new VersionRegistry())->forClass(stdClass::class);
    }
}
