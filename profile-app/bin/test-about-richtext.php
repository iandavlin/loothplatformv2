<?php
/**
 * Regression test for the About rich-text sanitizer/projection contract
 * (Block::sanitizeRichHtml + htmlToPlainText). Pure functions — no DB.
 *
 *   php bin/test-about-richtext.php     # exits 0 all-pass, 1 on any failure
 *
 * Guards the Ian 2026-07-23 bug fixes: (a) strict allowlist strips dangerous
 * markup; (b) strike survives (<s>/<strike>/<del>→<s>); (c) the save→reload→save
 * round-trip is IDEMPOTENT even against worst-case Quill mangling (list-transform
 * + every-space→nbsp); (d) no double-escape; (e) the plain projection is nbsp-clean.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Block;

$ok = true;
$n  = 0;
function check(bool $cond, string $label, string $detail = ''): void {
    global $ok, $n; $n++;
    echo sprintf("[%s] %s%s\n", $cond ? 'OK' : 'FAIL', $label, $cond ? '' : "  << $detail");
    if (!$cond) $ok = false;
}

$S = fn(string $h): string => Block::sanitizeRichHtml($h);

// ---- (a) security: dangerous markup stripped ----
check(strpos($S('<p>x</p><img src=x onerror=alert(1)>'), 'img') === false, 'img stripped');
check(strpos($S('<p>x</p><script>steal()</script>'), 'steal') === false, 'script content dropped whole');
check(strpos($S('<iframe src=//evil></iframe><b>ok</b>'), 'iframe') === false, 'iframe stripped');
check($S('<a href="javascript:alert(1)">e</a>') === 'e', 'javascript: href unwrapped to text');
check(strpos($S('<p onclick="e()">c</p>'), 'onclick') === false, 'event handler attr stripped');
check(strpos($S('<p class="x" id="y" style="color:red">z</p>'), 'class') === false, 'class/id/style stripped');

// ---- (b) allowlist survives ----
$rich = '<p><strong>b</strong><em>i</em><u>u</u> <a href="https://a.com/x">L</a></p><ul><li>a</li></ul><ol><li>1</li></ol><blockquote>q</blockquote><h3>h3</h3><h4>h4</h4>';
$rs = $S($rich);
foreach (['<strong>', '<em>', '<u>', '<li>', '<blockquote>', '<h3>', '<h4>'] as $t) {
    check(strpos($rs, $t) !== false, "allowlist keeps $t");
}
check(strpos($rs, 'rel="nofollow ugc noopener"') !== false, 'anchor gets forced rel');
check(strpos($rs, 'target="_blank"') !== false, 'anchor gets target=_blank');

// ---- (c) strike survives via s/strike/del ----
check($S('<s>a</s>')      === '<s>a</s>', '<s> kept');
check($S('<strike>a</strike>') === '<s>a</s>', '<strike> → <s>');
check($S('<del>a</del>')   === '<s>a</s>', '<del> → <s>');

// ---- (d) Quill list normalization ----
check($S('<ol><li data-list="bullet">a</li><li data-list="bullet">b</li></ol>') === '<ul><li>a</li><li>b</li></ul>', 'quill bullet list → <ul>');
check($S('<ol><li data-list="ordered">a</li></ol>') === '<ol><li>a</li></ol>', 'quill ordered list → <ol>');
check($S('<ol><li data-list="bullet">b</li><li data-list="ordered">o</li></ol>') === '<ul><li>b</li></ul><ol><li>o</li></ol>', 'quill mixed list → split <ul>/<ol>');

// ---- (e) idempotency + no-double-escape + nbsp heal (worst-case Quill sim) ----
function quillifyWorst(string $html): string {
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    $root = null; foreach ($doc->getElementsByTagName('div') as $d) { $root = $d; break; }
    // <ul>/<ol> → quill's <ol><li data-list=...> + ql-ui span
    $conv = function ($tag, $kind) use ($doc, $root) {
        foreach (iterator_to_array($root->getElementsByTagName($tag)) as $list) {
            if ($list->getAttribute('data-q')) continue;
            $new = $doc->createElement('ol'); $new->setAttribute('data-q', '1');
            foreach (iterator_to_array($list->childNodes) as $li) {
                if ($li instanceof DOMElement && strtolower($li->tagName) === 'li') {
                    $li->setAttribute('data-list', $kind);
                    $sp = $doc->createElement('span'); $sp->setAttribute('class', 'ql-ui'); $li->insertBefore($sp, $li->firstChild);
                }
                $new->appendChild($li);
            }
            $list->parentNode->replaceChild($new, $list);
        }
    };
    $conv('ul', 'bullet'); $conv('ol', 'ordered');
    // every text space → nbsp (strict upper bound on getSemanticHTML damage)
    $stack = [$root];
    while ($stack) { $node = array_pop($stack);
        foreach (iterator_to_array($node->childNodes) as $c) {
            if ($c instanceof DOMText) { $c->nodeValue = str_replace(' ', "\u{00A0}", $c->nodeValue); }
            elseif ($c instanceof DOMElement) { $stack[] = $c; }
        }
    }
    $out = ''; foreach (iterator_to_array($root->childNodes) as $c) { $out .= $doc->saveHTML($c); }
    return $out;
}
$cycleCases = [
    '<p><strong>bold</strong> <em>it</em> <u>u</u> <s>st</s> plain words</p>',
    '<p>Tom &amp; Jerry said "hi" &lt;ok&gt;</p>',
    '<p><a href="https://x.com/?a=1&amp;b=2">q</a></p>',
    '<p>lead</p><ul><li>one two</li><li>three</li></ul><ol><li>a</li></ol>',
    '<blockquote>a quote here</blockquote><h3>Head</h3>',
];
foreach ($cycleCases as $i => $x) {
    $s1 = $S($x);                       // stored after save 1
    $s2 = $S(quillifyWorst($s1));       // stored after save 2 (worst-case editor round-trip)
    $s3 = $S(quillifyWorst($s2));       // stored after save 3
    check($s1 === $s2, "cycle[$i] absorbs quill mangling in one pass", "s1=$s1 | s2=$s2");
    check($s2 === $s3, "cycle[$i] fixed point (byte-identical round 2+)", "s2=$s2 | s3=$s3");
    check(strpos($s2, "\u{00A0}") === false, "cycle[$i] no nbsp survives");
    check(strpos($s2, '&amp;amp;') === false && strpos($s2, '&amp;lt;') === false, "cycle[$i] no double-escape");
}

// ---- projection is nbsp-clean ----
$proj = Block::htmlToPlainText("<p>Tom\u{00A0}and\u{00A0}Jerry &amp; co</p>");
check(strpos($proj, "\u{00A0}") === false, 'projection heals nbsp → space');
check($proj === 'Tom and Jerry & co', 'projection decodes entities + clean spaces', $proj);

echo "\n" . ($ok ? "ALL $n CHECKS PASSED" : "FAILURES ABOVE ($n checks)") . "\n";
exit($ok ? 0 : 1);
