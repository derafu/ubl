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
use Derafu\TestsUbl\Support\ReadsXml;
use Derafu\TestsUbl\Support\UsesXmlsec;
use Derafu\Ubl\Exception\DeserializationException;
use Derafu\Ubl\Exception\UblException;
use Derafu\Ubl\Service\Deserializer;
use Derafu\Ubl\Service\SignatureValidator;
use Derafu\Ubl\Service\Signer;
use Derafu\Ubl\Service\XmlSchemaValidator;
use Derafu\Ubl\Support\DetachedSignature;
use Derafu\Ubl\Support\SignatureProfile;
use Derafu\Ubl\Support\SignatureReport;
use Derafu\Ubl\Support\SignerOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

/**
 * A document that has signatures is signed again as it is: a co-signature, and a
 * countersignature of a signature. Adding another signature does not invalidate
 * the ones that the document has when they use the flexible transform, and it
 * does when they are final.
 */
#[CoversClass(Signer::class)]
#[CoversClass(SignerOptions::class)]
#[CoversClass(SignatureValidator::class)]
#[CoversClass(SignatureReport::class)]
#[CoversClass(DetachedSignature::class)]
#[UsesClass(SignatureProfile::class)]
#[UsesNamespace('Derafu\\Ubl')]
final class SignerCosignTest extends TestCase
{
    use ReadsXml;
    use UsesXmlsec;

    protected function setUp(): void
    {
        $this->requireXmlsec();
    }

    #[Test]
    public function aSecondSignatureIsAddedToTheSignaturesOfTheDocumentWithoutInvalidatingTheFirst(): void
    {
        $first = $this->signer->sign($this->invoice(), $this->certificate);

        $both = $this->signer->sign($first, $this->certificate);

        (new XmlSchemaValidator())->validate($both, '2.1');
        $report = $this->validator->validate($both);
        $this->assertCount(2, $report->getSignatures());
        $this->assertTrue($report->isValid());
        $this->assertTrue($this->verifyWithXmlsec($both, 'ID Nothing', 1));
        $this->assertTrue($this->verifyWithXmlsec($both, 'ID Nothing', 2));

        // Both are in the same extension, and the second has its own identifiers.
        $this->assertCount(1, $this->elements($both, '//ext:UBLExtension'));
        $this->assertCount(2, $this->elements($both, '//sig:UBLDocumentSignatures/sac:SignatureInformation'));
        $this->assertSame('urn:oasis:names:specification:ubl:signature:2', $this->elements($both, '//sac:SignatureInformation/cbc:ID')[1]->textContent);
        $this->assertSame('dsig-2', $this->elements($both, '//ds:Signature')[1]->getAttribute('Id'));
    }

    #[Test]
    public function theSecondSignatureDoesNotChangeWhatTheFirstOneSigned(): void
    {
        $first = $this->signer->sign($this->invoice(), $this->certificate);

        $both = $this->signer->sign($first, $this->certificate);

        // The document is the same outside of the signatures: no `cac:Signature` is added.
        $this->assertCount(1, $this->elements($both, '/*/cac:Signature'));
        $this->assertSame(
            $this->elements($first, '//ds:SignatureValue')[0]->textContent,
            $this->elements($both, '//ds:SignatureValue')[0]->textContent
        );
    }

    #[Test]
    public function aSignatureThatIsFinalIsInvalidatedByAnotherOneAfterwards(): void
    {
        $first = $this->signer->sign($this->invoice(), $this->certificate, ['xpath' => 'final']);
        $this->assertTrue($this->validator->validate($first)->isValid());

        $both = $this->signer->sign($first, $this->certificate, ['xpath' => 'final']);

        $report = $this->validator->validate($both);
        $this->assertFalse($report->getSignatures()[0]->isValid());
        $this->assertTrue($report->getSignatures()[1]->isValid());
        $this->assertFalse($this->verifyWithXmlsec($both, 'ID Nothing', 1));
        $this->assertTrue($this->verifyWithXmlsec($both, 'ID Nothing', 2));
    }

    #[Test]
    public function theSecondSignatureCanReferenceACacSignatureThatTheDocumentHas(): void
    {
        $first = $this->signer->sign($this->invoice(), $this->certificate);

        $both = $this->signer->sign($first, $this->certificate, ['signature_id' => 'urn:oasis:names:specification:ubl:signature:Invoice']);

        $this->assertSame(
            ['urn:oasis:names:specification:ubl:signature:Invoice', 'urn:oasis:names:specification:ubl:signature:Invoice'],
            array_map(static fn ($element): string => $element->textContent, $this->elements($both, '//sbc:ReferencedSignatureID'))
        );
        $this->assertTrue($this->validator->validate($both)->isValid());
    }

    #[Test]
    public function aCacSignatureThatTheDocumentDoesNotHaveCanNotBeReferenced(): void
    {
        $first = $this->signer->sign($this->invoice(), $this->certificate);

        $this->expectException(UblException::class);
        $this->expectExceptionMessage('The document does not have a cac:Signature with the cbc:ID "urn:other".');

        $this->signer->sign($first, $this->certificate, ['signature_id' => 'urn:other']);
    }

