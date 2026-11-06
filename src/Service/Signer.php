<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Ubl\Service;

use Derafu\Array\ArrayReader;
use Derafu\Certificate\Contract\CertificateInterface;
use Derafu\Config\Contract\OptionsInterface;
use Derafu\Signature\Contract\ResourceResolverInterface;
use Derafu\Signature\Contract\SignatureGeneratorInterface;
use Derafu\Signature\Exception\SignatureException;
use Derafu\Ubl\Contract\DeserializerInterface;
use Derafu\Ubl\Contract\SerializerInterface;
use Derafu\Ubl\Contract\SignerInterface;
use Derafu\Ubl\Exception\UblException;
use Derafu\Ubl\Support\DetachedSignature;
use Derafu\Ubl\Support\SignatureProfile;
use Derafu\Ubl\Support\SignerOptions;
use Derafu\Value\ValueType;
use Derafu\Xml\Contract\XmlDocumentInterface;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Class that signs UBL documents with the UBL Digital Signature Profile
 * (OASIS), for UBL 2.1 to 2.5.
 *
 * The document is built with the model, so the extension and the
 * `cac:Signature` are where the schema of each document type has them, and the
 * signature is made by `derafu/signature` in the place that the profile says.
 * What is signed is the text of the XML that results from the model: it is not
 * written again afterwards.
 *
 * What the profile allows and this class does not do:
 *
 * - Plan the co-signers. The `cac:Signature` of a signature that is added later
 *   is not created, because adding it changes the document and invalidates the
 *   signatures that it has. To plan it, the `cac:Signature` elements are added to
 *   the document before the first signature, and referenced with `signature_id`.
 * - Several detached signatures in one call. All the `cac:Signature` of the
 *   document have to be there before it is signed, and every call makes one
 *   signature and adds one `cac:Signature`.
 * - XAdES. The URNs `...:enveloped:xades` and `...:detached:xades` are only
 *   written if `extension_uri` says so, and the qualifying properties are not
 *   built: a package that makes them gives the `Object` and its reference with
 *   the options `signature` and `references`.
 * - The optional elements of the `cac:Signature` that describe the validation
 *   (`cbc:ValidationDate`, `cbc:ValidationTime`, `cbc:ValidatorID`,
 *   `cbc:CanonicalizationMethod`, `cac:OriginalDocumentReference`). Only its
 *   `cbc:ID`, `cbc:SignatureMethod`, `cac:SignatoryParty` (with the `id` and the
 *   `name` of `signatory`) and, if it is detached, its attachment are written.
 * - Several `ext:UBLExtension` with signatures: a document has one, and the
 *   signatures that are added go in its `sig:UBLDocumentSignatures`.
 *
 * The detached signature is a document of its own that the caller keeps: the
 * `signature_uri` is only written in the `cac:Signature`, it is not read.
 */
final class Signer implements SignerInterface
{
    private const string DOCUMENT_PATTERN = '/^(Derafu\\\\Ubl\\\\V\d+\\\\Oasis\\\\)Doc\\\\([A-Za-z0-9]+)$/';

