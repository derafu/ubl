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
use Derafu\Ubl\V21\Oasis\Xml\DocumentRegistry as V21DocumentRegistry;
use Derafu\Ubl\V22\Oasis\Xml\DocumentRegistry as V22DocumentRegistry;
use Derafu\Ubl\V23\Oasis\Xml\DocumentRegistry as V23DocumentRegistry;
use Derafu\Ubl\V24\Oasis\Xml\DocumentRegistry as V24DocumentRegistry;
use Derafu\Ubl\V25\Oasis\Xml\DocumentRegistry as V25DocumentRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version::class)]
#[UsesClass(UnsupportedVersionException::class)]
#[UsesClass(V21DocumentRegistry::class)]
#[UsesClass(V22DocumentRegistry::class)]
#[UsesClass(V23DocumentRegistry::class)]
#[UsesClass(V24DocumentRegistry::class)]
#[UsesClass(V25DocumentRegistry::class)]
final class VersionTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function versions(): array
    {
        $cases = [];
        foreach (VersionRegistry::SUPPORTED as $version) {
            $cases[$version] = [$version];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('versions')]
    public function everythingFollowsFromTheId(string $id): void
    {
        $version = new Version($id);

        $this->assertSame($id, $version->getId());
        $this->assertSame('Derafu\\Ubl\\V' . str_replace('.', '', $id) . '\\Oasis', $version->getNamespace());
    }

    #[Test]
    #[DataProvider('versions')]
    public function everyDocumentOfTheModelHasItsOfficialXsd(string $id): void
    {
        $version = new Version($id);

        $documents = glob(dirname(__DIR__, 2) . '/../src/V' . str_replace('.', '', $id) . '/Oasis/Doc/*.php') ?: [];
        $this->assertNotEmpty($documents);

        foreach ($documents as $path) {
            $this->assertFileExists($version->getXsdPath(basename($path, '.php')));
        }
    }

    #[Test]
    #[DataProvider('versions')]
    public function everyDocumentOfTheModelHasItsOfficialJsonSchemaWhereOasisPublishedThem(string $id): void
    {
        $version = new Version($id);

        $documents = glob(dirname(__DIR__, 2) . '/../src/V' . str_replace('.', '', $id) . '/Oasis/Doc/*.php') ?: [];
        foreach ($documents as $path) {
            $schema = $version->getJsonSchemaPath(basename($path, '.php'));

            if (in_array($id, ['2.4', '2.5'], true)) {
                $this->assertNull($schema);
                $this->assertNull($version->getJsonSchemaDirectory());
            } else {
                $this->assertNotNull($schema);
                $this->assertFileExists($schema);
            }
        }
    }

    #[Test]
    #[DataProvider('versions')]
    public function theRootElementsOfTheDocumentsAreTheSameForEveryVersion(string $id): void
    {
        $version = new Version($id);
        $uri = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

        $class = $version->classFor($uri, 'Invoice');

        $this->assertSame($version->getNamespace() . '\\Doc\\Invoice', $class);
        $this->assertSame($class, $version->getDocumentClass('Invoice'));
        $this->assertSame(['namespace' => $uri, 'localName' => 'Invoice'], $version->rootElementFor((string) $class));
    }

    #[Test]
    public function aDocumentIsOnlyOfTheVersionsThatHaveIt(): void
    {
        // The Quotation is of every version, the PurchaseReceipt was introduced by 2.4.
        $this->assertNotNull((new Version('2.1'))->getDocumentClass('Quotation'));
        $this->assertNull((new Version('2.3'))->getDocumentClass('PurchaseReceipt'));
        $this->assertNotNull((new Version('2.4'))->getDocumentClass('PurchaseReceipt'));

        $uri = 'urn:oasis:names:specification:ubl:schema:xsd:PurchaseReceipt-2';
        $this->assertNull((new Version('2.3'))->classFor($uri, 'PurchaseReceipt'));
        $this->assertNotNull((new Version('2.4'))->classFor($uri, 'PurchaseReceipt'));
    }

    #[Test]
    public function aNameThatIsNotADocumentIsNoClass(): void
    {
        $version = new Version('2.1');

        $this->assertNull($version->getDocumentClass('Nothing'));
        $this->assertNull($version->getDocumentClass('../Cac/Address'));
        $this->assertNull($version->getDocumentClass('Cac\\Address'));
        $this->assertNull($version->classFor('urn:example:nothing', 'Nothing'));
        $this->assertNull($version->rootElementFor(self::class));
    }

    #[Test]
    #[DataProvider('unsupportedIds')]
    public function aVersionWithoutAModelIsUnsupported(string $id): void
    {
        $this->expectException(UnsupportedVersionException::class);

        new Version($id);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsupportedIds(): array
    {
        return [
            'not a version' => ['latest'],
            'another major' => ['3.0'],
            'no model for it' => ['2.9'],
            'a version of the model of another one' => ['2.1.1'],
        ];
    }
}
