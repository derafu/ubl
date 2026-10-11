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
 * (Re)generates Derafu\Ubl\Oasis\{Cbc,Cac,Doc,Enum} from the official OASIS UBL XSD, with
 * xterr/php-ubl-generator. Downloads the XSD into resources/xsd/<version>/ if not already
 * there, and generates from that same committed copy.
 *
 * Run with: composer run generate-oasis (which also runs tools/patch-oasis.php and
 * composer phpcs-fix afterwards).
 */

const UBL_VERSION = '2.1';

$rootDir = dirname(__DIR__);
$schemaDir = "$rootDir/resources/xsd/" . UBL_VERSION;
$zipUrl = 'https://docs.oasis-open.org/ubl/os-UBL-' . UBL_VERSION . '/UBL-' . UBL_VERSION . '.zip';
$zipFile = "$rootDir/var/UBL-" . UBL_VERSION . '.zip';
$outputDir = "$rootDir/src/Oasis";

if (!is_dir($schemaDir)) {
    fwrite(STDOUT, "Fetching $zipUrl ...\n");

    if (!is_dir("$rootDir/var")) {
        mkdir("$rootDir/var", 0775, true);
    }

    $curl = curl_init($zipUrl);
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

    mkdir($schemaDir, 0775, true);
    // Only the schemas are needed to generate the model, extracted without the zip's own
    // leading xsd/ directory so resources/xsd/<version>/ holds their contents directly.
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if ($entry === false || !str_starts_with($entry, 'xsd/')) {
            continue;
        }

        $relative = substr($entry, strlen('xsd/'));
        if ($relative === '') {
            continue;
        }

        $target = "$schemaDir/$relative";
        if (str_ends_with($entry, '/')) {
            mkdir($target, 0775, true);
            continue;
        }

        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }

        $contents = $zip->getFromIndex($i);
        if ($contents === false) {
            fwrite(STDERR, "Could not read zip entry: $entry\n");
            exit(1);
        }

        file_put_contents($target, $contents);
    }
    $zip->close();
    unlink($zipFile);
}

$generator = "$rootDir/vendor/bin/php-ubl-generator";
$command = sprintf(
    '%s --schema-dir=%s --schema-version=%s --namespace=%s --output-dir=%s --force',
    escapeshellarg($generator),
    escapeshellarg($schemaDir),
    escapeshellarg(UBL_VERSION),
    escapeshellarg('Derafu\\Ubl\\Oasis'),
    escapeshellarg($outputDir)
);

passthru($command, $exitCode);

if ($exitCode !== 0) {
    exit($exitCode);
}

fwrite(STDOUT, "Generated into $outputDir.\n");
