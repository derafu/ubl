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

use Derafu\Certificate\Contract\CertificateInterface;
use Derafu\Certificate\Service\CertificateLoader;
use Derafu\TestsUbl\Support\ReadsXml;
use Derafu\TestsUbl\Support\UsesXmlsec;
use Derafu\Ubl\Service\SignatureValidator;
use Derafu\Ubl\Service\Signer;
use Derafu\Ubl\Service\XmlSchemaValidator;
use Derafu\Ubl\Support\DetachedSignature;
use Derafu\Ubl\Support\SignatureProfile;
use Derafu\Ubl\Support\SignatureReport;
use Derafu\Ubl\Support\SignerOptions;
use Derafu\Xml\XmlDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

/**
 * The options of the signature of the enveloped profile: what the document and
 * the signature have by default and what each option changes. What is signed
 * with them is validated by `derafu/signature` and verified by `xmlsec1`.
 */
#[CoversClass(Signer::class)]
#[CoversClass(SignerOptions::class)]
#[CoversClass(SignatureValidator::class)]
#[CoversClass(SignatureReport::class)]
#[CoversClass(DetachedSignature::class)]
#[UsesClass(SignatureProfile::class)]
#[UsesNamespace('Derafu\\Ubl')]
final class SignerTest extends TestCase
{
    use ReadsXml;
    use UsesXmlsec;

    protected function setUp(): void
    {
        $this->requireXmlsec();
    }

    /**
     * Checks a signed document: it is valid against the schema, for the library
     * and for `xmlsec1`.
     */
    private function assertSigned(string $signed, int $signatures = 1): void
    {
        (new XmlSchemaValidator())->validate($signed, '2.1');
        $report = $this->validator->validate($signed);
        $this->assertCount($signatures, $report->getSignatures());
        $this->assertTrue($report->areSignaturesValid(), (string) $report->getSignatures()[0]->getError()?->getMessage());
        $this->assertSame([], $report->getIssues());
        for ($i = 1; $i <= $signatures; $i++) {
            $this->assertTrue($this->verifyWithXmlsec($signed, 'ID Nothing', $signatures > 1 ? $i : null));
        }
    }

