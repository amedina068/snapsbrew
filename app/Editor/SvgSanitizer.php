<?php

namespace App\Editor;

/**
 * Small fail-closed sanitizer for inline editor SVG shapes.
 *
 * The renderer puts the SVG inline in an HTML page, so the HTML parser reads
 * it, not an XML parser. The HTML parser closes the SVG at an HTML-only
 * element such as `embed`, `img` or `meta`, and Chrome then loads its URL.
 * A deny list cannot name every such element, so the SVG passes only when
 * each element and each attribute is on an allow list of shape features.
 */
final class SvgSanitizer
{
    public const MAX_BYTES = 65536;

    private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

    /** Shape, text, grouping and paint-server elements. No element on the list loads a URL. */
    private const ELEMENTS = [
        'svg', 'g', 'defs', 'title', 'desc',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan',
        'lineargradient', 'radialgradient', 'stop',
        'clippath', 'mask', 'pattern', 'marker',
    ];

    /** Geometry and presentation attributes. No attribute on the list holds a URL. */
    private const ATTRIBUTES = [
        'version', 'viewbox', 'preserveaspectratio', 'width', 'height', 'x', 'y',
        'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'dx', 'dy',
        'd', 'points', 'pathlength', 'transform', 'rotate', 'id', 'class',
        'fill', 'fill-opacity', 'fill-rule', 'opacity', 'visibility', 'display',
        'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
        'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'vector-effect',
        'shape-rendering', 'color', 'clip-path', 'clip-rule', 'mask',
        'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform', 'spreadmethod',
        'patternunits', 'patterncontentunits', 'patterntransform',
        'maskunits', 'maskcontentunits', 'clippathunits',
        'markerwidth', 'markerheight', 'markerunits', 'refx', 'refy', 'orient',
        'marker-start', 'marker-mid', 'marker-end',
        'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor',
        'dominant-baseline', 'alignment-baseline', 'letter-spacing', 'word-spacing',
        'textlength', 'lengthadjust',
    ];

    public static function sanitize(mixed $svg): ?string
    {
        if (! is_string($svg) || strlen($svg) > self::MAX_BYTES || ! str_starts_with(ltrim($svg), '<svg')) {
            return null;
        }

        if (str_contains($svg, '&') || str_contains($svg, '\\') || str_contains($svg, '/*')
            || preg_match('/<(?:script|foreignObject|use|animate|set|iframe|object)\b|\bon\w+\s*=|\b(?:href|xlink:href|style)\s*=|<!DOCTYPE|<!ENTITY|<style\b/i', $svg)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument;
            $parsed = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $parsed || $document->documentElement?->localName !== 'svg' || ! self::isSafeElement($document->documentElement)) {
            return null;
        }

        return $svg;
    }

    private static function isSafeElement(\DOMElement $element): bool
    {
        if (! in_array($element->namespaceURI, [null, self::SVG_NAMESPACE], true)
            || ! in_array(strtolower($element->localName), self::ELEMENTS, true)) {
            return false;
        }

        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->localName);
            $allowed = $attribute->namespaceURI === null
                ? in_array($name, self::ATTRIBUTES, true)
                : $attribute->namespaceURI === 'http://www.w3.org/XML/1998/namespace' && $name === 'space';

            if (! $allowed || preg_match('/url\s*\(/i', $attribute->value)) {
                return false;
            }
        }

        foreach ($element->childNodes as $child) {
            // The HTML parser ends a comment or a processing instruction at
            // the first `>`, so only elements and plain text pass.
            $safe = match (true) {
                $child instanceof \DOMCdataSection => false,
                $child instanceof \DOMText => true,
                $child instanceof \DOMElement => self::isSafeElement($child),
                default => false,
            };

            if (! $safe) {
                return false;
            }
        }

        return true;
    }
}
