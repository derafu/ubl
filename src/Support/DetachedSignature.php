<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Ubl\Support;

/**
 * A document with the signature that is not in it (detached).
 */
final class DetachedSignature
{
    /**
     * @param string $document XML of the document, with the `cac:Signature`
     * that says where its signature is. It must be kept as it is.
     * @param string $signature XML of the signature.
     */
    public function __construct(
        public readonly string $document,
        public readonly string $signature,
    ) {
    }
}
