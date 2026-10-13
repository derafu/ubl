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

use Derafu\Ubl\Exception\DeserializationException;
use Derafu\Ubl\Exception\SerializationException;
use JsonException;

/**
 * A document as JSON, in the model-based representation of the OASIS note (see
 * `ArrayFormatHandler`): `{"_D": "...", "_A": "...", "_B": "...", "Quotation": {...}}`.
 * The same structure `yaml` uses.
 */
final class JsonHandler extends ArrayFormatHandler
{
    public function getFormat(): string
    {
        return 'json';
    }

    protected function encode(array $data, string $class): string
    {
        try {
            return (string) json_encode(EmptyObjects::of($data), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new SerializationException([
                'Could not encode {class} as JSON: {message}',
                'class' => $class,
                'message' => $e->getMessage(),
            ], previous: $e);
        }
    }

    protected function decode(string $content): array
    {
        try {
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DeserializationException([
                'Could not decode JSON: {message}',
                'message' => $e->getMessage(),
            ], previous: $e);
        }

        if (!is_array($data)) {
            throw new DeserializationException([
                'Decoded JSON must be an array, got {type}.',
                'type' => get_debug_type($data),
            ]);
        }

        return $data;
    }
}
