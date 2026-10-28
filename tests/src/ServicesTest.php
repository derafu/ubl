<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsUbl;

use Derafu\TestsUbl\Fixture\NoteExtensionCodec;
use Derafu\Translation\TranslatorFactory;
use Derafu\Ubl\Contract\DeserializerInterface;
use Derafu\Ubl\Contract\FormatHandlerFactoryInterface;
use Derafu\Ubl\Contract\JsonValidatorInterface;
use Derafu\Ubl\Contract\NormalizerInterface;
use Derafu\Ubl\Contract\SerializerInterface;
use Derafu\Ubl\Contract\VersionRegistryInterface;
use Derafu\Ubl\Contract\XmlValidatorInterface;
use Derafu\Ubl\Exception\UnsupportedFormatException;
use Derafu\Ubl\Service\Deserializer;
use Derafu\Ubl\Service\ExtensionContentCodecs;
use Derafu\Ubl\Service\FormatHandlerFactory;
use Derafu\Ubl\Service\JsonSchemaValidator;
use Derafu\Ubl\Service\Normalizer;
use Derafu\Ubl\Service\Serializer;
use Derafu\Ubl\Service\VersionRegistry;
use Derafu\Ubl\Service\XmlSchemaValidator;
use Derafu\Ubl\Translation\UblTranslationResourceProvider;
use Derafu\Ubl\V21\Oasis\Doc\Invoice;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

/**
 * Exercises the real configuration with OASIS documents and a consumer codec.
 */
#[CoversNothing]
final class ServicesTest extends TestCase
{
    private function container(?string $version = null, bool $codec = false): ContainerBuilder
    {
        $root = dirname(__DIR__, 2);
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator($root . '/resources/config'));
        $loader->load($codec
            ? $root . '/tests/fixtures/config/codec-services.yaml'
            : 'ubl-services.yaml');
        if ($version !== null) {
            $container->setParameter('derafu.ubl.version', $version);
        }
        if ($codec) {
            $this->assertArrayHasKey(NoteExtensionCodec::class, $container->findTaggedServiceIds('derafu_ubl.extension_content_codec'));
        }
        $this->assertArrayHasKey(
            UblTranslationResourceProvider::class,
            $container->findTaggedServiceIds('derafu_translation.resource_provider')
        );
        $container->register('app.translator', Translator::class)
            ->setFactory([TranslatorFactory::class, 'create'])
            ->setArguments(['es', ['en'], new TaggedIteratorArgument('derafu_translation.resource_provider')]);
        foreach ($container->getDefinitions() as $definition) {
            $definition->setPublic(true);
        }
        $container->compile();

