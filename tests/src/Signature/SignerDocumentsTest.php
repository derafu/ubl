<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsUbl\Signature;

use Derafu\TestsUbl\Support\ReadsXml;
use Derafu\TestsUbl\Support\UsesXmlsec;
use Derafu\Ubl\Service\Deserializer;
use Derafu\Ubl\Service\VersionRegistry;
use Derafu\Ubl\Service\XmlSchemaValidator;
use Derafu\Ubl\Support\SignatureProfile;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every document type of UBL 2.1 to 2.5 is signed with the enveloped profile: its
 * `cac:Signature` is where the schema of the type has it, the document is valid
 * against the XSD of its version, the signature is validated by
 * `derafu/signature` and verified by `xmlsec1`.
 *
 * It uses a different class of the model for each type and each version, so it
 * does not cover a class of its own (see FixtureRoundTripTest).
 */
#[CoversNothing]
final class SignerDocumentsTest extends TestCase
{
    use ReadsXml;
    use UsesXmlsec;

    protected function setUp(): void
    {
        $this->requireXmlsec();
    }

    /**
     * The first fixture of each document type and version that has no signatures.
     *
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        $cases = [];
        foreach (VersionRegistry::SUPPORTED as $version) {
            $directory = dirname(__DIR__, 2) . '/fixtures/' . $version . '/doc/xml';
            foreach (glob($directory . '/*', GLOB_ONLYDIR) ?: [] as $type) {
                foreach (glob($type . '/*.xml') ?: [] as $file) {
                    if (!str_contains((string) file_get_contents($file), 'UBLDocumentSignatures')) {
                        $cases[$version . '/' . basename($type)] = [$version, $file];
                        break;
                    }
                }
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('documents')]
    public function theDocumentIsSignedAndValidatedByTheSchemaByDerafuAndByXmlsec(string $version, string $file): void
    {
        $xml = (string) file_get_contents($file);

        $signed = $this->signer->sign($xml, $this->certificate, ['version' => $version]);

        (new XmlSchemaValidator())->validate($signed, $version);
        $report = $this->validator->validate($signed);
        $this->assertCount(1, $report->getSignatures());
        $this->assertTrue($report->areSignaturesValid(), (string) $report->getSignatures()[0]->getError()?->getMessage());
        $this->assertSame([], $report->getIssues());
        $this->assertTrue($this->verifyWithXmlsec($signed));

        // The signature is inside the extension, and the `cac:Signature` that it
        // references is in the document.
        $this->assertCount(1, $this->elements($signed, '/*/ext:UBLExtensions[1]/ext:UBLExtension/ext:ExtensionContent/sig:UBLDocumentSignatures/sac:SignatureInformation/ds:Signature'));
        $this->assertCount(1, $this->elements($signed, '/*/cac:Signature[cbc:SignatureMethod="' . SignatureProfile::ENVELOPED . '"]'));
    }

    #[Test]
    #[DataProvider('documents')]
    public function theDocumentThatTheModelHoldsIsSignedAsTheXmlIs(string $version, string $file): void
    {
        $xml = (string) file_get_contents($file);
        $document = (new Deserializer())->deserialize($xml, 'xml', ['version' => $version]);

        $fromTheModel = $this->signer->sign($document, $this->certificate);
        $fromTheXml = $this->signer->sign($xml, $this->certificate, ['version' => $version]);

        $this->assertTrue($this->validator->validate($fromTheModel)->isValid());
        $this->assertSame($fromTheXml, $fromTheModel);
    }
}
