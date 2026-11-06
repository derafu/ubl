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

use Derafu\Config\Contract\OptionsInterface;
use Derafu\Config\Options;
use Derafu\Signature\Contract\ResourceResolverInterface;
use Derafu\Signature\Contract\SignatureValidatorInterface as XmlSignatureValidatorInterface;
use Derafu\Signature\Exception\SignatureException;
use Derafu\Ubl\Contract\SignatureValidatorInterface;
use Derafu\Ubl\Exception\UblException;
use Derafu\Ubl\Support\SignatureProfile;
use Derafu\Ubl\Support\SignatureReport;
use Derafu\Xml\Contract\XmlDocumentInterface;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface as OptionsResolverException;

/**
 * Class that validates the signatures of UBL documents with the UBL Digital
 * Signature Profile (OASIS): the signatures are validated by `derafu/signature`,
 * and the document is checked against what the profile requires of it.
 *
 * What the profile allows and this class does not check:
 *
 * - The content of XAdES. The URNs of XAdES are accepted, and the signature of
 *   its `SignedProperties` is validated as any other reference, but that the
 *   signing certificate that they name is the one of the `KeyInfo` is not.
 * - That a reference of a countersignature points to a `ds:Signature` or a
 *   `ds:SignatureValue` of the document. The reference is validated by
 *   `derafu/signature` as any other one, and what it points to is not checked.
 * - That the `cbc:URI` of the attachment of a detached `cac:Signature` is where
 *   the signature that is given is. The signature is given by the caller.
 * - That a detached signature signs every `cac:Signature` of the document that
 *   says that it has one outside: it validates the signature that it is given.
 */
