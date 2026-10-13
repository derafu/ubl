<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Ubl\Service\Format;

use stdClass;

/**
 * An empty array in the data of a document is an empty object (an aggregate that is present
 * with nothing in it, `<cac:Item/>`): lists are never empty there, an empty list is simply
 * absent. As an array it would be written as `[]`, and the OASIS note asks for an object,
 * `{}`. Used to write the data and to validate it.
 */
final class EmptyObjects
{
    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>|stdClass
     */
    public static function of(array $data): array|stdClass
    {
        if ($data === []) {
            return new stdClass();
        }

        return array_map(
            static fn (mixed $value): mixed => is_array($value) ? self::of($value) : $value,
            $data
        );
    }
}