    #[Test]
    public function theIdentifierOfASignatureInformationOfTheDocumentCanNotBeUsedAgain(): void
    {
        $first = $this->signer->sign($this->invoice(), $this->certificate);

        $this->expectException(UblException::class);
        $this->expectExceptionMessage('already has a sac:SignatureInformation with the cbc:ID');

        $this->signer->sign($first, $this->certificate, ['information_id' => 'urn:oasis:names:specification:ubl:signature:1']);
    }

    #[Test]
    public function aSignatureThatSignsTheValueOfAnotherOneIsACountersignature(): void
    {
        $first = $this->signer->sign($this->invoice(), $this->certificate);

        $countersigned = $this->signer->sign($first, $this->certificate, ['countersign' => 'dsig-1-value']);

        (new XmlSchemaValidator())->validate($countersigned, '2.1');
        $report = $this->validator->validate($countersigned);
        $this->assertCount(2, $report->getSignatures());
        $this->assertTrue($report->isValid());
        $this->assertCount(2, $this->elements($countersigned, '(//ds:Signature)[2]/ds:SignedInfo/ds:Reference'));
        $this->assertSame('#dsig-1-value', $this->elements($countersigned, '(//ds:Signature)[2]/ds:SignedInfo/ds:Reference')[1]->getAttribute('URI'));
        $this->assertTrue($this->verifyWithXmlsec($countersigned, 'Id SignatureValue', 2));
    }

    #[Test]
    public function aChangeOfTheSignatureThatIsCountersignedInvalidatesTheCountersignature(): void
    {
        $first = $this->signer->sign($this->invoice(), $this->certificate);
        $countersigned = $this->signer->sign($first, $this->certificate, ['countersign' => ['dsig-1', 'dsig-1-value']]);
        $this->assertTrue($this->validator->validate($countersigned)->areSignaturesValid());

        $value = $this->elements($countersigned, '//ds:SignatureValue')[0]->textContent;
        $changed = str_replace(substr($value, 0, 6), 'AAAAAA', $countersigned);

        $report = $this->validator->validate($changed);
        $this->assertFalse($report->getSignatures()[0]->isValid());
        $this->assertFalse($report->getSignatures()[1]->isValid());
    }

    #[Test]
    public function aDocumentThatTheModelHoldsWithSignaturesInsideIsNotSignedFromTheModel(): void
    {
        $first = $this->signer->sign($this->invoice(), $this->certificate);
        $document = (new Deserializer())->deserialize($first, 'xml', ['version' => '2.1']);

        $this->expectException(UblException::class);
        $this->expectExceptionMessage('The document already has signatures inside: give its XML to add another one.');

        $this->signer->sign($document, $this->certificate);
    }

    #[Test]
    public function aDocumentWithSignaturesInsideCanNotGetADetachedOne(): void
    {
        $first = $this->signer->sign($this->invoice(), $this->certificate);

        $this->expectException(UblException::class);
        $this->expectExceptionMessage('can not get a detached one');

        $this->signer->signDetached($first, $this->certificate, ['document_uri' => 'a.xml', 'signature_uri' => 'b.xml']);
    }

    #[Test]
    public function aXmlThatIsNotAUblDocumentCanNotBeSigned(): void
    {
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Could not resolve a UBL document type from the XML root element.');

        $this->signer->sign('<a/>', $this->certificate);
    }

    #[Test]
    public function anObjectThatIsNotAUblDocumentOfThePackageCanNotBeSigned(): void
    {
        $this->expectException(UblException::class);
        $this->expectExceptionMessage('stdClass is not a UBL document of this package.');

        $this->signer->sign(new \stdClass(), $this->certificate);
    }

    #[Test]
    public function aCacSignatureWithTheIdentifierOfTheSignatureOfTheDocumentIsAnError(): void
    {
        // A detached signature adds a `cac:Signature` with the default identifier.
        $detached = $this->signer->signDetached($this->invoice(), $this->certificate, ['document_uri' => 'a.xml', 'signature_uri' => 'b.xml']);

        $this->expectException(UblException::class);
        $this->expectExceptionMessage('already has a cac:Signature with the cbc:ID "urn:oasis:names:specification:ubl:signature:Invoice"');

        $this->signer->sign($detached->document, $this->certificate);
    }

    #[Test]
    public function aDocumentWithACacSignatureGetsAnotherOneWithAnotherIdentifier(): void
    {
        $detached = $this->signer->signDetached($this->invoice(), $this->certificate, ['document_uri' => 'a.xml', 'signature_uri' => 'b.xml']);

        $signed = $this->signer->sign($detached->document, $this->certificate, ['signature_id' => 'urn:example:other']);

        $this->assertCount(2, $this->elements($signed, '/*/cac:Signature'));
        (new XmlSchemaValidator())->validate($signed, '2.1');
        $this->assertTrue($this->validator->validate($signed)->isValid());
    }

    #[Test]
    public function aMalformedSignatureIsAnErrorOfTheSignature(): void
    {
        $this->expectException(SignatureException::class);

        $this->validator->validate('<Invoice/>');
    }
}
