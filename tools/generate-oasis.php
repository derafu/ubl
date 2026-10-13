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
 * (Re)generates Derafu\Ubl\V<nn>\Oasis\{Cbc,Cac,Doc,Enum,Ext,Sac,Sig} (`src/V<nn>/Oasis/`) from the
 * official OASIS UBL XSD of a version, with xterr/php-ubl-generator. Downloads the XSD into
 * resources/schemas/xsd/<version>/ if not already there, and generates from that same committed
 * copy.
 *
 *     php tools/generate-oasis.php [2.1|2.2|2.3|2.4|2.5|all]
 *
 * Run with: composer run generate-oasis (every version; it also runs tools/patch-oasis.php and
 * composer phpcs-fix afterwards).
 */

require __DIR__ . '/oasis-common.php';

$rootDir = dirname(__DIR__);

foreach (oasisSelectVersions($argv) as $version => $urls) {
    $schemaDir = "$rootDir/resources/schemas/xsd/$version";
    $outputDir = oasisSourceDirOf($version);

    if (!is_dir($schemaDir)) {
        $zipFile = "$rootDir/var/UBL-$version.zip";
        oasisDownload($urls['xsd'], $zipFile);
        $zip = oasisOpenZip($zipFile);

        mkdir($schemaDir, 0775, true);
        // Only the schemas are needed to generate the model, extracted without the zip's own
        // leading xsd/ directory so resources/schemas/xsd/<version>/ holds their contents
        // directly.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false) {
                continue;
            }

            $entry = oasisStripRoot($entry);
            if (!str_starts_with($entry, 'xsd/')) {
                continue;
            }

            $relative = substr($entry, strlen('xsd/'));
            if ($relative === '') {
                continue;
            }

            $target = "$schemaDir/$relative";
            if (str_ends_with($entry, '/')) {
                if (!is_dir($target)) {
                    mkdir($target, 0775, true);
                }
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

    $command = sprintf(
        '%s --schema-dir=%s --schema-version=%s --namespace=%s --output-dir=%s --force',
        escapeshellarg("$rootDir/vendor/bin/php-ubl-generator"),
        escapeshellarg($schemaDir),
        escapeshellarg($version),
        escapeshellarg(oasisNamespaceOf($version)),
        escapeshellarg($outputDir)
    );

    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        exit($exitCode);
    }

    fwrite(STDOUT, "Generated UBL $version into $outputDir.\n");
}
