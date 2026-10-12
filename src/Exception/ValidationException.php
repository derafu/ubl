<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Ubl\Exception;

use Derafu\Translation\Contract\TranslatableInterface;
use LibXMLError;
use Throwable;

/**
 * Thrown when an XML document is not well-formed, its document type cannot be resolved, or it
 * does not validate against the official OASIS XSD.
 */
final class ValidationException extends UblException
{
    /**
     * @param string|array<int|string, mixed>|TranslatableInterface $message
     * @param list<LibXMLError> $errors The libxml errors that caused this exception, if any.
     */
    public function __construct(
        string|array|TranslatableInterface $message,
        private readonly array $errors = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return list<LibXMLError>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
