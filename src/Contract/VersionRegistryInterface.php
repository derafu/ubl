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

use Derafu\Ubl\Exception\UnsupportedVersionException;

/**
 * The versions of UBL that are available, and which one is used when the caller does not say.
 */
interface VersionRegistryInterface
{
    /**
     * @param string|null $id The id of the version (`2.2`), `null` for the default one.
     *
     * @throws UnsupportedVersionException If the version is not supported.
     */
    public function get(?string $id = null): VersionInterface;

    public function getDefault(): VersionInterface;

    /**
     * The version of the model that a document class belongs to.
     *
     * @param class-string $class
     *
     * @throws UnsupportedVersionException If the class is not part of any supported version.
     */
    public function forClass(string $class): VersionInterface;

    /**
     * @return list<VersionInterface>
     */
    public function all(): array;
}
