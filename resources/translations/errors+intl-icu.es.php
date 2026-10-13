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

    // Array wire format: the shape of the data (model-based JSON of the OASIS note).
    'The value of {class} must have a "_" property with its content.' =>
        'El valor de {class} debe tener una propiedad "_" con su contenido.',
    'The "_" of {class} must be a scalar, got {type}.' =>
        'El "_" de {class} debe ser un escalar, se encontró {type}.',
    '"{property}" of {class} must be an array of exactly one item.' =>
        '"{property}" de {class} debe ser un arreglo de exactamente un elemento.',
    '"{property}" of {class} must be an array, got {type}.' =>
        '"{property}" de {class} debe ser un arreglo, se encontró {type}.',
    '"{property}" of {class} must be an object, got {type}.' =>
        '"{property}" de {class} debe ser un objeto, se encontró {type}.',
    '"{property}" of {class} must have a "_" property with its content.' =>
        '"{property}" de {class} debe tener una propiedad "_" con su contenido.',
    'The content of the extension "{uri}" is not XML fragments and no codec supports it.' =>
        'El contenido de la extensión "{uri}" no son fragmentos XML y ningún codec lo soporta.',

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
    'Could not resolve a UBL document type from the XML root element.' =>
        'No se pudo resolver un tipo de documento UBL a partir del elemento raíz del XML.',

    // Schema validation.
    'Could not parse the XML to validate it: {errors}' =>
        'No se pudo parsear el XML para validarlo: {errors}',
    '{class} does not validate against its UBL schema: {errors}' =>
        '{class} no valida contra su esquema UBL: {errors}',
    'No bundled XSD for "{class}" ("{path}" does not exist).' =>
        'No hay un XSD empaquetado para "{class}" ("{path}" no existe).',
    'No bundled JSON schema for "{class}" ("{path}" does not exist).' =>
        'No hay un esquema JSON empaquetado para "{class}" ("{path}" no existe).',
    'Could not turn the data into JSON to validate it: {message}' =>
        'No se pudieron convertir los datos a JSON para validarlos: {message}',
    'Validating JSON or YAML needs the "opis/json-schema" package: composer require opis/json-schema.' =>
        'Validar JSON o YAML requiere el paquete "opis/json-schema": composer require opis/json-schema.',
    'Could not resolve a UBL document type from the data: expected exactly one top-level key naming it, got {count}.' =>
        'No se pudo resolver un tipo de documento UBL a partir de los datos: se esperaba exactamente una clave de primer nivel que lo nombre, hay {count}.',
    'The content is a {actual}, not the {expected} that was asked for.' =>
        'El contenido es un {actual}, no el {expected} que se pidió.',
    'Unknown deserialization option "{option}".' =>
        'Opción de deserialización desconocida "{option}".',
];
