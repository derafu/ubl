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

/**
 * Turns a string, in any registered format (`json`, `yaml`, `xml`), into a UBL document.
 */
interface DeserializerInterface
{
    /**
     * When `$class` is `null`, the target class is resolved from the content itself: the XML
     * root element, or the single top-level key of the decoded JSON/YAML.
     *
     * @param class-string|null $class
     *
     * @throws UnsupportedFormatException If `$format` has no registered handler.
     * @throws DeserializationException If `$content` cannot be turned into a document.
     */
    public function deserialize(string $content, string $format, ?string $class = null): object;
}
