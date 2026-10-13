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

use Derafu\Ubl\Contract\ExtensionContentCodecInterface;
use Derafu\Ubl\Exception\DeserializationException;
use Derafu\Ubl\Service\ExtensionContentCodecs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtensionContentCodecs::class)]
final class ExtensionContentCodecsTest extends TestCase
{
    private const URI = 'urn:example:ext:1';

    /**
     * An extension that a codec supports is written as the object of its vocabulary, and read
     * back as the XML fragments it is.
     */
    #[Test]
    public function aSupportedExtensionIsTheObjectOfItsCodec(): void
    {
        $codecs = new ExtensionContentCodecs([$this->codec()]);
        $xml = $this->document(['any' => ['<e:Note xmlns:e="urn:example:ext:1">hi</e:Note>']]);
        $json = $this->document(['Note' => 'hi']);

        $this->assertSame($json, $codecs->encode($xml));
        $this->assertSame($xml, $codecs->decode($json));
    }

    /**
     * Without a codec the XML of the extension is kept as it is, so nothing of it is lost.
     */
    #[Test]
    public function anExtensionWithoutACodecKeepsItsXml(): void
    {
        $xml = $this->document(['any' => ['<x:Signature xmlns:x="urn:other"/>']]);

        $this->assertSame($xml, (new ExtensionContentCodecs())->encode($xml));
        $this->assertSame($xml, (new ExtensionContentCodecs())->decode($xml));
    }

    #[Test]
    public function anExtensionThatIsNotXmlAndHasNoCodecThrows(): void
    {
        $this->expectException(DeserializationException::class);

        (new ExtensionContentCodecs())->decode($this->document(['Note' => 'hi']));
    }

    #[Test]
    public function aDocumentWithoutExtensionsIsNotTouched(): void
    {
        $data = ['ID' => ['_' => 'Q-1']];

        $this->assertSame($data, (new ExtensionContentCodecs([$this->codec()]))->encode($data));
        $this->assertSame($data, (new ExtensionContentCodecs([$this->codec()]))->decode($data));
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function document(array $content): array
    {
        return ['UBLExtensions' => [['UBLExtension' => [
            ['ExtensionURI' => [['_' => self::URI]], 'ExtensionContent' => [$content]],
        ]]]];
    }

    private function codec(): ExtensionContentCodecInterface
    {
        return new class () implements ExtensionContentCodecInterface {
            public function supports(string $extensionUri): bool
            {
                return $extensionUri === 'urn:example:ext:1';
            }

            public function encode(array $fragments): array
            {
                return ['Note' => strip_tags($fragments[0])];
            }

            public function decode(array $content): array
            {
                $note = $content['Note'] ?? '';

                return [sprintf('<e:Note xmlns:e="urn:example:ext:1">%s</e:Note>', is_string($note) ? $note : '')];
            }
        };
    }
}