        return $container;
    }

    private function invoiceXml(): string
    {
        $path = dirname(__DIR__) . '/fixtures/2.1/doc/xml/Invoice/Invoice_001_official_example.xml';
        $xml = file_get_contents($path);
        $this->assertNotFalse($xml);

        return $xml;
    }

    public function testConfigurationConstructsEveryDeclaredService(): void
    {
        $container = $this->container();
        foreach ([
            SerializerInterface::class => Serializer::class,
            DeserializerInterface::class => Deserializer::class,
            VersionRegistryInterface::class => VersionRegistry::class,
            XmlValidatorInterface::class => XmlSchemaValidator::class,
            JsonValidatorInterface::class => JsonSchemaValidator::class,
            NormalizerInterface::class => Normalizer::class,
            ExtensionContentCodecs::class => ExtensionContentCodecs::class,
            FormatHandlerFactoryInterface::class => FormatHandlerFactory::class,
            UblTranslationResourceProvider::class => UblTranslationResourceProvider::class,
        ] as $id => $class) {
            $this->assertInstanceOf($class, $container->get($id));
        }
        $provider = $container->get(UblTranslationResourceProvider::class);
        $this->assertInstanceOf(UblTranslationResourceProvider::class, $provider);
        $directories = [...$provider->getDirectories()];
        $this->assertCount(1, $directories);
        $this->assertSame(realpath(dirname(__DIR__, 2) . '/resources/translations'), realpath($directories[0]));
        $versions = $container->get(VersionRegistryInterface::class);
        $this->assertInstanceOf(VersionRegistryInterface::class, $versions);
        $this->assertSame('2.1', $versions->getDefault()->getId());
    }

    public function testConfiguredServicesValidateAndRoundTripAnOfficialInvoiceInEveryFormat(): void
    {
        $container = $this->container();
        $deserializer = $container->get(DeserializerInterface::class);
        $this->assertInstanceOf(DeserializerInterface::class, $deserializer);
        $serializer = $container->get(SerializerInterface::class);
        $this->assertInstanceOf(SerializerInterface::class, $serializer);
        $factory = $container->get(FormatHandlerFactoryInterface::class);
        $this->assertInstanceOf(FormatHandlerFactoryInterface::class, $factory);
        $document = $deserializer->deserialize($this->invoiceXml(), 'xml', ['validate' => true]);
        $this->assertInstanceOf(Invoice::class, $document);
        $this->assertSame(['json', 'yaml', 'xml'], $factory->getSupportedFormats());

        foreach ($factory->getSupportedFormats() as $format) {
            $handler = $factory->createHandler($format);
            $this->assertSame($format, $handler->getFormat());
            $content = $serializer->serialize($document, $format);
            $again = $deserializer->deserialize($content, $format, ['validate' => true]);
            $this->assertInstanceOf(Invoice::class, $again);
            $this->assertSame($document->toArray(), $again->toArray());
        }
    }

    public function testApplicationVersionOverrideReachesTheDefaultXmlHandler(): void
    {
        $container = $this->container(version: '2.2');
        $versions = $container->get(VersionRegistryInterface::class);
        $this->assertInstanceOf(VersionRegistryInterface::class, $versions);
        $this->assertSame('2.2', $versions->getDefault()->getId());
        $deserializer = $container->get(DeserializerInterface::class);
        $this->assertInstanceOf(DeserializerInterface::class, $deserializer);
        $document = $deserializer->deserialize($this->invoiceXml(), 'xml', ['validate' => true]);
        $this->assertSame('Derafu\\Ubl\\V22\\Oasis\\Doc\\Invoice', $document::class);
    }

    public function testTaggedCodecIsConsumedBySerializationAndDeserialization(): void
    {
        $container = $this->container(codec: true);
        $this->assertInstanceOf(NoteExtensionCodec::class, $container->get(NoteExtensionCodec::class));
        $deserializer = $container->get(DeserializerInterface::class);
        $this->assertInstanceOf(DeserializerInterface::class, $deserializer);
        $serializer = $container->get(SerializerInterface::class);
        $this->assertInstanceOf(SerializerInterface::class, $serializer);
        $invoice = $deserializer->deserialize($this->invoiceXml(), 'xml');
        $this->assertInstanceOf(Invoice::class, $invoice);
        $data = $invoice->toArray();
        $fragment = '<e:Note xmlns:e="urn:example:services:note">hello &amp; goodbye</e:Note>';
        $data['UBLExtensions'] = [['UBLExtension' => [[
            'ExtensionURI' => [['_' => NoteExtensionCodec::URI]],
            'ExtensionContent' => [['any' => [$fragment]]],
        ]]]];
        $document = Invoice::fromArray($data);

        foreach (['json', 'yaml'] as $format) {
            $content = $serializer->serialize($document, $format);
            $encoded = $format === 'json'
                ? json_decode($content, true, flags: JSON_THROW_ON_ERROR)
                : Yaml::parse($content);
            foreach (['Invoice', 'UBLExtensions', 0, 'UBLExtension', 0, 'ExtensionContent', 0] as $key) {
                $this->assertIsArray($encoded);
                $this->assertArrayHasKey($key, $encoded);
                $encoded = $encoded[$key];
            }
            $this->assertSame(['Note' => 'hello & goodbye'], $encoded);
            $again = $deserializer->deserialize($content, $format);
            $this->assertInstanceOf(Invoice::class, $again);
            $this->assertSame($document->toArray(), $again->toArray());
            $this->assertStringContainsString($fragment, $serializer->serialize($again, 'xml'));
        }
    }

    public function testTaggedResourcesTranslateARealUnsupportedFormatException(): void
    {
        $container = $this->container();
        $factory = $container->get(FormatHandlerFactoryInterface::class);
        $this->assertInstanceOf(FormatHandlerFactoryInterface::class, $factory);
        $translator = $container->get('app.translator');
        $this->assertInstanceOf(Translator::class, $translator);
        try {
            $factory->createHandler('toml');
            $this->fail('An unsupported format was accepted.');
        } catch (UnsupportedFormatException $exception) {
            $this->assertSame('El formato "toml" no está soportado.', $exception->trans($translator));
        }
    }
}
