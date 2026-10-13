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

require dirname(__DIR__) . '/vendor/autoload.php';

use Xterr\UBL\Xml\Mapping\XmlAny;
use Xterr\UBL\Xml\Mapping\XmlAttribute;
use Xterr\UBL\Xml\Mapping\XmlElement;
use Xterr\UBL\Xml\Mapping\XmlValue;

$oasisDir = dirname(__DIR__) . '/src/Oasis';
$files = new RegexIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($oasisDir)),
    '/\.php$/'
);

$totalChanged = 0;
// First, and before anything that reads a path or reflects a class: every later step, including
// the ARRAY_METADATA one, must see each class where it finally lives.
$totalChanged += relocateForeignNamespaceClasses($oasisDir);
$totalChanged += removeW3cMetaschemaEnums($oasisDir);
$totalChanged += fixValidatorDocblocks($files);
$totalChanged += fixTimeFormat(dirname(__DIR__) . '/src/Oasis');
$totalChanged += fixDocumentRegistry(dirname(__DIR__) . '/src/Oasis/Xml/DocumentRegistry.php');
$totalChanged += fixSignatureInformationSignatureType(dirname(__DIR__) . '/src/Oasis/Sac/SignatureInformation.php');
$totalChanged += fixAnyWildcards($oasisDir);
$totalChanged += addCopyrightHeader(dirname(__DIR__) . '/src/Oasis');
$totalChanged += addArraySerializableTrait(dirname(__DIR__) . '/src/Oasis');
$totalChanged += addArrayMetadataConstant(dirname(__DIR__) . '/src/Oasis');

fwrite(STDOUT, "patch-oasis: $totalChanged file(s) changed.\n");

/**
 * The generator puts every class that is not `Cbc` into `Cac`, including the few whose own
 * XML namespace is not `CommonAggregateComponents` at all: the extension slot (`ext:`), and the
 * signature envelope (`sig:`, `sac:`). Their PHP namespace then claims a UBL component family
 * they do not belong to (`Cac\UBLExtensions` is a `CommonExtensionComponents` type). This moves
 * each one to the directory named after its own XML namespace prefix, the same naming `Cac` and
 * `Cbc` already follow: `Ext`, `Sac`, `Sig`.
 *
 * Runs before every other step because they all work from a class's path (and the metadata step
 * reflects it): a class moved later would be read from a place it no longer is. Safe to run on
 * an already corrected tree, and over a freshly generated one (the generator re-emits these
 * classes into `Cac` on every run, so an existing target is overwritten).
 *
 * References from other classes are plain `use` statements (the metadata, with its fully
 * qualified names, is computed after this step, from the moved classes). A class that used one
 * of the moved ones without a `use`, because they shared a namespace, gets one added.
 *
 * @return int Number of files changed.
 */
function relocateForeignNamespaceClasses(string $oasisDir): int
{
    $moves = [
        'UBLExtensions' => 'Ext',
        'UBLExtension' => 'Ext',
        'ExtensionContent' => 'Ext',
        'SignatureInformation' => 'Sac',
        'UBLDocumentSignatures' => 'Sig',
    ];
    $base = 'Derafu\\Ubl\\Oasis\\';
    $changed = 0;

    foreach ($moves as $class => $target) {
        $from = "$oasisDir/Cac/$class.php";
        $to = "$oasisDir/$target/$class.php";

        if (!is_file($from)) {
            if (!is_file($to)) {
                fwrite(STDERR, "patch-oasis: neither $from nor $to exists (generator output changed?)\n");
                exit(1);
            }

            continue;
        }

        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0775, true);
        }

        $source = (string) file_get_contents($from);
        $patched = str_replace("namespace {$base}Cac;", "namespace {$base}{$target};", $source);
        file_put_contents($to, $patched);
        unlink($from);
        $changed++;
    }

    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($oasisDir)),
        '/\.php$/'
    );

    foreach ($files as $file) {
        $path = $file->getPathname();
        $source = (string) file_get_contents($path);
        $patched = $source;

        // Imports of a moved class, wherever they are.
        foreach ($moves as $class => $target) {
            $patched = str_replace(
                "use {$base}Cac\\{$class};",
                "use {$base}{$target}\\{$class};",
                $patched
            );
        }

        // A moved class that used a class it no longer shares a namespace with, or a class left
        // in `Cac` that used a moved one, without importing it (same namespace before).
        $ownNamespace = preg_match('/^namespace ([^;]+);/m', $patched, $m) === 1 ? $m[1] : null;
        foreach ($moves as $class => $target) {
            $targetNamespace = $base . $target;
            $import = "use {$targetNamespace}\\{$class};";
            if ($ownNamespace === $targetNamespace || str_contains($patched, $import . "\n")) {
                continue;
            }
            if (preg_match('/(?<![\w\\\\])' . $class . '(?:::class|\s+\$)/', $patched) !== 1) {
                continue;
            }

            $patched = preg_replace_callback(
                '/^(namespace [^;]+;\n\n)/m',
                static fn (array $match): string => $match[1] . $import . "\n",
                $patched,
                1
            );
        }

        if ($patched !== $source) {
            file_put_contents($path, $patched);
            $changed++;
        }
    }

    foreach (array_keys($moves) as $class) {
        assertNoneMatch(
            $oasisDir,
            '/' . preg_quote("{$base}Cac\\{$class}", '/') . '(?!\w)/',
            "still references {$base}Cac\\{$class}, which was moved to the directory of its own XML namespace"
        );
    }

    return $changed;
}

