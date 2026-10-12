<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Ubl\Service\Format;

use Derafu\Ubl\Contract\FormatHandlerInterface;
use Derafu\Ubl\Exception\DeserializationException;
use Derafu\Ubl\Exception\SerializationException;
use Derafu\Ubl\Service\RootElementResolver;
use Xterr\UBL\Exception\DeserializationException as XterrDeserializationException;
use Xterr\UBL\Exception\SerializationException as XterrSerializationException;
use Xterr\UBL\Xml\XmlDeserializer;
use Xterr\UBL\Xml\XmlSerializer;

/**
 * A document as XML, via `xterr/php-ubl`. This is the only file in the package allowed to
 * `use Xterr\UBL\...`: no public method of `Serializer`/`Deserializer`/their interfaces
 * exposes an `Xterr\UBL` type, so no consumer ever needs to import it.
 */
final class XmlHandler implements FormatHandlerInterface
{
    public function __construct(
        private readonly XmlSerializer $serializer = new XmlSerializer(),
        private readonly XmlDeserializer $deserializer = new XmlDeserializer(),
    ) {
    }

    public function serialize(object $document): string
    {
        try {
            return $this->serializer->serialize($document);
        } catch (XterrSerializationException $e) {
            throw new SerializationException([
                'Could not serialize {class} as XML: {message}',
                'class' => $document::class,
                'message' => $e->getMessage(),
            ], previous: $e);
        }
    }

    /**
     * @param class-string|null $class
     */
    public function deserialize(string $content, ?string $class = null): object
    {
        $class ??= $this->detectClass($content);

        try {
            return $this->deserializer->deserialize($content, $class);
        } catch (XterrDeserializationException $e) {
            throw new DeserializationException([
                'Could not deserialize XML into {class}: {message}',
                'class' => $class,
                'message' => $e->getMessage(),
            ], previous: $e);
        }
    }

    public function getFormat(): string
    {
        return 'xml';
    }

    /**
     * Resolves the target class from the XML root element via `RootElementResolver`:
     * `Xterr\UBL\Xml\XmlDeserializer` requires an explicit class, it does not auto-detect one.
     *
     * @return class-string
     */
    private function detectClass(string $content): string
    {
        $class = RootElementResolver::resolve($content);

        if ($class === null) {
            throw new DeserializationException([
                'Could not resolve a UBL document type from the XML root element.',
            ]);
        }

        return $class;
    }
}
