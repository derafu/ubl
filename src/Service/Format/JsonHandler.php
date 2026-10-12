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

use Derafu\Ubl\Contract\FormatHandlerInterface;
use Derafu\Ubl\Exception\DeserializationException;
use Derafu\Ubl\Exception\SerializationException;
use JsonException;

/**
 * A document as JSON, with its `toArray()` wrapped under a single top-level key naming the
 * document type (`{"Quotation": {...}}`), the same convention `yaml` uses.
 */
final class JsonHandler implements FormatHandlerInterface
{
    use ArrayWireFormatTrait;

    public function serialize(object $document): string
    {
        try {
            return (string) json_encode($this->wrap($document), JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SerializationException([
                'Could not encode {class} as JSON: {message}',
                'class' => $document::class,
                'message' => $e->getMessage(),
            ], previous: $e);
        }
    }

    public function deserialize(string $content, ?string $class = null): object
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

        [$resolvedClass, $payload] = $this->unwrap($data, $class);

        return $resolvedClass::fromArray($payload);
    }

    public function getFormat(): string
    {
        return 'json';
    }
}
