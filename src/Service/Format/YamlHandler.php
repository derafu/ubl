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
use Symfony\Component\Yaml\Exception\DumpException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A document as YAML: the same structure as `json` (the model-based representation of the OASIS
 * note, see `ArrayFormatHandler`), carried in YAML. Requires `symfony/yaml`, which this package
 * does not require on its own: only autoloaded when a caller actually asks for the `yaml`
 * format.
 */
final class YamlHandler extends ArrayFormatHandler
{
    public function getFormat(): string
    {
        return 'yaml';
    }

    protected function encode(array $data, string $class): string
    {
        try {
            return Yaml::dump(EmptyObjects::of($data), 20, 2, Yaml::DUMP_OBJECT_AS_MAP);
        } catch (DumpException $e) {
            throw new SerializationException([
                'Could not encode {class} as YAML: {message}',
                'class' => $class,
                'message' => $e->getMessage(),
            ], previous: $e);
        }
    }

    protected function decode(string $content): array
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

        return $data;
    }
}
