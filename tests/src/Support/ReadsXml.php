<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsUbl\Support;

use Derafu\Ubl\Support\SignatureProfile;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads what a test wants to look at in a signed document, with the prefixes of
 * the profile.
 */
trait ReadsXml
{
    /**
     * Gives the elements that an XPath finds in a document.
     *
     * @return list<DOMElement>
     */
    private function elements(string $xml, string $query): array
    {
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        $xpath = new DOMXPath($dom);
        foreach ([
            'ext' => SignatureProfile::NS_EXT,
            'sig' => SignatureProfile::NS_SIG,
            'sac' => SignatureProfile::NS_SAC,
            'sbc' => SignatureProfile::NS_SBC,
            'cbc' => SignatureProfile::NS_CBC,
            'cac' => SignatureProfile::NS_CAC,
            'ds' => SignatureProfile::NS_DS,
        ] as $prefix => $namespace) {
            $xpath->registerNamespace($prefix, $namespace);
        }

        $nodes = $xpath->query($query);
        if ($nodes === false) {
            $this->fail('The XPath ' . $query . ' is not valid.');
        }

        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * Gives the text of the first element that an XPath finds.
     */
    private function text(string $xml, string $query): string
    {
        $elements = $this->elements($xml, $query);
        $this->assertNotSame([], $elements, 'The XPath ' . $query . ' does not find an element.');

        return trim($elements[0]->textContent);
    }

    /**
     * Gives the XML of the invoice example of UBL 2.1, an unsigned document.
     */
    private function invoice(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/2.1/doc/xml/Invoice/Invoice_001_official_example.xml'
        );
    }
}
