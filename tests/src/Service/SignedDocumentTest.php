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

use Derafu\Ubl\Service\Deserializer;
use Derafu\Ubl\Service\Serializer;
use Derafu\Ubl\Service\VersionRegistry;
use DOMDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * UBL 2.0's own signed example (`Invoice_003_official_example_enveloped.xml`: an XML-DSig and
 * XAdES signature inside the `ext:UBLExtension` of the invoice). What a signature is made of is
 * the text of its XML, so what the model keeps of it (the unmapped children of the extension, as
 * strings) has to be that text, and the parts of `xterr/php-ubl` that read and write it are
 * patched for that (`tools/patch-xterr-ubl.php`): a fragment stands on its own, and is written
 * as it was read.
 */
#[CoversNothing]
final class SignedDocumentTest extends TestCase
{
    private const SIGNED = __DIR__ . '/../../fixtures/2.1/doc/xml/Invoice/Invoice_003_official_example_enveloped.xml';

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
    public function theSignatureIsAFragmentThatStandsOnItsOwn(string $version): void
    {
        $fragments = [$this->signature($this->read($version))];

        // The `sig:` and `sac:` prefixes are declared by the `ext:UBLExtension` that holds it, not
        // by the fragment: it carries the declarations it uses, and is XML by itself.
        $this->assertStringStartsWith('<sig:UBLDocumentSignatures', $fragments[0]);
        $this->assertStringContainsString('xmlns:sig="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2"', $fragments[0]);
        $this->assertStringContainsString('xmlns:sac="urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2"', $fragments[0]);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($fragments[0]));
    }

    #[Test]
    #[DataProvider('versions')]
    public function theSignatureIsWrittenAsItWasRead(string $version): void
    {
        $document = $this->read($version);
        $fragments = $this->fragments($document);
        $signature = $this->signature($document);

        $xml = (new Serializer())->serialize($document, 'xml');

        // Byte by byte: not a default namespace of the signature (XAdES's) rewritten as a
        // prefixed one, the way a DOM import does.
        $this->assertStringContainsString($signature, $xml);
        $this->assertStringContainsString('<QualifyingProperties xmlns="http://uri.etsi.org/01903/v1.3.2#" Target="#addedSig">', $xml);
        $this->assertStringNotContainsString('xmlns:default', $xml);

        // And again: what is read from what was written is the same fragment.
        $again = (new Deserializer())->deserialize($xml, 'xml', ['version' => $version]);
        $this->assertSame($fragments, $this->fragments($again));
    }

    #[Test]
    #[DataProvider('versions')]
    public function theSignedDocumentIsValidAgainstTheSchemaOfEveryVersion(string $version): void
    {
        $document = $this->read($version);

        (new \Derafu\Ubl\Service\XmlSchemaValidator())->validate(
            (new Serializer())->serialize($document, 'xml'),
            $version
        );

        $this->addToAssertionCount(1);
    }

    private function read(string $version): object
    {
        return (new Deserializer())->deserialize(
            (string) file_get_contents(self::SIGNED),
            'xml',
            ['version' => $version]
        );
    }

    /**
     * The signature: the fragment of the extensions of the document that is not a dummy one.
     */
    private function signature(object $document): string
    {
        $signatures = array_values(array_filter(
            $this->fragments($document),
            static fn (string $fragment): bool => str_starts_with($fragment, '<sig:UBLDocumentSignatures')
        ));
        $this->assertCount(1, $signatures);

        return $signatures[0];
    }

    /**
     * The XML fragments that the extension of the document holds.
     *
     * @return list<string>
     */
    private function fragments(object $document): array
    {
        $this->assertTrue(method_exists($document, 'toArray'));
        $data = $document->toArray();

        $fragments = [];
        foreach ($data['UBLExtensions'] ?? [] as $extensions) {
            foreach ($extensions['UBLExtension'] ?? [] as $extension) {
                foreach ($extension['ExtensionContent'] ?? [] as $content) {
                    foreach ($content['any'] ?? [] as $fragment) {
                        $fragments[] = $fragment;
                    }
                }
            }
        }

        return $fragments;
    }
}
