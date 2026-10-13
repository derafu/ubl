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

use Derafu\Ubl\Exception\ValidationException;

/**
 * Validates a UBL document's XML against the official OASIS XSD.
 */
interface XmlValidatorInterface
{
    /**
     * The document type is resolved from the XML root element, the same way
     * `DeserializerInterface::deserialize()` does for `xml`.
     *
     * @throws ValidationException If `$xml` is not well-formed, its document type cannot be
     * resolved, or it does not validate against that type's XSD.
     */
    public function validate(string $xml): void;
}
