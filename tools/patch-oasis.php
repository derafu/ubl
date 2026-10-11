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
 * Corrects known defects of xterr/php-ubl-generator's output in the committed src/Oasis.
 *
 * Run with: composer run generate-oasis (right after tools/generate-oasis.php, before
 * composer phpcs-fix).
 */

$oasisDir = dirname(__DIR__) . '/src/Oasis';
$files = new RegexIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($oasisDir)),
    '/\.php$/'
);

$totalChanged = 0;
$totalChanged += fixValidatorDocblocks($files);
$totalChanged += fixTimeFormat(dirname(__DIR__) . '/src/Oasis');
$totalChanged += fixDocumentRegistry(dirname(__DIR__) . '/src/Oasis/Xml/DocumentRegistry.php');
$totalChanged += addCopyrightHeader(dirname(__DIR__) . '/src/Oasis');

fwrite(STDOUT, "patch-oasis: $totalChanged file(s) changed.\n");

/**
 * Fixes the `@param list<SpecificType> $values` docblock the generator writes on each
 * private `validateXxx(array $values): void` helper: the type is not yet proven at that
 * point, so the correct docblock is `list<mixed>`.
 *
 * @param iterable<SplFileInfo> $files
 */
function fixValidatorDocblocks(iterable $files): int
{
    $wrong = '/@param list<(?!mixed>)[^>]+> \$values\n(\s*\*\/\n\s*private static function validate)/';
    $changed = 0;

    foreach ($files as $file) {
        $path = $file->getPathname();
        $source = file_get_contents($path);
        $patched = preg_replace($wrong, "@param list<mixed> \$values\n$1", $source);
        if ($patched !== $source) {
            file_put_contents($path, $patched);
            $changed++;
        }
    }

    assertNoneMatch(oasisDir(), $wrong, 'validateXxx() still has a non-mixed list<> docblock');

    return $changed;
}

/**
 * Adds the missing `format:` to every `#[XmlElement]` for an XSD element whose name ends in
 * `Time`: the generator maps it to `DateTimeImmutable` with no format, so the runtime
 * serializes it as a date (`Y-m-d`) instead of a time.
 */
function fixTimeFormat(string $dir): int
{
    $missingFormat = "/#\[XmlElement\(name: '(\w*Time)', namespace: Ns::CBC((?:, required: true)?)\)\]/";
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)),
        '/\.php$/'
    );
    $changed = 0;

    foreach ($files as $file) {
        $path = $file->getPathname();
        $source = file_get_contents($path);
        $patched = preg_replace(
            $missingFormat,
            "#[XmlElement(name: '$1', namespace: Ns::CBC$2, format: 'H:i:sP')]",
            $source
        );
        if ($patched !== $source) {
            file_put_contents($path, $patched);
            $changed++;
        }
    }

    assertNoneMatch($dir, $missingFormat, "a '*Time' XmlElement is still missing format:");

    return $changed;
}

/**
 * Guards the `preg_match()` in `DocumentRegistry::rootElementFor()` before it reads its
 * capture groups.
 */
function fixDocumentRegistry(string $path): int
{
    if (!is_file($path)) {
        fwrite(STDERR, "patch-oasis: expected file not found: $path\n");
        exit(1);
    }

    $unchecked = "preg_match('/^\\{(.+?)\\}(.+)\$/', \$key, \$m);\n                return ['namespace' => \$m[1], 'localName' => \$m[2]];";
    $checked = "if (preg_match('/^\\{(.+?)\\}(.+)\$/', \$key, \$m) === 1) {\n                    return ['namespace' => \$m[1], 'localName' => \$m[2]];\n                }";

    $source = file_get_contents($path);
    $patched = str_replace($unchecked, $checked, $source);

    if ($patched === $source && !str_contains($source, $checked)) {
        fwrite(STDERR, "patch-oasis: the unchecked preg_match() pattern was not found in $path (generator output changed?)\n");
        exit(1);
    }

    if ($patched !== $source) {
        file_put_contents($path, $patched);

        return 1;
    }

    return 0;
}

/**
 * Adds the package's copyright header to every generated file, the same as any other file
 * of the package.
 */
function addCopyrightHeader(string $dir): int
{
    $year = date('Y');
    $header = <<<PHP
        <?php

        declare(strict_types=1);

        /**
         * Derafu: UBL - One Language for Every Business Document.
         *
         * Copyright (c) $year Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
         * Licensed under the MIT License.
         * See LICENSE file for more details.
         */

        namespace
        PHP;
    $missing = '/^<\?php\n\ndeclare\(strict_types=1\);\n\nnamespace/';
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)),
        '/\.php$/'
    );
    $changed = 0;

    foreach ($files as $file) {
        $path = $file->getPathname();
        $source = file_get_contents($path);
        $patched = preg_replace($missing, $header, $source, 1);
        if ($patched !== $source) {
            file_put_contents($path, $patched);
            $changed++;
        }
    }

    assertNoneMatch($dir, $missing, 'is still missing the package copyright header');

    return $changed;
}

function assertNoneMatch(string $dir, string $pattern, string $message): void
{
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)),
        '/\.php$/'
    );

    foreach ($files as $file) {
        if (preg_match($pattern, file_get_contents($file->getPathname())) === 1) {
            fwrite(STDERR, "patch-oasis: postcondition failed in {$file->getPathname()}: $message\n");
            exit(1);
        }
    }
}

function oasisDir(): string
{
    return dirname(__DIR__) . '/src/Oasis';
}
