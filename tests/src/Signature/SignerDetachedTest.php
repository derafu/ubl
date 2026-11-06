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
use Derafu\Ubl\Exception\UblException;
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
 * The detached profile: the document says where its signature is, and the
 * signature is in another document whose reference is the document.
 */
#[CoversClass(Signer::class)]
#[CoversClass(SignerOptions::class)]
#[CoversClass(SignatureValidator::class)]
#[CoversClass(SignatureReport::class)]
#[CoversClass(DetachedSignature::class)]
#[UsesClass(SignatureProfile::class)]
#[UsesNamespace('Derafu\\Ubl')]
final class SignerDetachedTest extends TestCase
{
    use ReadsXml;
    use UsesXmlsec;

    private const DOCUMENT_URI = 'invoice.xml';

    private const SIGNATURE_URI = 'invoice-signature.xml';

    protected function setUp(): void
    {
        $this->requireXmlsec();
    }

    /**
     * @return array<string, mixed>
     */
    private function options(string $documentUri = self::DOCUMENT_URI): array
    {
        return ['document_uri' => $documentUri, 'signature_uri' => self::SIGNATURE_URI];
    }

    #[Test]
    public function theDocumentSaysWhereItsSignatureIsAndIsValidAgainstTheSchema(): void
    {
        $detached = $this->signer->signDetached($this->invoice(), $this->certificate, $this->options());

        (new XmlSchemaValidator())->validate($detached->document, '2.1');
        $this->assertSame([], $this->elements($detached->document, '/*/ext:UBLExtensions'));
        $this->assertSame('urn:oasis:names:specification:ubl:dsig:detached', $this->text($detached->document, '/*/cac:Signature/cbc:SignatureMethod'));
        $this->assertSame(self::SIGNATURE_URI, $this->text($detached->document, '/*/cac:Signature/cac:DigitalSignatureAttachment/cac:ExternalReference/cbc:URI'));
        $this->assertSame('urn:oasis:names:specification:ubl:signature:Invoice', $this->text($detached->document, '/*/cac:Signature/cbc:ID'));
    }

    #[Test]
    public function theSignatureIsADocumentWhoseReferenceIsTheDocument(): void
    {
        $detached = $this->signer->signDetached($this->invoice(), $this->certificate, $this->options());

        $this->assertStringStartsWith('<?xml version="1.0"?>' . "\n" . '<ds:Signature ', $detached->signature);
        $reference = $this->elements($detached->signature, '//ds:Reference')[0];
        $this->assertSame(self::DOCUMENT_URI, $reference->getAttribute('URI'));
        $this->assertSame(
            'count(ancestor-or-self::sig:UBLDocumentSignatures)=0',
            preg_replace('/\s+/', ' ', $this->text($detached->signature, '//ds:XPath'))
        );
        $this->assertSame('dsig-1', $this->elements($detached->signature, '/ds:Signature')[0]->getAttribute('Id'));
    }

    #[Test]
    public function theSignatureIsValidatedWithTheDocument(): void
    {
        $detached = $this->signer->signDetached($this->invoice(), $this->certificate, $this->options());

        $report = $this->validator->validateDetached($detached->document, $detached->signature, ['document_uri' => self::DOCUMENT_URI]);

        $this->assertCount(1, $report->getSignatures());
        $this->assertTrue($report->isValid(), (string) $report->getSignatures()[0]->getError()?->getMessage());
        $this->assertSame([], $report->getIssues());
    }

    #[Test]
    public function aChangeOfTheDocumentMakesTheSignatureNotValid(): void
    {
        $detached = $this->signer->signDetached($this->invoice(), $this->certificate, $this->options());

        $report = $this->validator->validateDetached(
            str_replace('TOSL108', 'TOSL109', $detached->document),
            $detached->signature,
            ['document_uri' => self::DOCUMENT_URI]
        );

        $this->assertFalse($report->areSignaturesValid());
    }

    #[Test]
    public function aSignatureThatIsForAnotherUriIsNotValid(): void
    {
        $detached = $this->signer->signDetached($this->invoice(), $this->certificate, $this->options());

        $report = $this->validator->validateDetached($detached->document, $detached->signature, ['document_uri' => 'other.xml']);

        $this->assertFalse($report->areSignaturesValid());
        $this->assertStringContainsString('The resource "invoice.xml" can not be resolved.', (string) $report->getSignatures()[0]->getError()?->getMessage());
    }

    #[Test]
    public function theFormatOfTheDocumentDoesNotMatterAsLongAsItsCanonicalFormIsTheSame(): void
    {
        $detached = $this->signer->signDetached($this->invoice(), $this->certificate, $this->options());

        $rewritten = str_replace('<cbc:ID>TOSL108</cbc:ID>', '<cbc:ID  >TOSL108</cbc:ID>', $detached->document);

        $this->assertNotSame($detached->document, $rewritten);
        $this->assertTrue($this->validator->validateDetached($rewritten, $detached->signature, ['document_uri' => self::DOCUMENT_URI])->isValid());
    }

    #[Test]
    public function aSignatureThatIsAddedInsideTheDocumentAfterwardsInvalidatesTheDetachedOne(): void
    {
        $detached = $this->signer->signDetached($this->invoice(), $this->certificate, $this->options());

        // It adds the extension to the document, and that is not left out by the transform.
        $withSignatures = $this->signer->sign($detached->document, $this->certificate, ['signature_id' => 'urn:example:other']);

        $report = $this->validator->validateDetached($withSignatures, $detached->signature, ['document_uri' => self::DOCUMENT_URI]);
        $this->assertFalse($report->areSignaturesValid());
    }

