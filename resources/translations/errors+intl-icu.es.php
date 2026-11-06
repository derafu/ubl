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
    'The XML fragments of an extension are not a list of strings: {reason}' =>
        'Los fragmentos XML de una extensión no son una lista de strings: {reason}',
    'The content of an extension is not an object: {reason}' =>
        'El contenido de una extensión no es un objeto: {reason}',
    'The document built from the content is not an instance of {class}: {reason}' =>
        'El documento construido desde el contenido no es una instancia de {class}: {reason}',
    'The toArray() of {class} did not return an array: {reason}' =>
        'El toArray() de {class} no devolvió un array: {reason}',
    '{class} does not validate against its UBL schema and its errors cannot be read: {reason}' =>
        '{class} no valida contra su esquema UBL y sus errores no se pueden leer: {reason}',

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

    // Versions.
    'UBL version "{version}" is not supported.' =>
        'La versión {version} de UBL no está soportada.',
    '{class} is not part of the model of any supported UBL version.' =>
        '{class} no es parte del modelo de ninguna versión de UBL soportada.',
    'OASIS has published no JSON schemas for UBL {version}, so its JSON and YAML cannot be validated.' =>
        'OASIS no ha publicado esquemas JSON para UBL {version}, por lo que su JSON y YAML no se pueden validar.',

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

    // Signature of the documents (UBL Digital Signature Profile).
    'The options of the signature are not valid: {detail}' =>
        'Las opciones de la firma no son válidas: {detail}',
    '{class} is not a UBL document of this package.' =>
        '{class} no es un documento UBL de este paquete.',
    'The document already has signatures inside: give its XML to add another one.' =>
        'El documento ya tiene firmas en su interior: se debe dar su XML para agregar otra.',
    'A document that has signatures inside can not get a detached one: its signatures would not be valid.' =>
        'Un documento que tiene firmas en su interior no puede recibir una firma separada: sus firmas dejarían de ser válidas.',
    'The document does not have a cac:Signature with the cbc:ID "{id}".' =>
        'El documento no tiene un cac:Signature con el cbc:ID "{id}".',
    'The document already has a cac:Signature with the cbc:ID "{id}".' =>
        'El documento ya tiene un cac:Signature con el cbc:ID "{id}".',
    'The document already has a sac:SignatureInformation with the cbc:ID "{id}".' =>
        'El documento ya tiene un sac:SignatureInformation con el cbc:ID "{id}".',
    'The resource "{uri}" can not be resolved.' =>
        'No se puede obtener el recurso "{uri}".',

    // Validation of the signatures of the documents.
    'An extension with signatures does not have the ext:ExtensionURI that says so.' =>
        'Una extensión con firmas no tiene el ext:ExtensionURI que lo indica.',
    'The ext:ExtensionURI "{uri}" of an extension with signatures is not one of the profile.' =>
        'El ext:ExtensionURI "{uri}" de una extensión con firmas no es uno de los del perfil.',
    'The sbc:ReferencedSignatureID "{id}" is not the cbc:ID of a cac:Signature of the document.' =>
        'El sbc:ReferencedSignatureID "{id}" no es el cbc:ID de un cac:Signature del documento.',
    'The cbc:SignatureMethod "{method}" of the cac:Signature "{id}" is not one of the enveloped profile.' =>
        'El cbc:SignatureMethod "{method}" del cac:Signature "{id}" no es uno del perfil enveloped.',
    'The Id "{id}" of a signature or of its value is not unique in the document.' =>
        'El Id "{id}" de una firma o de su valor no es único en el documento.',
    'The cbc:SignatureMethod "{method}" of a cac:Signature with a signature outside of the document is not one of the detached profile.' =>
        'El cbc:SignatureMethod "{method}" de un cac:Signature con una firma fuera del documento no es uno del perfil detached.',
];
