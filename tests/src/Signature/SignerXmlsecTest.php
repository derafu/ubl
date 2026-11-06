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
use Derafu\Ubl\Service\SignatureValidator;
use Derafu\Ubl\Service\Signer;
use Derafu\Ubl\Service\XmlSchemaValidator;
use Derafu\Ubl\Support\DetachedSignature;
use Derafu\Ubl\Support\SignatureProfile;
use Derafu\Ubl\Support\SignatureReport;
use Derafu\Ubl\Support\SignerOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

/**
 * What `xmlsec1` signs inside a UBL document is validated by the package, with
 * the scaffolding of the profile that the signer writes: the document is signed
 * by an implementation that is not this one, in the way that the profile says.
 */
#[CoversClass(Signer::class)]
#[CoversClass(SignerOptions::class)]
#[CoversClass(SignatureValidator::class)]
#[CoversClass(SignatureReport::class)]
#[CoversClass(DetachedSignature::class)]
#[UsesClass(SignatureProfile::class)]
#[UsesNamespace('Derafu\\Ubl')]
final class SignerXmlsecTest extends TestCase
{
    use ReadsXml;
    use UsesXmlsec;

    protected function setUp(): void
    {
        $this->requireXmlsec();
    }

    /**
     * Gives the document with the scaffolding of the signature and the
     * template of the signature of `xmlsec1` inside, to sign.
     *
     * @param list<array{string, string, list<array{string, string}>}> $references
     */
    private function templateDocument(array $references, string $signatureMethod = self::RSA_SHA256): string
    {
        // The signer writes the scaffolding: the signature that it makes is
        // replaced by the template.
        $signed = $this->signer->sign($this->invoice(), $this->certificate);
        $template = $this->template('ds', $signatureMethod, self::C14N, $references);

        $document = (string) preg_replace('#<ds:Signature .*?</ds:Signature>#s', $template, $signed, 1);
        $this->assertNotSame($signed, $document);

        return $document;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function transformProvider(): array
    {
        return [
            'flexible' => [SignatureProfile::XPATH_FLEXIBLE],
            'final' => [SignatureProfile::XPATH_FINAL],
        ];
    }

    #[Test]
    #[DataProvider('transformProvider')]
    public function aSignatureOfXmlsecWithTheTransformOfTheProfileIsValidated(string $expression): void
    {
        $document = $this->templateDocument([
            ['', self::SHA256, [[self::XPATH, $expression], [self::C14N, '']]],
        ]);

        $signed = $this->signWithXmlsec($document);

        (new XmlSchemaValidator())->validate($signed, '2.1');
        $report = $this->validator->validate($signed);
        $this->assertCount(1, $report->getSignatures());
        $this->assertTrue($report->isValid(), (string) $report->getSignatures()[0]->getError()?->getMessage());
    }

    #[Test]
    public function aSignatureOfXmlsecWithTheEnvelopedSignatureTransformIsValidated(): void
    {
        $document = $this->templateDocument([
            ['', self::SHA256, [[self::ENVELOPED, ''], [self::C14N, '']]],
        ]);

        $signed = $this->signWithXmlsec($document);

        $report = $this->validator->validate($signed);
        $this->assertTrue($report->isValid(), (string) $report->getSignatures()[0]->getError()?->getMessage());
    }

    #[Test]
    public function aSignatureOfXmlsecWithSha1IsValidated(): void
    {
        $document = $this->templateDocument(
            [['', 'http://www.w3.org/2000/09/xmldsig#sha1', [[self::XPATH, SignatureProfile::XPATH_FLEXIBLE], [self::C14N, '']]]],
            'http://www.w3.org/2000/09/xmldsig#rsa-sha1'
        );

        $signed = $this->signWithXmlsec($document);

        $this->assertTrue($this->validator->validate($signed)->isValid());
    }

    #[Test]
    public function aSignatureOfXmlsecThatSignsOnlyOneThingIsValidatedAndTheDocumentIsNot(): void
    {
        $document = $this->templateDocument([
            ['', self::SHA256, [[self::XPATH, SignatureProfile::XPATH_FLEXIBLE], [self::C14N, '']]],
        ]);
        $signed = $this->signWithXmlsec($document);

        $changed = str_replace('TOSL108', 'TOSL109', $signed);

        $this->assertFalse($this->validator->validate($changed)->areSignaturesValid());
    }

    #[Test]
    public function aSecondSignatureOfXmlsecIsAddedToTheOneOfThePackage(): void
    {
        $first = $this->signer->sign($this->invoice(), $this->certificate);
        $information = '<sac:SignatureInformation xmlns:sac="' . SignatureProfile::NS_SAC . '" xmlns:cbc="' . SignatureProfile::NS_CBC . '">'
            . '<cbc:ID>urn:oasis:names:specification:ubl:signature:2</cbc:ID>'
            . $this->template('ds', self::RSA_SHA256, self::C14N, [
                ['', self::SHA256, [[self::XPATH, SignatureProfile::XPATH_FLEXIBLE], [self::C14N, '']]],
            ])
            . '</sac:SignatureInformation>';
        $document = str_replace('</sig:UBLDocumentSignatures>', $information . '</sig:UBLDocumentSignatures>', $first);

        $signed = $this->signWithXmlsec($document, 'ID Nothing', "(//*[local-name()='Signature'][namespace-uri()='" . self::DSIG . "'])[2]");

        $report = $this->validator->validate($signed);
        $this->assertCount(2, $report->getSignatures());
        $this->assertTrue($report->areSignaturesValid(), (string) ($report->getSignatures()[0]->getError() ?? $report->getSignatures()[1]->getError())?->getMessage());
        $this->assertTrue($this->verifyWithXmlsec($signed, 'ID Nothing', 1));
    }
}
