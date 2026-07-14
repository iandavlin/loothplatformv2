<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * HTML SANITIZER for chapter rich-text (CHAPTER-V2 ask 1, Ian 2026-07-14).
 *
 * profile_app is NOT WordPress, so there is no wp_kses here. This is the one allowlist that
 * makes Quill output safe to STORE and to inject into the DOM on render. It is deliberately
 * tight — only the tags the chapter Quill toolbar can emit survive; everything else is
 * UNWRAPPED (its text kept, the tag dropped) or removed. No <img>, <script>, <style>, <iframe>,
 * no event handlers, no class/id/style/data-* attributes. The only attribute that survives is a
 * scheme-checked href on <a> (http/https/mailto or a site-relative path), always re-stamped with
 * rel="noopener nofollow ugc" target="_blank".
 *
 * Sanitize on STORE (so the DB never holds a payload) AND treat the stored value as already-safe
 * on render — belt and braces. Test vectors live in tools/tests/html-sanitize-vectors.php.
 */
final class HtmlSanitize
{
    /** Tags the chapter Quill toolbar produces (bold/italic/underline/strike, h2/h3, lists, quote, link). */
    private const ALLOWED = [
        'p', 'br', 'strong', 'em', 'u', 's', 'h2', 'h3', 'ol', 'ul', 'li', 'a', 'blockquote',
    ];

    /** Absolute-URL schemes permitted on <a href>. Everything else (javascript:, data:, vbscript:…) is dropped. */
    private const OK_SCHEMES = ['http', 'https', 'mailto'];

    /** Hard ceiling before parsing, so a pathological blob can't DoS the parser. */
    private const MAX_BYTES = 100_000;

    public static function chapterHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '';
        if (strlen($html) > self::MAX_BYTES) $html = substr($html, 0, self::MAX_BYTES);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // <article> is a wrapper Quill never emits, so we can retrieve exactly our subtree back.
        // The meta forces UTF-8; NOIMPLIED/NODEFDTD keep libxml from injecting <html>/<body>/DOCTYPE;
        // NONET blocks any network entity fetch.
        $loaded = $doc->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><article>' . $html . '</article>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) return '';

        $root = $doc->getElementsByTagName('article')->item(0);
        if (!$root) return '';

        self::clean($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    /** Recursively enforce the allowlist on $node's descendants (mutates the tree). */
    private static function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                continue;                                   // text serializes escaped — always safe
            }
            if (!($child instanceof DOMElement)) {
                $node->removeChild($child);                 // comments, CDATA, processing-instructions
                continue;
            }

            $tag = strtolower($child->localName ?? $child->nodeName);
            if (!in_array($tag, self::ALLOWED, true)) {
                // UNWRAP: clean then lift the children into place, drop the tag itself. Keeps the
                // text Quill wraps in stray <div>/<span> without keeping a disallowed element.
                self::clean($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            self::stripAttrs($child, $tag);
            self::clean($child);
        }
    }

    /** Remove every attribute; for <a>, re-add a scheme-validated href with hardened rel/target. */
    private static function stripAttrs(DOMElement $el, string $tag): void
    {
        $href = $tag === 'a' ? trim((string)$el->getAttribute('href')) : '';

        foreach (iterator_to_array($el->attributes) as $attr) {
            $el->removeAttribute($attr->nodeName);
        }

        $clean = $tag === 'a' ? self::cleanHref($href) : null;
        if ($clean !== null) {
            $el->setAttribute('href', $clean);
            $el->setAttribute('rel', 'noopener nofollow ugc');
            $el->setAttribute('target', '_blank');
        }
        // An <a> with an unsafe/empty href survives as text-only (no href) — never a live vector.
    }

    /** Return a safe, normalised href, or null if it must be dropped. */
    private static function cleanHref(string $href): ?string
    {
        if ($href === '') return null;
        // Browsers strip ALL whitespace and control chars (TAB/LF/CR/NUL, leading spaces) from a
        // URL BEFORE parsing its scheme, so "java\tscript:", "  javascript:", "java\nscript:" all
        // resolve to "javascript:". Normalise the SAME way, then judge AND store the normalised
        // value — no gap between what we validated and what we keep.
        $norm = (string)preg_replace('/[\x00-\x20\x7f]+/', '', $href);
        if ($norm === '') return null;
        if (str_starts_with($norm, '//')) return null;          // protocol-relative borrows page scheme
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $norm, $m)) {
            return in_array(strtolower($m[1]), self::OK_SCHEMES, true) ? $norm : null;
        }
        return $norm;                                           // scheme-less => site-relative/fragment
    }

    /** Plain-text projection of rich HTML — for list previews, the title fallback, and search. */
    public static function toPlainText(string $html): string
    {
        $text = preg_replace('#<(br|/p|/li|/h2|/h3|/blockquote)\s*/?>#i', "\n", $html);
        $text = strip_tags((string)$text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse the runs of blank lines strip_tags leaves behind.
        $text = preg_replace("/\n{3,}/", "\n\n", (string)$text);
        return trim((string)$text);
    }
}
