<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

return [
    // Formats.
    'Format "{format}" is not supported.' =>
        'El formato "{format}" no está soportado.',

    // Array wire format (shared by the json and yaml handlers).
    '{type} cannot be serialized: it has no toArray() method.' =>
        '{type} no se puede serializar: no tiene el método toArray().',
    'Expected exactly one top-level key naming the document type, got {count}.' =>
        'Se esperaba exactamente una clave de primer nivel con el nombre del tipo de documento, se encontraron {count}.',
    'The content of "{key}" must be an array, got {type}.' =>
        'El contenido de "{key}" debe ser un arreglo, se encontró {type}.',
    'Unknown UBL document type "{key}".' =>
        'Tipo de documento UBL desconocido: "{key}".',

    // JSON.
    'Could not encode {class} as JSON: {message}' =>
        'No se pudo codificar {class} como JSON: {message}',
    'Could not decode JSON: {message}' =>
        'No se pudo decodificar el JSON: {message}',
    'Decoded JSON must be an array, got {type}.' =>
        'El JSON decodificado debe ser un arreglo, se encontró {type}.',

    // YAML.
    'Could not encode {class} as YAML: {message}' =>
        'No se pudo codificar {class} como YAML: {message}',
    'Could not parse YAML: {message}' =>
        'No se pudo parsear el YAML: {message}',
    'Parsed YAML must be an array, got {type}.' =>
        'El YAML parseado debe ser un arreglo, se encontró {type}.',

    // XML.
    'Could not serialize {class} as XML: {message}' =>
        'No se pudo serializar {class} como XML: {message}',
    'Could not deserialize XML into {class}: {message}' =>
        'No se pudo deserializar el XML en {class}: {message}',
    'Could not read the XML root element.' =>
        'No se pudo leer el elemento raíz del XML.',
    'Unknown UBL document root "{tag}".' =>
        'Raíz de documento UBL desconocida: "{tag}".',
];
