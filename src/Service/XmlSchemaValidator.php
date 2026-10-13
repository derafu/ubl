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

use Derafu\Ubl\Contract\VersionInterface;
use Derafu\Ubl\Contract\VersionRegistryInterface;
use Derafu\Ubl\Contract\XmlValidatorInterface;
use Derafu\Ubl\Exception\ValidationException;
use DOMDocument;
use LibXMLError;

/**
 * Validates a document's XML directly against the official OASIS XSD of its UBL version, bundled in
 * `resources/schemas/xsd/<version>/`, with nothing but `DOMDocument::schemaValidate()` and libxml's own error
 * reporting: no `derafu/xml` dependency, so this package does not inherit its non-translatable
 * `XmlException`. The document type (and so, which XSD file to use) is resolved the same way
 * `Format\XmlHandler` resolves it for deserializing, via the shared `RootElementResolver`.
 */
final class XmlSchemaValidator implements XmlValidatorInterface
{
    public function __construct(
        private readonly VersionRegistryInterface $versions = new VersionRegistry(),
    ) {
    }

    public function validate(string $xml, ?string $version = null): void
    {
        $version = $this->versions->get($version);
        $class = RootElementResolver::resolve($xml, $version);

        if ($class === null) {
            throw new ValidationException([
                'Could not resolve a UBL document type from the XML root element.',
            ]);
        }

        $xsd = $this->xsdPathFor($class, $version);

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);

        if (!$loaded) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            throw new ValidationException([
                'Could not parse the XML to validate it: {errors}',
                'errors' => $this->describeErrors($errors),
            ], $errors);
        }

        $isValid = $dom->schemaValidate($xsd);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$isValid) {
            throw new ValidationException([
                '{class} does not validate against its UBL schema: {errors}',
                'class' => $class,
                'errors' => $this->describeErrors($errors),
            ], $errors);
        }
    }

    /**
     * @param class-string $class
     */
    private function xsdPathFor(string $class, VersionInterface $version): string
    {
        $parts = explode('\\', $class);
        $path = $version->getXsdPath((string) end($parts));

        if (!is_file($path)) {
            throw new ValidationException([
                'No bundled XSD for "{class}" ("{path}" does not exist).',
                'class' => $class,
                'path' => $path,
            ]);
        }

        return $path;
    }

    /**
     * @param list<LibXMLError> $errors
     */
    private function describeErrors(array $errors): string
    {
        if ($errors === []) {
            return 'unknown error';
        }

        return implode('; ', array_map(
            fn (LibXMLError $error): string => trim($error->message) . " (line {$error->line})",
            $errors
        ));
    }
}
