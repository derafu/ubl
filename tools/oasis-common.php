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
 * What the tools of this directory share: the UBL versions the package has a model for, where
 * each one's files live, and how they are downloaded. Every tool takes an optional version
 * (`php tools/generate-oasis.php 2.2`) and does all of them without it.
 *
 * Where OASIS publishes what, per version:
 *
 *   - `xsd`: the zip of the OS (OASIS Standard), with the XSD (`xsd/`) and the XML examples
 *     (`xml/`). The 2.5 one has everything under a leading `os-UBL-2.5/` directory.
 *   - `json`: the zip of the "UBL JSON Alternative Representation" note, with the model-based
 *     JSON schemas and examples. OASIS has published it for 2.1 (v2.0), 2.2 and 2.3 (v1.0), and
 *     for no later version (`null`): those have no official JSON schemas to validate against.
 *
 * @return array<string, array{xsd: string, json: ?string}>
 */
function oasisVersions(): array
{
    $xsd = static fn (string $version): string => "https://docs.oasis-open.org/ubl/os-UBL-$version/UBL-$version.zip";
    $json = static fn (string $version, string $note): string => "https://docs.oasis-open.org/ubl/UBL-$version-JSON/$note/cn01/UBL-$version-JSON-$note-cn01.zip";

    return [
        '2.1' => ['xsd' => $xsd('2.1'), 'json' => $json('2.1', 'v2.0')],
        '2.2' => ['xsd' => $xsd('2.2'), 'json' => $json('2.2', 'v1.0')],
        '2.3' => ['xsd' => $xsd('2.3'), 'json' => $json('2.3', 'v1.0')],
        '2.4' => ['xsd' => $xsd('2.4'), 'json' => null],
        '2.5' => ['xsd' => $xsd('2.5'), 'json' => null],
    ];
}

/**
 * The versions a tool has to work on: the one it was given as its first argument, or all.
 *
 * @param list<string> $argv
 * @return array<string, array{xsd: string, json: ?string}>
 */
function oasisSelectVersions(array $argv): array
{
    $all = oasisVersions();
    $requested = $argv[1] ?? 'all';

    if ($requested === 'all') {
        return $all;
    }

    if (!isset($all[$requested])) {
        fwrite(STDERR, "Unknown UBL version \"$requested\". Known ones: " . implode(', ', array_keys($all)) . ", or all.\n");
        exit(1);
    }

    return [$requested => $all[$requested]];
}

/**
 * The PHP namespace of the model of a version: `Derafu\Ubl\V21\Oasis`.
 */
function oasisNamespaceOf(string $version): string
{
    return 'Derafu\\Ubl\\V' . str_replace('.', '', $version) . '\\Oasis';
}

/**
 * The directory of the generated model of a version: `src/V21/Oasis`.
 */
function oasisSourceDirOf(string $version): string
{
    return dirname(__DIR__) . '/src/V' . str_replace('.', '', $version) . '/Oasis';
}

/**
 * Downloads a file; exits with a message if it cannot.
 */
function oasisDownload(string $url, string $target): void
{
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0775, true);
    }

    fwrite(STDOUT, "Fetching $url ...\n");

    $curl = curl_init($url);
    $file = fopen($target, 'wb');
    curl_setopt_array($curl, [
        CURLOPT_FILE => $file,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_FAILONERROR => true,
        CURLOPT_TIMEOUT => 300,
    ]);
    $ok = curl_exec($curl);
    $error = curl_error($curl);
    fclose($file);

    if (!$ok) {
        unlink($target);
        fwrite(STDERR, "Download failed: $error\n");
        exit(1);
    }
}

/**
 * Opens a zip; exits with a message if it cannot.
 */
function oasisOpenZip(string $path): ZipArchive
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        fwrite(STDERR, "Could not open the downloaded archive: $path\n");
        exit(1);
    }

    return $zip;
}

/**
 * The entry of a zip without the leading directory that some of them (2.5) wrap everything in.
 */
function oasisStripRoot(string $entry): string
{
    return (string) preg_replace('#^os-UBL-[\d.]+/#', '', $entry);
}
