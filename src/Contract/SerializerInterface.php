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

use Derafu\Ubl\Exception\SerializationException;
use Derafu\Ubl\Exception\UnsupportedFormatException;

/**
 * Turns a UBL document into a string, in any registered format (`json`, `yaml`, `xml`).
 */
interface SerializerInterface
{
    /**
     * @throws UnsupportedFormatException If `$format` has no registered handler.
     * @throws SerializationException If `$document` cannot be turned into that format.
     */
    public function serialize(object $document, string $format): string;
}
