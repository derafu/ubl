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
use Derafu\Ubl\Exception\UnsupportedVersionException;

/**
 * A version of UBL. Everything about it follows from its id: the namespace of its model
 * (`2.1` is `Derafu\Ubl\V21\Oasis`), its generated `Xml\DocumentRegistry` and the directories of
 * its schemas under `resources/schemas/`.
 */
final class Version implements VersionInterface
{
    private readonly string $namespace;

    /**
     * @var class-string
     */
    private readonly string $registry;

    /**
     * @throws UnsupportedVersionException If there is no model generated for the version.
     */
    public function __construct(private readonly string $id)
    {
        if (preg_match('/^2\.\d+$/', $id) !== 1) {
            throw new UnsupportedVersionException([
                'UBL version "{version}" is not supported.',
                'version' => $id,
            ]);
        }

        $this->namespace = 'Derafu\\Ubl\\V' . str_replace('.', '', $id) . '\\Oasis';

        $registry = $this->namespace . '\\Xml\\DocumentRegistry';
        if (!class_exists($registry)) {
            throw new UnsupportedVersionException([
                'UBL version "{version}" is not supported.',
                'version' => $id,
            ]);
        }
        $this->registry = $registry;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function classFor(string $namespaceUri, string $localName): ?string
    {
        $classFor = [$this->registry, 'classFor'];
        $class = is_callable($classFor) ? $classFor($namespaceUri, $localName) : null;

        return is_string($class) && class_exists($class) ? $class : null;
    }

    public function rootElementFor(string $class): ?array
    {
        $rootElementFor = [$this->registry, 'rootElementFor'];
        $root = is_callable($rootElementFor) ? $rootElementFor($class) : null;

        if (!is_array($root) || !is_string($root['namespace'] ?? null) || !is_string($root['localName'] ?? null)) {
            return null;
        }

        return ['namespace' => $root['namespace'], 'localName' => $root['localName']];
    }

    public function getDocumentClass(string $document): ?string
    {
        if (preg_match('/^[A-Za-z]+$/', $document) !== 1) {
            return null;
        }

        $class = $this->namespace . '\\Doc\\' . $document;

        return class_exists($class) && method_exists($class, 'fromArray') ? $class : null;
    }

    public function getXsdPath(string $document): string
    {
        return $this->resources('xsd') . "/maindoc/UBL-{$document}-{$this->id}.xsd";
    }

    public function getJsonSchemaDirectory(): ?string
    {
        $directory = $this->resources('json');

        return is_dir($directory) ? $directory : null;
    }

    public function getJsonSchemaPath(string $document): ?string
    {
        $directory = $this->getJsonSchemaDirectory();

        return $directory === null ? null : "$directory/maindoc/UBL-{$document}-{$this->id}.json";
    }

    private function resources(string $kind): string
    {
        return dirname(__DIR__, 2) . "/resources/schemas/$kind/{$this->id}";
    }
}
