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

use Derafu\Ubl\Exception\UnsupportedFormatException;

/**
 * Resolves a format name to the `FormatHandlerInterface` that handles it, shared by
 * `Serializer` and `Deserializer`.
 */
interface FormatHandlerFactoryInterface
{
    /**
     * @throws UnsupportedFormatException If no handler is registered for `$format`.
     */
    public function createHandler(string $format): FormatHandlerInterface;

    /**
     * @param class-string<FormatHandlerInterface> $handlerClass
     */
    public function registerHandler(string $format, string $handlerClass): static;

    /**
     * @return list<string>
     */
    public function getSupportedFormats(): array;
}
