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

use Derafu\Signature\Exception\SignatureException;
use Derafu\Signature\Service\SignatureGenerator;
use Derafu\Signature\Service\SignatureValidator as XmlSignatureValidator;
use Derafu\Ubl\Exception\UblException;
use Derafu\Ubl\Service\SignatureValidator;
use Derafu\Ubl\Support\SignatureProfile;
use Derafu\Ubl\Support\SignatureReport;
use Derafu\Xml\Service\XmlDecoder;
use Derafu\Xml\Service\XmlEncoder;
use Derafu\Xml\Service\XmlService;
use Derafu\Xml\Service\XmlValidator;
use Derafu\Xml\XmlDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The validation of the profile: the signatures of a document are validated, and
 * the document is checked against what the profile requires. It is done with the
 * signed example of OASIS, that is not made by this package, and with changes of
 * its text.
 */
#[CoversClass(SignatureValidator::class)]
#[CoversClass(SignatureReport::class)]
#[UsesClass(SignatureProfile::class)]
#[UsesClass(UblException::class)]
final class SignatureValidatorTest extends TestCase
{
    private const EXAMPLE = __DIR__ . '/../../fixtures/2.1/doc/xml/Invoice/Invoice_003_official_example_enveloped.xml';

    private SignatureValidator $validator;

    protected function setUp(): void
    {
        $xmlService = new XmlService(new XmlEncoder(), new XmlDecoder(), new XmlValidator());
        $this->validator = new SignatureValidator(new XmlSignatureValidator(new SignatureGenerator($xmlService), $xmlService));
    }

    private function example(): string
    {
        return (string) file_get_contents(self::EXAMPLE);
    }

    /**
     * Gives the example with an `ext:ExtensionURI` in the extension of its signature.
     */
    private function withExtensionUri(string $uri): string
    {
        return (string) preg_replace(
            '#(<ext:UBLExtension xmlns:sig="[^>]*>)#',
            '$1<ext:ExtensionURI>' . $uri . '</ext:ExtensionURI>',
            $this->example(),
            1
        );
    }

    /**
     * @return list<string>
     */
    private function messages(SignatureReport $report): array
    {
        return array_map(static fn (UblException $issue): string => $issue->getMessage(), $report->getIssues());
    }

    #[Test]
    public function theSignedExampleOfOasisHasAValidSignatureAndLacksTheUriOfItsExtension(): void
    {
        $report = $this->validator->validate($this->example());

        $this->assertCount(1, $report->getSignatures());
        $this->assertTrue($report->areSignaturesValid(), (string) $report->getSignatures()[0]->getError()?->getMessage());
        $this->assertSame(['An extension with signatures does not have the ext:ExtensionURI that says so.'], $this->messages($report));
        $this->assertFalse($report->isConformant());
        $this->assertFalse($report->isValid());
    }

