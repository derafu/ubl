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

/**
 * Shared by the format handlers whose wire format is a plain array (`json`, `yaml`): wraps a
 * document's `toArray()` under its own short class name, and resolves the target class back
 * from that same key when the caller does not pass one explicitly. `Doc` is flat, so the key
 * maps to its class by simple string concatenation, no registry needed.
 */
trait ArrayWireFormatTrait
{
    /**
     * @return array<string, mixed>
     */
    private function wrap(mixed $document): array
    {
        if (!is_object($document) || !method_exists($document, 'toArray')) {
            throw new SerializationException([
                '{type} cannot be serialized: it has no toArray() method.',
                'type' => get_debug_type($document),
            ]);
        }

        return [self::shortClassName($document::class) => $document->toArray()];
    }

    /**
     * @param array<array-key, mixed> $data
     * @param class-string|null $class
     * @return array{0: class-string, 1: array<array-key, mixed>}
     */
    private function unwrap(array $data, ?string $class): array
    {
        if (count($data) !== 1) {
            throw new DeserializationException([
                'Expected exactly one top-level key naming the document type, got {count}.',
                'count' => count($data),
            ]);
        }

        $key = array_key_first($data);
        $payload = $data[$key];

        if (!is_array($payload)) {
            throw new DeserializationException([
                'The content of "{key}" must be an array, got {type}.',
                'key' => $key,
                'type' => get_debug_type($payload),
            ]);
        }

        $resolved = $class ?? ('Derafu\\Ubl\\Oasis\\Doc\\' . $key);

        if (!class_exists($resolved) || !method_exists($resolved, 'fromArray')) {
            throw new DeserializationException([
                'Unknown UBL document type "{key}".',
                'key' => $key,
            ]);
        }

        return [$resolved, $payload];
    }

    private static function shortClassName(string $class): string
    {
        $parts = explode('\\', $class);

        return (string) end($parts);
    }
}
