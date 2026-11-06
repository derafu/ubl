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

/**
 * What the UBL Digital Signature Profile (OASIS) defines: the namespaces of its
 * elements, the URNs that say how a document is signed and the XPath
 * expressions of its transforms.
 *
 * The text is the same for UBL 2.1 to 2.5.
 */
final class SignatureProfile
{
    public const string NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    public const string NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public const string NS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    public const string NS_SIG = 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2';

    public const string NS_SAC = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2';

    public const string NS_SBC = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2';

    public const string NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    /**
     * The `ExtensionURI` of an extension with signatures in the document, and
     * the `cbc:SignatureMethod` of its `cac:Signature`.
     */
    public const string ENVELOPED = 'urn:oasis:names:specification:ubl:dsig:enveloped';

    public const string ENVELOPED_XADES = 'urn:oasis:names:specification:ubl:dsig:enveloped:xades';

    /**
     * The `cbc:SignatureMethod` of a `cac:Signature` whose signature is not in
     * the document.
     */
    public const string DETACHED = 'urn:oasis:names:specification:ubl:dsig:detached';

    public const string DETACHED_XADES = 'urn:oasis:names:specification:ubl:dsig:detached:xades';

    /**
     * The URNs of the profile.
     */
    public const array METHODS = [
        self::ENVELOPED,
        self::ENVELOPED_XADES,
        self::DETACHED,
        self::DETACHED_XADES,
    ];

    /**
     * The prefix of the `cbc:ID` that the elements of the signatures have when
     * they are not given.
     */
    public const string ID_PREFIX = 'urn:oasis:names:specification:ubl:signature:';

    /**
     * The transform that lets other signatures be added afterwards inside the
     * extension without invalidating this one: it leaves out every
     * `sig:UBLDocumentSignatures` of the document.
     */
    public const string XPATH_FLEXIBLE = 'count(ancestor-or-self::sig:UBLDocumentSignatures | here()/ancestor::sig:UBLDocumentSignatures[1]) > count(ancestor-or-self::sig:UBLDocumentSignatures)';

    /**
     * The transform of a "final" signature: it leaves out only the signature
     * itself, so another signature added afterwards invalidates it.
     */
    public const string XPATH_FINAL = 'count(ancestor-or-self::ds:Signature | here()/ancestor::ds:Signature[1]) > count(ancestor-or-self::ds:Signature)';

    /**
     * The transform of the signature of a document that is not in it: the
     * document without its `sig:UBLDocumentSignatures`.
     */
    public const string XPATH_DETACHED = 'count(ancestor-or-self::sig:UBLDocumentSignatures)=0';
}
