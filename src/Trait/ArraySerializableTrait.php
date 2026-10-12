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
use DateTimeZone;
use Exception;

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
     * A property is included whenever it was actually set, never based on whether its own
     * exported shape happens to be empty: an aggregate present but with every field of its own
     * empty (`<cac:Item/>`) still exports as `[]`, which is not the same as the property never
     * having been set at all - the same distinction `exportSingle()` makes for a value leaf.
     * `fromArray()` already treats `[]` as "a real but empty object", via the same `fromArray()`
     * every nested class gets from this trait.
     *
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

            if ($property['list']) {
                if (!is_array($raw) || $raw === []) {
                    continue;
                }
                $result[$property['name']] = self::exportList($raw, $property['format']);
            } else {
                if ($raw === null) {
                    continue;
                }
                $result[$property['name']] = self::exportOne($raw, $property['kind'], $property['format']);
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

    /**
     * A leaf with no attributes simplifies to its bare `@value` - except when that value is
     * itself `null`: the leaf object is still there (a required property genuinely never set
     * would already have returned `null` in `exportOne()`, before even reaching here), it is
     * just empty, the same as an XML element present with no content (`<cbc:ID/>`). Keeping the
     * `@value`/`@attributes` shape in that case is what lets `fromArray()` tell "present but
     * empty" apart from "absent" and reconstruct the same empty-but-set object, instead of
     * leaving a required property genuinely uninitialized - which `ArraySerializableTrait`
     * itself tolerates, but `Xterr\UBL\Xml\XmlSerializer` does not.
     */
    private static function exportSingle(mixed $raw, ?string $format): mixed
    {
        if (is_object($raw) && method_exists($raw, 'toArray')) {
            /** @var array<string, mixed> $exported */
            $exported = $raw->toArray();
            $attributes = $exported['@attributes'] ?? [];

            if (array_key_exists('@value', $exported) && $attributes === [] && $exported['@value'] !== null) {
                return $exported['@value'];
            }

            return $exported;
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

    /**
     * `xsd:date`/`xsd:time` both allow an optional fractional second and an optional timezone,
     * neither of which `$format`'s fixed pattern can express as optional: `createFromFormat()`
     * only matches the one shape `$format` spells out exactly. A value in a different (still
     * valid) shape - "11:32:26.0Z" against the dateless "H:i:sP", say - falls back to PHP's own
     * lenient parser, which handles both the fraction and the timezone being present or not.
     *
     * Whichever of the two parses `$date`, its internal shape (what date a time-only value is
     * filled in with, whether its timezone ends up the named "UTC" or the offset "+00:00")
     * depends on which one it was, even though the exported string (`$date->format($format)`,
     * what `exportScalar()` actually emits) is identical either way. Re-deriving `$date` from
     * that same exported string, through the strict parser, collapses both onto the one shape
     * the strict parse alone already produces - without changing the exported string itself.
     * `xterr/php-ubl`'s own date parsing gets the equivalent fix, in `tools/patch-xterr-ubl.php`.
     */
    private static function importDate(mixed $raw, ?string $format): ?DateTimeImmutable
    {
        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
            return null;
        }

        $raw = (string) $raw;
        $resolvedFormat = $format ?? 'Y-m-d';
        $date = DateTimeImmutable::createFromFormat('!' . $resolvedFormat, $raw);

        if ($date === false) {
            try {
                $date = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
            } catch (Exception) {
                return null;
            }
        }

        $canonical = DateTimeImmutable::createFromFormat('!' . $resolvedFormat, $date->format($resolvedFormat));

        return $canonical !== false ? $canonical : $date;
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
