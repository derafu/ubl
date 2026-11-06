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

use Derafu\Config\Contract\OptionsInterface;
use Derafu\Signature\Exception\SignatureException;
use Derafu\Ubl\Exception\UblException;
use Derafu\Ubl\Support\SignatureReport;
use Derafu\Xml\Contract\XmlDocumentInterface;

/**
 * Interface for the class that validates the signatures of UBL documents with
 * the UBL Digital Signature Profile (OASIS).
 */
interface SignatureValidatorInterface
{
    /**
     * Validates the signatures that a document has inside (the enveloped
     * profile): each signature, and that the document follows what the profile
     * requires of it.
     *
     * The XML is validated as it is given. A signature is never rejected for
     * not following what the profile recommends (the transform that it uses, for
     * example).
     *
     * @param XmlDocumentInterface|string $xml XML of the document, as an XML
     * document (it is validated as it saves its XML, with its own format
     * settings) or as a string.
     * @param array<array-key, mixed>|OptionsInterface $options Options of the
     * validation: `resource_resolver` (`ResourceResolverInterface|null`), that
     * gives the resources that a signature references and are not in the
     * document.
     * @throws SignatureException If the XML is malformed or has no signatures,
     * or an option is not valid.
     */
    public function validate(XmlDocumentInterface|string $xml, array|OptionsInterface $options = []): SignatureReport;

    /**
     * Validates the signature of a document that is not in it (the detached
     * profile).
     *
     * @param XmlDocumentInterface|string $document XML of the document, as it
     * was signed.
     * @param XmlDocumentInterface|string $signature XML of the signature.
     * @param array<array-key, mixed>|OptionsInterface $options Options of the
     * validation: `document_uri` (`string`, required), the `URI` that the
     * signature has for the document.
     * @throws UblException If an option is not valid.
     * @throws SignatureException If the signature is malformed.
     */
    public function validateDetached(
        XmlDocumentInterface|string $document,
        XmlDocumentInterface|string $signature,
        array|OptionsInterface $options
    ): SignatureReport;
}
