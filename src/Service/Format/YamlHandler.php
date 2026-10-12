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
use Symfony\Component\Yaml\Exception\DumpException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A document as YAML, with its `toArray()` wrapped under a single top-level key naming the
 * document type (`Quotation: {...}`), the same convention `json` uses. Requires
 * `symfony/yaml`, which this package does not require on its own (see CLAUDE.md): only
 * autoloaded when a caller actually asks for the `yaml` format.
 */
final class YamlHandler implements FormatHandlerInterface
{
    use ArrayWireFormatTrait;

    public function serialize(object $document): string
    {
        try {
            return Yaml::dump($this->wrap($document), 20, 2);
        } catch (DumpException $e) {
            throw new SerializationException([
                'Could not encode {class} as YAML: {message}',
                'class' => $document::class,
                'message' => $e->getMessage(),
            ], previous: $e);
        }
    }

    public function deserialize(string $content, ?string $class = null): object
    {
        try {
            $data = Yaml::parse($content);
        } catch (ParseException $e) {
            throw new DeserializationException([
                'Could not parse YAML: {message}',
                'message' => $e->getMessage(),
            ], previous: $e);
        }

        if (!is_array($data)) {
            throw new DeserializationException([
                'Parsed YAML must be an array, got {type}.',
                'type' => get_debug_type($data),
            ]);
        }

        [$resolvedClass, $payload] = $this->unwrap($data, $class);

        return $resolvedClass::fromArray($payload);
    }

    public function getFormat(): string
    {
        return 'yaml';
    }
}
