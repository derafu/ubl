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
use Derafu\Ubl\Contract\FormatHandlerInterface;
use Derafu\Ubl\Exception\UnsupportedFormatException;
use Derafu\Ubl\Service\Format\JsonHandler;
use Derafu\Ubl\Service\Format\XmlHandler;
use Derafu\Ubl\Service\Format\YamlHandler;

/**
 * Maps a format name to the `FormatHandlerInterface` class that handles it, and instantiates
 * it lazily: a handler class is only autoloaded when its format is actually requested, so a
 * caller who never asks for `yaml` never needs `symfony/yaml` installed.
 */
final class FormatHandlerFactory implements FormatHandlerFactoryInterface
{
    /**
     * @var array<string, class-string<FormatHandlerInterface>>
     */
    private array $handlers = [
        'json' => JsonHandler::class,
        'yaml' => YamlHandler::class,
        'xml' => XmlHandler::class,
    ];

    /**
     * @param array<string, class-string<FormatHandlerInterface>> $handlers Overrides the
     * default `json`/`yaml`/`xml` map entirely when not empty.
     */
    public function __construct(array $handlers = [])
    {
        if ($handlers !== []) {
            $this->handlers = $handlers;
        }
    }

    public function createHandler(string $format): FormatHandlerInterface
    {
        $format = strtolower($format);

        if (!isset($this->handlers[$format])) {
            throw new UnsupportedFormatException([
                'Format "{format}" is not supported.',
                'format' => $format,
            ]);
        }

        $handlerClass = $this->handlers[$format];

        return new $handlerClass();
    }

    public function registerHandler(string $format, string $handlerClass): static
    {
        $this->handlers[strtolower($format)] = $handlerClass;

        return $this;
    }

    public function getSupportedFormats(): array
    {
        return array_keys($this->handlers);
    }
}
