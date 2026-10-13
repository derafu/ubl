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
 * Validates a UBL document in its JSON representation (the model-based one of the OASIS note,
 * which `yaml` carries as the very same structure) against the official OASIS JSON schemas.
 */
interface JsonValidatorInterface
{
    /**
     * The document type is resolved from the single property of `$data` that does not start
     * with `_`, like `DeserializerInterface::deserialize()` does.
     *
     * @param array<array-key, mixed> $data The decoded JSON or YAML: the apex (`_D`, `_A`, `_B`
     * and `_E`, if any) and the property named as the document (`Invoice`).
     * @param string|null $version The UBL version (`2.2`), `null` for the default one.
     *
     * @throws UnsupportedVersionException If the version is not supported.
     * @throws ValidationException If the document type cannot be resolved, there is no JSON
     * validator available (`opis/json-schema` is not installed), OASIS has published no JSON
     * schemas for the version, or `$data` does not validate against the schema of that type.
     */
    public function validate(array $data, ?string $version = null): void;
}
