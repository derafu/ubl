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

use Derafu\Ubl\Contract\FormatHandlerFactoryInterface;
use Derafu\Ubl\Contract\SerializerInterface;

final class Serializer implements SerializerInterface
{
    public function __construct(
        private readonly FormatHandlerFactoryInterface $factory = new FormatHandlerFactory(),
    ) {
    }

    public function serialize(object $document, string $format): string
    {
        return $this->factory->createHandler($format)->serialize($document);
    }
}
