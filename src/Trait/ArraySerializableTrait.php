<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Ubl\Trait;

use BackedEnum;
use DateTimeImmutable;

/**
 * `toArray()`, `fromArray()` and `jsonSerialize()` for an `Oasis` class, keyed by the UBL
 * element and attribute names (the same ones the XML uses), not by the PHP property name.
 *
 * None of it uses reflection: `tools/patch-oasis.php` reads each class's `#[XmlElement]`/
 * `#[XmlAttribute]`/`#[XmlValue]` attributes once, at generation time, and writes the result
 * as the plain, literal `ARRAY_METADATA` constant this trait reads. Getters and setters are
 * called by name (`get`/`set` + the property name from that constant), never a raw property
 * access: `fromArray()` goes through the same `validate*()` guard a direct call would.
 *
 * A class with an `#[XmlValue]` property (`Identifier`, `Amount`, `Text`...) is a value with
 * attributes, not a list of elements: `toArray()` gives it as `['@value' => ..., '@attributes'
 * => [...]]`. A parent embeds that value as the plain scalar when it has no attributes, and
 * only keeps the `@value`/`@attributes` shape when it does - `fromArray()` accepts either form
 * back, so a plain `['ID' => 'Q-1']` and the attribute-bearing form both work.
 */
trait ArraySerializableTrait
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return self::ARRAY_METADATA['isValueType']
            ? $this->valueToArray()
            : $this->elementsToArray();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        $instance = new static();

        if (self::ARRAY_METADATA['isValueType']) {
            self::hydrateValue($instance, $data);
        } else {
            self::hydrateElements($instance, $data);
        }

        return $instance;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function valueToArray(): array
    {
        $attributes = [];
        $value = null;
        $format = null;

        foreach (self::ARRAY_METADATA['properties'] as $property) {
            if ($property['kind'] === 'value') {
                $value = self::get($this, $property['property']);
                $format = $property['format'];
            } elseif ($property['kind'] === 'attribute') {
                $raw = self::get($this, $property['property']);
                if ($raw !== null) {
                    $attributes[$property['name']] = self::exportScalar($raw, null);
                }
            }
        }

        return ['@value' => self::exportScalar($value, $format), '@attributes' => $attributes];
    }

    /**
     * @return array<string, mixed>
     */
    private function elementsToArray(): array
    {
        $result = [];

        foreach (self::ARRAY_METADATA['properties'] as $property) {
            if ($property['name'] === null) {
                continue;
            }

            $raw = self::get($this, $property['property']);
            $exported = $property['list']
                ? self::exportList($raw, $property['format'])
                : self::exportOne($raw, $property['kind'], $property['format']);

            if ($exported !== null && $exported !== []) {
                $result[$property['name']] = $exported;
            }
        }

        return $result;
    }

    private static function exportList(mixed $raw, ?string $format): mixed
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        return array_values(array_map(
            fn (mixed $item): mixed => self::exportSingle($item, $format),
            $raw
        ));
    }

    private static function exportOne(mixed $raw, string $kind, ?string $format): mixed
    {
        if ($raw === null) {
            return null;
        }

        return $kind === 'attribute' ? self::exportScalar($raw, null) : self::exportSingle($raw, $format);
    }

    private static function exportSingle(mixed $raw, ?string $format): mixed
    {
        if (is_object($raw) && method_exists($raw, 'toArray')) {
            /** @var array<string, mixed> $exported */
            $exported = $raw->toArray();
            $attributes = $exported['@attributes'] ?? [];

            return array_key_exists('@value', $exported) && $attributes === []
                ? $exported['@value']
                : $exported;
        }

        return self::exportScalar($raw, $format);
    }

    private static function exportScalar(mixed $raw, ?string $format): mixed
    {
        return match (true) {
            $raw instanceof DateTimeImmutable => $raw->format($format ?? 'Y-m-d'),
            $raw instanceof BackedEnum => $raw->value,
            default => $raw,
        };
    }

    private static function hydrateValue(object $instance, mixed $data): void
    {
        $value = is_array($data) ? ($data['@value'] ?? null) : $data;
        $rawAttributes = is_array($data) ? ($data['@attributes'] ?? null) : null;
        $attributes = is_array($rawAttributes) ? $rawAttributes : [];

        foreach (self::ARRAY_METADATA['properties'] as $property) {
            if ($property['kind'] === 'value') {
                if ($value !== null) {
                    self::set($instance, $property['property'], $value);
                }
            } elseif (array_key_exists($property['name'], $attributes)) {
                self::set($instance, $property['property'], $attributes[$property['name']]);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function hydrateElements(object $instance, array $data): void
    {
        foreach (self::ARRAY_METADATA['properties'] as $property) {
            if ($property['name'] === null || !array_key_exists($property['name'], $data)) {
                continue;
            }

            if ($property['list']) {
                self::hydrateList($instance, $property, $data[$property['name']]);
            } else {
                self::hydrateSingle($instance, $property, $data[$property['name']]);
            }
        }
    }

    /**
     * @param array{property: string, kind: string, item: ?string, format: ?string} $property
     */
    private static function hydrateList(object $instance, array $property, mixed $raw): void
    {
        if ($property['item'] === null) {
            return;
        }

        self::set($instance, $property['property'], array_map(
            fn (mixed $item): mixed => self::importValue($property['item'], $item, $property['format']),
            is_array($raw) ? $raw : []
        ));
    }

    /**
     * @param array{property: string, kind: string, item: ?string, format: ?string} $property
     */
    private static function hydrateSingle(object $instance, array $property, mixed $raw): void
    {
        $value = $property['kind'] === 'attribute'
            ? $raw
            : self::importValue($property['item'], $raw, $property['format']);

        if ($value !== null) {
            self::set($instance, $property['property'], $value);
        }
    }

    private static function importValue(?string $type, mixed $raw, ?string $format): mixed
    {
        return match (true) {
            $type === null => $raw,
            $type === DateTimeImmutable::class => self::importDate($raw, $format),
            is_a($type, BackedEnum::class, true) => self::importEnum($type, $raw),
            method_exists($type, 'fromArray') => $type::fromArray(is_array($raw) ? $raw : ['@value' => $raw]),
            $type === 'string' && is_scalar($raw) => (string) $raw,
            $type === 'bool' => (bool) $raw,
            $type === 'int' && is_scalar($raw) => (int) $raw,
            $type === 'float' && is_scalar($raw) => (float) $raw,
            default => $raw,
        };
    }

    private static function importDate(mixed $raw, ?string $format): ?DateTimeImmutable
    {
        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat($format ?? 'Y-m-d', (string) $raw);

        return $date === false ? null : $date;
    }

    /**
     * @param class-string<BackedEnum> $type
     */
    private static function importEnum(string $type, mixed $raw): ?BackedEnum
    {
        return (is_string($raw) || is_int($raw)) ? $type::tryFrom($raw) : null;
    }

    /**
     * `isset()` on an uninitialized typed property returns `false` instead of throwing (a
     * required property the caller never set has no default to read): this is the
     * reflection-free way to tell "not set" from "set to null", that `ReflectionProperty::
     * isInitialized()` gave before.
     */
    private static function get(object $object, string $property): mixed
    {
        if (!isset($object->$property)) {
            return null;
        }

        $getter = 'get' . ucfirst($property);

        return method_exists($object, $getter) ? $object->$getter() : null;
    }

    private static function set(object $instance, string $property, mixed $value): void
    {
        $setter = 'set' . ucfirst($property);
        if (method_exists($instance, $setter)) {
            $instance->$setter($value);
        }
    }
}
