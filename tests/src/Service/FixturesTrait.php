<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsUbl\Service;

use Derafu\Ubl\Service\VersionRegistry;

/**
 * The fixtures of the tests, found by glob and never listed by hand: for each UBL version, the
 * files under tests/fixtures/<version>/doc/<format>/<Document>/. Adding a fixture, or the
 * fixtures of a new version (see tools/fetch-oasis-examples.php), picks it up everywhere.
 *
 * A case is `[version, path]`: the version is what the fixture is of, and what the test has to
 * ask the deserializer for (every 2.x version has the same XML namespaces, so a file does not
 * say it).
 */
trait FixturesTrait
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private static function fixtures(string $format): array
    {
        $cases = [];
        foreach (VersionRegistry::SUPPORTED as $version) {
            $dir = dirname(__DIR__, 2) . "/fixtures/$version/doc/$format";
            foreach (glob("$dir/*/*.$format") ?: [] as $file) {
                $cases["$version/" . basename(dirname($file)) . '/' . basename($file)] = [$version, $file];
            }
        }

        return $cases;
    }

    /**
     * The same fixtures, keeping those of the versions that OASIS has published official JSON
     * schemas for.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function fixturesWithJsonSchemas(string $format): array
    {
        return array_filter(
            self::fixtures($format),
            static fn (array $case): bool => is_dir(dirname(__DIR__, 2) . "/../resources/schemas/json/{$case[0]}")
        );
    }

    /**
     * Where the official JSON example of an official XML one is, if there is one.
     */
    private static function jsonOf(string $xmlPath): string
    {
        return str_replace(['/xml/', '.xml'], ['/json/', '.json'], $xmlPath);
    }
}
