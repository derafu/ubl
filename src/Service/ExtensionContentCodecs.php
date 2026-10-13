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

use Derafu\Ubl\Contract\ExtensionContentCodecInterface;
use Derafu\Ubl\Exception\DeserializationException;

/**
 * Applies the `ExtensionContentCodecInterface` of each extension of a document, in the
 * `array` that is the JSON/YAML of that document (the scaffolding is
 * `UBLExtensions[0].UBLExtension[*]`, with `ExtensionURI[0]._` and `ExtensionContent[0]`).
 *
 * Without a codec for an extension the content stays as `ExtensionContent::toArray()` gives
 * it, `{"any": ["<xml .../>", ...]}`: the XML as it is, so nothing of it (a digital signature,
 * for example) is lost.
 */
final class ExtensionContentCodecs
{
    /**
     * @param iterable<ExtensionContentCodecInterface> $codecs
     */
    public function __construct(
        private readonly iterable $codecs = [],
    ) {
    }

    /**
     * Content of the extensions as XML fragments -> as the codecs say.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function encode(array $document): array
    {
        return $this->apply($document, function (ExtensionContentCodecInterface $codec, array $content): array {
            $fragments = $content['any'] ?? null;

            return is_array($fragments) && array_is_list($fragments)
                ? $codec->encode($fragments)
                : $content;
        }, false);
    }

    /**
     * Content of the extensions as the codecs say -> as XML fragments.
     *
     * @param array<array-key, mixed> $document
     * @return array<array-key, mixed>
     */
    public function decode(array $document): array
    {
        return $this->apply($document, fn (ExtensionContentCodecInterface $codec, array $content): array => isset($content['any'])
                ? $content
                : ['any' => $codec->decode($content)], true);
    }

    /**
     * @param array<array-key, mixed> $document
     * @param callable(ExtensionContentCodecInterface, array<array-key, mixed>): array<array-key, mixed> $convert
     * @return array<array-key, mixed>
     */
    private function apply(array $document, callable $convert, bool $strict): array
    {
        $wrapper = $document['UBLExtensions'] ?? null;
        $scaffolding = is_array($wrapper) ? ($wrapper[0] ?? null) : null;
        $extensions = is_array($scaffolding) ? ($scaffolding['UBLExtension'] ?? null) : null;
        if (!is_array($extensions)) {
            return $document;
        }

        foreach ($extensions as $i => $extension) {
            if (!is_array($extension)) {
                continue;
            }

            $contents = $extension['ExtensionContent'] ?? null;
            if (!is_array($contents) || !is_array($contents[0] ?? null)) {
                continue;
            }
            $content = $contents[0];

            $ids = $extension['ExtensionURI'] ?? null;
            $id = is_array($ids) ? ($ids[0] ?? null) : null;
            $uri = is_array($id) ? ($id['_'] ?? null) : null;
            $codec = is_string($uri) ? $this->find($uri) : null;

            if ($codec === null) {
                // Without a codec the content must already be the fragments of the XML.
                if ($strict && !isset($content['any'])) {
                    throw new DeserializationException([
                        'The content of the extension "{uri}" is not XML fragments and no codec supports it.',
                        'uri' => is_string($uri) ? $uri : '',
                    ]);
                }
                continue;
            }

            $contents[0] = $convert($codec, $content);
            $extension['ExtensionContent'] = $contents;
            $extensions[$i] = $extension;
        }

        $scaffolding['UBLExtension'] = $extensions;
        $wrapper[0] = $scaffolding;
        $document['UBLExtensions'] = $wrapper;

        return $document;
    }

    private function find(string $extensionUri): ?ExtensionContentCodecInterface
    {
        foreach ($this->codecs as $codec) {
            if ($codec->supports($extensionUri)) {
                return $codec;
            }
        }

        return null;
    }
}
