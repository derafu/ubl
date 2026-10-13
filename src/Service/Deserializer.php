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
use Derafu\Ubl\Exception\DeserializationException;

final class Deserializer implements DeserializerInterface
{
    public function __construct(
        private readonly FormatHandlerFactoryInterface $factory = new FormatHandlerFactory(),
    ) {
    }

    public function deserialize(string $content, string $format, array $options = []): object
    {
        foreach (array_diff(array_keys($options), [self::VALIDATE, self::CLASS_NAME, self::VERSION]) as $option) {
            throw new DeserializationException([
                'Unknown deserialization option "{option}".',
                'option' => (string) $option,
            ]);
        }

        $document = $this->factory->createHandler($format)->deserialize($content, $options);

        $expected = $options[self::CLASS_NAME] ?? null;
        if ($expected !== null && !$document instanceof $expected) {
            throw new DeserializationException([
                'The content is a {actual}, not the {expected} that was asked for.',
                'actual' => $document::class,
                'expected' => $expected,
            ]);
        }

        return $document;
    }
}
