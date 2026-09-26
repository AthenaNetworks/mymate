<?php

namespace App\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Makes an uploaded SVG safe to keep and serve (GitHub #37 map backgrounds). SVG is XML that can
 * carry script, event handlers, foreign HTML and external references, so it's parsed (never
 * regex-edited) and rebuilt from an allowlist:
 *
 *   - a DOCTYPE is refused outright - that's where entity expansion / XXE lives, and no real
 *     drawing needs one;
 *   - only plain SVG drawing elements survive (no script, foreignObject, animate/set, a, iframe,
 *     feImage...). Anything outside the SVG namespace (inkscape:/sodipodi: editor junk) goes too;
 *   - on* handlers and non-SVG attributes are dropped, href/xlink:href must be a local #fragment
 *     (or an inline raster data: URI on <image>), and anything reaching for javascript: or an
 *     external url()/@import is removed;
 *   - processing instructions (xml-stylesheet) are stripped.
 *
 * It's defence in depth, not the only line: the image is always drawn through <img> (which never
 * runs script) and served with a sandboxed CSP, so even a miss here can't execute on our origin.
 */
class SvgSanitizer
{
    private const SVG_NS = 'http://www.w3.org/2000/svg';

    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';

    /** Lowercased local names of the elements we keep. Drawing, text, paint servers, filters. */
    private const ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'switch',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textpath', 'image', 'style', 'marker', 'pattern',
        'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask',
        'filter', 'feblend', 'fecolormatrix', 'fecomponenttransfer', 'fecomposite',
        'feconvolvematrix', 'fediffuselighting', 'fedisplacementmap', 'fedistantlight',
        'fedropshadow', 'feflood', 'fefunca', 'fefuncb', 'fefuncg', 'fefuncr',
        'fegaussianblur', 'femerge', 'femergenode', 'femorphology', 'feoffset',
        'fepointlight', 'fespecularlighting', 'fespotlight', 'fetile', 'feturbulence',
    ];

    /**
     * Sanitised SVG markup, or null if it isn't a usable SVG at all (unparseable, has a DOCTYPE,
     * or the root isn't <svg>).
     */
    public static function sanitize(string $xml): ?string
    {
        // Entity declarations only ever arrive in a DOCTYPE; refuse the lot rather than try to
        // defuse them. Checked on the raw bytes before the parser sees anything.
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return null;
        }

        $doc = new DOMDocument;
        $prev = libxml_use_internal_errors(true);
        // LIBXML_NONET: never fetch anything. No LIBXML_NOENT, so entities are never substituted.
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->documentElement;
        if (! $ok || $doc->doctype !== null || $root === null
            || $root->namespaceURI !== self::SVG_NS || strtolower($root->localName) !== 'svg') {
            return null;
        }

        // Top-level processing instructions / comments around the root.
        foreach (iterator_to_array($doc->childNodes, false) as $node) {
            if ($node !== $root) {
                $doc->removeChild($node);
            }
        }

        self::clean($root);

        return $doc->saveXML($root) ?: null;
    }

    private static function clean(DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes, false) as $attr) {
            /** @var DOMAttr $attr */
            if (! self::keepAttribute($el, $attr)) {
                $el->removeAttributeNode($attr);
            }
        }

        foreach (iterator_to_array($el->childNodes, false) as $child) {
            /** @var DOMNode $child */
            if ($child instanceof DOMElement) {
                if ($child->namespaceURI !== self::SVG_NS || ! in_array(strtolower($child->localName), self::ELEMENTS, true)) {
                    $el->removeChild($child);

                    continue;
                }
                if (strtolower($child->localName) === 'style' && self::dangerousCss($child->textContent)) {
                    $el->removeChild($child);

                    continue;
                }
                self::clean($child);
            } elseif ($child->nodeType === XML_PI_NODE) {
                $el->removeChild($child);
            }
            // text, CDATA (inside <style>, already vetted above) and comments are inert.
        }
    }

    private static function keepAttribute(DOMElement $el, DOMAttr $attr): bool
    {
        $name = strtolower($attr->localName);
        $ns = $attr->namespaceURI;
        $value = $attr->value;

        // Only plain attributes plus xlink:/xml: ones - editor namespaces are dead weight.
        if ($ns !== null && $ns !== self::XLINK_NS && $ns !== self::XML_NS) {
            return false;
        }
        if (str_starts_with($name, 'on')) {
            return false; // onload, onclick, onbegin...
        }
        if ($name === 'href') {
            return self::safeHref($el, $value);
        }

        return ! self::dangerousCss($value);
    }

    /** A local #fragment anywhere, or an inline raster image on <image>. Nothing that leaves the file. */
    private static function safeHref(DOMElement $el, string $value): bool
    {
        $v = trim($value);
        if (str_starts_with($v, '#')) {
            return true;
        }

        return strtolower($el->localName) === 'image'
            && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[a-z0-9+/=\s]*$#i', $v) === 1;
    }

    /**
     * javascript:/vbscript:/data:text anywhere, @import, expression(), or a url() that isn't a local
     * #fragment. Whitespace and control characters are squeezed out first so `java\tscript:` and
     * friends don't slip past.
     */
    private static function dangerousCss(string $value): bool
    {
        $squeezed = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $value));

        if (str_contains($squeezed, 'javascript:') || str_contains($squeezed, 'vbscript:')
            || str_contains($squeezed, 'data:text') || str_contains($squeezed, '@import')
            || str_contains($squeezed, 'expression(')) {
            return true;
        }

        // Every url(...) must point at a local #id.
        if (preg_match_all('/url\(([^)]*)\)/', $squeezed, $m)) {
            foreach ($m[1] as $target) {
                if (! str_starts_with(trim($target, '\'"'), '#')) {
                    return true;
                }
            }
        }

        // An escaped `\75 rl(` style trick: refuse any CSS escape in a url-ish context.
        return str_contains($squeezed, '\\') && str_contains($squeezed, '(');
    }
}
