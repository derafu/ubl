<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Ubl\Support;

use Derafu\Config\Contract\OptionsInterface;
use Derafu\Config\Options;
use Derafu\Ubl\Exception\UblException;
use Derafu\Value\ValueType;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface as OptionsResolverException;
use Symfony\Component\OptionsResolver\Options as ResolverOptions;

/**
 * Options of the signature of a UBL document, resolved with their schema.
 *
 * They are valid only for the signature that they are given to: the ones that
 * are not given have their default value, and nothing is kept for the next
 * one.
 */
final class SignerOptions
{
    /**
     * What is not valid in an identifier: it is written in an XML text and in an
     * XPath of the signature.
     */
    private const string ID_PATTERN = '/^[^\s\'"<>&]+$/';

    /**
     * The options of the signature of `derafu/signature` that the signer decides.
     */
    private const array RESERVED = ['reference', 'references', 'signature_location', 'resource_resolver'];

    /**
     * @param array{id: string|null, name: string|null}|null $signatory
     * @param list<string> $countersign
     * @param array<array-key, mixed> $references
     * @param array<array-key, mixed> $signature
     */
    private function __construct(
        public readonly ?string $version,
        public readonly string $xpath,
        public readonly bool $includeSignature,
        public readonly ?string $signatureId,
        public readonly ?string $informationId,
        public readonly ?string $extensionId,
        public readonly ?string $extensionUri,
        public readonly ?array $signatory,
        public readonly array $countersign,
        public readonly array $references,
        public readonly array $signature,
        public readonly ?string $documentUri,
        public readonly ?string $signatureUri,
    ) {
    }

    /**
     * Resolves the options of a signature.
     *
     * @param array<array-key, mixed>|OptionsInterface $options
     * @param bool $detached Whether the signature is detached: the document
     * and the signature have a `URI` each one, and what it signs is the
     * document.
     * @throws UblException If an option is not valid.
     */
    public static function resolve(array|OptionsInterface $options, bool $detached = false): self
    {
        try {
            $reader = (new Options($options, self::schema()))->getReader();
        } catch (OptionsResolverException $e) {
            throw self::invalid($e->getMessage(), $e);
        }

        $signatory = $reader->getArray('signatory');
        $countersign = $reader->requireArray('countersign');
        $references = $reader->requireArray('references');
        $signature = $reader->requireArray('signature');

        // The normalizers give the shape of these options.
        /** @var array{id: string|null, name: string|null}|null $signatory */
        /** @var list<string> $countersign */
        $settings = new self(
            version: $reader->getString('version'),
            xpath: $reader->requireString('xpath'),
            includeSignature: $reader->requireBool('include_signature'),
            signatureId: $reader->getString('signature_id'),
            informationId: $reader->getString('information_id'),
            extensionId: $reader->getString('extension_id'),
            extensionUri: $reader->getString('extension_uri'),
            signatory: $signatory,
            countersign: $countersign,
            references: $references,
            signature: $signature,
            documentUri: $reader->getString('document_uri'),
            signatureUri: $reader->getString('signature_uri'),
        );

        $settings->assertConsistent($detached);

        return $settings;
    }

    /**
     * Creates the exception for an option that is not valid.
     */
    private static function invalid(string $detail, ?OptionsResolverException $previous = null): UblException
    {
        return new UblException(
            [
                'The options of the signature are not valid: {detail}',
                'detail' => $detail,
            ],
            previous: $previous
        );
    }

    /**
     * Checks the options that depend on each other, and on the kind of
     * signature.
     *
     * @throws UblException If the options are not valid together.
     */
    private function assertConsistent(bool $detached): void
    {
        $detail = null;
        if ($detached && ($this->documentUri === null || $this->signatureUri === null)) {
            $detail = 'a detached signature needs the options "document_uri" and "signature_uri".';
        } elseif (!$detached && ($this->documentUri !== null || $this->signatureUri !== null)) {
            $detail = 'the options "document_uri" and "signature_uri" are only for a detached signature.';
        } elseif ($detached && $this->countersign !== []) {
            $detail = 'a detached signature has no countersignature: the option "countersign" is for the signatures of the document.';
        } else {
            foreach (self::RESERVED as $key) {
                if (array_key_exists($key, $this->signature)) {
                    $detail = 'the option "' . $key . '" of "signature" is decided by the signer.';
                    break;
                }
            }
        }

        if ($detail !== null) {
            throw self::invalid($detail);
        }
    }

