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
 * xterr/php-ubl-generator. Downloads the XSD into a local cache under var/ if not already
 * there.
 *
 * Run with: composer run generate-oasis (which also runs tools/patch-oasis.php and
 * composer phpcs-fix afterwards).
 */

const UBL_VERSION = '2.1';

$rootDir = dirname(__DIR__);
$cacheDir = "$rootDir/var/oasis-ubl-" . UBL_VERSION;
$zipUrl = 'https://docs.oasis-open.org/ubl/os-UBL-' . UBL_VERSION . '/UBL-' . UBL_VERSION . '.zip';
$zipFile = "$rootDir/var/UBL-" . UBL_VERSION . '.zip';
$outputDir = "$rootDir/src/Oasis";

if (!is_dir("$cacheDir/xsd")) {
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
    curl_close($curl);
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

    mkdir($cacheDir, 0775, true);
    // Only the schemas are needed to generate the model.
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if ($entry !== false && str_starts_with($entry, 'xsd/')) {
            $zip->extractTo($cacheDir, $entry);
        }
    }
    $zip->close();
    unlink($zipFile);
}

$generator = "$rootDir/vendor/bin/php-ubl-generator";
$command = sprintf(
    '%s --schema-dir=%s --schema-version=%s --namespace=%s --output-dir=%s --force',
    escapeshellarg($generator),
    escapeshellarg("$cacheDir/xsd"),
    escapeshellarg(UBL_VERSION),
    escapeshellarg('Derafu\\Ubl\\Oasis'),
    escapeshellarg($outputDir)
);

passthru($command, $exitCode);

if ($exitCode !== 0) {
    exit($exitCode);
}

fwrite(STDOUT, "Generated into $outputDir.\n");
