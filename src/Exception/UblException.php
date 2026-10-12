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

use Derafu\Translation\Exception\Core\TranslatableException;

/**
 * This package's generic error, thrown directly when nothing more specific applies, and the
 * common ancestor every other exception of this package extends.
 */
class UblException extends TranslatableException
{
}
