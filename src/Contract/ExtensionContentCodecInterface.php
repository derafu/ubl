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

/**
 * Says how the content of one UBL extension (`UBLExtension/ExtensionContent`) is expressed in
 * JSON and YAML.
 *
 * UBL leaves the content of an extension free (XML of any vocabulary), and the OASIS JSON
 * note leaves it out of its scope on purpose: "trading partners must agree on the significance"
 * of it, and the schemas only ask for an object. The extension is identified by its
 * `ExtensionURI`, so a codec is the agreement of a vocabulary: it turns the XML fragments of
 * the extension into the JSON object of that vocabulary, and back.
 *
 * An extension that no codec supports keeps its fragments as they are, as XML strings under
 * `any` (see `Service\ExtensionContentCodecs`).
 */
interface ExtensionContentCodecInterface
{
    /**
     * Tells if this codec is the one of the extension with that `ExtensionURI`.
     */
    public function supports(string $extensionUri): bool;

    /**
     * XML fragments of the extension -> the object that is the content of the extension in
     * JSON and YAML.
     *
     * @param list<string> $fragments
     * @return array<string, mixed>
     */
    public function encode(array $fragments): array;

    /**
     * The object that is the content of the extension in JSON and YAML -> XML fragments.
     *
     * @param array<string, mixed> $content
     * @return list<string>
     */
    public function decode(array $content): array;
}