final class SignatureValidator implements SignatureValidatorInterface
{
    public function __construct(
        private readonly XmlSignatureValidatorInterface $validator,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function validate(XmlDocumentInterface|string $xml, array|OptionsInterface $options = []): SignatureReport
    {
        $xml = $xml instanceof XmlDocumentInterface ? $xml->saveXml() : $xml;

        $resolver = $this->resolve($options, ['resource_resolver' => ['types' => [ResourceResolverInterface::class, 'null'], 'default' => null]])
            ->getReader()->getObject('resource_resolver', ResourceResolverInterface::class);

        $results = $this->validator->validateXml(
            $xml,
            ['resource_resolver' => $resolver instanceof ResourceResolverInterface ? $resolver : null]
        );

        return new SignatureReport($results, $this->issues($xml, false));
    }

    /**
     * {@inheritDoc}
     */
    public function validateDetached(
        XmlDocumentInterface|string $document,
        XmlDocumentInterface|string $signature,
        array|OptionsInterface $options
    ): SignatureReport {
        $document = $document instanceof XmlDocumentInterface ? $document->saveXml() : $document;
        $signature = $signature instanceof XmlDocumentInterface ? $signature->saveXml() : $signature;

        $uri = $this->resolve($options, ['document_uri' => ['types' => 'string', 'required' => true]])
            ->getReader()->requireString('document_uri');

        $resolver = new class ($uri, $document) implements ResourceResolverInterface {
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

        return new SignatureReport(
            $this->validator->validateXml($signature, ['resource_resolver' => $resolver]),
            $this->issues($document, true)
        );
    }

    /**
     * Resolves the options with a schema.
     *
     * @param array<array-key, mixed>|OptionsInterface $options
     * @param array<string, array<string, mixed>> $schema
     * @throws UblException If an option is not valid.
     */
    private function resolve(array|OptionsInterface $options, array $schema): OptionsInterface
    {
        try {
            return new Options($options, $schema);
        } catch (OptionsResolverException $e) {
            throw new UblException(
                [
                    'The options of the signature are not valid: {detail}',
                    'detail' => $e->getMessage(),
                ],
                previous: $e
            );
        }
    }

    /**
     * Gives what the document does not follow of the conformance of the
     * profile.
     *
     * @return list<UblException>
     */
    private function issues(string $xml, bool $detached): array
    {
        $dom = new DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);
        if (!$loaded) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        foreach ([
            'cac' => SignatureProfile::NS_CAC,
            'cbc' => SignatureProfile::NS_CBC,
            'ext' => SignatureProfile::NS_EXT,
            'sig' => SignatureProfile::NS_SIG,
            'sbc' => SignatureProfile::NS_SBC,
            'ds' => SignatureProfile::NS_DS,
        ] as $prefix => $namespace) {
            $xpath->registerNamespace($prefix, $namespace);
        }

        return $detached
            ? $this->detachedIssues($xpath)
            : [...$this->extensionIssues($xpath), ...$this->referenceIssues($xpath), ...$this->identifierIssues($xpath)];
    }

    /**
     * The extensions with signatures must say so with their `ext:ExtensionURI`.
     *
     * @return list<UblException>
     */
    private function extensionIssues(DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($this->elements($xpath, '//ext:UBLExtension[.//sig:UBLDocumentSignatures]') as $extension) {
            $uri = $this->text($xpath, 'ext:ExtensionURI', $extension);
            if ($uri === null) {
                $issues[] = new UblException(
                    'An extension with signatures does not have the ext:ExtensionURI that says so.'
                );
            } elseif (!in_array($uri, [SignatureProfile::ENVELOPED, SignatureProfile::ENVELOPED_XADES], true)) {
                $issues[] = new UblException([
                    'The ext:ExtensionURI "{uri}" of an extension with signatures is not one of the profile.',
                    'uri' => $uri,
                ]);
            }
        }

        return $issues;
    }

    /**
     * Each `sbc:ReferencedSignatureID` must be the `cbc:ID` of a `cac:Signature`
     * of the document, and its `cbc:SignatureMethod`, if it has it, one of the
     * enveloped profile.
     *
     * @return list<UblException>
     */
    private function referenceIssues(DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($this->elements($xpath, '//sbc:ReferencedSignatureID') as $reference) {
            $id = trim($reference->textContent);
            $signatures = $this->elements($xpath, '/*/cac:Signature[normalize-space(cbc:ID)=' . DOMXPath::quote($id) . ']');
            if ($signatures === []) {
                $issues[] = new UblException([
                    'The sbc:ReferencedSignatureID "{id}" is not the cbc:ID of a cac:Signature of the document.',
                    'id' => $id,
                ]);
                continue;
            }

            $method = $this->text($xpath, 'cbc:SignatureMethod', $signatures[0]);
            if ($method !== null && !in_array($method, [SignatureProfile::ENVELOPED, SignatureProfile::ENVELOPED_XADES], true)) {
                $issues[] = new UblException([
                    'The cbc:SignatureMethod "{method}" of the cac:Signature "{id}" is not one of the enveloped profile.',
                    'method' => $method,
                    'id' => $id,
                ]);
            }
        }

        return $issues;
    }

    /**
     * The `Id` of a `ds:Signature` or of its `ds:SignatureValue`, that lets
     * another signature countersign it, must be unique among the `Id` of the
     * document.
     *
     * @return list<UblException>
     */
    private function identifierIssues(DOMXPath $xpath): array
    {
        $counts = [];
        foreach ($xpath->query('//@Id') ?: [] as $attribute) {
            $counts[$attribute->nodeValue ?? ''] = ($counts[$attribute->nodeValue ?? ''] ?? 0) + 1;
        }

        $issues = [];
        $reported = [];
        foreach ($this->elements($xpath, '//ds:Signature[@Id] | //ds:SignatureValue[@Id]') as $element) {
            $id = $element->getAttribute('Id');
            if ($counts[$id] > 1 && !isset($reported[$id])) {
                $reported[$id] = true;
                $issues[] = new UblException([
                    'The Id "{id}" of a signature or of its value is not unique in the document.',
                    'id' => $id,
                ]);
            }
        }

        return $issues;
    }

    /**
     * The `cac:Signature` of a signature that is outside of the document must
     * say so with its `cbc:SignatureMethod`, if it has it.
     *
     * @return list<UblException>
     */
    private function detachedIssues(DOMXPath $xpath): array
    {
        $issues = [];
        foreach ($this->elements($xpath, '/*/cac:Signature[cac:DigitalSignatureAttachment]') as $signature) {
            $method = $this->text($xpath, 'cbc:SignatureMethod', $signature);
            if ($method !== null && !in_array($method, [SignatureProfile::DETACHED, SignatureProfile::DETACHED_XADES], true)) {
                $issues[] = new UblException([
                    'The cbc:SignatureMethod "{method}" of a cac:Signature with a signature outside of the document is not one of the detached profile.',
                    'method' => $method,
                ]);
            }
        }

        return $issues;
    }

    /**
     * Gives the elements that an XPath finds.
     *
     * @return list<DOMElement>
     */
    private function elements(DOMXPath $xpath, string $query, ?DOMElement $context = null): array
    {
        $nodes = $xpath->query($query, $context);
        $elements = [];
        foreach ($nodes !== false ? $nodes : [] as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * Gives the text of the first element that an XPath finds, without the
     * spaces around it, or `null` if there is none or it is empty.
     */
    private function text(DOMXPath $xpath, string $query, DOMElement $context): ?string
    {
        $element = $this->elements($xpath, $query, $context)[0] ?? null;
        $text = $element !== null ? trim($element->textContent) : '';

        return $text !== '' ? $text : null;
    }
}
