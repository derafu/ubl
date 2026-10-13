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
 * Writes the minimal YAML fixture of the documents of a UBL version that do not have one:
 * `tests/fixtures/<version>/doc/yaml/<Document>/<Document>_001_simple.yaml`, the document with
 * only what its model marks `required: true`, in the model-based JSON representation of the
 * OASIS note (what `Deserializer` reads), each field commented with UBL's own definition of it
 * (the `ccts:Definition` of the XSD of the version).
 *
 *     php tools/generate-yaml-fixtures.php <version> [Document ...]
 *
 * Without documents, the ones that the version introduced (not in the previous one). It never
 * overwrites a file: the 2.1 fixtures are the hand-reviewed ones, and so is any other once it has
 * been edited. The result is what the tests prove: it has to read, write and validate against
 * the XSD of the version (tests/src/Service/FixtureRoundTripTest.php).
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/oasis-common.php';

use Symfony\Component\Yaml\Inline;
use Xterr\UBL\Xml\Mapping\XmlAttribute;
use Xterr\UBL\Xml\Mapping\XmlElement;

$version = $argv[1] ?? null;
if ($version === null || !isset(oasisVersions()[$version])) {
    fwrite(STDERR, "Usage: php tools/generate-yaml-fixtures.php <version> [Document ...]\n");
    exit(1);
}

$rootDir = dirname(__DIR__);
$namespace = oasisNamespaceOf($version);
$documents = array_slice($argv, 2);

if ($documents === []) {
    $previous = array_keys(oasisVersions());
    $previous = $previous[array_search($version, $previous, true) - 1] ?? null;
    $namesOf = static fn (string $v): array => array_map(
        static fn (string $path): string => basename($path, '.php'),
        glob(oasisSourceDirOf($v) . '/Doc/*.php') ?: []
    );
    $documents = array_values(array_diff($namesOf($version), $previous === null ? [] : $namesOf($previous)));
}

$definitions = new Definitions($rootDir . "/resources/schemas/xsd/$version", $version);
$written = 0;

foreach ($documents as $document) {
    $target = "$rootDir/tests/fixtures/$version/doc/yaml/$document/{$document}_001_simple.yaml";
    if (is_file($target)) {
        continue;
    }

    $class = "$namespace\\Doc\\$document";
    if (!class_exists($class)) {
        fwrite(STDERR, "No $class class.\n");
        exit(1);
    }

    $lines = [];
    emitObject($class, 1, $lines, [], $definitions, "{$document}Type", "maindoc/UBL-$document-$version.xsd");

    $uri = static fn (string $part): string => "urn:oasis:names:specification:ubl:schema:xsd:$part-2";
    $definition = $definitions->ofDocument($document);
    $head = [
        "# $document",
        '#',
        "# UBL $version's own definition (UBL-$document-$version.xsd): \"$definition\"",
        '#',
        "# Only what `$document` marks `required: true` (see src/V" . str_replace('.', '', $version) . "/Oasis/Doc/$document.php)",
        '# is here: the smallest document of the model, written by tools/generate-yaml-fixtures.php.',
        '#',
        '# The structure is the model-based JSON representation of OASIS\'s "UBL JSON Alternative',
        '# Representation" (Committee Note), carried as YAML: the keys are the UBL element names, a value',
        '# (a BBIE) is always an object with its content under `_` and its attributes next to it, and only',
        '# what UBL allows more than once is a list. `_D`, `_A` and `_B` are the namespaces of the apex.',
        '#',
        '# Each field\'s comment is UBL\'s own definition of it (the `ccts:Definition` of the XSD).',
        '_D: ' . $uri($document),
        '_A: ' . $uri('CommonAggregateComponents'),
        '_B: ' . $uri('CommonBasicComponents'),
        "$document:",
    ];

    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0775, true);
    }
    file_put_contents($target, implode("\n", array_merge($head, $lines)) . "\n");
    $written++;
}

fwrite(STDOUT, "UBL $version: wrote $written YAML fixture(s).\n");

/**
 * The `ccts:Definition` of the elements of the XSDs of a version.
 */
final class Definitions
{
    /**
     * @var array<string, DOMXPath>
     */
    private array $xpaths = [];

    public function __construct(private readonly string $directory, private readonly string $version)
    {
    }

    public function ofDocument(string $document): string
    {
        $xpath = $this->xpath("maindoc/UBL-$document-{$this->version}.xsd");

        return $this->text($xpath->query("//xsd:complexType[@name='{$document}Type']/xsd:annotation/xsd:documentation//ccts:Definition")) ?: $document;
    }

    /**
     * The definition of an element as it is used by a type (`PartyType` uses `cac:PartyName`).
     */
    public function ofElement(string $type, string $element, string $xsd): string
    {
        $xpath = $this->xpath($xsd);
        $query = "//xsd:complexType[@name='$type']//xsd:element[@ref='cac:$element' or @ref='cbc:$element' or @name='$element']/xsd:annotation/xsd:documentation//ccts:Definition";

        return $this->text($xpath->query($query));
    }

