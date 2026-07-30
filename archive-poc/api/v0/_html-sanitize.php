<?php
/**
 * archive-poc/api/v0/_html-sanitize.php — the write-boundary HTML sanitizer.
 *
 * WHY THIS EXISTS
 * ---------------
 * `rows[].query.html` is the one config field that reaches the public front page
 * as RAW markup (web/_render-main-row.php:476 echoes it unescaped, after
 * expanding [member_map]). That line has always carried the comment
 * "trusted: sanitized by dash on save" — which was NOT true: ArchivePocDash
 * never ran kses over the field, and the webhook passed it through as an opaque
 * scalar. It was only ever protected by the fact that writing needs the loopback
 * X-LG-Config-Secret, i.e. it was trusted-admin HTML, not sanitized HTML.
 *
 * The front-end editor adds a SECOND write path into that same field, so the
 * guarantee now gets enforced where it belongs: at the write boundary, on the
 * server, for every writer (FE editor AND the wp-admin dash), never on the
 * client. After this, the render-side comment is finally accurate.
 *
 * WHAT IT KEEPS
 * -------------
 * The whitelist is deliberately shaped around what the front page's authored
 * copy actually uses today (headings, paragraphs, lists, links, <hr>, inline
 * emphasis, the `vp-*` classes) plus images. Anything else is either unwrapped
 * (children kept — so text never silently vanishes) or, for the actively
 * dangerous elements, dropped whole.
 *
 * Shortcodes ([member_map]) are plain text at this layer and pass through
 * untouched; they are expanded at RENDER time, after this ran.
 *
 * Self-test:  php _html-sanitize.php --selftest
 */

declare(strict_types=1);

/** Elements kept, mapped to the attributes each may keep. */
const LG_AP_ALLOWED = [
    'p'          => ['class'],
    'br'         => [],
    'strong'     => [], 'b' => [],
    'em'         => [], 'i' => [],
    's'          => [], 'del' => [], 'u' => [],
    'h3'         => ['class'], 'h4' => ['class'], 'h5' => ['class'],
    'ul'         => ['class'], 'ol' => ['class'], 'li' => ['class'],
    'a'          => ['href', 'class', 'rel', 'target', 'title', 'data-feedback', 'data-action'],
    'hr'         => ['class'],
    'blockquote' => ['class'],
    'code'       => ['class'], 'pre' => ['class'],
    'span'       => ['class'],
    'img'        => ['src', 'alt', 'class', 'width', 'height', 'loading', 'srcset', 'sizes'],
];

/**
 * Elements dropped WITH their subtree. Everything else that is merely
 * not-allowed gets unwrapped instead, so authored text is never lost to a
 * stray <div> or a tag we simply haven't whitelisted yet.
 */
const LG_AP_STRIP_SUBTREE = [
    'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button',
    'select', 'textarea', 'link', 'meta', 'base', 'svg', 'math', 'template',
    'noscript', 'frame', 'frameset', 'applet', 'audio', 'video', 'source',
];

/** URL schemes permitted in href/src. Root-relative and #fragment also pass. */
function lg_ap_safe_url(string $url, bool $for_image = false): bool
{
    $u = trim($url);
    if ($u === '') return false;
    // Reject control chars / newlines used to smuggle "java\nscript:".
    if (preg_match('~[\x00-\x1F\x7F]~', $u)) return false;
    // `//host` is protocol-relative — it leaves the site, so it is NOT a
    // root-relative path. Must be tested before the leading-slash check.
    if (str_starts_with($u, '//')) return false;
    if ($u[0] === '#' || $u[0] === '/') return true;          // fragment / root-relative
    if (preg_match('~^https?://~i', $u)) return true;
    if (!$for_image && preg_match('~^mailto:~i', $u)) return true;
    return false;                                              // javascript:, data:, vbscript:, protocol-relative
}

/**
 * Sanitize an authored HTML fragment to the front-page whitelist.
 * Returns '' for empty/unparseable input.
 */
