<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsUbl\Service;

/**
 * Compares JSON/YAML data the way a JSON consumer would: by value, not by spelling.
 */
trait CanonicalizesDataTrait
{
    /**
     * What two spellings of the same value have in common: a number by its value (`100.0` and
     * `100` are the same JSON number), a time of the UTC by its offset (`12:00:01.000Z` and
     * `12:00:01+00:00`), an empty element by an empty object (the note writes `<cac:Item/>` as
     * `{"_": ""}`), and an object by its properties, in no order (a JSON object has none).
     */
    private function canonical(mixed $data): mixed
    {
        if (is_array($data)) {
            if ($data === ['_' => '']) {
                return [];
            }

            $data = array_map(fn (mixed $item): mixed => $this->canonical($item), $data);
            if (!array_is_list($data)) {
                ksort($data);
            }

            return $data;
        }

        if (is_string($data) && preg_match('/^(\d\d:\d\d:\d\d)(?:\.0+)?Z$/', $data, $match) === 1) {
            return $match[1] . '+00:00';
        }

        return is_float($data) && floor($data) === $data && abs($data) < 1e15 ? (int) $data : $data;
    }
}
