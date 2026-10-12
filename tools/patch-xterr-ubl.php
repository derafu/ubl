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
 * Patches known defects of xterr/php-ubl directly in vendor/, the same way tools/patch-oasis.php
 * patches this package's own generated code: each patch checks its exact expected source first
 * and fails loudly if it is not there, instead of silently doing nothing.
 *
 * Wired as this package's own composer post-install-cmd/post-update-cmd, so it reapplies on
 * every install here. Composer never runs a dependency's own scripts for the project that
 * requires it, so a project that depends on derafu/ubl (not derafu/ubl itself) must add the
 * same command to its own composer.json to get these patches too.
 *
 * Finds the installed package through Composer\InstalledVersions, not a path relative to this
 * file: this script's own location differs between the two cases above (tools/ at the project
 * root, or vendor/derafu/ubl/tools/ inside another project), but the working directory composer
 * invokes it from is always that project's root either way.
 */

require getcwd() . '/vendor/autoload.php';

use Composer\InstalledVersions;

const PATCHES = [
    'parseDateTimeFallsBackToTheLenientParserWhenTheStrictFormatFails',
    'parseDateTimeCanonicalizesItsResult',
];

$installPath = InstalledVersions::getInstallPath('xterr/php-ubl');

if ($installPath === null) {
    fwrite(STDERR, "xterr/php-ubl is not installed; nothing to patch.\n");
    exit(1);
}

$changed = 0;

foreach (PATCHES as $patch) {
    $changed += $patch($installPath) ? 1 : 0;
}

fwrite(STDOUT, "patch-xterr-ubl: $changed patch(es) applied.\n");

/**
 * `XmlDeserializer::parseDateTime()` only falls back to PHP's own lenient date parser when a
 * property has no `format:` at all; one that does (every `*Date`/`*Time` element in this
 * package has one, so `ArraySerializableTrait::exportScalar()` and `XmlSerializer`'s own
 * default never have to guess a format for them) must match that format exactly through
 * `createFromFormat()`, which cannot express the fractional second or the timezone that
 * `xsd:date`/`xsd:time` both allow as optional. Falls back to the lenient parser there too,
 * the same fix as `Derafu\Ubl\Trait\ArraySerializableTrait::importDate()`.
 */
function parseDateTimeFallsBackToTheLenientParserWhenTheStrictFormatFails(string $installPath): bool
{
    $file = "$installPath/src/Xml/XmlDeserializer.php";
    $source = file_get_contents($file);

    if ($source === false) {
        fwrite(STDERR, "Could not read $file.\n");
        exit(1);
    }

    $patched = '        $dt = $format !== null
            ? \DateTimeImmutable::createFromFormat(\'!\' . $format, $text, new \DateTimeZone(\'UTC\'))
            : false;

        if ($dt === false) {
            try {
                $dt = new \DateTimeImmutable($text, new \DateTimeZone(\'UTC\'));
            } catch (\Exception) {
                $dt = false;
            }
        }';

    if (str_contains($source, $patched)) {
        return false;
    }

    $original = '        if ($format !== null) {
            $dt = \DateTimeImmutable::createFromFormat(\'!\' . $format, $text, new \DateTimeZone(\'UTC\'));
        } else {
            try {
                $dt = new \DateTimeImmutable($text);
            } catch (\Exception) {
                $dt = false;
            }
        }';

    if (!str_contains($source, $original)) {
        fwrite(
            STDERR,
            "xterr/php-ubl's XmlDeserializer::parseDateTime() no longer matches the expected "
                . "source (the installed version may have changed); patch skipped, needs review.\n"
        );
        exit(1);
    }

    file_put_contents($file, str_replace($original, $patched, $source));

    $result = file_get_contents($file);
    if ($result === false || !str_contains($result, $patched)) {
        fwrite(STDERR, "Patched $file but the expected result is not there afterwards.\n");
        exit(1);
    }

    return true;
}

/**
 * Whichever of the two branches above produced `$dt`, its internal shape (what date a
 * time-only value is filled in with, whether its timezone is the named "UTC" or the offset
 * "+00:00") depends on which one it was, even when the exported string
 * (`$dt->format($format)`, what `ArraySerializableTrait::exportScalar()` and
 * `XmlSerializer` actually emit) is identical either way. Re-deriving `$dt` from that same
 * exported string, through the strict parser, collapses both branches onto the one shape the
 * strict branch alone already produces - without changing the exported string itself.
 */
function parseDateTimeCanonicalizesItsResult(string $installPath): bool
{
    $file = "$installPath/src/Xml/XmlDeserializer.php";
    $source = file_get_contents($file);

    if ($source === false) {
        fwrite(STDERR, "Could not read $file.\n");
        exit(1);
    }

    $patched = '        if ($dt === false) {
            throw new DeserializationException(\sprintf(\'Cannot parse date "%s" with format "%s"\', $text, $format ?? \'auto\'));
        }

        if ($format !== null) {
            $canonical = \DateTimeImmutable::createFromFormat(\'!\' . $format, $dt->format($format), new \DateTimeZone(\'UTC\'));
            if ($canonical !== false) {
                $dt = $canonical;
            }
        }

        return $dt;';

    if (str_contains($source, $patched)) {
        return false;
    }

    $original = '        if ($dt === false) {
            throw new DeserializationException(\sprintf(\'Cannot parse date "%s" with format "%s"\', $text, $format ?? \'auto\'));
        }

        return $dt;';

    if (!str_contains($source, $original)) {
        fwrite(
            STDERR,
            "xterr/php-ubl's XmlDeserializer::parseDateTime() no longer matches the expected "
                . "source (the installed version may have changed); patch skipped, needs review.\n"
        );
        exit(1);
    }

    file_put_contents($file, str_replace($original, $patched, $source));

    $result = file_get_contents($file);
    if ($result === false || !str_contains($result, $patched)) {
        fwrite(STDERR, "Patched $file but the expected result is not there afterwards.\n");
        exit(1);
    }

    return true;
}
