<?php
/**
 * lg_scrub_anon_contacts — wipe emails + @handles out of discussion content
 * shown to LOGGED-OUT viewers (Ian 2026-06-10: "wipe emails and @s from logged
 * out views of discussions. Just scrub them."). Pairs with the identity mask
 * (lg_bb_mirror_mask_visibility): names are withheld, the discussion is seen,
 * and contact handles inside the text don't leak either.
 *
 * Logged-in members see the original text. Idempotent, HTML-safe enough for
 * our sanitized bodies: mailto anchors die whole (the href leaks), bare emails
 * then @handles get neutral placeholders.
 */
function lg_scrub_anon_contacts(string $s): string
{
    // NB the fast-path also keys on 'mention': mention anchors carry the member's
    // NAME as text now (render-source swap, Ian handles-invisible final 2026-07-26)
    // — a body can leak identity to anon with no '@' in it at all.
    if ($s === '' || (strpos($s, '@') === false && stripos($s, 'mention') === false)) return $s;
    // 1. mailto links — the whole anchor (href carries the address)
    $s = preg_replace('~<a\b[^>]*href\s*=\s*([\'"])mailto:[^\'"]*\1[^>]*>.*?</a>~is', '[email withheld]', $s) ?? $s;
    // 2. bare email addresses
    $s = preg_replace('~[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}~', '[email withheld]', $s) ?? $s;
    // 3. mention anchors — text is the member's CURRENT NAME, which the @-pattern
    //    below can no longer catch. Neutralize the text, keep the anchor (parity
    //    with the old behavior, where only the @handle text was replaced).
    $s = preg_replace('~(<a\b[^>]*class\s*=\s*([\'"])[^\'"]*\b(?:bp-suggestions-mention|bb-mention)\b[^\'"]*\2[^>]*>).*?(</a>)~is', '$1@member$3', $s) ?? $s;
    // 4. @handles (emails already gone; not preceded by a word char, so
    //    attributes like href="/u/x" and mid-word @ survive untouched)
    $s = preg_replace('~(?<![\w.\-@])@[A-Za-z0-9_.\-]{2,}~', '@member', $s) ?? $s;
    return $s;
}
