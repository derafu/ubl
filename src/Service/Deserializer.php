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

use Derafu\Ubl\Contract\DeserializerInterface;
use Derafu\Ubl\Contract\FormatHandlerFactoryInterface;

final class Deserializer implements DeserializerInterface
{
    public function __construct(
        private readonly FormatHandlerFactoryInterface $factory = new FormatHandlerFactory(),
    ) {
    }

    public function deserialize(string $content, string $format, ?string $class = null): object
    {
        return $this->factory->createHandler($format)->deserialize($content, $class);
    }
}