    private function xpath(string $file): DOMXPath
    {
        if (!isset($this->xpaths[$file])) {
            $dom = new DOMDocument();
            $dom->load("{$this->directory}/$file");
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('xsd', 'http://www.w3.org/2001/XMLSchema');
            $xpath->registerNamespace('ccts', 'urn:un:unece:uncefact:documentation:2');
            $this->xpaths[$file] = $xpath;
        }

        return $this->xpaths[$file];
    }

    private function text(DOMNodeList|false $nodes): string
    {
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        return trim((string) preg_replace('/\s+/', ' ', (string) $nodes->item(0)?->textContent));
    }
}

/**
 * Writes the required properties of a class as YAML lines, recursively.
 *
 * @param list<string> $lines
 * @param list<class-string> $stack The classes being written, to stop a cycle.
 */
function emitObject(string $class, int $level, array &$lines, array $stack, Definitions $definitions, string $typeName, string $xsd): void
{
    if (in_array($class, $stack, true)) {
        return;
    }
    $stack[] = $class;

    $reflection = new ReflectionClass($class);
    $metadata = $reflection->getReflectionConstant('ARRAY_METADATA')?->getValue();
    $pad = str_repeat('  ', $level);

    foreach ($metadata['properties'] as $property) {
        $attribute = $reflection->getProperty($property['property'])->getAttributes()[0] ?? null;
        $mapping = $attribute?->newInstance();
        if (!($mapping instanceof XmlElement || $mapping instanceof XmlAttribute) || !$mapping->required) {
            continue;
        }

        $name = $property['name'];
        if ($mapping instanceof XmlAttribute) {
            $lines[] = $pad . $name . ': ' . Inline::dump(sampleAttribute($name));
            continue;
        }

        $comment = $definitions->ofElement($typeName, $name, $xsd);
        $trailing = $comment === '' ? '' : " # $comment";
        $item = $property['item'];
        $inner = [];

        if (str_contains((string) $item, '\\Cbc\\') || in_array($item, ['DateTimeImmutable', 'bool', 'string'], true)) {
            $inner[] = '_: ' . Inline::dump(sampleValue($name, (string) $item, $property['format'] ?? null));
            foreach (requiredAttributes((string) $item) as $attributeName) {
                $inner[] = $attributeName . ': ' . Inline::dump(sampleAttribute($attributeName));
            }
        } else {
            $innerLines = [];
            emitObject($item, 0, $innerLines, $stack, $definitions, shortName($item) . 'Type', cacXsd($definitions));
            $inner = $innerLines;
        }

        if ($property['list']) {
            $lines[] = "$pad$name:$trailing";
            if ($inner === []) {
                $lines[] = "$pad  - {}";
                continue;
            }
            foreach ($inner as $i => $line) {
                $lines[] = $pad . ($i === 0 ? '  - ' : '    ') . $line;
            }
        } elseif ($inner === []) {
            $lines[] = "$pad$name: {}$trailing";
        } else {
            $lines[] = "$pad$name:$trailing";
            foreach ($inner as $line) {
                $lines[] = "$pad  $line";
            }
        }
    }
}

function cacXsd(Definitions $definitions): string
{
    global $version;

    return "common/UBL-CommonAggregateComponents-$version.xsd";
}

function shortName(string $class): string
{
    $parts = explode('\\', $class);

    return (string) end($parts);
}

/**
 * The attributes that a basic component requires (an amount's `currencyID`).
 *
 * @return list<string>
 */
function requiredAttributes(string $class): array
{
    if (!class_exists($class)) {
        return [];
    }

    $names = [];
    foreach ((new ReflectionClass($class))->getProperties() as $property) {
        $mapping = ($property->getAttributes(XmlAttribute::class)[0] ?? null)?->newInstance();
        if ($mapping instanceof XmlAttribute && $mapping->required) {
            $names[] = $mapping->name;
        }
    }

    return $names;
}

function sampleAttribute(string $name): string
{
    return match ($name) {
        'currencyID' => 'EUR',
        'unitCode' => 'C62',
        'mimeCode' => 'text/plain',
        'languageID' => 'en',
        default => '1',
    };
}

function sampleValue(string $element, string $type, ?string $format): string|int|bool
{
    if ($type === 'bool') {
        return true;
    }

    if ($type === 'DateTimeImmutable') {
        return $format !== null || str_ends_with($element, 'Time') ? '12:00:00+00:00' : '2026-01-15';
    }

    $short = shortName($type);

    return match ($short) {
        'Text' => 'Text',
        'BinaryObject' => 'AA==',
        // The content of these is a JSON number.
        'Amount', 'Quantity', 'Measure', 'Numeric' => 1,
        default => '1',
    };
}
