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
    'unmappedChildrenKeepTheNamespacesInScope',
    'rawFragmentsAreWrittenWithoutReconcilingTheirNamespaces',
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

/**
 * `XmlDeserializer` keeps every child it has no mapping for (the content of an `ExtensionContent`,
 * a signature) as the string of `saveXML($child)`. That string only declares the namespaces the
 * child itself declares: one declared by an ancestor (`xmlns:sig` on the `ext:UBLExtension` of
 * UBL's own signed example, `UBL-Invoice-2.0-Enveloped.xml`) is lost, and the fragment is no
 * longer well-formed XML on its own: written back, the document is not even XML ("Namespace
 * prefix sig on UBLDocumentSignatures is not defined").
 *
 * Adds to the start tag of the fragment a declaration for each namespace that an ancestor declared
 * and that the fragment uses (by an element or attribute prefix, or by its default namespace), so
 * it stands on its own wherever it ends up. Nothing else changes: not a byte of what the child
 * already had, which a digital signature over it depends on (a copy of the node, which libxml
 * reconciles, rewrote its default namespaces as prefixed ones).
 */
function unmappedChildrenKeepTheNamespacesInScope(string $installPath): bool
{
    $file = "$installPath/src/Xml/XmlDeserializer.php";
    $source = file_get_contents($file);

    if ($source === false) {
        fwrite(STDERR, "Could not read $file.\n");
        exit(1);
    }

    $patchedCall = '$anyFragments[] = $this->serializeFragment($child);';
    if (str_contains($source, $patchedCall)) {
        return false;
    }

    $originalCall = '$anyFragments[] = $element->ownerDocument?->saveXML($child);';
    $anchor = '    private function getDirectTextContent(\DOMElement $element): string';

    if (!str_contains($source, $originalCall) || !str_contains($source, $anchor)) {
        fwrite(
            STDERR,
            "xterr/php-ubl's XmlDeserializer no longer matches the expected source for its unmapped "
                . "children (the installed version may have changed); patch skipped, needs review.\n"
        );
        exit(1);
    }

    $method = <<<'PHP'
    /**
     * An unmapped child as an XML string that stands on its own: the serialization of the
     * child, with the declarations of the namespaces that an ancestor declared and the child
     * (its elements, its attributes) uses, which `saveXML()` leaves out.
     */
    private function serializeFragment(\DOMElement $child): string
    {
        $document = $child->ownerDocument;
        $parent = $child->parentNode;
        $xml = (string) $document?->saveXML($child);

        if ($document === null || !$parent instanceof \DOMElement) {
            return $xml;
        }

        $xpath = new \DOMXPath($document);
        $used = [];
        $usedDefault = [];
        foreach ($xpath->query('descendant-or-self::*', $child) ?: [] as $element) {
            if ($element->prefix !== '') {
                $used['xmlns:' . $element->prefix] = true;
            } elseif ($element->namespaceURI !== null && $element->namespaceURI !== '') {
                $usedDefault[] = $element->namespaceURI;
            }
            foreach ($element->attributes ?? [] as $attribute) {
                if ($attribute->prefix !== '') {
                    $used['xmlns:' . $attribute->prefix] = true;
                }
            }
        }

        $missing = '';
        foreach ($xpath->query('namespace::*', $parent) ?: [] as $declaration) {
            $name = $declaration->nodeName;
            $isUsed = $name === 'xmlns' ? in_array($declaration->nodeValue, $usedDefault, true) : isset($used[$name]);
            if ($name === 'xmlns:xml' || !$isUsed || $child->hasAttribute($name)) {
                continue;
            }
            $missing .= ' ' . $name . '="' . htmlspecialchars($declaration->nodeValue, ENT_QUOTES | ENT_XML1) . '"';
        }

        return $missing === '' ? $xml : (string) preg_replace_callback(
            '/^<[^\s\/>]+/',
            static fn (array $tag): string => $tag[0] . $missing,
            $xml,
            1
        );
    }


PHP;

    $patched = str_replace($originalCall, $patchedCall, $source);
    $patched = str_replace($anchor, $method . $anchor, $patched);
    file_put_contents($file, $patched);

    $result = file_get_contents($file);
    if ($result === false || !str_contains($result, $patchedCall) || !str_contains($result, 'function serializeFragment')) {
        fwrite(STDERR, "Patched $file but the expected result is not there afterwards.\n");
        exit(1);
    }

    return true;
}

/**
 * `XmlSerializer::injectRawXml()` writes the fragments of a `#[XmlAny]` property by importing
 * their nodes into the document (`importNode()`), and DOM reconciles the namespaces of what it
 * imports (and of what it appends): the default namespace that a fragment declares for itself
 * (`<QualifyingProperties xmlns="http://uri.etsi.org/01903/v1.3.2#">` of a XAdES signature)
 * comes out as a prefixed one (`default:`), with declarations it did not have. The same XML
 * infoset, another text: what is written is not what was read, and the canonical form that a
 * digital signature over the fragment depends on is not the same.
 *
 * Writes a placeholder (a comment) where the fragment goes and, once the document is a string,
 * puts the fragment in its place: as it is, byte by byte. The check of the fragment before
 * (well-formed, no DOCTYPE or entities) stays.
 */
function rawFragmentsAreWrittenWithoutReconcilingTheirNamespaces(string $installPath): bool
{
    $file = "$installPath/src/Xml/XmlSerializer.php";
    $source = file_get_contents($file);

    if ($source === false) {
        fwrite(STDERR, "Could not read $file.\n");
        exit(1);
    }

    if (str_contains($source, 'rawFragments')) {
        return false;
    }

    $replacements = [
        [
            '    private MetadataFactory $metadataFactory;
',
            '    private MetadataFactory $metadataFactory;

    /** @var array<string, string> The raw fragments of the document being written, by placeholder. */
    private array $rawFragments = [];
',
        ],
        [
            '        // Serialize the object into the root element
        $this->serializeObject($dom, $root, $object);

        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new SerializationException(\'Failed to serialize XML.\');
        }
',
            '        // Serialize the object into the root element
        $this->rawFragments = [];
        $this->serializeObject($dom, $root, $object);

        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new SerializationException(\'Failed to serialize XML.\');
        }

        foreach ($this->rawFragments as $placeholder => $fragment) {
            $xml = str_replace(\'<!--\' . $placeholder . \'-->\', $fragment, $xml);
        }
',
        ],
        [
            '        foreach ($rootEl->childNodes as $node) {
            $imported = $dom->importNode($node, true);
            $parent->appendChild($imported);
        }',
            '        $placeholder = \'xterr-raw-fragment-\' . \count($this->rawFragments);
        $this->rawFragments[$placeholder] = $fragment;
        $parent->appendChild($dom->createComment($placeholder));',
        ],
    ];

    $patched = $source;
    foreach ($replacements as [$original, $replacement]) {
        if (!str_contains($patched, $original)) {
            fwrite(
                STDERR,
                "xterr/php-ubl's XmlSerializer no longer matches the expected source for its raw "
                    . "fragments (the installed version may have changed); patch skipped, needs review.\n"
            );
            exit(1);
        }
        $patched = str_replace($original, $replacement, $patched);
    }

    file_put_contents($file, $patched);

    $result = file_get_contents($file);
    if ($result === false || substr_count($result, 'rawFragments') < 4) {
        fwrite(STDERR, "Patched $file but the expected result is not there afterwards.\n");
        exit(1);
    }

    return true;
}