    public function __construct(
        private readonly SignatureGeneratorInterface $generator,
        private readonly SerializerInterface $serializer = new Serializer(),
        private readonly DeserializerInterface $deserializer = new Deserializer(),
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function sign(
        object|string $document,
        CertificateInterface $certificate,
        array|OptionsInterface $options = []
    ): string {
        $settings = SignerOptions::resolve($options);
        $document = $this->asText($document);

        // A document that has signatures is signed as it is: building it with
        // the model could change what the other signatures sign.
        [$xml, $position] = (is_string($document) ? $this->addInformation($document, $settings) : null)
            ?? $this->build($document, $settings, $certificate);

        return $this->generator->signXml($xml, $certificate, $this->signatureOptions(
            $certificate,
            $settings,
            $this->informationId($settings, $position),
            $position
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function signDetached(
        object|string $document,
        CertificateInterface $certificate,
        array|OptionsInterface $options
    ): DetachedSignature {
        $settings = SignerOptions::resolve($options, detached: true);
        $document = $this->asText($document);
        if (is_string($document) && $this->hasSignatures($document)) {
            throw new UblException(
                'A document that has signatures inside can not get a detached one: its signatures would not be valid.'
            );
        }

        [$data, $class, $name] = $this->load($document, $settings);
        $this->assertNoSignatures($data);
        $id = $this->signatureId($settings, $data, $name);
        $data['Signature'] = [...$this->list($data, 'Signature'), $this->signatureElement(
            $id,
            SignatureProfile::DETACHED,
            $settings,
            $certificate,
            $settings->signatureUri
        )];
        $xml = $this->serialize($class, $data);

        $uri = (string) $settings->documentUri;
        $signature = $this->generator->signXmlDetached(
            $certificate,
            $this->detachedOptions($certificate, $settings, $uri, $xml)
        );

        return new DetachedSignature($xml, $signature);
    }

    /**
     * Gives an XML document as the text that it saves, with its own format
     * settings (the format of the document is not changed: it is what is
     * signed). Anything else is given as it is.
     */
    private function asText(object|string $document): object|string
    {
        return $document instanceof XmlDocumentInterface ? $document->saveXml() : $document;
    }

    /**
     * Builds the document with the extension of the signature and its
     * `cac:Signature`, and gives its XML.
     *
     * @return array{string, int} The XML and the position of the signature.
     */
    private function build(object|string $document, SignerOptions $settings, CertificateInterface $certificate): array
    {
        [$data, $class, $name] = $this->load($document, $settings);
        $this->assertNoSignatures($data);

        $signatureId = $this->signatureId($settings, $data, $name);
        $informationId = $this->informationId($settings, 1);

        $extension = [
            'ExtensionURI' => [['_' => $settings->extensionUri ?? SignatureProfile::ENVELOPED]],
            'ExtensionContent' => [['any' => [$this->signaturesFragment(
                $this->informationFragment($informationId, $settings->includeSignature ? $signatureId : null)
            )]]],
        ];
        if ($settings->extensionId !== null) {
            $extension = ['ID' => [['_' => $settings->extensionId]]] + $extension;
        }

        $extensions = $this->list($data, 'UBLExtensions');
        $first = is_array($extensions[0] ?? null) ? $extensions[0] : [];
        $first['UBLExtension'] = [...$this->listOf($first, 'UBLExtension'), $extension];
        $data['UBLExtensions'] = [$first];

        if ($settings->includeSignature) {
            $data['Signature'] = [...$this->list($data, 'Signature'), $this->signatureElement(
                $signatureId,
                SignatureProfile::ENVELOPED,
                $settings,
                $certificate,
                null
            )];
        }

        return [$this->serialize($class, $data), 1];
    }

    /**
     * Adds a `sac:SignatureInformation` to the `sig:UBLDocumentSignatures` of a
     * document that has signatures, where the new signature goes. The rest of the
     * document is not changed.
     *
     * @return array{string, int}|null The XML and the position of the
     * signature, or `null` if the document does not have signatures.
     */
    private function addInformation(string $xml, SignerOptions $settings): ?array
    {
        $dom = new DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $xpath = $this->xpath($dom);
        $containers = $xpath->query('//sig:UBLDocumentSignatures');
        $container = $containers !== false ? $containers->item($containers->length - 1) : null;
        if (!$container instanceof DOMElement) {
            return null;
        }

        $position = ($xpath->query('//sac:SignatureInformation')->length ?? 0) + 1;
        $informationId = $this->informationId($settings, $position);

        $referenced = null;
        if ($settings->signatureId !== null) {
            $found = $xpath->query('/*/cac:Signature[cbc:ID=' . $this->literal($settings->signatureId) . ']');
            if ($found === false || $found->length === 0) {
                throw new UblException([
                    'The document does not have a cac:Signature with the cbc:ID "{id}".',
                    'id' => $settings->signatureId,
                ]);
            }
            $referenced = $settings->signatureId;
        }
        $existing = $xpath->query('//sac:SignatureInformation[cbc:ID=' . $this->literal($informationId) . ']');
        if ($existing !== false && $existing->length > 0) {
            throw new UblException([
                'The document already has a sac:SignatureInformation with the cbc:ID "{id}".',
                'id' => $informationId,
            ]);
        }

        $fragment = $dom->createDocumentFragment();
        $fragment->appendXML($this->informationFragment($informationId, $referenced));
        $container->appendChild($fragment);

        return [$dom->saveXML() ?: '', $position];
    }

    /**
     * Loads a document as its data, with its class and its name.
     *
     * @return array{array<array-key, mixed>, class-string, string}
     */
    private function load(object|string $document, SignerOptions $settings): array
    {
        if (is_string($document)) {
            $document = $this->deserializer->deserialize(
                $document,
                'xml',
                $settings->version !== null ? [DeserializerInterface::VERSION => $settings->version] : []
            );
        }

        $class = $document::class;
        if (preg_match(self::DOCUMENT_PATTERN, $class, $matches) !== 1 || !method_exists($document, 'toArray')) {
            throw new UblException([
                '{class} is not a UBL document of this package.',
                'class' => $class,
            ]);
        }

        return [ValueType::map()->parse($document->toArray(), 'toArray()'), $class, $matches[2]];
    }

    /**
     * Serializes a document from its data.
     *
     * @param class-string $class
     * @param array<array-key, mixed> $data
     */
    private function serialize(string $class, array $data): string
    {
        return $this->serializer->serialize(ValueType::instanceOf($class)->parse($class::fromArray($data)), 'xml');
    }

    /**
     * Checks that the data of a document do not have signatures inside.
     *
     * @param array<array-key, mixed> $data
     * @throws UblException If it has them.
     */
    private function assertNoSignatures(array $data): void
    {
        if (str_contains((string) json_encode($data['UBLExtensions'] ?? []), 'UBLDocumentSignatures')) {
            throw new UblException(
                'The document already has signatures inside: give its XML to add another one.'
            );
        }
    }

    /**
     * Gives the `cbc:ID` of the `cac:Signature`.
     *
     * @param array<array-key, mixed> $data
     * @throws UblException If the document has a `cac:Signature` with it.
     */
    private function signatureId(SignerOptions $settings, array $data, string $name): string
    {
        $id = $settings->signatureId ?? SignatureProfile::ID_PREFIX . $name;

        foreach ($this->list($data, 'Signature') as $signature) {
            $existing = is_array($signature) ? ArrayReader::fromArray($signature)->getReader('ID')?->getString('_') : null;
            if ($existing === $id) {
                throw new UblException([
                    'The document already has a cac:Signature with the cbc:ID "{id}".',
                    'id' => $id,
                ]);
            }
        }

        return $id;
    }

    /**
     * Gives the `cbc:ID` of the `sac:SignatureInformation`.
     */
    private function informationId(SignerOptions $settings, int $position): string
    {
        return $settings->informationId ?? SignatureProfile::ID_PREFIX . $position;
    }

    /**
     * Gives the data of a `cac:Signature`.
     *
     * @return array<string, mixed>
     */
    private function signatureElement(
        string $id,
        string $method,
        SignerOptions $settings,
        CertificateInterface $certificate,
        ?string $uri
    ): array {
        // The elements that are not the scaffolding of the extensions have the
        // data of a single one as it is, and the ones that can be repeated as a
        // list.
        $party = [];
        $signatoryId = $settings->signatory['id'] ?? null;
        $signatoryName = $settings->signatory['name'] ?? ($certificate->getNames()[0] ?? null);
        if ($signatoryId !== null) {
            $party['PartyIdentification'] = [['ID' => ['_' => $signatoryId]]];
        }
        if ($signatoryName !== null) {
            $party['PartyName'] = [['Name' => ['_' => $signatoryName]]];
        }

        $signature = [
            'ID' => ['_' => $id],
            'SignatureMethod' => ['_' => $method],
        ];
        if ($party !== []) {
            $signature['SignatoryParty'] = $party;
        }
        if ($uri !== null) {
            $signature['DigitalSignatureAttachment'] = ['ExternalReference' => ['URI' => ['_' => $uri]]];
        }

        return $signature;
    }

    /**
     * The `sig:UBLDocumentSignatures` with its signatures.
     */
    private function signaturesFragment(string $information): string
    {
        return '<sig:UBLDocumentSignatures xmlns:sig="' . SignatureProfile::NS_SIG . '"'
            . ' xmlns:sac="' . SignatureProfile::NS_SAC . '"'
            . ' xmlns:sbc="' . SignatureProfile::NS_SBC . '"'
            . ' xmlns:cbc="' . SignatureProfile::NS_CBC . '">'
            . $information
            . '</sig:UBLDocumentSignatures>';
    }

    /**
     * The `sac:SignatureInformation` that the signature goes in.
     */
    private function informationFragment(string $id, ?string $referenced): string
    {
        return '<sac:SignatureInformation xmlns:sac="' . SignatureProfile::NS_SAC . '"'
            . ' xmlns:sbc="' . SignatureProfile::NS_SBC . '"'
            . ' xmlns:cbc="' . SignatureProfile::NS_CBC . '">'
            . '<cbc:ID>' . $id . '</cbc:ID>'
            . ($referenced !== null ? '<sbc:ReferencedSignatureID>' . $referenced . '</sbc:ReferencedSignatureID>' : '')
            . '</sac:SignatureInformation>';
    }

    /**
     * Gives the options of the signature of `derafu/signature`.
     *
     * @return array<array-key, mixed>
     */
    private function signatureOptions(
        CertificateInterface $certificate,
        SignerOptions $settings,
        string $informationId,
        int $position
    ): array {
        $options = $settings->signature + $this->defaults($certificate, $position);

        $references = [$this->documentReference($settings)];
        foreach ($settings->countersign as $id) {
            $references[] = ['uri' => '#' . $id];
        }
        $options['references'] = [...$references, ...$settings->references];

        $options['signature_location'] = [
            'xpath' => '/*/ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent/sig:UBLDocumentSignatures'
                . '/sac:SignatureInformation[cbc:ID=' . $this->literal($informationId) . ']',
            'namespaces' => [
                'ext' => SignatureProfile::NS_EXT,
                'sig' => SignatureProfile::NS_SIG,
                'sac' => SignatureProfile::NS_SAC,
                'cbc' => SignatureProfile::NS_CBC,
            ],
            'position' => 'last_child',
        ];

        return $options;
    }

    /**
     * Gives the options of the detached signature of `derafu/signature`.
     *
     * @return array<array-key, mixed>
     */
    private function detachedOptions(
        CertificateInterface $certificate,
        SignerOptions $settings,
        string $uri,
        string $document
    ): array {
        $options = $settings->signature + $this->defaults($certificate, 1);

        $options['references'] = [
            [
                'uri' => $uri,
                'transforms' => [
                    [
                        'type' => 'xpath',
                        'expression' => SignatureProfile::XPATH_DETACHED,
                        'namespaces' => ['sig' => SignatureProfile::NS_SIG],
                    ],
                    'c14n',
                ],
            ],
            ...$settings->references,
        ];
        $options['resource_resolver'] = new class ($uri, $document) implements ResourceResolverInterface {
            public function __construct(private readonly string $uri, private readonly string $document)
            {
            }

            public function resolve(string $uri): string
            {
                return $uri === $this->uri
                    ? $this->document
                    : throw new SignatureException([
                        'The resource "{uri}" can not be resolved.',
                        'uri' => $uri,
                    ]);
            }
        };

        return $options;
    }

    /**
     * Gives the options of the signature that the signer chooses when they are
     * not given.
     *
     * @return array<array-key, mixed>
     */
    private function defaults(CertificateInterface $certificate, int $position): array
    {
        $type = ArrayReader::fromArray($certificate->getPublicKeyDetails())->getInt('type', OPENSSL_KEYTYPE_RSA);

        return [
            'signature_prefix' => 'ds',
            'digest_algorithm' => 'sha256',
            'signature_algorithm' => match ($type) {
                OPENSSL_KEYTYPE_EC => 'ecdsa-sha256',
                OPENSSL_KEYTYPE_DSA => 'dsa-sha256',
                default => 'rsa-sha256',
            },
            'signature_id' => 'dsig-' . $position,
            'signature_value_id' => 'dsig-' . $position . '-value',
        ];
    }

    /**
     * Gives the reference to the document that the signature has.
     *
     * @return array<string, mixed>
     */
    private function documentReference(SignerOptions $settings): array
    {
        $transforms = match ($settings->xpath) {
            'flexible' => [
                [
                    'type' => 'xpath',
                    'expression' => SignatureProfile::XPATH_FLEXIBLE,
                    'namespaces' => ['sig' => SignatureProfile::NS_SIG],
                ],
                'c14n',
            ],
            'final' => [
                [
                    'type' => 'xpath',
                    'expression' => SignatureProfile::XPATH_FINAL,
                    'namespaces' => ['ds' => SignatureProfile::NS_DS],
                ],
                'c14n',
            ],
            default => ['enveloped', 'c14n'],
        };

        return ['uri' => '', 'transforms' => $transforms];
    }

    /**
     * Indicates if an XML has signatures of the profile inside.
     */
    private function hasSignatures(string $xml): bool
    {
        if (!str_contains($xml, 'UBLDocumentSignatures')) {
            return false;
        }

        $dom = new DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $nodes = $loaded ? $this->xpath($dom)->query('//sig:UBLDocumentSignatures') : false;

        return $nodes !== false && $nodes->length > 0;
    }

    /**
     * Gives an XPath with the prefixes of the profile.
     */
    private function xpath(DOMDocument $dom): DOMXPath
    {
        $xpath = new DOMXPath($dom);
        foreach ([
            'cac' => SignatureProfile::NS_CAC,
            'cbc' => SignatureProfile::NS_CBC,
            'ext' => SignatureProfile::NS_EXT,
            'sig' => SignatureProfile::NS_SIG,
            'sac' => SignatureProfile::NS_SAC,
        ] as $prefix => $namespace) {
            $xpath->registerNamespace($prefix, $namespace);
        }

        return $xpath;
    }

    /**
     * Gives a text as a literal of an XPath.
     */
    private function literal(string $text): string
    {
        return DOMXPath::quote($text);
    }

    /**
     * Gives the list that a key of the data has.
     *
     * @param array<array-key, mixed> $data
     * @return list<mixed>
     */
    private function list(array $data, string $key): array
    {
        return $this->listOf($data, $key);
    }

    /**
     * Gives the list that a key of an array has.
     *
     * @param array<array-key, mixed> $data
     * @return list<mixed>
     */
    private function listOf(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }
}
