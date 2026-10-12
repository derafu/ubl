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
 * Downloads the official OASIS UBL 2.1 example instance documents (not the XSD, see
 * tools/generate-oasis.php for that) into tests/fixtures/doc/xml/<document>/, one subdirectory
 * per Oasis\Doc\* class name. These are test-only fixtures, never shipped under resources/: a
 * consumer of this package has no use for them, only this package's own test suite does.
 *
 * Run with: composer run fetch-oasis-examples.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

const UBL_VERSION = '2.1';

$rootDir = dirname(__DIR__);
$zipUrl = 'https://docs.oasis-open.org/ubl/os-UBL-' . UBL_VERSION . '/UBL-' . UBL_VERSION . '.zip';
$zipFile = "$rootDir/var/UBL-" . UBL_VERSION . '.zip';
$fixturesDir = "$rootDir/tests/fixtures/doc/xml";

if (!is_dir("$rootDir/var")) {
    mkdir("$rootDir/var", 0775, true);
}

fwrite(STDOUT, "Fetching $zipUrl ...\n");

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

// Only UBL 2.1 examples (the archive also carries 2.0 ones, for types that were not
// resubmitted with a 2.1 example), named xml/UBL-<Document>-2.1-Example[-<variant>].xml.
$pattern = '/^xml\/UBL-([A-Za-z]+)-' . preg_quote(UBL_VERSION, '/') . '-Example(?:-(.+))?\.xml$/';
$entries = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if ($name !== false && preg_match($pattern, $name, $matches) === 1) {
        // Sorted so the unsuffixed example of a document (if any) always numbers 001, variants
        // after it in zip order: a plain "...-Example.xml" otherwise sorts after a suffixed
        // "...-Example-Trivial.xml" ('.' > '-' in ASCII), which reads backwards.
        $variantRank = isset($matches[2]) ? '1' . $matches[2] : '0';
        $sortKey = $matches[1] . '|' . $variantRank . '|' . $name;
        $entries[$sortKey] = [$name, $matches];
    }
}
ksort($entries);

if ($entries === []) {
    fwrite(STDERR, 'No UBL ' . UBL_VERSION . " example found in the archive at the expected xml/ path.\n");
    exit(1);
}

$counters = [];
$written = 0;

foreach ($entries as [$name, $matches]) {
    $documentName = $matches[1];
    $variant = $matches[2] ?? null;
    $class = 'Derafu\\Ubl\\Oasis\\Doc\\' . $documentName;

    if (!class_exists($class)) {
        fwrite(STDERR, "No Oasis\\Doc\\$documentName class for example \"$name\"; the model may be out of date.\n");
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

fwrite(STDOUT, "Wrote $written official UBL " . UBL_VERSION . " example(s) into $fixturesDir.\n");