    #[Test]
    public function theSignatureIsVerifiedByXmlsecWithTheDocumentInAFile(): void
    {
        $file = $this->xmlsecDirectory . '/' . self::DOCUMENT_URI;
        $uri = 'file://' . $file;
        $detached = $this->signer->signDetached($this->invoice(), $this->certificate, $this->options($uri));
        file_put_contents($file, $detached->document);

        $this->assertTrue($this->verifyWithXmlsec($detached->signature, 'ID Nothing', null, 'empty,same-doc,local'));
        file_put_contents($file, str_replace('TOSL108', 'TOSL109', $detached->document));
        $this->assertFalse($this->verifyWithXmlsec($detached->signature, 'ID Nothing', null, 'empty,same-doc,local'));
    }

    #[Test]
    public function aSignatureThatXmlsecMakesIsValidatedWithTheDocument(): void
    {
        $file = $this->xmlsecDirectory . '/' . self::DOCUMENT_URI;
        $uri = 'file://' . $file;
        $detached = $this->signer->signDetached($this->invoice(), $this->certificate, $this->options($uri));
        file_put_contents($file, $detached->document);
        $template = '<?xml version="1.0"?>' . "\n" . $this->template('ds', self::RSA_SHA256, self::C14N, [
            [$uri, self::SHA256, [[self::XPATH, SignatureProfile::XPATH_DETACHED], [self::C14N, '']]],
        ]);
        $template = str_replace('<ds:XPath>', '<ds:XPath xmlns:sig="' . SignatureProfile::NS_SIG . '">', $template);

        $input = $this->xmlsecDirectory . '/template.xml';
        $output = $this->xmlsecDirectory . '/signature.xml';
        file_put_contents($input, $template);
        exec(
            'xmlsec1 --sign --enabled-reference-uris empty,same-doc,local --privkey-pem:key '
            . escapeshellarg($this->xmlsecDirectory . '/key.pem') . ',' . escapeshellarg($this->xmlsecDirectory . '/cert.pem')
            . ' --output ' . escapeshellarg($output) . ' ' . escapeshellarg($input) . ' 2>&1',
            $lines,
            $status
        );
        $this->assertSame(0, $status, implode("\n", $lines));

        $report = $this->validator->validateDetached($detached->document, (string) file_get_contents($output), ['document_uri' => $uri]);

        $this->assertTrue($report->isValid(), (string) $report->getSignatures()[0]->getError()?->getMessage());
    }

    #[Test]
    public function theModelThatHoldsTheDocumentIsSignedAsItsXmlIs(): void
    {
        $document = (new \Derafu\Ubl\Service\Deserializer())->deserialize($this->invoice(), 'xml', ['version' => '2.1']);

        $fromTheModel = $this->signer->signDetached($document, $this->certificate, $this->options());
        $fromTheXml = $this->signer->signDetached($this->invoice(), $this->certificate, $this->options() + ['version' => '2.1']);

        $this->assertSame($fromTheXml->document, $fromTheModel->document);
        $this->assertSame($fromTheXml->signature, $fromTheModel->signature);
    }

    #[Test]
    public function xmlDocumentsAreSignedAndValidatedAsTheXmlThatTheySaveIs(): void
    {
        $document = new XmlDocument();
        $document->loadXml($this->invoice());

        $detached = $this->signer->signDetached($document, $this->certificate, $this->options());
        $documentToValidate = new XmlDocument();
        $documentToValidate->loadXml($detached->document);
        $signature = new XmlDocument();
        $signature->loadXml($detached->signature);

        $report = $this->validator->validateDetached($documentToValidate, $signature, ['document_uri' => self::DOCUMENT_URI]);
        $this->assertTrue($report->isValid(), (string) $report->getSignatures()[0]->getError()?->getMessage());
        $this->assertSame(
            $this->signer->signDetached($this->invoice(), $this->certificate, $this->options())->document,
            $detached->document
        );
    }

    #[Test]
    public function aDetachedSignatureCanHaveMoreReferencesAndTheOptionsOfTheSignature(): void
    {
        $detached = $this->signer->signDetached($this->invoice(), $this->certificate, $this->options() + [
            'signature' => ['digest_algorithm' => 'sha512', 'objects' => [['xml' => '<p:A xmlns:p="urn:p" Id="a-1">x</p:A>']]],
            'references' => [['uri' => '#a-1']],
        ]);

        $report = $this->validator->validateDetached($detached->document, $detached->signature, ['document_uri' => self::DOCUMENT_URI]);

        $this->assertTrue($report->areSignaturesValid(), (string) $report->getSignatures()[0]->getError()?->getMessage());
        $this->assertCount(2, $this->elements($detached->signature, '//ds:Reference'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'without the uris' => [[], 'a detached signature needs the options "document_uri" and "signature_uri"'],
            'without the uri of the signature' => [['document_uri' => 'a.xml'], 'a detached signature needs the options "document_uri" and "signature_uri"'],
            'a countersignature' => [['document_uri' => 'a.xml', 'signature_uri' => 'b.xml', 'countersign' => 'x'], 'a detached signature has no countersignature'],
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('invalidProvider')]
    public function theOptionsOfADetachedSignatureThatAreNotValidAreAnError(array $options, string $detail): void
    {
        $this->expectException(UblException::class);
        $this->expectExceptionMessage($detail);

        $this->signer->signDetached($this->invoice(), $this->certificate, $options);
    }

    #[Test]
    public function aReferenceToAnotherResourceCanNotBeSignedWithoutItsResolver(): void
    {
        $this->expectException(\Derafu\Signature\Exception\SignatureException::class);
        $this->expectExceptionMessage('The resource "other.xml" can not be resolved.');

        $this->signer->signDetached($this->invoice(), $this->certificate, $this->options() + [
            'references' => [['uri' => 'other.xml']],
        ]);
    }
}
