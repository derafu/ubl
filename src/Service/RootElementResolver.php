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

use Derafu\Ubl\Oasis\Xml\DocumentRegistry;
use DOMDocument;

/**
 * Resolves an `Oasis\Doc\*` class from an XML string's own root element, via the generated
 * `DocumentRegistry`. Shared by `Format\XmlHandler` (to auto-detect what to deserialize into)
 * and `XmlSchemaValidator` (to auto-detect which XSD to validate against): both need exactly this,
 * and nothing else about the XML.
 */
final class RootElementResolver
{
    /**
     * @return class-string|null `null` when the content is not well-formed XML, or its root
     * element does not match any known UBL document.
     */
    public static function resolve(string $xml): ?string
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $dom->documentElement === null) {
            return null;
        }

        $root = $dom->documentElement;
        $class = DocumentRegistry::classFor($root->namespaceURI ?? '', $root->localName ?? '');

        return $class !== null && class_exists($class) ? $class : null;
    }
}