    #[Test]
    public function theDefaultsAreTheOnesOfTheProfileAndTheSignatureIsSha256(): void
    {
        $signed = $this->signer->sign($this->invoice(), $this->certificate);

        $this->assertSigned($signed);
        $this->assertSame(SignatureProfile::ENVELOPED, $this->text($signed, '//ext:UBLExtension/ext:ExtensionURI'));
        $this->assertSame('urn:oasis:names:specification:ubl:signature:1', $this->text($signed, '//sac:SignatureInformation/cbc:ID'));
        $this->assertSame('urn:oasis:names:specification:ubl:signature:Invoice', $this->text($signed, '//sbc:ReferencedSignatureID'));
        $this->assertSame('urn:oasis:names:specification:ubl:signature:Invoice', $this->text($signed, '/*/cac:Signature/cbc:ID'));
        $this->assertSame(SignatureProfile::ENVELOPED, $this->text($signed, '/*/cac:Signature/cbc:SignatureMethod'));
        $this->assertSame(
            $this->certificate->getNames()[0],
            $this->text($signed, '/*/cac:Signature/cac:SignatoryParty/cac:PartyName/cbc:Name')
        );
        $this->assertSame([], $this->elements($signed, '/*/cac:Signature/cac:SignatoryParty/cac:PartyIdentification'));
        $signature = $this->elements($signed, '//ds:Signature')[0];
        $this->assertSame('dsig-1', $signature->getAttribute('Id'));
        $this->assertSame('dsig-1-value', $this->elements($signed, '//ds:SignatureValue')[0]->getAttribute('Id'));
        $this->assertSame(
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
            $this->elements($signed, '//ds:SignatureMethod')[0]->getAttribute('Algorithm')
        );
        $this->assertSame(
            'http://www.w3.org/2001/04/xmlenc#sha256',
            $this->elements($signed, '//ds:DigestMethod')[0]->getAttribute('Algorithm')
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function transformProvider(): array
    {
        return [
            // The text of the transforms of the profile of OASIS, as it writes them.
            'flexible' => ['flexible', 'count(ancestor-or-self::sig:UBLDocumentSignatures | here()/ancestor::sig:UBLDocumentSignatures[1]) > count(ancestor-or-self::sig:UBLDocumentSignatures)'],
            'final' => ['final', 'count(ancestor-or-self::ds:Signature | here()/ancestor::ds:Signature[1]) > count(ancestor-or-self::ds:Signature)'],
        ];
    }

    #[Test]
    #[DataProvider('transformProvider')]
    public function theTransformOfTheProfileIsTheOneThatItsTextSays(string $name, string $expression): void
    {
        $signed = $this->signer->sign($this->invoice(), $this->certificate, ['xpath' => $name]);

        $this->assertSigned($signed);
        $this->assertSame(
            $expression,
            preg_replace('/\s+/', ' ', $this->text($signed, '//ds:XPath'))
        );
        $this->assertSame(
            'http://www.w3.org/TR/1999/REC-xpath-19991116',
            $this->elements($signed, '//ds:Transform')[0]->getAttribute('Algorithm')
        );
    }

    #[Test]
    public function theEnvelopedSignatureTransformSignsTheWholeDocumentWithoutTheSignature(): void
    {
        $signed = $this->signer->sign($this->invoice(), $this->certificate, ['xpath' => 'enveloped']);

        $this->assertSigned($signed);
        $this->assertSame(
            SignatureProfile::NS_DS . 'enveloped-signature',
            $this->elements($signed, '//ds:Transform')[0]->getAttribute('Algorithm')
        );
        // The data are the whole document: any change of it is not valid.
        $this->assertFalse(
            $this->validator->validate(str_replace('TOSL108', 'TOSL109', $signed))->areSignaturesValid()
        );
    }

    #[Test]
    #[DataProvider('transformProvider')]
    public function aChangeOfTheDocumentIsNotValid(string $name, string $expression): void
    {
        $signed = $this->signer->sign($this->invoice(), $this->certificate, ['xpath' => $name]);

        $changed = str_replace('TOSL108', 'TOSL109', $signed);

        $this->assertFalse($this->validator->validate($changed)->areSignaturesValid());
        $this->assertFalse($this->verifyWithXmlsec($changed));
    }

    #[Test]
    public function theDocumentWithoutItsSignatureElementHasNoCacSignature(): void
    {
        $signed = $this->signer->sign($this->invoice(), $this->certificate, ['include_signature' => false]);

        $this->assertSigned($signed);
        $this->assertSame([], $this->elements($signed, '/*/cac:Signature'));
        $this->assertSame([], $this->elements($signed, '//sbc:ReferencedSignatureID'));
    }

    #[Test]
    public function theIdentifiersAndTheSignatoryAreTheOnesThatAreGiven(): void
    {
        $signed = $this->signer->sign($this->invoice(), $this->certificate, [
            'signature_id' => 'urn:example:signature:A',
            'information_id' => 'urn:example:information:A',
            'extension_id' => 'urn:example:extension:A',
            'extension_uri' => SignatureProfile::ENVELOPED_XADES,
            'signatory' => ['id' => 'RUT-1', 'name' => 'The Signatory'],
        ]);

        $this->assertSigned($signed);
        $this->assertSame('urn:example:signature:A', $this->text($signed, '/*/cac:Signature/cbc:ID'));
        $this->assertSame('urn:example:signature:A', $this->text($signed, '//sbc:ReferencedSignatureID'));
        $this->assertSame('urn:example:information:A', $this->text($signed, '//sac:SignatureInformation/cbc:ID'));
        $this->assertSame('urn:example:extension:A', $this->text($signed, '//ext:UBLExtension/cbc:ID'));
        $this->assertSame(SignatureProfile::ENVELOPED_XADES, $this->text($signed, '//ext:UBLExtension/ext:ExtensionURI'));
        $this->assertSame('RUT-1', $this->text($signed, '/*/cac:Signature/cac:SignatoryParty/cac:PartyIdentification/cbc:ID'));
        $this->assertSame('The Signatory', $this->text($signed, '/*/cac:Signature/cac:SignatoryParty/cac:PartyName/cbc:Name'));
    }

    #[Test]
    public function theSignatoryCanHaveOnlyAName(): void
    {
        $signed = $this->signer->sign($this->invoice(), $this->certificate, ['signatory' => ['name' => 'Only a name']]);

        $this->assertSame('Only a name', $this->text($signed, '/*/cac:Signature/cac:SignatoryParty/cac:PartyName/cbc:Name'));
        $this->assertSigned($signed);
    }

    #[Test]
    public function theOptionsOfTheSignatureOfDerafuSignatureAreTheOnesThatAreGiven(): void
    {
        $signed = $this->signer->sign($this->invoice(), $this->certificate, [
            'signature' => [
                'digest_algorithm' => 'sha512',
                'signature_algorithm' => 'rsa-sha512',
                'canonicalization_method' => 'exc_c14n',
                'signature_prefix' => 'dsig',
                'signature_id' => 'my-signature',
                'signature_value_id' => 'my-value',
                'include_certificate_chain' => true,
            ],
        ]);

        $this->assertSigned($signed);
        $this->assertSame('my-signature', $this->elements($signed, '//*[local-name()="Signature"][namespace-uri()="' . SignatureProfile::NS_DS . '"]')[0]->getAttribute('Id'));
        $this->assertSame(
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512',
            $this->elements($signed, '//*[local-name()="SignatureMethod"]')[0]->getAttribute('Algorithm')
        );
        $this->assertSame('http://www.w3.org/2001/10/xml-exc-c14n#', $this->elements($signed, '//*[local-name()="CanonicalizationMethod"]')[0]->getAttribute('Algorithm'));
        $this->assertGreaterThan(1, count($this->elements($signed, '//*[local-name()="X509Certificate"]')));
    }

    #[Test]
    public function aKeyOfAnEllipticCurveSignsWithEcdsa(): void
    {
        $this->useCertificate($this->createCertificate(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']));

        $signed = $this->signer->sign($this->invoice(), $this->certificate);

        $this->assertSigned($signed);
        $this->assertSame(
            'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256',
            $this->elements($signed, '//ds:SignatureMethod')[0]->getAttribute('Algorithm')
        );
    }

    #[Test]
    public function aKeyOfDsaSignsWithDsaSha256(): void
    {
        $this->useCertificate($this->createCertificate(['private_key_type' => OPENSSL_KEYTYPE_DSA, 'private_key_bits' => 2048]));

        $signed = $this->signer->sign($this->invoice(), $this->certificate);

        $this->assertSigned($signed);
        $this->assertSame(
            'http://www.w3.org/2009/xmldsig11#dsa-sha256',
            $this->elements($signed, '//ds:SignatureMethod')[0]->getAttribute('Algorithm')
        );
    }

    #[Test]
    public function aSignatureCanSignMoreThanTheDocument(): void
    {
        $signed = $this->signer->sign($this->invoice(), $this->certificate, [
            'signature' => ['objects' => [['xml' => '<p:Properties xmlns:p="urn:example" Id="props-1">v</p:Properties>']]],
            'references' => [['uri' => '#props-1']],
        ]);

        $report = $this->validator->validate($signed);
        $this->assertTrue($report->areSignaturesValid(), (string) $report->getSignatures()[0]->getError()?->getMessage());
        $this->assertCount(2, $this->elements($signed, '//ds:Reference'));
        $this->assertFalse($this->validator->validate(str_replace('>v<', '>w<', $signed))->areSignaturesValid());
        (new XmlSchemaValidator())->validate($signed, '2.1');
    }

    #[Test]
    public function theDocumentOfEveryVersionHasTheSignatureInItsOwnSchema(): void
    {
        foreach (['2.2', '2.3', '2.4', '2.5'] as $version) {
            // Every version has the same namespaces: the invoice of 2.1 is one of 2.2 to 2.5 too.
            $signed = $this->signer->sign($this->invoice(), $this->certificate, ['version' => $version]);

            (new XmlSchemaValidator())->validate($signed, $version);
            $this->assertTrue($this->validator->validate($signed)->isValid(), $version);
        }
    }

    #[Test]
    public function anXmlDocumentIsSignedAsTheXmlThatItSavesIs(): void
    {
        $document = new XmlDocument();
        $document->loadXml($this->invoice());

        $fromTheDocument = $this->signer->sign($document, $this->certificate, ['version' => '2.1']);

        $this->assertSame($this->signer->sign($document->saveXml(), $this->certificate, ['version' => '2.1']), $fromTheDocument);
        $this->assertTrue($this->validator->validate($fromTheDocument)->isValid());
        $this->assertTrue($this->verifyWithXmlsec($fromTheDocument));
    }

    #[Test]
    public function aSignedXmlDocumentIsValidatedAsTheXmlThatItSavesIs(): void
    {
        $signed = $this->signer->sign($this->invoice(), $this->certificate);
        $document = new XmlDocument();
        $document->loadXml($signed);

        $this->assertTrue($this->validator->validate($document)->isValid());
        $document->loadXml(str_replace('TOSL108', 'TOSL109', $signed));
        $this->assertFalse($this->validator->validate($document)->areSignaturesValid());
    }

    #[Test]
    public function aSignedXmlDocumentCanGetAnotherSignature(): void
    {
        $document = new XmlDocument();
        $document->loadXml($this->signer->sign($this->invoice(), $this->certificate));

        $both = $this->signer->sign($document, $this->certificate);

        $this->assertCount(2, $this->validator->validate($both)->getSignatures());
        $this->assertTrue($this->validator->validate($both)->isValid());
    }

    #[Test]
    public function theSameOptionsSignTheSameDocumentTheSameWay(): void
    {
        $this->assertSame(
            $this->signer->sign($this->invoice(), $this->certificate),
            $this->signer->sign($this->invoice(), $this->certificate)
        );
    }

    #[Test]
    public function theOptionsAreNotKeptForTheNextSignature(): void
    {
        $this->signer->sign($this->invoice(), $this->certificate, ['xpath' => 'final', 'include_signature' => false]);

        $signed = $this->signer->sign($this->invoice(), $this->certificate);

        $this->assertSame(1, count($this->elements($signed, '/*/cac:Signature')));
        $this->assertStringContainsString('sig:UBLDocumentSignatures', (string) preg_replace('/\s+/', ' ', $this->text($signed, '//ds:XPath')));
    }

    /**
     * Creates a certificate with another kind of key.
     *
     * @param array<string, mixed> $key
     */
    private function createCertificate(array $key): CertificateInterface
    {
        $pair = openssl_pkey_new($key);
        if ($pair === false) {
            $this->fail('OpenSSL could not make the key.');
        }
        $copy = $pair;
        $request = openssl_csr_new(['commonName' => 'derafu test'], $copy);
        if (!$request instanceof \OpenSSLCertificateSigningRequest) {
            $this->fail('OpenSSL could not make the request of the certificate.');
        }
        $certificate = openssl_csr_sign($request, null, $pair, 2);
        if (!$certificate instanceof \OpenSSLCertificate) {
            $this->fail('OpenSSL could not sign the certificate.');
        }

        $certificatePem = '';
        $privateKeyPem = '';
        $this->assertTrue(openssl_x509_export($certificate, $certificatePem));
        $copy = $pair;
        $this->assertTrue(openssl_pkey_export($copy, $privateKeyPem));
        $this->assertIsString($certificatePem);
        $this->assertIsString($privateKeyPem);

        return (new CertificateLoader())->loadFromKeys($certificatePem, $privateKeyPem);
    }

    /**
     * Signs with another certificate: the one of the test and the one that
     * `xmlsec1` uses.
     */
    private function useCertificate(CertificateInterface $certificate): void
    {
        $this->certificate = $certificate;
        file_put_contents($this->xmlsecDirectory . '/key.pem', $certificate->getPrivateKey());
        file_put_contents($this->xmlsecDirectory . '/cert.pem', $certificate->getCertificate());
    }
}
