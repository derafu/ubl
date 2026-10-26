<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsUbl\Fixture;

use Derafu\Ubl\Contract\ExtensionContentCodecInterface;
use DOMDocument;
use UnexpectedValueException;

/**
 * A consumer vocabulary with one XML note and its JSON/YAML representation.
 */
final class NoteExtensionCodec implements ExtensionContentCodecInterface
{
    public const URI = 'urn:example:services:note';

    public function supports(string $extensionUri): bool
    {
        return $extensionUri === self::URI;
    }

    public function encode(array $fragments): array
    {
        $document = new DOMDocument();
        $document->loadXML($fragments[0] ?? '');
        $note = $document->getElementsByTagNameNS(self::URI, 'Note')->item(0);
        if ($note === null) {
            throw new UnexpectedValueException('The extension must contain a note.');
        }

        return ['Note' => $note->textContent];
    }

    public function decode(array $content): array
    {
        $note = $content['Note'] ?? null;
        if (!is_string($note)) {
            throw new UnexpectedValueException('The extension note must be a string.');
        }

        return [sprintf(
            '<e:Note xmlns:e="%s">%s</e:Note>',
            self::URI,
            htmlspecialchars($note, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        )];
    }
}
