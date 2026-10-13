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

use Derafu\Ubl\Exception\DeserializationException;
use Derafu\Ubl\Exception\UnsupportedFormatException;
use Derafu\Ubl\Exception\ValidationException;

/**
 * Turns a string, in any registered format (`json`, `yaml`, `xml`), into a UBL document.
 */
interface DeserializerInterface
{
    /**
     * Option: when `true`, the content is validated against the official OASIS schema of its
     * document type (the XSD for `xml`, the JSON schema for `json` and `yaml`, which needs
     * `opis/json-schema`) before it becomes a document. It is off by default.
     */
    public const VALIDATE = 'validate';

    /**
     * Option: the document class the content has to be (`Invoice::class`). The type is always
     * resolved from the content itself, the XML root element or the single top-level key of the
     * decoded JSON/YAML, so this is an assertion, not a hint: a content of another type throws.
     */
    public const CLASS_NAME = 'class';

    /**
     * The target class is resolved from the content itself: the XML root element, or the single
     * top-level key of the decoded JSON/YAML.
     *
     * @param array{validate?: bool, class?: class-string} $options See the constants of this
     * interface.
     *
     * @throws UnsupportedFormatException If `$format` has no registered handler.
     * @throws DeserializationException If `$content` cannot be turned into a document, it is
     * not of the class that the `class` option asks for, or an option is unknown.
     * @throws ValidationException If `validate` is requested and the content is not valid.
     */
    public function deserialize(string $content, string $format, array $options = []): object;
}
