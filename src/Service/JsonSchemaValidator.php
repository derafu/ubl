<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Ubl\Service;

use Derafu\Ubl\Contract\JsonValidatorInterface;
use Derafu\Ubl\Exception\ValidationException;
use Derafu\Ubl\Service\Format\EmptyObjects;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates a document in its JSON representation against the official model-based JSON schemas
 * of the OASIS note, bundled in `resources/schemas/json/2.1/`. It needs `opis/json-schema`,
 * which is only suggested: the package works without it, until validation is requested.
 *
 * The same validator instance serves every call, so the schemas are loaded once.
 */
final class JsonSchemaValidator implements JsonValidatorInterface
{
    private const SCHEMA_PREFIX = 'https://schemas.derafu.dev/ubl-json/';

    private ?Validator $validator = null;

    public function validate(array $data): void
    {
        $document = $this->documentName($data);
        $schema = dirname(__DIR__, 2) . "/resources/schemas/json/2.1/maindoc/UBL-{$document}-2.1.json";

        if (!is_file($schema)) {
            throw new ValidationException([
                'No bundled JSON schema for "{class}" ("{path}" does not exist).',
                'class' => $document,
                'path' => $schema,
            ]);
        }

        try {
            $json = json_decode(
                json_encode(EmptyObjects::of($data), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                flags: JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new ValidationException([
                'Could not turn the data into JSON to validate it: {message}',
                'message' => $e->getMessage(),
            ], previous: $e);
        }

        $result = $this->validator()->validate(
            $json,
            self::SCHEMA_PREFIX . "maindoc/UBL-{$document}-2.1.json"
        );

        if ($result->isValid()) {
            return;
        }

        $errors = $result->error() === null
            ? []
            : $this->flatten((new ErrorFormatter())->formatKeyed($result->error()));

        throw new ValidationException([
            '{class} does not validate against its UBL schema: {errors}',
            'class' => $document,
            'errors' => $errors === [] ? 'unknown error' : implode('; ', $errors),
        ], $errors);
    }

    private function validator(): Validator
    {
        if (!class_exists(Validator::class)) {
            throw new ValidationException([
                'Validating JSON or YAML needs the "opis/json-schema" package: composer require opis/json-schema.',
            ]);
        }

        if ($this->validator === null) {
            $this->validator = new Validator();
            $this->validator->setMaxErrors(10);
            $this->validator->resolver()?->registerPrefix(
                self::SCHEMA_PREFIX,
                dirname(__DIR__, 2) . '/resources/schemas/json/2.1'
            );
        }

        return $this->validator;
    }

    /**
     * The short name of the document: the only property of the data that is not part of the
     * apex.
     *
     * @param array<array-key, mixed> $data
     */
    private function documentName(array $data): string
    {
        $keys = array_values(array_filter(
            array_keys($data),
            static fn (int|string $key): bool => !str_starts_with((string) $key, '_')
        ));

        if (count($keys) !== 1) {
            throw new ValidationException([
                'Could not resolve a UBL document type from the data: expected exactly one top-level key naming it, got {count}.',
                'count' => count($keys),
            ]);
        }

        return (string) $keys[0];
    }

    /**
     * Turns the errors by pointer of `ErrorFormatter` into `pointer: message` lines.
     *
     * @param array<string, list<string>> $byPointer
     * @return list<string>
     */
    private function flatten(array $byPointer): array
    {
        $errors = [];
        foreach ($byPointer as $pointer => $messages) {
            foreach ($messages as $message) {
                $errors[] = ($pointer === '' ? '/' : $pointer) . ': ' . $message;
            }
        }

        return $errors;
    }
}
