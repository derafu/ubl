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

use Derafu\Ubl\Exception\UnsupportedVersionException;
use Derafu\Ubl\Exception\ValidationException;

/**
 * Validates a UBL document's XML against the official OASIS XSD of its UBL version.
 */
interface XmlValidatorInterface
{
    /**
     * The document type is resolved from the XML root element, the same way
     * `DeserializerInterface::deserialize()` does for `xml`.
     *
     * @param string|null $version The UBL version (`2.2`), `null` for the default one.
     *
     * @throws UnsupportedVersionException If the version is not supported.
     * @throws ValidationException If `$xml` is not well-formed, its document type cannot be
     * resolved, or it does not validate against that type's XSD.
     */
    public function validate(string $xml, ?string $version = null): void;
}
