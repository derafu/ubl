<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Ubl\Contract;

/**
 * One format (`json`, `yaml`, `xml`) a document can be serialized to and deserialized from.
 */
interface FormatHandlerInterface
{
    /**
     * Converts a document to its string representation in this format.
     */
    public function serialize(object $document): string;

    /**
     * Converts a string in this format back into a document.
     *
     * The target class is resolved from the content itself (the XML root element, or the single
     * top-level key of the decoded JSON/YAML).
     *
     * @param array{validate?: bool, version?: string} $options See `DeserializerInterface` (its
     * `class` option is checked by the `Deserializer`, not by the handlers).
     */
    public function deserialize(string $content, array $options = []): object;

    /**
     * The format name this handler is registered under (`json`, `yaml`, `xml`).
     */
    public function getFormat(): string;
}