/**
 * The generator does not only emit enums for UBL's own types (always PascalCase): it also
 * leaks a handful of W3C XML Schema meta-schema utility types - the vocabulary XSD uses to
 * describe itself (`block`/`final`/`elementFormDefault` and the like), not UBL business data.
 * Those are always lowerCamelCase in the real W3C spec (`derivationControl`, `formChoice`, ...)
 * and each carries the literal annotation "A utility type, not for public use" copied from
 * that spec. Confirmed unused anywhere in `Cac`/`Cbc`/`Doc`/`Ext`/`Sac`/`Sig`: nothing in this package's own
 * model ever had a reason to reference the vocabulary XSD uses to describe itself.
 *
 * Casing alone is only ever a proxy for "came from the W3C XML Schema namespace, not a UBL
 * one" - true today for every lowercase file this generator has ever produced, but not a law
 * of nature. Before trusting it, isReferencedOutsideEnum() checks the one thing that actually
 * matters: that nothing else in the generated model points at this class. If a future
 * regeneration ever produces a lowercase file something else DOES reference, this refuses to
 * delete it and fails loud instead of silently creating a dangling reference.
 *
 * @return int Number of files removed.
 */
function removeW3cMetaschemaEnums(string $oasisDir): int
{
    $enumDir = "$oasisDir/Enum";
    $removed = 0;

    foreach (glob("$enumDir/*.php") ?: [] as $file) {
        $className = basename($file, '.php');
        if ($className === '' || ctype_upper($className[0])) {
            continue;
        }

        if (isReferencedOutsideEnum($oasisDir, $className)) {
            fwrite(STDERR, "patch-oasis: $file is lowercase, which so far has only ever meant a W3C meta-schema leak, but it IS referenced elsewhere in src/Oasis - refusing to delete it (a genuinely-used lowercase UBL type?), needs a human to look.\n");
            exit(1);
        }

        unlink($file);
        $removed++;
    }

    assertNoneMatch($enumDir, '/^enum [a-z]/m', 'is a W3C meta-schema utility type, not a UBL one');

    return $removed;
}

/**
 * Plain text search, deliberately not reflection/AST-based: good enough to prove a class name
 * appears nowhere outside Enum/ itself, which is all removeW3cMetaschemaEnums() needs before
 * trusting casing alone is safe to delete a file by.
 */
function isReferencedOutsideEnum(string $oasisDir, string $className): bool
{
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($oasisDir)),
        '/\.php$/'
    );

    foreach ($files as $file) {
        $path = $file->getPathname();
        if (str_contains($path, '/Enum/')) {
            continue;
        }

        if (str_contains((string) file_get_contents($path), $className)) {
            return true;
        }
    }

    return false;
}

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
 * `cac:SignatureInformationType`'s `ds:Signature` element is a real W3C XML-DSig signature
 * envelope (`SignedInfo`/`SignatureValue`/`KeyInfo`/`Object` - a completely different schema,
 * bundled at `resources/xsd/2.1/common/UBL-xmldsig-core-schema-2.1.xsd`, not part of UBL
 * itself), never a UBL `Cac\Signature` (plain metadata: ID/ValidationDate/SignatoryParty/...,
 * what `cac:Signature` actually is). `UblTypeRegistry::COMPONENT_NAMESPACES` never processes
 * that schema's namespace, so its `TypeResolver` resolves the `ds:Signature` reference by its
 * bare, namespace-stripped name alone ("Signature") and lands on this package's own, unrelated
 * `Cac\Signature` class - the two happen to share a name, nothing else. Typed that way, a real
 * signed document would silently hydrate into the wrong shape. This does not model the real
 * `ds:SignatureType` (see CLAUDE.md's note on digital signatures for why, and for what a
 * future signing package would need to do instead) - it only stops the false claim, widening
 * the property to a plain, honestly-untyped string.
 */
