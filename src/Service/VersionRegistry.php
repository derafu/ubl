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

use Derafu\Ubl\Contract\VersionInterface;
use Derafu\Ubl\Contract\VersionRegistryInterface;
use Derafu\Ubl\Exception\UnsupportedVersionException;

/**
 * The versions of UBL this package has a model for, and the one that is used by default.
 *
 * The default is `2.1`, the version of Peppol BIS Billing 3.0 and of most of the world's
 * e-invoicing. An application changes it in its own services (`derafu.ubl.version`), and any call
 * can ask for another one (`['version' => '2.2']`).
 *
 * The version is chosen, never detected: every 2.x version has the same XML namespaces and
 * `UBLVersionID` is optional. And each version has its own model because the versions only add and
 * relax cardinalities, and a relaxed cardinality changes the PHP type of a property (a value that
 * becomes a list): what is valid in a version is valid in the later ones, but not as the same
 * class.
 */
final class VersionRegistry implements VersionRegistryInterface
{
    public const DEFAULT = '2.1';

    public const SUPPORTED = ['2.1', '2.2', '2.3', '2.4', '2.5'];

    /**
     * @var array<string, VersionInterface>
     */
    private array $versions = [];

    /**
     * @param list<string> $supported The ids of the versions that are available.
     *
     * @throws UnsupportedVersionException If the default is not one of them.
     */
    public function __construct(
        private readonly string $default = self::DEFAULT,
        private readonly array $supported = self::SUPPORTED,
    ) {
        if (!in_array($default, $supported, true)) {
            throw new UnsupportedVersionException([
                'UBL version "{version}" is not supported.',
                'version' => $default,
            ]);
        }
    }

    public function get(?string $id = null): VersionInterface
    {
        $id ??= $this->default;

        if (!in_array($id, $this->supported, true)) {
            throw new UnsupportedVersionException([
                'UBL version "{version}" is not supported.',
                'version' => $id,
            ]);
        }

        return $this->versions[$id] ??= new Version($id);
    }

    public function getDefault(): VersionInterface
    {
        return $this->get();
    }

    public function forClass(string $class): VersionInterface
    {
        foreach ($this->supported as $id) {
            $version = $this->get($id);
            if (str_starts_with($class, $version->getNamespace() . '\\')) {
                return $version;
            }
        }

        throw new UnsupportedVersionException([
            '{class} is not part of the model of any supported UBL version.',
            'class' => $class,
        ]);
    }

    public function all(): array
    {
        return array_map($this->get(...), $this->supported);
    }
}
