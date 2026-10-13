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

use Derafu\Ubl\Contract\NormalizerInterface;

/**
 * The normalizer that does nothing: it returns the data it was given.
 *
 * It is the place where the shapes other than the model-based one of the OASIS note will be
 * accepted (see `NormalizerInterface`). For now none is: the data has to come as the note says.
 */
final class Normalizer implements NormalizerInterface
{
    /**
     * {@inheritDoc}
     */
    public function normalize(array $data, string $class): array
    {
        return $data;
    }
}