function fixSignatureInformationSignatureType(string $path): int
{
    if (!is_file($path)) {
        fwrite(STDERR, "patch-oasis: expected file not found: $path\n");
        exit(1);
    }

    $source = (string) file_get_contents($path);
    $patched = str_replace(
        [
            'private ?Signature $signature = null;',
            'getSignature(): ?Signature',
            'setSignature(?Signature $signature = null): self',
        ],
        [
            'private ?string $signature = null;',
            'getSignature(): ?string',
            'setSignature(?string $signature = null): self',
        ],
        $source
    );

    if ($patched === $source && !str_contains($source, 'private ?string $signature = null;')) {
        fwrite(STDERR, "patch-oasis: the wrong ?Signature typing was not found in $path (generator output changed?)\n");
        exit(1);
    }

    if ($patched !== $source) {
        file_put_contents($path, $patched);

        return 1;
    }

    return 0;
}

/**
 * UBL's generic extension slot (`ext:ExtensionContentType`, reached from every document via
 * `UBLExtensions > UBLExtension > ExtensionContent`) is a real XSD wildcard: `<xsd:any
 * namespace="##other" processContents="lax"/>`, meant to carry arbitrary foreign content (a
 * signature, a Peppol extension, anything not defined by UBL itself). The generator never
 * models it that way: `goetas-webservices/xsd-reader`'s own `Any` class (what its XSD reader
 * represents `<xsd:any>` as) hardcodes `getName(): string { return 'any'; }` - a real wildcard
 * has no name, but that library's `ElementItem` interface requires one, so it picks a literal
 * placeholder. `xterr/php-ubl-generator` has no code path for this at all (confirmed: no
 * mention of "Any"/wildcards anywhere in its own source), so it falls through the same handling
 * as any named element and takes that literal "any" as if it were one, emitting a plain
 * `#[XmlElement(name: 'any', namespace: '')]` property - a single scalar, in the empty
 * namespace. The runtime package this wraps, `xterr/php-ubl`, has a real, working wildcard
 * mechanism of its own, `#[XmlAny]` (confirmed by reading `XmlSerializer`/`XmlDeserializer`: it
 * reads/writes a list of raw, real XML fragments, not an escaped string) - the generator just
 * never learned about it.
 *
 * With the generator's own mapping this is not merely imprecise, it does not work at all:
 * proved empirically that even a plain string set into it fails real schema validation (libxml:
 * "Element 'any': this element is not expected" - `##other` never matches the empty namespace
 * `#[XmlElement(..., namespace: '')]` hardcodes), and a real foreign element is silently dropped
 * on read. This widens the property to `#[XmlAny] private array $any = []` (a list of raw XML
 * fragment strings, one per foreign child) wherever the generator's wrong pattern appears -
 * proved against the real XSD: content set this way validates and round-trips correctly, with
 * real markup, not escaped text.
 *
 * `ArraySerializableTrait`'s own `toArray()`/`fromArray()` (the array/json/yaml path) does not
 * read `#[XmlAny]`, only `#[XmlElement]`/`#[XmlAttribute]`/`#[XmlValue]` - this only fixes the
 * xml path; an `any` property silently absent from array/json/yaml output is a separate, known
 * gap this does not claim to solve.
 *
 * @return int Number of files changed.
 */
function fixAnyWildcards(string $oasisDir): int
{
    $wrongProperty = "#[XmlElement(name: 'any', namespace: '')]\n    private ?string \$any = null;";
    $fixedProperty = "/** @var list<string> */\n    #[XmlAny]\n    private array \$any = [];";
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($oasisDir)),
        '/\.php$/'
    );
    $changed = 0;

    foreach ($files as $file) {
        $path = $file->getPathname();
        $source = (string) file_get_contents($path);

        if (!str_contains($source, $wrongProperty)) {
            continue;
        }

        $patched = str_replace(
            [
                $wrongProperty,
                '    public function getAny(): ?string',
                '    public function setAny(?string $any = null): self',
            ],
            [
                $fixedProperty,
                "    /**\n     * @return list<string>\n     */\n    public function getAny(): array",
                "    /**\n     * @param list<string> \$any\n     */\n    public function setAny(array \$any = []): self",
            ],
            $source
        );

        // XmlElement's import might have been there only for the property just replaced: drop
        // it and import XmlAny instead when so, otherwise keep it (still needed by another
        // property of the same file) and add XmlAny alongside it.
        if (!str_contains($patched, 'XmlElement(')) {
            $patched = str_replace(
                "use Xterr\\UBL\\Xml\\Mapping\\XmlElement;\n",
                "use Xterr\\UBL\\Xml\\Mapping\\XmlAny;\n",
                $patched
            );
        } elseif (!str_contains($patched, 'XmlAny;')) {
            $patched = str_replace(
                "use Xterr\\UBL\\Xml\\Mapping\\XmlElement;\n",
                "use Xterr\\UBL\\Xml\\Mapping\\XmlAny;\nuse Xterr\\UBL\\Xml\\Mapping\\XmlElement;\n",
                $patched
            );
        }

        file_put_contents($path, $patched);
        $changed++;
    }

    assertNoneMatch(
        $oasisDir,
        '/private \?string \$any = null;/',
        "still has the generator's broken any-wildcard scalar instead of #[XmlAny]"
    );

    return $changed;
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