function lg_archive_poc_sanitize_html(string $html): string
{
    if (trim($html) === '') return '';

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    // The meta charset + wrapper make loadHTML treat this as a UTF-8 fragment.
    $ok = $doc->loadHTML(
        '<?xml encoding="UTF-8"><html><body><div id="lg-ap-root">' . $html . '</div></body></html>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return '';

    $root = $doc->getElementById('lg-ap-root');
    if (!$root) return '';

    lg_ap_clean_children($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}

/** Recursively clean $node's children in place. */
function lg_ap_clean_children(DOMNode $node): void
{
    // Snapshot: we mutate the live child list while walking it.
    $children = [];
    foreach ($node->childNodes as $c) $children[] = $c;

    foreach ($children as $child) {
        if ($child instanceof DOMComment) {          // comments can hide markup
            $child->parentNode->removeChild($child);
            continue;
        }
        if ($child instanceof DOMText) continue;      // text is always safe
        if (!($child instanceof DOMElement)) {        // CDATA, PI, doctype…
            $child->parentNode->removeChild($child);
            continue;
        }

        $tag = strtolower($child->nodeName);

        if (in_array($tag, LG_AP_STRIP_SUBTREE, true)) {
            $child->parentNode->removeChild($child);
            continue;
        }

        if (!array_key_exists($tag, LG_AP_ALLOWED)) {
            lg_ap_clean_children($child);             // clean, then unwrap
            lg_ap_unwrap($child);
            continue;
        }

        // Allowed element: strip every attribute not on its own whitelist.
        $allowed = LG_AP_ALLOWED[$tag];
        $attrs   = [];
        foreach ($child->attributes as $a) $attrs[] = $a->nodeName;
        foreach ($attrs as $name) {
            $lname = strtolower($name);
            if (!in_array($lname, $allowed, true)) {
                $child->removeAttribute($name);
                continue;
            }
            $val = $child->getAttribute($name);
            if ($lname === 'href' && !lg_ap_safe_url($val)) {
                $child->removeAttribute($name);
            } elseif ($lname === 'src' && !lg_ap_safe_url($val, true)) {
                // An <img> with no usable src is noise — drop the element.
                $child->parentNode->removeChild($child);
                continue 2;
            } elseif ($lname === 'srcset') {
                // Every candidate must be a safe URL, else drop the attribute.
                foreach (explode(',', $val) as $cand) {
                    $u = trim(explode(' ', trim($cand))[0] ?? '');
                    if ($u !== '' && !lg_ap_safe_url($u, true)) { $child->removeAttribute($name); break; }
                }
            } elseif ($lname === 'target' && $val !== '_blank') {
                $child->removeAttribute($name);
            }
        }
        // target=_blank without noopener is a tabnabbing footgun — pin rel.
        if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
            $child->setAttribute('rel', 'noopener');
        }

        lg_ap_clean_children($child);
    }
}

/** Replace an element with its children, in place. */
function lg_ap_unwrap(DOMElement $el): void
{
    $parent = $el->parentNode;
    if (!$parent) return;
    while ($el->firstChild) {
        $parent->insertBefore($el->firstChild, $el);
    }
    $parent->removeChild($el);
}

/**
 * A YouTube id, extracted from whatever an author pasted (watch URL, youtu.be,
 * /embed/, /shorts/, or a bare id). Returns '' if nothing id-shaped is found,
 * so a bad paste blanks the video instead of injecting into the iframe src.
 */
function lg_archive_poc_youtube_id(string $raw): string
{
    $s = trim($raw);
    if ($s === '') return '';
    foreach ([
        '~[?&]v=([A-Za-z0-9_-]{6,20})~',
        '~youtu\.be/([A-Za-z0-9_-]{6,20})~',
        '~/embed/([A-Za-z0-9_-]{6,20})~',
        '~/shorts/([A-Za-z0-9_-]{6,20})~',
        '~/live/([A-Za-z0-9_-]{6,20})~',
    ] as $re) {
        if (preg_match($re, $s, $m)) return $m[1];
    }
    return preg_match('~^[A-Za-z0-9_-]{6,20}$~', $s) ? $s : '';
}

/** Embed aspect ratios the renderer has CSS for. */
function lg_archive_poc_aspect(string $raw): string
{
    $a = strtolower(trim($raw));
    return in_array($a, ['16x9', '4x3', '1x1', '9x16'], true) ? $a : '16x9';
}

/* ------------------------------------------------------------------ selftest */
if (PHP_SAPI === 'cli' && in_array('--selftest', $argv ?? [], true)) {
    $pass = 0; $fail = 0;
    $check = function (string $what, $got, $want) use (&$pass, &$fail) {
        if ($got === $want) { $pass++; return; }
        $fail++;
        fwrite(STDERR, "FAIL  $what\n  got:  " . var_export($got, true)
                     . "\n  want: " . var_export($want, true) . "\n");
    };

    // --- attacks that must not survive ---
    $check('script dropped',
        lg_archive_poc_sanitize_html('<p>hi</p><script>alert(1)</script>'), '<p>hi</p>');
    $check('onerror stripped',
        lg_archive_poc_sanitize_html('<img src="/a.png" onerror="alert(1)">'),
        '<img src="/a.png">');
    $check('javascript: href stripped',
        lg_archive_poc_sanitize_html('<a href="javascript:alert(1)">x</a>'), '<a>x</a>');
    $check('data: image dropped',
        lg_archive_poc_sanitize_html('<img src="data:text/html;base64,PHN2Zz4=">'), '');
    $check('style attr stripped',
        lg_archive_poc_sanitize_html('<p style="position:fixed">x</p>'), '<p>x</p>');
    $check('iframe dropped whole',
        lg_archive_poc_sanitize_html('<iframe src="//evil"></iframe><p>keep</p>'), '<p>keep</p>');
    $check('comment dropped',
        lg_archive_poc_sanitize_html('<p>a</p><!-- <script>x</script> -->'), '<p>a</p>');
    $check('svg dropped whole',
        lg_archive_poc_sanitize_html('<svg onload="alert(1)"><use/></svg><p>k</p>'), '<p>k</p>');
    $check('form dropped whole',
        lg_archive_poc_sanitize_html('<form action="/x"><input name="p"></form><p>k</p>'), '<p>k</p>');
    $check('protocol-relative href stripped',
        lg_archive_poc_sanitize_html('<a href="//evil.com">x</a>'), '<a>x</a>');
    $check('div unwrapped, text kept',
        lg_archive_poc_sanitize_html('<div><p>kept</p></div>'), '<p>kept</p>');
    $check('unknown tag unwrapped, text kept',
        lg_archive_poc_sanitize_html('<marquee>text</marquee>'), 'text');
    $check('target=_blank gets noopener',
        lg_archive_poc_sanitize_html('<a href="/x" target="_blank">x</a>'),
        '<a href="/x" target="_blank" rel="noopener">x</a>');

    // --- real front-page content that must survive byte-identically ---
    $keep = '<p class="vp-eyebrow">What\'s new</p><h3>Welcome to the new Looth Group website</h3>'
          . '<ul><li><strong>The Hub</strong> &mdash; every discussion.</li></ul>'
          . '<hr class="vp-divider">'
          . '<p><a class="vp-cta" data-feedback href="/hub/?compose=suggestion-box-bug-reporting">Report a bug &rarr;</a></p>';
    $got = lg_archive_poc_sanitize_html($keep);
    $check('authored copy survives (entities normalize to UTF-8)',
        $got,
        '<p class="vp-eyebrow">What\'s new</p><h3>Welcome to the new Looth Group website</h3>'
        . '<ul><li><strong>The Hub</strong> — every discussion.</li></ul>'
        . '<hr class="vp-divider">'
        . '<p><a class="vp-cta" data-feedback href="/hub/?compose=suggestion-box-bug-reporting">Report a bug →</a></p>');
    $check('[member_map] shortcode passes through',
        lg_archive_poc_sanitize_html('<p>before</p>[member_map]<p>after</p>'),
        '<p>before</p>[member_map]<p>after</p>');
    $check('empty in, empty out', lg_archive_poc_sanitize_html('   '), '');

    // --- youtube id extraction ---
    foreach ([
        'https://www.youtube.com/watch?v=LhcIQ0x31hc'        => 'LhcIQ0x31hc',
        'https://youtu.be/LhcIQ0x31hc?t=30'                  => 'LhcIQ0x31hc',
        'https://www.youtube.com/embed/LhcIQ0x31hc?rel=0'    => 'LhcIQ0x31hc',
        'https://www.youtube.com/shorts/LhcIQ0x31hc'         => 'LhcIQ0x31hc',
        'LhcIQ0x31hc'                                        => 'LhcIQ0x31hc',
        'https://evil.com/"><script>'                        => '',
        ''                                                   => '',
    ] as $in => $want) {
        $check("youtube id from " . ($in === '' ? '(empty)' : $in),
               lg_archive_poc_youtube_id($in), $want);
    }
    $check('aspect passthrough', lg_archive_poc_aspect('9x16'), '9x16');
    $check('aspect fallback',    lg_archive_poc_aspect('evil"onload='), '16x9');

    echo ($fail === 0 ? "OK" : "FAILED") . " — $pass passed, $fail failed\n";
    exit($fail === 0 ? 0 : 1);
}
