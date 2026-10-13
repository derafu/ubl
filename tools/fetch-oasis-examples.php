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
 * Downloads the official OASIS UBL example instance documents of a version (not the XSD, see
 * tools/generate-oasis.php for that) into tests/fixtures/<version>/doc/xml/<document>/, one
 * subdirectory per `Doc` class name. These are test-only fixtures, never shipped under
 * resources/: a consumer of this package has no use for them, only this package's own test
 * suite does.
 *
 *     php tools/fetch-oasis-examples.php [2.1|2.2|2.3|2.4|2.5|all]
 *
 * Only the examples of that version: the archive of each version also carries the ones of the
 * earlier versions (documents that were not resubmitted with a newer example), which are the
 * fixtures of those versions (plus UBL 2.0's signed example, in the first). Run with:
 * composer run fetch-oasis-examples.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/oasis-common.php';

$rootDir = dirname(__DIR__);

foreach (oasisSelectVersions($argv) as $version => $urls) {
    $zipFile = "$rootDir/var/UBL-$version.zip";
    $fixturesDir = "$rootDir/tests/fixtures/$version/doc/xml";

    oasisDownload($urls['xsd'], $zipFile);
    $zip = oasisOpenZip($zipFile);

    // Named xml/UBL-<Document>-<version>-Example[-<variant>].xml.
    $pattern = '/^xml\/UBL-([A-Za-z]+)-' . preg_quote($version, '/') . '-Example(?:-(.+))?\.xml$/';
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name !== false && preg_match($pattern, oasisStripRoot($name), $matches) === 1) {
            // Sorted so the unsuffixed example of a document (if any) always numbers 001,
            // variants after it in zip order: a plain "...-Example.xml" otherwise sorts after a
            // suffixed "...-Example-Trivial.xml" ('.' > '-' in ASCII), which reads backwards.
            $variantRank = isset($matches[2]) ? '1' . $matches[2] : '0';
            $entries[$matches[1] . '|' . $variantRank . '|' . $name] = [$name, $matches];
        }
    }
    // The only official example of a signed document is UBL 2.0's (`ext:UBLExtension` holding a
    // `sig:UBLDocumentSignatures`): it is a fixture of the first version this package has, which
    // is valid in (and read by) every later one. The other 2.0 signature example, the
    // `Detached-Signature`, is a bare XML-DSig `ds:Signature`, not a UBL document.
    if ($version === array_key_first(oasisVersions())) {
        $entries['Invoice|1enveloped|xml/UBL-Invoice-2.0-Enveloped.xml'] = [
            'xml/UBL-Invoice-2.0-Enveloped.xml',
            ['', 'Invoice', 'enveloped'],
        ];
    }
    ksort($entries);

    if ($entries === []) {
        fwrite(STDERR, "No UBL $version example found in the archive at the expected xml/ path.\n");
        exit(1);
    }

    $counters = [];
    $written = 0;

    foreach ($entries as [$name, $matches]) {
        $documentName = $matches[1];
        $variant = $matches[2] ?? null;
        $class = oasisNamespaceOf($version) . '\\Doc\\' . $documentName;

        if (!class_exists($class)) {
            fwrite(STDERR, "No $class class for example \"$name\"; the model may be out of date.\n");
            exit(1);
        }

        $contents = $zip->getFromName($name);
        if ($contents === false) {
            fwrite(STDERR, "Could not read zip entry: $name\n");
            exit(1);
        }

        $counters[$documentName] = ($counters[$documentName] ?? 0) + 1;
        $slug = $variant === null ? 'official_example' : 'official_example_' . strtolower($variant);
        $filename = sprintf('%s_%03d_%s.xml', $documentName, $counters[$documentName], $slug);

        $targetDir = "$fixturesDir/$documentName";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        file_put_contents("$targetDir/$filename", $contents);
        $written++;
    }

    $zip->close();
    unlink($zipFile);

    fwrite(STDOUT, "Wrote $written official UBL $version example(s) into $fixturesDir.\n");
}
