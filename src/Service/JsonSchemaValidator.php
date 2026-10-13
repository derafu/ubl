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
use Derafu\Ubl\Contract\VersionInterface;
use Derafu\Ubl\Contract\VersionRegistryInterface;
use Derafu\Ubl\Exception\ValidationException;
use Derafu\Ubl\Service\Format\EmptyObjects;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates a document in its JSON representation against the official model-based JSON schemas
 * of the OASIS note, bundled in `resources/schemas/json/<version>/` for the versions that the
 * note covers (2.1, 2.2 and 2.3). It needs `opis/json-schema`, which is only suggested: the
 * package works without it, until validation is requested.
 *
 * The same validator instance serves every call, so the schemas are loaded once.
 */
final class JsonSchemaValidator implements JsonValidatorInterface
{
    private const SCHEMA_PREFIX = 'https://schemas.derafu.dev/ubl-json/';

    private ?Validator $validator = null;

    /**
     * @var array<string, true>
     */
    private array $registered = [];

    public function __construct(
        private readonly VersionRegistryInterface $versions = new VersionRegistry(),
    ) {
    }

    public function validate(array $data, ?string $version = null): void
    {
        $version = $this->versions->get($version);
        $document = $this->documentName($data);
        $schema = $version->getJsonSchemaPath($document);

        if ($schema === null) {
            throw new ValidationException([
                'OASIS has published no JSON schemas for UBL {version}, so its JSON and YAML cannot be validated.',
                'version' => $version->getId(),
            ]);
        }

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

        $result = $this->validator($version)->validate(
            $json,
            self::SCHEMA_PREFIX . $version->getId() . "/maindoc/UBL-{$document}-{$version->getId()}.json"
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

    private function validator(VersionInterface $version): Validator
    {
        if (!class_exists(Validator::class)) {
            throw new ValidationException([
                'Validating JSON or YAML needs the "opis/json-schema" package: composer require opis/json-schema.',
            ]);
        }

        $this->validator ??= new Validator();
        $this->validator->setMaxErrors(10);

        $directory = $version->getJsonSchemaDirectory();
        if ($directory !== null && !isset($this->registered[$version->getId()])) {
            $this->validator->resolver()?->registerPrefix(
                self::SCHEMA_PREFIX . $version->getId() . '/',
                $directory
            );
            $this->registered[$version->getId()] = true;
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