/**
 * Adds `Derafu\Ubl\Trait\ArraySerializableTrait` (`toArray()`, `fromArray()`,
 * `jsonSerialize()`, keyed by the UBL element/attribute names) to every generated class -
 * everything under `Cbc`, `Cac`, `Doc`, `Ext`, `Sac` and `Sig`. Not `Xml/DocumentRegistry` (a lookup table, not a
 * UBL value) and not `Enum` (a plain `BackedEnum` already serializes as its own scalar
 * value).
 */
function addArraySerializableTrait(string $dir): int
{
    $missingImports = '/^(namespace [^;]+;\n\n)/m';
    $missingTrait = '/^final class (\w+)\n\{\n/m';
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)),
        '/\.php$/'
    );
    $changed = 0;

    foreach ($files as $file) {
        $path = $file->getPathname();
        if (str_contains($path, '/Xml/') || str_contains($path, '/Enum/')) {
            continue;
        }

        $source = file_get_contents($path);
        if (preg_match($missingTrait, $source) !== 1) {
            continue;
        }

        $patched = preg_replace(
            $missingImports,
            "$1use Derafu\\Ubl\\Trait\\ArraySerializableTrait;\nuse JsonSerializable;\n",
            $source,
            1
        );
        $patched = preg_replace(
            $missingTrait,
            "final class $1 implements JsonSerializable\n{\n    use ArraySerializableTrait;\n\n",
            $patched,
            1
        );

        file_put_contents($path, $patched);
        $changed++;
    }

    assertNoneMatch($dir, $missingTrait, 'is still missing ArraySerializableTrait', ['/Xml/', '/Enum/']);

    return $changed;
}

/**
 * Computes `ArraySerializableTrait`'s metadata once, here, instead of every time a class
 * calls `toArray()`/`fromArray()`: reads each class's `#[XmlElement]`/`#[XmlAttribute]`/
 * `#[XmlValue]` attributes through reflection (safe to do at generation time; this script
 * is never run in production) and writes the result as a plain, literal `ARRAY_METADATA`
 * constant. The trait itself never reflects anything.
 */
function addArrayMetadataConstant(string $dir): int
{
    $missingConstant = '/use ArraySerializableTrait;\n\n(?!    private const ARRAY_METADATA)/';
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)),
        '/\.php$/'
    );
    $changed = 0;

    foreach ($files as $file) {
        $path = $file->getPathname();
        if (str_contains($path, '/Xml/') || str_contains($path, '/Enum/')) {
            continue;
        }

        $source = file_get_contents($path);
        if (preg_match($missingConstant, $source) !== 1) {
            continue;
        }

        $class = classFor($dir, $path);
        $metadata = classArrayMetadata($class);
        $constant = '    private const ARRAY_METADATA = ' . exportMetadata($metadata) . ";\n\n";
        $patched = preg_replace($missingConstant, "use ArraySerializableTrait;\n\n$constant", $source, 1);

        file_put_contents($path, $patched);
        $changed++;
    }

    assertNoneMatch($dir, $missingConstant, 'is still missing ARRAY_METADATA', ['/Xml/', '/Enum/']);

    return $changed;
}

function classFor(string $oasisDir, string $path): string
{
    $relative = substr($path, strlen($oasisDir) + 1, -4);

    return 'Derafu\\Ubl\\Oasis\\' . str_replace('/', '\\', $relative);
}

/**
 * @param class-string $class
 * @return array{isValueType: bool, properties: list<array{property: string, name: ?string, kind: string, list: bool, item: ?string, format: ?string}>}
 */