    #[Test]
    public function anExtensionUriOfTheProfileIsConformantAndTheSignatureNeedsItsOwnText(): void
    {
        foreach (['urn:oasis:names:specification:ubl:dsig:enveloped', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades'] as $uri) {
            $report = $this->validator->validate($this->withExtensionUri($uri));

            $this->assertSame([], $this->messages($report));
            $this->assertTrue($report->isConformant());
            // The signature is of the text of the document, and the text changed.
            $this->assertFalse($report->areSignaturesValid());
            $this->assertFalse($report->isValid());
        }
    }

    #[Test]
    public function anExtensionUriThatIsNotOfTheProfileIsAnIssue(): void
    {
        $report = $this->validator->validate($this->withExtensionUri('urn:example:other'));

        $this->assertSame(
            ['The ext:ExtensionURI "urn:example:other" of an extension with signatures is not one of the profile.'],
            $this->messages($report)
        );
    }

    #[Test]
    public function aReferencedSignatureThatTheDocumentDoesNotHaveIsAnIssue(): void
    {
        $xml = str_replace('<sac:SignatureInformation>', '<sac:SignatureInformation xmlns:sbc="' . SignatureProfile::NS_SBC . '"><sbc:ReferencedSignatureID>urn:example:missing</sbc:ReferencedSignatureID>', $this->example());

        $this->assertContains(
            'The sbc:ReferencedSignatureID "urn:example:missing" is not the cbc:ID of a cac:Signature of the document.',
            $this->messages($this->validator->validate($xml))
        );
    }

    #[Test]
    public function aReferencedSignatureThatTheDocumentHasIsConformant(): void
    {
        $xml = str_replace('<sac:SignatureInformation>', '<sac:SignatureInformation xmlns:sbc="' . SignatureProfile::NS_SBC . '"><sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>', $this->example());

        $this->assertSame(
            ['An extension with signatures does not have the ext:ExtensionURI that says so.'],
            $this->messages($this->validator->validate($xml))
        );
    }

    #[Test]
    public function aSignatureMethodThatIsNotOfTheProfileIsAnIssueOfTheSignatureThatIsReferenced(): void
    {
        $xml = str_replace(
            ['<sac:SignatureInformation>', 'urn:oasis:names:specification:ubl:dsig:enveloped</cbc:SignatureMethod>'],
            ['<sac:SignatureInformation xmlns:sbc="' . SignatureProfile::NS_SBC . '"><sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>', 'urn:example:other</cbc:SignatureMethod>'],
            $this->example()
        );

        $this->assertContains(
            'The cbc:SignatureMethod "urn:example:other" of the cac:Signature "urn:oasis:names:specification:ubl:signature:Invoice" is not one of the enveloped profile.',
            $this->messages($this->validator->validate($xml))
        );
    }

    #[Test]
    public function aCacSignatureThatNoSignatureReferencesIsNotChecked(): void
    {
        $xml = str_replace('urn:oasis:names:specification:ubl:dsig:enveloped</cbc:SignatureMethod>', 'urn:example:other</cbc:SignatureMethod>', $this->example());

        $this->assertSame(
            ['An extension with signatures does not have the ext:ExtensionURI that says so.'],
            $this->messages($this->validator->validate($xml))
        );
    }

    #[Test]
    public function anXmlDocumentIsValidatedAsTheXmlThatItSavesIs(): void
    {
        $document = new XmlDocument();
        $document->loadXml($this->example());

        $report = $this->validator->validate($document);

        $this->assertTrue($report->areSignaturesValid());
        $this->assertSame($this->messages($this->validator->validate($this->example())), $this->messages($report));
    }

    #[Test]
    public function theIdentifiersOfTheSignatureAndOfItsValueOfTheExampleAreUnique(): void
    {
        $this->assertNotContains(
            'The Id "addedSig" of a signature or of its value is not unique in the document.',
            $this->messages($this->validator->validate($this->example()))
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function repeatedIdentifierProvider(): array
    {
        return [
            'the identifier of the signature' => ['addedSig'],
            'the identifier of its value' => ['addedSigVal'],
            'the identifier of the signed properties' => ['xades-test-s'],
        ];
    }

    #[Test]
    #[DataProvider('repeatedIdentifierProvider')]
    public function anIdentifierOfTheSignatureOrItsValueThatTheDocumentRepeatsIsAnIssue(string $id): void
    {
        $xml = $this->example();
        if ($id === 'xades-test-s') {
            // The signature has the identifier of its signed properties.
            $xml = str_replace('Id="addedSig"', 'Id="xades-test-s"', $xml);
        } else {
            // Another element of the document has the same identifier.
            $xml = str_replace('<cbc:UBLVersionID>', '<cbc:Note Id="' . $id . '">x</cbc:Note><cbc:UBLVersionID>', $xml);
        }
        $expected = 'The Id "' . $id . '" of a signature or of its value is not unique in the document.';

        $messages = $this->messages($this->validator->validate($xml));

        // It is reported once.
        $this->assertCount(1, array_keys($messages, $expected, true));
    }

    #[Test]
    public function anIdentifierThatTheSignatureAndItsValueShareIsReportedOnce(): void
    {
        $xml = str_replace('Id="addedSigVal"', 'Id="addedSig"', $this->example());

        $messages = $this->messages($this->validator->validate($xml));

        $this->assertCount(1, array_keys($messages, 'The Id "addedSig" of a signature or of its value is not unique in the document.', true));
    }

    #[Test]
    public function aDocumentWithoutSignaturesIsAnErrorOfTheSignature(): void
    {
        $this->expectException(SignatureException::class);

        $this->validator->validate('<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"/>');
    }

    #[Test]
    public function theOptionsThatAreNotValidAreAnError(): void
    {
        $this->expectException(UblException::class);
        $this->expectExceptionMessage('The options of the signature are not valid');

        $this->validator->validate($this->example(), ['resource_resolver' => 'file']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidDetachedOptionsProvider(): array
    {
        return [
            'without the uri' => [[]],
            'a uri that is not a string' => [['document_uri' => 1]],
            'an option that does not exist' => [['document_uri' => 'a.xml', 'other' => 1]],
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('invalidDetachedOptionsProvider')]
    public function theOptionsOfTheValidationOfADetachedSignatureThatAreNotValidAreAnError(array $options): void
    {
        $this->expectException(UblException::class);
        $this->expectExceptionMessage('The options of the signature are not valid');

        $this->validator->validateDetached('<a/>', '<b/>', $options);
    }

    #[Test]
    public function aCacSignatureWithAnAttachmentThatSaysEnvelopedIsAnIssueOfTheDetachedProfile(): void
    {
        $document = '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            . ' xmlns:cac="' . SignatureProfile::NS_CAC . '" xmlns:cbc="' . SignatureProfile::NS_CBC . '">'
            . '<cac:Signature><cbc:ID>x</cbc:ID><cbc:SignatureMethod>' . SignatureProfile::ENVELOPED . '</cbc:SignatureMethod>'
            . '<cac:DigitalSignatureAttachment><cac:ExternalReference><cbc:URI>s.xml</cbc:URI></cac:ExternalReference></cac:DigitalSignatureAttachment>'
            . '</cac:Signature></Invoice>';
        $signature = (string) preg_replace('#^.*?(<Signature.*</Signature>).*$#s', '$1', $this->example());

        $report = $this->validator->validateDetached($document, $signature, ['document_uri' => 'a.xml']);

        $this->assertSame(
            ['The cbc:SignatureMethod "' . SignatureProfile::ENVELOPED . '" of a cac:Signature with a signature outside of the document is not one of the detached profile.'],
            $this->messages($report)
        );
        $this->assertFalse($report->isValid());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function detachedMethodProvider(): array
    {
        return [
            'detached' => ['urn:oasis:names:specification:ubl:dsig:detached'],
            'detached with XAdES' => ['urn:oasis:names:specification:ubl:dsig:detached:xades'],
        ];
    }

    #[Test]
    #[DataProvider('detachedMethodProvider')]
    public function aCacSignatureWithAnAttachmentThatSaysDetachedIsConformant(string $method): void
    {
        $document = '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            . ' xmlns:cac="' . SignatureProfile::NS_CAC . '" xmlns:cbc="' . SignatureProfile::NS_CBC . '">'
            . '<cac:Signature><cbc:ID>x</cbc:ID><cbc:SignatureMethod>' . $method . '</cbc:SignatureMethod>'
            . '<cac:DigitalSignatureAttachment><cac:ExternalReference><cbc:URI>s.xml</cbc:URI></cac:ExternalReference></cac:DigitalSignatureAttachment>'
            . '</cac:Signature></Invoice>';
        $signature = (string) preg_replace('#^.*?(<Signature.*</Signature>).*$#s', '$1', $this->example());

        $report = $this->validator->validateDetached($document, $signature, ['document_uri' => 'a.xml']);

        $this->assertSame([], $this->messages($report));
        $this->assertTrue($report->isConformant());
    }

    #[Test]
    public function theIssuesOfADocumentThatIsNotXmlAreNone(): void
    {
        $report = $this->validator->validateDetached('not xml', $this->example(), ['document_uri' => 'a.xml']);

        $this->assertSame([], $report->getIssues());
    }

    #[Test]
    public function aReportIsValidOnlyWithValidSignaturesAndNoIssues(): void
    {
        $valid = $this->validator->validate($this->example())->getSignatures();

        $this->assertTrue((new SignatureReport($valid, []))->isValid());
        $this->assertFalse((new SignatureReport($valid, [new UblException('An issue.')]))->isValid());
        $this->assertTrue((new SignatureReport([], []))->isValid());
    }
}
