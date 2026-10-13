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
use Derafu\Ubl\Exception\DeserializationException;
use Derafu\Ubl\Oasis\Cbc\Amount;
use Derafu\Ubl\Oasis\Cbc\Measure;
use Derafu\Ubl\Oasis\Cbc\Numeric;
use Derafu\Ubl\Oasis\Cbc\Quantity;
use Exception;

/**
 * `toArray()`, `fromArray()` and `jsonSerialize()` for an `Oasis` class, in the model-based
 * JSON representation of "UBL 2.1 JSON Alternative Representation" v2.0 (OASIS Committee Note
 * 01, https://docs.oasis-open.org/ubl/UBL-2.1-JSON/v2.0/cn01/): keyed by the UBL element names
 * (the same ones the XML uses), not by the PHP property name. `Service\Format\JsonHandler` and
 * `YamlHandler` add the apex of that note (`_D`, `_A`, `_B`, `_E` and the document key).
 *
 * The rules this trait applies, all from the note:
 *
 *  - A BBIE (a leaf) is always an object: its content under the `_` property and its
 *    supplementary components (XML attributes, `currencyID`, `unitCode`...) as sibling
 *    properties: `{"_": 100.0, "currencyID": "CAD"}`. Never a bare scalar.
 *  - Model-based cardinality: a property whose maximum cardinality is unbounded is an array
 *    (even with one item); one that is bounded to 1 is the object itself.
 *  - Typing: the content of an `Amount`, `Quantity`, `Measure` or `Numeric` is a JSON number,
 *    an indicator is a boolean; every other content (dates and times included) is a string.
 *  - The extension scaffolding (`UBLExtensions` and everything in `Oasis\Ext`) is always made of
 *    arrays of exactly one item, as the note's schemas define it, even in model-based JSON.
 *  - What is inside `ExtensionContent` is out of the scope of the note ("trading partners must
 *    agree"): here it is the list of the XML fragments under `any`, as strings, which
 *    `Service\ExtensionContentCodecs` can turn into something else per extension.
 *
 * None of it uses reflection: `tools/patch-oasis.php` reads each class's `#[XmlElement]`/
 * `#[XmlAttribute]`/`#[XmlValue]` attributes once, at generation time, and writes the result
 * as the plain, literal `ARRAY_METADATA` constant this trait reads. Getters and setters are
 * called by name (`get`/`set` + the property name from that constant), never a raw property
 * access: `fromArray()` goes through the same `validate*()` guard a direct call would.
 *
 * `fromArray()` only reads that representation. Tolerating other shapes (the legacy-based
 * form of the note, bare scalars...) is the job of `Service\Normalizer`, before it gets here.
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
     * @throws DeserializationException When `$data` is not shaped as the note says.
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

        foreach (self::ARRAY_METADATA['properties'] as $property) {
            if ($property['kind'] === 'value') {
                $value = self::get($this, $property['property']);
            } elseif ($property['kind'] === 'attribute') {
                $raw = self::get($this, $property['property']);
                if ($raw !== null) {
                    $attributes[$property['name']] = self::exportScalar($raw, null);
                }
            }
        }

        // An element that is there with no content (`<cbc:ID/>`) is the empty string: the note
        // asks for the content of every leaf, and its schemas for a string.
        return ['_' => self::isNumericValueType() ? self::exportNumber($value) ?? '' : $value ?? ''] + $attributes;
    }

    /**
     * A property is included whenever it was actually set, never based on whether its own
     * exported shape happens to be empty: an aggregate present but with every field of its own
     * empty (`<cac:Item/>`) still exports as `[]`, which is not the same as the property never
     * having been set at all. `fromArray()` already treats `[]` as "a real but empty object",
     * via the same `fromArray()` every nested class gets from this trait.
     *
     * @return array<string, mixed>
     */
    private function elementsToArray(): array
    {
        $result = [];
        $scaffolding = self::isScaffolding(static::class);

        foreach (self::ARRAY_METADATA['properties'] as $property) {
            if ($property['name'] === null) {
                continue;
            }

            $raw = self::get($this, $property['property']);

            if ($property['list']) {
                if (!is_array($raw) || $raw === []) {
                    continue;
                }
                $result[$property['name']] = array_values(array_map(
                    fn (mixed $item): mixed => self::exportSingle($item, $property),
                    $raw
                ));
            } else {
                if ($raw === null) {
                    continue;
                }
                $exported = self::exportSingle($raw, $property);
                // The scaffolding of the extensions is arrays of one item, in every mode.
                $result[$property['name']] = $scaffolding || self::isScaffolding($property['item'])
                    ? [$exported]
                    : $exported;
            }
        }

        return $result;
    }

    /**
     * @param array{kind: string, item: ?string, format: ?string} $property
     */
    private static function exportSingle(mixed $raw, array $property): mixed
    {
        if (is_object($raw) && method_exists($raw, 'toArray')) {
            return $raw->toArray();
        }

        if ($property['kind'] === 'attribute') {
            return self::exportScalar($raw, null);
        }

        // A fragment of XML of a wildcard (`xs:any`): kept as the string it is.
        if ($property['item'] === 'string') {
            return $raw;
        }

        // A leaf that is not a class of its own (a date, a boolean) is still a BBIE.
        return ['_' => self::exportScalar($raw, $property['format'])];
    }

    private static function exportScalar(mixed $raw, ?string $format): mixed
    {
        return match (true) {
            $raw instanceof DateTimeImmutable => $raw->format($format ?? 'Y-m-d'),
            $raw instanceof BackedEnum => $raw->value,
            default => $raw,
        };
    }

    /**
     * The decimal text of a numeric content, as the JSON number it is. A text that is not a
     * number (an invalid document being converted anyway) stays as it is, never lost.
     */
    private static function exportNumber(mixed $value): mixed
    {
        if (!is_string($value) || !is_numeric($value)) {
            return $value;
        }

        if (preg_match('/^[+-]?\d{1,18}$/', $value) === 1) {
            return (int) $value;
        }

        return (float) $value;
    }

    /**
     * The inverse of `exportNumber()`: the decimal text of a JSON number, without exponent.
     */
    private static function importNumber(int|float|string $raw): string
    {
        if (!is_float($raw)) {
            return (string) $raw;
        }

        $text = rtrim(rtrim(sprintf('%.12F', $raw), '0'), '.');

        return $text === '' || $text === '-' ? '0' : $text;
    }

    /**
     * `Amount`, `Quantity`, `Measure` and `Numeric` are the only contents that are numbers in
     * the note.
     */
    private static function isNumericValueType(): bool
    {
        return in_array(static::class, [Amount::class, Quantity::class, Measure::class, Numeric::class], true);
    }

    /**
     * The extension scaffolding is what lives in `Oasis\Ext`.
     */
    private static function isScaffolding(?string $class): bool
    {
        return $class !== null && str_starts_with($class, 'Derafu\\Ubl\\Oasis\\Ext\\');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function hydrateValue(object $instance, array $data): void
    {
        if (!array_key_exists('_', $data)) {
            throw new DeserializationException([
                'The value of {class} must have a "_" property with its content.',
                'class' => static::class,
            ]);
        }

        foreach (self::ARRAY_METADATA['properties'] as $property) {
            if ($property['kind'] === 'value') {
                $value = $data['_'];
                if (is_array($value)) {
                    throw new DeserializationException([
                        'The "_" of {class} must be a scalar, got {type}.',
                        'class' => static::class,
                        'type' => get_debug_type($value),
                    ]);
                }
                // The content of an element that is there with no content is the empty string
                // (see `valueToArray()`), which is no content at all.
                if ($value !== null && $value !== '') {
                    self::set($instance, $property['property'], is_scalar($value) && !is_string($value)
                        ? self::importNumber(is_bool($value) ? (int) $value : $value)
                        : $value);
                }
            } elseif (array_key_exists($property['name'], $data)) {
                self::set($instance, $property['property'], $data[$property['name']]);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function hydrateElements(object $instance, array $data): void
    {
        $scaffolding = self::isScaffolding(static::class);

        foreach (self::ARRAY_METADATA['properties'] as $property) {
            if ($property['name'] === null || !array_key_exists($property['name'], $data)) {
                continue;
            }

            if ($property['list']) {
                self::hydrateList($instance, $property, $data[$property['name']]);
            } else {
                $raw = $data[$property['name']];
                if ($scaffolding || self::isScaffolding($property['item'])) {
                    $raw = self::unwrapOne($property, $raw);
                }
                self::hydrateSingle($instance, $property, $raw);
            }
        }
    }

    /**
     * The scaffolding of the extensions is an array of exactly one item.
     *
     * @param array{name: ?string} $property
     */
    private static function unwrapOne(array $property, mixed $raw): mixed
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) !== 1) {
            throw new DeserializationException([
                '"{property}" of {class} must be an array of exactly one item.',
                'property' => (string) $property['name'],
                'class' => static::class,
            ]);
        }

        return $raw[0];
    }

    /**
     * @param array{property: string, name: ?string, kind: string, item: ?string, format: ?string} $property
     */
    private static function hydrateList(object $instance, array $property, mixed $raw): void
    {
        if ($property['item'] === null) {
            return;
        }

        if (!is_array($raw) || !array_is_list($raw)) {
            throw new DeserializationException([
                '"{property}" of {class} must be an array, got {type}.',
                'property' => (string) $property['name'],
                'class' => static::class,
                'type' => get_debug_type($raw),
            ]);
        }

        self::set($instance, $property['property'], array_map(
            fn (mixed $item): mixed => self::importSingle($property, $item),
            $raw
        ));
    }

    /**
     * @param array{property: string, name: ?string, kind: string, item: ?string, format: ?string} $property
     */
    private static function hydrateSingle(object $instance, array $property, mixed $raw): void
    {
        $value = $property['kind'] === 'attribute'
            ? $raw
            : self::importSingle($property, $raw);

        if ($value !== null) {
            self::set($instance, $property['property'], $value);
        }
    }

    /**
     * @param array{name: ?string, kind: string, item: ?string, format: ?string} $property
     */
    private static function importSingle(array $property, mixed $raw): mixed
    {
        $type = $property['item'];

        // A fragment of XML of a wildcard (`xs:any`): kept as the string it is.
        if ($type === null || $type === 'string') {
            return $raw;
        }

        // Everything else, a leaf of a class of its own or one that is not, is an object.
        if (!is_array($raw) || array_is_list($raw) && $raw !== []) {
            throw new DeserializationException([
                '"{property}" of {class} must be an object, got {type}.',
                'property' => (string) $property['name'],
                'class' => static::class,
                'type' => get_debug_type($raw),
            ]);
        }

        if (method_exists($type, 'fromArray')) {
            return $type::fromArray($raw);
        }

        if (!array_key_exists('_', $raw)) {
            throw new DeserializationException([
                '"{property}" of {class} must have a "_" property with its content.',
                'property' => (string) $property['name'],
                'class' => static::class,
            ]);
        }

        return self::importScalar($type, $raw['_'], $property['format']);
    }

    private static function importScalar(string $type, mixed $raw, ?string $format): mixed
    {
        return match (true) {
            $type === DateTimeImmutable::class => self::importDate($raw, $format),
            is_a($type, BackedEnum::class, true) => self::importEnum($type, $raw),
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
