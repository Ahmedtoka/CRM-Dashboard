<?php

namespace App\Ads\Platforms;

use DOMDocument;
use DOMElement;
use DOMText;

/**
 * Ad preview markup from a platform is untrusted HTML. Only a single https iframe on a Facebook host
 * survives: its src becomes `preview_url`, and the stored `preview_html` is rebuilt from that src
 * (plus numeric width/height) so nothing else from the original markup is ever kept.
 */
final class PreviewMarkup
{
    public const HOSTS = ['facebook.com', 'fb.com'];

    /** https and a host of facebook.com / fb.com (or one of their subdomains). */
    public static function allowedUrl(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /** The first iframe[src] of the markup when its src passes the host check, else null. */
    public static function iframeSrc(?string $html): ?string
    {
        foreach (self::elements($html) as $el) {
            $frames = $el->nodeName === 'iframe' ? [$el] : iterator_to_array($el->getElementsByTagName('iframe'));
            foreach ($frames as $frame) {
                $src = trim($frame->getAttribute('src'));
                if ($src !== '') {
                    return self::allowedUrl($src) ? $src : null;
                }
            }
        }

        return null;
    }

    /** A clean `<iframe>` when the markup is exactly one allowed iframe element, else null. */
    public static function singleIframe(?string $html): ?string
    {
        $doc = self::parse($html);
        if ($doc === null) {
            return null;
        }
        $body = $doc->getElementsByTagName('body')->item(0);
        $element = null;
        foreach ($body?->childNodes ?? [] as $node) {
            if ($node instanceof DOMText && trim($node->textContent) === '') {
                continue;
            }
            if (! $node instanceof DOMElement || $node->nodeName !== 'iframe' || $element !== null || $node->childNodes->length > 0) {
                return null;
            }
            $element = $node;
        }
        // Nothing may have landed outside the body either (only our own charset meta in head).
        if ($element === null || ($doc->getElementsByTagName('head')->item(0)?->childNodes->length ?? 0) > 1
            || $doc->getElementsByTagName('iframe')->length !== 1 || $doc->getElementsByTagName('script')->length > 0) {
            return null;
        }

        $src = trim($element->getAttribute('src'));
        if (! self::allowedUrl($src)) {
            return null;
        }

        $attrs = ['src="'.htmlspecialchars($src, ENT_QUOTES | ENT_HTML5).'"'];
        foreach (['width', 'height'] as $dim) {
            $v = $element->getAttribute($dim);
            if (preg_match('/^\d{1,4}$/', $v)) {
                $attrs[] = $dim.'="'.$v.'"';
            }
        }

        return '<iframe '.implode(' ', $attrs).'></iframe>';
    }

    /** @return list<DOMElement> top-level elements of the body */
    private static function elements(?string $html): array
    {
        $body = self::parse($html)?->getElementsByTagName('body')->item(0);
        $out = [];
        foreach ($body?->childNodes ?? [] as $node) {
            if ($node instanceof DOMElement) {
                $out[] = $node;
            }
        }

        return $out;
    }

    private static function parse(?string $html): ?DOMDocument
    {
        if ($html === null || trim($html) === '') {
            return null;
        }
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $doc->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $ok ? $doc : null;
    }
}
