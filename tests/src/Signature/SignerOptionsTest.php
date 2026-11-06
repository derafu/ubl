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

use Derafu\Config\Options;
use Derafu\Ubl\Exception\UblException;
use Derafu\Ubl\Support\SignerOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The options of the signature: the ones that are not given have their default
 * value, the ones that are not valid are an error of the package that says which
 * one is, and they are valid only for the signature that they are given to.
 */
#[CoversClass(SignerOptions::class)]
#[UsesClass(UblException::class)]
final class SignerOptionsTest extends TestCase
{
    #[Test]
    public function theOptionsThatAreNotGivenHaveTheirDefaultValue(): void
    {
        $options = SignerOptions::resolve([]);

        $this->assertNull($options->version);
        $this->assertSame('flexible', $options->xpath);
        $this->assertTrue($options->includeSignature);
        $this->assertNull($options->signatureId);
        $this->assertNull($options->informationId);
        $this->assertNull($options->extensionId);
        $this->assertNull($options->extensionUri);
        $this->assertNull($options->signatory);
        $this->assertSame([], $options->countersign);
        $this->assertSame([], $options->references);
        $this->assertSame([], $options->signature);
        $this->assertNull($options->documentUri);
        $this->assertNull($options->signatureUri);
    }

    #[Test]
    public function theOptionsAreTheOnesGiven(): void
    {
        $options = SignerOptions::resolve([
            'version' => '2.3',
            'xpath' => 'final',
            'include_signature' => false,
            'signature_id' => 'urn:a',
            'information_id' => 'urn:b',
            'extension_id' => 'urn:c',
            'extension_uri' => 'urn:d',
            'signatory' => ['id' => '1', 'name' => 'N'],
            'countersign' => ['x', 'y'],
            'references' => [['uri' => '#z']],
            'signature' => ['digest_algorithm' => 'sha512'],
        ]);

        $this->assertSame('2.3', $options->version);
        $this->assertSame('final', $options->xpath);
        $this->assertFalse($options->includeSignature);
        $this->assertSame('urn:a', $options->signatureId);
        $this->assertSame('urn:b', $options->informationId);
        $this->assertSame('urn:c', $options->extensionId);
        $this->assertSame('urn:d', $options->extensionUri);
        $this->assertSame(['id' => '1', 'name' => 'N'], $options->signatory);
        $this->assertSame(['x', 'y'], $options->countersign);
        $this->assertSame([['uri' => '#z']], $options->references);
        $this->assertSame(['digest_algorithm' => 'sha512'], $options->signature);
    }

    #[Test]
    public function aSingleCountersignatureIsAList(): void
    {
        $this->assertSame(['x'], SignerOptions::resolve(['countersign' => 'x'])->countersign);
        $this->assertSame([], SignerOptions::resolve(['countersign' => null])->countersign);
    }

    #[Test]
    public function aSignatoryMayHaveOnlyOneOfItsKeys(): void
    {
        $this->assertSame(['id' => null, 'name' => 'N'], SignerOptions::resolve(['signatory' => ['name' => 'N']])->signatory);
        $this->assertSame(['id' => 'I', 'name' => null], SignerOptions::resolve(['signatory' => ['id' => 'I']])->signatory);
    }

    #[Test]
    public function theOptionsCanBeAnOptionsObject(): void
    {
        $options = SignerOptions::resolve(new Options(['xpath' => 'final']));

        $this->assertSame('final', $options->xpath);
    }

    #[Test]
    public function theOptionsOfADetachedSignatureHaveTheUris(): void
    {
        $options = SignerOptions::resolve(['document_uri' => 'a.xml', 'signature_uri' => 'b.xml'], detached: true);

        $this->assertSame('a.xml', $options->documentUri);
        $this->assertSame('b.xml', $options->signatureUri);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'an option that does not exist' => [['other' => 1], 'other'],
            'a version that is not a string' => [['version' => 2.1], 'version'],
            'a transform that does not exist' => [['xpath' => 'strict'], 'xpath'],
            'a signature element that is not a bool' => [['include_signature' => 'yes'], 'include_signature'],
            'an identifier with spaces' => [['signature_id' => 'a b'], 'an identifier can not be empty nor have spaces'],
            'an identifier with quotes' => [['information_id' => "a'b"], 'an identifier can not be empty nor have spaces'],
            'an identifier that is empty' => [['extension_id' => ''], 'an identifier can not be empty nor have spaces'],
            'an identifier with a mark' => [['signature_id' => 'a<b'], 'an identifier can not be empty nor have spaces'],
            'an identifier that is not a string' => [['signature_id' => 1], 'signature_id'],
            'a signatory that is not an array' => [['signatory' => 'N'], 'signatory'],
            'a signatory with a key that does not exist' => [['signatory' => ['code' => 'N']], 'the key "code" of "signatory" is not valid'],
            'a signatory id that is not a string' => [['signatory' => ['id' => 1]], 'the "id" of "signatory" must be a string'],
            'a signatory name that is not a string' => [['signatory' => ['name' => []]], 'the "name" of "signatory" must be a string'],
            'a countersignature that is not a list' => [['countersign' => ['a' => 'x']], '"countersign" must be an identifier or a list of identifiers'],
            'a countersignature with an identifier that is not valid' => [['countersign' => ['a b']], 'an identifier of "countersign" can not be empty'],
            'a countersignature that is not a string' => [['countersign' => [1]], 'an identifier of "countersign" can not be empty'],
            'references that are not an array' => [['references' => 'a'], 'references'],
            'a signature that is not an array' => [['signature' => 'a'], 'signature'],
            'a reference that the signer decides' => [['signature' => ['reference' => 'a']], 'the option "reference" of "signature" is decided by the signer'],
            'references that the signer decides' => [['signature' => ['references' => []]], 'the option "references" of "signature" is decided by the signer'],
            'a location that the signer decides' => [['signature' => ['signature_location' => []]], 'the option "signature_location" of "signature" is decided by the signer'],
            'a resolver that the signer decides' => [['signature' => ['resource_resolver' => null]], 'the option "resource_resolver" of "signature" is decided by the signer'],
            'the uris of a detached signature' => [['document_uri' => 'a.xml'], 'are only for a detached signature'],
            'the uri of a signature' => [['signature_uri' => 'a.xml'], 'are only for a detached signature'],
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('invalidProvider')]
    public function anOptionThatIsNotValidIsAnErrorThatSaysWhich(array $options, string $detail): void
    {
        try {
            SignerOptions::resolve($options);
            $this->fail('It should have failed.');
        } catch (UblException $e) {
            $this->assertStringStartsWith('The options of the signature are not valid: ', $e->getMessage());
            $this->assertStringContainsString($detail, $e->getMessage());
        }
    }

    #[Test]
    public function theOptionsThatAreNotValidKeepTheExceptionOfTheResolverWhenItIsOne(): void
    {
        try {
            SignerOptions::resolve(['xpath' => 'strict']);
            $this->fail('It should have failed.');
        } catch (UblException $e) {
            $this->assertNotNull($e->getPrevious());
        }
    }
}