function classArrayMetadata(string $class): array
{
    $isValueType = false;
    $properties = [];

    foreach ((new ReflectionClass($class))->getProperties() as $property) {
        $valueAttribute = $property->getAttributes(XmlValue::class);
        $elementAttribute = $property->getAttributes(XmlElement::class);
        $attributeAttribute = $property->getAttributes(XmlAttribute::class);
        $anyAttribute = $property->getAttributes(XmlAny::class);
        $name = $property->getName();

        if ($valueAttribute !== []) {
            $isValueType = true;
            $meta = $valueAttribute[0]->newInstance();
            $properties[] = ['property' => $name, 'name' => null, 'kind' => 'value', 'list' => false, 'item' => null, 'format' => $meta->format];

            continue;
        }

        if ($attributeAttribute !== []) {
            $meta = $attributeAttribute[0]->newInstance();
            $properties[] = ['property' => $name, 'name' => $meta->name, 'kind' => 'attribute', 'list' => false, 'item' => null, 'format' => null];

            continue;
        }

        // `#[XmlAny]` (a real XSD wildcard, `xterr/php-ubl`'s own mechanism for it) has no UBL
        // element name to key the array form by - unlike `#[XmlElement]`, which always names
        // one. Keying it by its own property name instead (here, always `any`) is this
        // package's own, internal choice, not a UBL name: nothing about the array/json/yaml
        // shape of a wildcard is standardized by UBL itself. Otherwise identical to a list of
        // plain strings (`list: true, item: 'string'`), because that is genuinely what it is:
        // `ArraySerializableTrait::exportList()`/`hydrateList()` already handle that shape with
        // no change of their own needed.
        if ($anyAttribute !== []) {
            $properties[] = ['property' => $name, 'name' => $name, 'kind' => 'element', 'list' => true, 'item' => 'string', 'format' => null];

            continue;
        }

        if ($elementAttribute !== []) {
            $meta = $elementAttribute[0]->newInstance();
            $type = $property->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
            $isList = $typeName === 'array';
            // For a list, #[XmlElement(type: ...)] names the item class (the property itself
            // is just `array`). For a single value, the property's own declared type already
            // says what to hydrate into (a class, or a scalar name importValue() passes through).
            $item = $isList ? $meta->type : $typeName;
            $properties[] = ['property' => $name, 'name' => $meta->name, 'kind' => 'element', 'list' => $isList, 'item' => $item, 'format' => $meta->format];
        }
    }

    return ['isValueType' => $isValueType, 'properties' => $properties];
}

/**
 * `var_export()` with 4-space indentation, to match the rest of the file (`php-cs-fixer` only
 * fixes indentation already made of spaces, it does not re-indent `var_export()`'s own 2-space,
 * PSR-12-breaking output), and with the constant's own nesting folded in: the array literal
 * starts mid-line, after `private const ARRAY_METADATA = `, so every line after the first needs
 * one indent level more than `var_export()` assumes (column 0) to land under that declaration
 * instead of under it. It also collapses a nested array's `array (` onto its key's own line
 * (`var_export()` always puts it on a new one: `'properties' => \narray (`) and drops the space
 * before every `(` it is about to turn into `[` - left in place, that space survives
 * `php-cs-fixer`'s short-array-syntax rewrite as a stray double space (`=>  [`).
 */
function exportMetadata(array $metadata): string
{
    $exported = var_export($metadata, true);
    $exported = preg_replace('/=> \n\s*array \(/', '=> array (', $exported);
    $exported = str_replace('array (', 'array(', (string) $exported);

    $lines = explode("\n", $exported);
    foreach ($lines as $i => $line) {
        if ($i === 0) {
            continue;
        }
        $trimmed = ltrim($line, ' ');
        $depth = (strlen($line) - strlen($trimmed)) / 2;
        $lines[$i] = str_repeat(' ', $depth * 4 + 4) . $trimmed;
    }

    return implode("\n", $lines);
}

/**
 * @param list<string> $excludePaths Path substrings to skip, the same way as the ones
 * `addArraySerializableTrait()` does not touch.
 */
function assertNoneMatch(string $dir, string $pattern, string $message, array $excludePaths = []): void
{
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)),
        '/\.php$/'
    );

    foreach ($files as $file) {
        $path = $file->getPathname();
        foreach ($excludePaths as $exclude) {
            if (str_contains($path, $exclude)) {
                continue 2;
            }
        }

        if (preg_match($pattern, file_get_contents($path)) === 1) {
            fwrite(STDERR, "patch-oasis: postcondition failed in {$path}: $message\n");
            exit(1);
        }
    }
}

function oasisDir(): string
{
    return dirname(__DIR__) . '/src/Oasis';
}
