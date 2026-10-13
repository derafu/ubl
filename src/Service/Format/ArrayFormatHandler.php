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

use Derafu\Ubl\Contract\DeserializerInterface;
use Derafu\Ubl\Contract\FormatHandlerInterface;
use Derafu\Ubl\Contract\JsonValidatorInterface;
use Derafu\Ubl\Contract\NormalizerInterface;
use Derafu\Ubl\Exception\DeserializationException;
use Derafu\Ubl\Exception\SerializationException;
use Derafu\Ubl\Oasis\Xml\DocumentRegistry;
use Derafu\Ubl\Service\ExtensionContentCodecs;
use Derafu\Ubl\Service\JsonSchemaValidator;
use Derafu\Ubl\Service\Normalizer;

/**
 * Shared by the format handlers whose wire format is a plain array (`json`, `yaml`): the
 * model-based JSON representation of "UBL 2.1 JSON Alternative Representation" v2.0 (OASIS
 * Committee Note 01, https://docs.oasis-open.org/ubl/UBL-2.1-JSON/v2.0/cn01/), which `yaml`
 * carries as the very same structure.
 *
 * It is the apex of that note around a document's `toArray()`: the properties `_D`, `_A`,
 * `_B` and `_E` (the namespaces of the document, of the aggregate components, of the basic
 * components and of the extensions, the last one only if there are extensions) and the
 * property named as the document (`Invoice`), whose value is the document object. On the way
 * back that property is the only one whose name does not start with `_`, and it resolves the
 * target class when the caller does not pass one: `Doc` is flat, so the key maps to its class by
 * simple string concatenation, no registry needed.
 *
 * Between decoding and `fromArray()` the data goes through the `NormalizerInterface`, and
 * the content of the extensions through `ExtensionContentCodecs`. If validation is requested
 * (`DeserializerInterface::VALIDATE`) it is the normalized data, in the form of the note, that
 * is validated, before the codecs.
 */
abstract class ArrayFormatHandler implements FormatHandlerInterface
{
    private const NAMESPACE_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const NAMESPACE_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const NAMESPACE_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    public function __construct(
        private readonly NormalizerInterface $normalizer = new Normalizer(),
        private readonly ExtensionContentCodecs $extensions = new ExtensionContentCodecs(),
        private readonly JsonValidatorInterface $validator = new JsonSchemaValidator(),
    ) {
    }

    final public function serialize(object $document): string
    {
        return $this->encode($this->wrap($document), $document::class);
    }

    final public function deserialize(string $content, array $options = []): object
    {
        [$resolvedClass, $apex, $payload] = $this->unwrap($this->decode($content));

        $payload = $this->normalizer->normalize($payload, $resolvedClass);

        // Validated as it is written, the extensions still as the codecs read them.
        if (($options[DeserializerInterface::VALIDATE] ?? false) === true) {
            $this->validator->validate($apex + [self::shortClassName($resolvedClass) => $payload]);
        }

        $payload = $this->extensions->decode($payload);

        return $resolvedClass::fromArray($payload);
    }

    /**
     * Encodes the apex as the text of the format.
     *
     * @param array<string, mixed> $data
     * @param class-string $class The document that is being serialized.
     * @throws SerializationException
     */
    abstract protected function encode(array $data, string $class): string;

    /**
     * Decodes the text of the format to the array it carries.
     *
     * @return array<array-key, mixed>
     * @throws DeserializationException
     */
    abstract protected function decode(string $content): array;

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

        $root = DocumentRegistry::rootElementFor($document::class);
        $data = $this->extensions->encode($document->toArray());

        $apex = [];
        if ($root !== null) {
            $apex['_D'] = $root['namespace'];
        }
        $apex['_A'] = self::NAMESPACE_CAC;
        $apex['_B'] = self::NAMESPACE_CBC;
        if (isset($data['UBLExtensions'])) {
            $apex['_E'] = self::NAMESPACE_EXT;
        }
        $apex[self::shortClassName($document::class)] = $data;

        return $apex;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array{0: class-string, 1: array<array-key, mixed>, 2: array<array-key, mixed>} The
     * class, the apex (the properties that start with `_`) and the content of the document.
     */
    private function unwrap(array $data): array
    {
        // The namespaces of the apex (`_D`, `_A`, `_B`, `_E`) are only documentary.
        $apex = array_filter(
            $data,
            static fn (int|string $key): bool => str_starts_with((string) $key, '_'),
            ARRAY_FILTER_USE_KEY
        );
        $data = array_diff_key($data, $apex);

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

        $resolved = 'Derafu\\Ubl\\Oasis\\Doc\\' . $key;

        if (!class_exists($resolved) || !method_exists($resolved, 'fromArray')) {
            throw new DeserializationException([
                'Unknown UBL document type "{key}".',
                'key' => $key,
            ]);
        }

        return [$resolved, $apex, $payload];
    }

    private static function shortClassName(string $class): string
    {
        $parts = explode('\\', $class);

        return (string) end($parts);
    }
}
