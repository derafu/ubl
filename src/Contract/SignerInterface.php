<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Ubl\Contract;

use Derafu\Certificate\Contract\CertificateInterface;
use Derafu\Config\Contract\OptionsInterface;
use Derafu\Signature\Exception\SignatureException;
use Derafu\Ubl\Exception\UblException;
use Derafu\Ubl\Support\DetachedSignature;
use Derafu\Xml\Contract\XmlDocumentInterface;

/**
 * Interface for the class that signs UBL documents with the UBL Digital
 * Signature Profile (OASIS).
 */
interface SignerInterface
{
    /**
     * Signs a document with the enveloped profile: the signature is inside the
     * document, in an `ext:UBLExtension`.
     *
     * The document is an object of the model, or its XML. An XML that already
     * has signatures is signed as it is, adding the signature to the
     * `sig:UBLDocumentSignatures` that it has (a co-signature, or a
     * countersignature if `countersign` is given). Anything else is built with
     * the model: the extension with its `sig:UBLDocumentSignatures` and, if
     * `include_signature` is `true`, the `cac:Signature` where the schema of the
     * document has it.
     *
     * @param XmlDocumentInterface|object|string $document The document, as an
     * object of the model, as an XML document or as XML. An XML document is
     * signed as it saves its XML, with its own format settings: its format is
     * not changed.
     * @param CertificateInterface $certificate Certificate to sign with.
     * @param array<array-key, mixed>|OptionsInterface $options Options of the
     * signature. They are valid only for this signature, and the ones that are
     * not given have the default value:
     * - `version` (`string|null`, default `null`): The version of UBL of the XML
     *   of the document (the default one of the registry if it is `null`).
     * - `xpath` (`string`, default `flexible`): The transform of the signature.
     *   `flexible` leaves out every `sig:UBLDocumentSignatures`, so other
     *   signatures can be added afterwards without invalidating this one.
     *   `final` leaves out only this signature, so another signature added
     *   afterwards invalidates it. `enveloped` is the enveloped signature
     *   transform of XML Signature.
     * - `include_signature` (`bool`, default `true`): Whether the document has
     *   the `cac:Signature` of the signature. An XML that already has
     *   signatures does not get one: adding it would invalidate them.
     * - `signature_id` (`string|null`): The `cbc:ID` of the `cac:Signature`, that
     *   the `sbc:ReferencedSignatureID` has. Without it, it is
     *   `urn:oasis:names:specification:ubl:signature:` and the name of the
     *   document (`Invoice`). In an XML that already has signatures, it is the
     *   `cbc:ID` of a `cac:Signature` of the document that the new signature
     *   references.
     * - `information_id` (`string|null`): The `cbc:ID` of the
     *   `sac:SignatureInformation`. Without it, it is
     *   `urn:oasis:names:specification:ubl:signature:` and its position.
     * - `extension_id` (`string|null`): The `cbc:ID` of the `ext:UBLExtension`.
     * - `extension_uri` (`string|null`): The `ext:ExtensionURI`. Without it, the
     *   one of the enveloped profile.
     * - `signatory` (`array|null`): The `cac:SignatoryParty`, with `id` and
     *   `name`. Without them, the party has no identification and its name is
     *   the common name of the certificate.
     * - `countersign` (`string|list<string>`): The `Id` of the signatures or of
     *   the signature values that this signature signs too.
     * - `references` (`list<array>`): More references of the signature, as the
     *   ones of `derafu/signature`.
     * - `signature` (`array`): The options of the signature of
     *   `derafu/signature`, but `reference`, `references`, `signature_location`
     *   and `resource_resolver`. The digest is SHA-256 and the signature is of
     *   the type of the key of the certificate with SHA-256, unless it says
     *   other; the elements of the signature have the prefix `ds`, and the
     *   signature and its value have an `Id`.
     * @return string The XML of the signed document.
     * @throws UblException If an option is not valid or the document can not
     * be signed.
     * @throws SignatureException If the signature can not be made.
     */
    public function sign(
        object|string $document,
        CertificateInterface $certificate,
        array|OptionsInterface $options = []
    ): string;

    /**
     * Signs a document with the detached profile: the signature is in another
     * document, and what it signs is the document.
     *
     * The document has a `cac:Signature` that says so, with the `URI` of the
     * signature. The document that is returned must be kept as it is: it is
     * what the signature signs.
     *
     * It takes the options of `sign()`, and the ones of the `URI` of the
     * document and of the signature, that are required:
     * - `document_uri` (`string`): The `URI` of the document, that the signature
     *   has in its reference.
     * - `signature_uri` (`string`): The `URI` of the signature, that the document
     *   has in its `cac:Signature`.
     *
     * @param XmlDocumentInterface|object|string $document The document, as an
     * object of the model, as an XML document or as XML, without signatures
     * inside.
     * @param CertificateInterface $certificate Certificate to sign with.
     * @param array<array-key, mixed>|OptionsInterface $options Options of the
     * signature.
     * @return DetachedSignature The document and its signature.
     * @throws UblException If an option is not valid or the document can not
     * be signed.
     * @throws SignatureException If the signature can not be made.
     */
    public function signDetached(
        object|string $document,
        CertificateInterface $certificate,
        array|OptionsInterface $options
    ): DetachedSignature;
}
