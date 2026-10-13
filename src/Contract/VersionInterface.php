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
 * One version of UBL (`2.1`, `2.2`...): its generated model (`Derafu\Ubl\V21\Oasis\...`) and
 * the official OASIS schemas bundled for it.
 *
 * Every 2.x version shares the XML namespaces (`...:Invoice-2`), so a document does not say
 * which version it is: the caller chooses it (see `VersionRegistryInterface`).
 */
interface VersionInterface
{
    /**
     * The id of the version, as OASIS writes it: `2.1`.
     */
    public function getId(): string;

    /**
     * The PHP namespace of the generated model: `Derafu\Ubl\V21\Oasis`.
     */
    public function getNamespace(): string;

    /**
     * The document class of a root element, `null` if the element is not a document of this
     * version.
     *
     * @return class-string|null
     */
    public function classFor(string $namespaceUri, string $localName): ?string;

    /**
     * The root element of a document class, `null` if it is not a document of this version.
     *
     * @param class-string $class
     * @return array{namespace: string, localName: string}|null
     */
    public function rootElementFor(string $class): ?array;

    /**
     * The document class of the short name of a document (`Invoice`), `null` if this version
     * has no such document.
     *
     * @return class-string|null
     */
    public function getDocumentClass(string $document): ?string;

    /**
     * The XSD of a document (`Invoice`): the official one, bundled in `resources/schemas/xsd/`.
     */
    public function getXsdPath(string $document): string;

    /**
     * The directory of the official JSON schemas of the version, `null` if OASIS has not
     * published them for it (the JSON note covers 2.1, 2.2 and 2.3).
     */
    public function getJsonSchemaDirectory(): ?string;

    /**
     * The official JSON schema of a document (`Invoice`), `null` if the version has none.
     */
    public function getJsonSchemaPath(string $document): ?string;
}