    /**
     * Schema of the options.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function schema(): array
    {
        return [
            // The version of UBL of the document, when it is given as XML.
            'version' => ['types' => ['string', 'null'], 'default' => null],
            // The transform of the signature: `flexible` and `final` are the
            // ones of the profile, `enveloped` is the enveloped signature of
            // XML Signature.
            'xpath' => [
                'types' => 'string',
                'default' => 'flexible',
                'choices' => ['flexible', 'final', 'enveloped'],
            ],
            // Whether the document has the `cac:Signature` of the signature.
            'include_signature' => ['types' => 'bool', 'default' => true],
            // The `cbc:ID` of the `cac:Signature`, that the `sbc:ReferencedSignatureID` has.
            'signature_id' => [
                'types' => ['string', 'null'],
                'default' => null,
                'normalizer' => self::normalizeId(...),
            ],
            // The `cbc:ID` of the `sac:SignatureInformation`.
            'information_id' => [
                'types' => ['string', 'null'],
                'default' => null,
                'normalizer' => self::normalizeId(...),
            ],
            // The `cbc:ID` of the `ext:UBLExtension`.
            'extension_id' => [
                'types' => ['string', 'null'],
                'default' => null,
                'normalizer' => self::normalizeId(...),
            ],
            // The `ext:ExtensionURI` of the extension.
            'extension_uri' => ['types' => ['string', 'null'], 'default' => null],
            // The `cac:SignatoryParty`: its `id` and its `name`.
            'signatory' => [
                'types' => ['array', 'null'],
                'default' => null,
                'normalizer' => self::normalizeSignatory(...),
            ],
            // The `Id` of the signatures or signature values that this one signs.
            'countersign' => [
                'types' => ['string', 'array', 'null'],
                'default' => [],
                'normalizer' => self::normalizeCountersign(...),
            ],
            // Other references of the signature (they are the ones of `derafu/signature`).
            'references' => ['types' => 'array', 'default' => []],
            // The options of the signature of `derafu/signature`.
            'signature' => ['types' => 'array', 'default' => []],
            // The `URI` that the document has for the signature that is detached, and the one of the signature.
            'document_uri' => ['types' => ['string', 'null'], 'default' => null],
            'signature_uri' => ['types' => ['string', 'null'], 'default' => null],
        ];
    }

    /**
     * Normalizes an identifier.
     */
    private static function normalizeId(ResolverOptions $options, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match(self::ID_PATTERN, $value) !== 1) {
            throw self::invalid('an identifier can not be empty nor have spaces, quotes, "<", ">" or "&".');
        }

        return $value;
    }

    /**
     * Normalizes the signatory party.
     *
     * @return array{id: string|null, name: string|null}|null
     */
    private static function normalizeSignatory(ResolverOptions $options, mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        // The schema says it is an array.
        $value = ValueType::map()->parse($value, 'signatory');
        $unknown = array_diff(array_map(strval(...), array_keys($value)), ['id', 'name']);
        if ($unknown !== []) {
            throw self::invalid('the key "' . implode('", "', $unknown) . '" of "signatory" is not valid; the valid ones are: id, name.');
        }
        foreach (['id', 'name'] as $key) {
            if (isset($value[$key]) && !is_string($value[$key])) {
                throw self::invalid('the "' . $key . '" of "signatory" must be a string.');
            }
        }

        $id = $value['id'] ?? null;
        $name = $value['name'] ?? null;

        return ['id' => is_string($id) ? $id : null, 'name' => is_string($name) ? $name : null];
    }

    /**
     * Normalizes the identifiers that are countersigned.
     *
     * @return list<string>
     */
    private static function normalizeCountersign(ResolverOptions $options, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = is_array($value) ? $value : [$value];
        if (!array_is_list($items)) {
            throw self::invalid('"countersign" must be an identifier or a list of identifiers.');
        }

        $ids = [];
        foreach ($items as $item) {
            if (!is_string($item) || preg_match(self::ID_PATTERN, $item) !== 1) {
                throw self::invalid('an identifier of "countersign" can not be empty nor have spaces, quotes, "<", ">" or "&".');
            }
            $ids[] = $item;
        }

        return $ids;
    }
}
