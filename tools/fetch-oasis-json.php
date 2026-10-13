<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

/**
 * Downloads the official OASIS "UBL 2.1 JSON Alternative Representation" (Committee Note 01,
 * version 2.0, https://docs.oasis-open.org/ubl/UBL-2.1-JSON/v2.0/cn01/) test material:
 *
 *   - The model-based JSON examples (`json-model/`) into
 *     tests/fixtures/doc/json/<document>/, named like the XML ones
 *     (`<Document>_<NNN>_official_example[_<variant>].json`). Only the UBL 2.1 examples.
 *   - The model-based JSON schemas (`json-schema-model/`) into resources/schemas/json/2.1/.
 *
 * The JSON that this package produces and reads is the model-based form of that note, and
 * these are the official files it is proven against (see tests/src/Service/OasisJsonTest.php).
 * The schemas are shipped (resources/schemas/json/2.1/, untouched, each with the OASIS notice
 * it carries); the examples are test-only fixtures. Run it again to refresh them:
 *
 *     php tools/fetch-oasis-json.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

const ZIP_URL = 'https://docs.oasis-open.org/ubl/UBL-2.1-JSON/v2.0/cn01/UBL-2.1-JSON-v2.0-cn01.zip';

$rootDir = dirname(__DIR__);
$zipFile = "$rootDir/var/UBL-2.1-JSON-v2.0-cn01.zip";
$examplesDir = "$rootDir/tests/fixtures/doc/json";
$schemasDir = "$rootDir/resources/schemas/json/2.1";

if (!is_dir("$rootDir/var")) {
    mkdir("$rootDir/var", 0775, true);
}

fwrite(STDOUT, 'Fetching ' . ZIP_URL . " ...\n");

$curl = curl_init(ZIP_URL);
$file = fopen($zipFile, 'wb');
curl_setopt_array($curl, [
    CURLOPT_FILE => $file,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_FAILONERROR => true,
    CURLOPT_TIMEOUT => 120,
]);
$ok = curl_exec($curl);
$error = curl_error($curl);
fclose($file);

if (!$ok) {
    unlink($zipFile);
    fwrite(STDERR, "Download failed: $error\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipFile) !== true) {
    fwrite(STDERR, "Could not open the downloaded archive: $zipFile\n");
    exit(1);
}

// Examples: json-model/UBL-<Document>-2.1-Example[-<variant>].json, numbered like the XML ones.
$pattern = '/^json-model\/UBL-([A-Za-z]+)-2\.1-Example(?:-(.+))?\.json$/';
$entries = [];
$schemas = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if ($name === false) {
        continue;
    }
    if (preg_match($pattern, $name, $matches) === 1) {
        $variantRank = isset($matches[2]) ? '1' . $matches[2] : '0';
        $entries[$matches[1] . '|' . $variantRank . '|' . $name] = [$name, $matches];
    } elseif (str_starts_with($name, 'json-schema-model/') && str_ends_with($name, '.json')) {
        $schemas[] = $name;
    }
}
ksort($entries);

if ($entries === [] || $schemas === []) {
    fwrite(STDERR, "The archive does not have the expected json-model/ and json-schema-model/ content.\n");
    exit(1);
}

$counters = [];
$written = 0;
foreach ($entries as [$name, $matches]) {
    $documentName = $matches[1];
    $variant = $matches[2] ?? null;

    if (!class_exists('Derafu\\Ubl\\Oasis\\Doc\\' . $documentName)) {
        fwrite(STDERR, "No Oasis\\Doc\\$documentName class for example \"$name\"; the model may be out of date.\n");
        exit(1);
    }

    $contents = $zip->getFromName($name);
    if ($contents === false || trim($contents) === '') {
        fwrite(STDERR, "The zip entry is empty or unreadable: $name\n");
        exit(1);
    }

    $counters[$documentName] = ($counters[$documentName] ?? 0) + 1;
    $slug = $variant === null ? 'official_example' : 'official_example_' . strtolower($variant);
    $filename = sprintf('%s_%03d_%s.json', $documentName, $counters[$documentName], $slug);

    $targetDir = "$examplesDir/$documentName";
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }
    file_put_contents("$targetDir/$filename", $contents);
    $written++;
}

$writtenSchemas = 0;
foreach ($schemas as $name) {
    $target = $schemasDir . '/' . substr($name, strlen('json-schema-model/'));
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0775, true);
    }
    file_put_contents($target, (string) $zip->getFromName($name));
    $writtenSchemas++;
}

$zip->close();
unlink($zipFile);

fwrite(STDOUT, "Wrote $written official JSON example(s) into $examplesDir and $writtenSchemas JSON schema(s) into $schemasDir.\n");
