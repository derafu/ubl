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
 * Downloads the official OASIS "UBL JSON Alternative Representation" (Committee Note 01:
 * https://docs.oasis-open.org/ubl/UBL-2.1-JSON/v2.0/cn01/ for 2.1, and the v1.0 ones of 2.2 and
 * 2.3) of a version:
 *
 *   - The model-based JSON examples (`json-model/`) into
 *     tests/fixtures/<version>/doc/json/<document>/, named like the XML ones
 *     (`<Document>_<NNN>_official_example[_<variant>].json`). Only the examples of that version.
 *   - The model-based JSON schemas (`json-schema-model/`) into resources/schemas/json/<version>/.
 *
 *     php tools/fetch-oasis-json.php [2.1|2.2|2.3|all]
 *
 * OASIS has published the note for those three versions only: there are no official JSON
 * schemas for 2.4 and 2.5, and the JSON of those versions cannot be validated against any.
 *
 * The JSON that this package produces and reads is the model-based form of that note, and
 * these are the official files it is proven against (see tests/src/Service/OasisJsonTest.php).
 * The schemas are shipped (resources/schemas/json/<version>/, untouched, each with the OASIS
 * notice it carries); the examples are test-only fixtures. Run it again to refresh them.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/oasis-common.php';

$rootDir = dirname(__DIR__);

foreach (oasisSelectVersions($argv) as $version => $urls) {
    if ($urls['json'] === null) {
        fwrite(STDOUT, "UBL $version: OASIS has published no JSON representation for it, skipped.\n");
        continue;
    }

    $zipFile = "$rootDir/var/UBL-$version-JSON.zip";
    $examplesDir = "$rootDir/tests/fixtures/$version/doc/json";
    $schemasDir = "$rootDir/resources/schemas/json/$version";

    oasisDownload($urls['json'], $zipFile);
    $zip = oasisOpenZip($zipFile);

    // Examples: json-model/UBL-<Document>-<version>-Example[-<variant>].json, numbered like the
    // XML ones.
    $pattern = '/^json-model\/UBL-([A-Za-z]+)-' . preg_quote($version, '/') . '-Example(?:-(.+))?\.json$/';
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

        if (!class_exists(oasisNamespaceOf($version) . '\\Doc\\' . $documentName)) {
            fwrite(STDERR, "No Doc\\$documentName class for example \"$name\"; the model may be out of date.\n");
            exit(1);
        }

        $contents = $zip->getFromName($name);
        if ($contents === false) {
            fwrite(STDERR, "The zip entry is unreadable: $name\n");
            exit(1);
        }
        if (trim($contents) === '') {
            // OASIS ships a few of its examples as empty files (2.3's ImportCustomsDeclaration):
            // nothing to prove with them.
            fwrite(STDOUT, "UBL $version: $name is empty in the OASIS archive, skipped.\n");
            continue;
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

    fwrite(STDOUT, "UBL $version: wrote $written official JSON example(s) into $examplesDir and $writtenSchemas JSON schema(s) into $schemasDir.\n");
}
