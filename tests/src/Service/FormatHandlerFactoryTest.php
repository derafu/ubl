<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsUbl\Service;

use Derafu\Ubl\Exception\UnsupportedFormatException;
use Derafu\Ubl\Service\ExtensionContentCodecs;
use Derafu\Ubl\Service\Format\EmptyObjects;
use Derafu\Ubl\Service\Format\JsonHandler;
use Derafu\Ubl\Service\Format\XmlHandler;
use Derafu\Ubl\Service\Format\YamlHandler;
use Derafu\Ubl\Service\FormatHandlerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FormatHandlerFactory::class)]
#[UsesClass(JsonHandler::class)]
#[UsesClass(EmptyObjects::class)]
#[UsesClass(YamlHandler::class)]
#[UsesClass(XmlHandler::class)]
#[UsesClass(ExtensionContentCodecs::class)]
final class FormatHandlerFactoryTest extends TestCase
{
    #[Test]
    public function defaultsToJsonYamlAndXml(): void
    {
        $factory = new FormatHandlerFactory();

        $this->assertSame(['json', 'yaml', 'xml'], $factory->getSupportedFormats());
        $this->assertSame('json', $factory->createHandler('json')->getFormat());
        $this->assertSame('yaml', $factory->createHandler('yaml')->getFormat());
        $this->assertSame('xml', $factory->createHandler('xml')->getFormat());
    }

    /**
     * The format name is matched case-insensitively, the way a file extension or an HTTP
     * `Accept` subtype would be.
     */
    #[Test]
    public function matchesTheFormatNameCaseInsensitively(): void
    {
        $factory = new FormatHandlerFactory();

        $this->assertSame('json', $factory->createHandler('JSON')->getFormat());
    }

    #[Test]
    public function anUnregisteredFormatThrows(): void
    {
        $factory = new FormatHandlerFactory();

        $this->expectException(UnsupportedFormatException::class);

        $factory->createHandler('toml');
    }

    #[Test]
    public function aNewHandlerCanBeRegistered(): void
    {
        $factory = new FormatHandlerFactory();

        $factory->registerHandler('toml', JsonHandler::class);

        $this->assertContains('toml', $factory->getSupportedFormats());
        $this->assertInstanceOf(JsonHandler::class, $factory->createHandler('toml'));
    }
}
