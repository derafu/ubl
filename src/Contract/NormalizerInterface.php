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

/**
 * Gets the decoded data of a document ready for `fromArray()`.
 *
 * `fromArray()` only reads the model-based JSON representation of "UBL 2.1 JSON Alternative
 * Representation" (OASIS Committee Note 01), exactly. A normalizer is the one place where data
 * in other shapes (the legacy-based form of the same note, bare scalars where it asks for an
 * object...) can be brought to that one, so that no other code has to know about them.
 */
interface NormalizerInterface
{
    /**
     * @param array<array-key, mixed> $data The content of the document key of the decoded
     * JSON/YAML (what is handed to `fromArray()`), without the apex of the note.
     * @param class-string $class The `Oasis\Doc\*` class that is going to be built from it.
     * @return array<array-key, mixed> The same data, in the shape `fromArray()` reads.
     */
    public function normalize(array $data, string $class): array;
}
