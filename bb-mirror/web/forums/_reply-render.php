<?php
/**
 * Shared reply rendering — used by the feed teaser (_feed.php) and the lazy
 * full-thread endpoint (_topic-replies.php) so both emit identical .reply-stub
 * markup (same CSS + inline "… more" JS apply everywhere).
 *
 * Functions are function_exists-guarded so _feed.php (which also defines
 * bb_mirror_avatar/feed_rel_time historically) can require this safely.
 */

declare(strict_types=1);

if (!function_exists('lg_cover_src')) {
    /**
     * Same resizer-routing helper as _feed.php (function_exists-guarded twin —
     * _topic-replies.php includes this file without _feed.php). Reply-stub
     * images were the last feed images bypassing /img.php: raw ~100KB originals
     * for a 240px-max thumbnail (perf lane 2026-06-11).
     */
    function lg_cover_src(?string $url, int $w = 800): ?string
    {
        if (!$url) {
            return $url;
        }
        if (preg_match('#/wp-content/uploads/(.+)$#', $url, $m)) {
            return '/img.php?s=' . rawurlencode($m[1]) . '&w=' . $w;
        }
        return $url;
    }
}

// Whether the current viewer may post (create topics/replies). Posting is
// authenticated-only and the BuddyBoss REST API rejects anonymous writes (401) —
// that 401 is the real, inspector-proof backstop. This is the SERVER-rendered UI
// gate matching it: a genuinely logged-out viewer receives no post/reply markup,
// so there's nothing to un-hide in the inspector.
//
// We key on the presence of a WordPress login cookie — the SAME signal the
// posting path uses: the bb-mirror reply form mints its BuddyBoss nonce from
// /bb-mirror-api/v0/auth.php, which authorises via WP's own cookie
// (get_current_user_id), NOT /whoami. Gating on /whoami here would wrongly hide
// the post UI from logged-in members whose profile isn't bridged yet
// (whoami→anon despite a valid WP session). Cookie presence avoids that; a
// forged cookie at most reveals a button that still 401s on submit.
if (!function_exists('lg_bb_mirror_can_post')) {
    function lg_bb_mirror_can_post(): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $cached = false;
        foreach ($_COOKIE as $name => $val) {
            if (strncmp($name, 'wordpress_logged_in_', 20) === 0 && trim((string)$val) !== '') {
                $cached = true;
                break;
            }
        }
        return $cached;
    }
}

if (!function_exists('bb_mirror_avatar')) {
    /** Real profile-app avatar (when $avatar_url resolved) else a CSS initials
     *  circle. $slug picks a stable palette colour for the fallback. */
    function bb_mirror_avatar(string $display_name, string $slug, int $size = 32, ?string $avatar_url = null): string
    {
        if ($avatar_url !== null && $avatar_url !== '') {
            $u = function_exists('lg_bb_mirror_safe_avatar') ? lg_bb_mirror_safe_avatar($avatar_url) : $avatar_url;
            // Route uploads-hosted avatars through the resizer at 2x the slot
            // (a 300px/149KB BB original was shipping into 40px circles —
            // craft gate IMG-RAW, Ian 6/12). Non-uploads URLs pass through.
            if (preg_match('#/wp-content/uploads/(.+)$#', (string)$u, $m)) {
                $u = '/img.php?s=' . rawurlencode($m[1]) . '&w=96';   // smallest resizer bucket; avatars render 32-48px
            } elseif (str_starts_with((string)$u, '/profile-media/')) {
                $u .= (str_contains((string)$u, '?') ? '&' : '?') . 'w=96';   // profile-app media.php resize bucket
            }
            return sprintf(
                '<img class="avatar-init avatar-init--img" src="%s" width="%d" height="%d" alt="" loading="lazy" decoding="async">',
                htmlspecialchars((string)$u), $size, $size
            );
        }
        $palette = ['#6b7c52','#c66845','#87986a','#4a5a36','#7a5a14','#2e3a23','#5a6a5a','#a0714f'];
        $idx = abs(crc32($slug)) % count($palette);
        $bg  = $palette[$idx];
        $initial = mb_strtoupper(mb_substr($display_name, 0, 1));
        $fs  = round($size * 0.42);
        return sprintf(
            '<span class="avatar-init" style="width:%dpx;height:%dpx;font-size:%dpx;background:%s" aria-hidden="true">%s</span>',
            $size, $size, $fs, htmlspecialchars($bg), htmlspecialchars($initial)
        );
    }
}

if (!function_exists('feed_rel_time')) {
    function feed_rel_time(string $ts): string
    {
        $unix = strtotime($ts . (str_contains($ts, '+') ? '' : ' UTC'));
        if (!$unix) return '—';
        $diff = time() - $unix;
        if ($diff <     60) return $diff . 's';
        if ($diff <   3600) return round($diff / 60)   . 'm';
        if ($diff <  86400) return round($diff / 3600)  . 'h';
        if ($diff < 604800) return round($diff / 86400) . 'd';
        return date('M j, Y', $unix);
    }
}

if (!function_exists('bb_mirror__safe_href')) {
    /** Allow only http(s) and site-relative ("/...") hrefs through to output. */
    function bb_mirror__safe_href(string $href): bool
    {
        return $href !== '' && (str_starts_with($href, 'http://')
            || str_starts_with($href, 'https://')
            || str_starts_with($href, '/'));
    }
}

if (!function_exists('bb_mirror__mention_identities')) {
    /**
     * Batch-resolve mentioned members to their CURRENT identity.
     *
     * Source of truth is profile_app (users.slug), read over the same loopback endpoint
     * hub_resolve_profiles() already uses. Deliberately NOT forums.person: that table
     * caches the WP user_nicename, which is NOT the handle a member controls. Reading it
     * is precisely what makes a mention render a name its owner no longer uses.
     *
     * Memoised (incl. negative results) for the request: a feed page with the same member
     * mentioned on ten cards resolves them once, not ten times.
     *
     * @param int[]    $wpIds legacy BuddyBoss placeholders
     * @param string[] $uuids mentions minted by us
     * @return array{wp:array<int,?array>, uuid:array<string,?array>}
     */
    function bb_mirror__mention_identities(array $wpIds, array $uuids): array
    {
        static $memoWp = [], $memoUuid = [];

        $needWp   = array_values(array_diff(array_unique(array_map('intval', $wpIds)), array_keys($memoWp)));
        $needUuid = array_values(array_diff(array_unique(array_map('strtolower', $uuids)), array_keys($memoUuid)));

        $fetch = function (string $qs): array {
            // NB: no CLI short-circuit. Unlike hub_resolve_profiles() (which forwards the
            // viewer's browser cookie and is meaningless without one), this resolver reads
            // the PUBLIC identity over the loopback endpoint, which is internal-exempt
            // (users.php: REMOTE_ADDR 127.0.0.1/::1 → no auth). So it works — and is
            // unit-testable — from CLI and any cron/CLI render path, cookie or not.
            $hdrs = ['Host: ' . (defined('LG_BB_MIRROR_HOST') ? LG_BB_MIRROR_HOST : 'localhost')];
            if (!empty($_SERVER['HTTP_COOKIE'])) $hdrs[] = 'Cookie: ' . $_SERVER['HTTP_COOKIE'];
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => 'https://127.0.0.1/profile-api/v0/users?' . $qs,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_HTTPHEADER     => $hdrs,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code !== 200 || !$body) return [];
            $data = json_decode((string)$body, true);
            return (array)($data['items'] ?? []);
        };

        $shape = function (array $it): array {
            return [
                'uuid'    => strtolower((string)($it['uuid'] ?? '')),
                'slug'    => $it['slug'] ?? null,
                'private' => (($it['profile_visibility'] ?? 'public') === 'private'),
            ];
        };

        foreach (array_chunk($needWp, 100) as $chunk) {
            foreach ($fetch('wp_ids=' . rawurlencode(implode(',', $chunk))) as $it) {
                $id = $shape($it);
                if (!empty($it['wp_user_id'])) $memoWp[(int)$it['wp_user_id']] = $id;
                if ($id['uuid'] !== '')        $memoUuid[$id['uuid']] = $id;
            }
        }
        foreach (array_chunk($needUuid, 100) as $chunk) {
            foreach ($fetch('uuids=' . rawurlencode(implode(',', $chunk))) as $it) {
                $id = $shape($it);
                if ($id['uuid'] !== '') $memoUuid[$id['uuid']] = $id;
            }
        }
        // Negative-cache the misses (deleted member, dead uuid) so we don't re-ask per card.
        foreach ($needWp as $i)   if (!array_key_exists($i, $memoWp))   $memoWp[$i]   = null;
        foreach ($needUuid as $u) if (!array_key_exists($u, $memoUuid)) $memoUuid[$u] = null;

        return ['wp' => $memoWp, 'uuid' => $memoUuid];
    }
}

if (!function_exists('bb_mirror_resolve_mentions')) {
    /**
     * Make stored content_html clickable:
     *   1. Resolve @mentions to the mentioned member's CURRENT handle → /u/<slug>.
     *   2. Auto-link bare URLs that were typed as plain text.
     * Returns full HTML — structure preserved. Used for the expanded topic body, and by
     * bb_mirror_format_snippet() for every teaser/stub, so it is the ONE place mentions
     * are resolved on every surface.
     *
     * TWO STORED SHAPES, ONE MEANING — "this anchor refers to a member":
     *   OURS   <a … data-lg-uuid="<uuid>" …>@whatever</a>            (uuid = native, immutable)
     *   LEGACY <a … href="{{mention_user_id_<wpid>}}" …>@whatever</a> (BuddyBoss; wpid → uuid
     *          via the profile bridge — all 65 members ever mentioned bridge cleanly)
     *
     * Both carry a STABLE reference to a person. Neither one's TEXT is trustworthy: it is
     * the handle FROZEN at the moment the post was written. So we rebuild the whole anchor
     * — href AND visible text — from the member's CURRENT slug. That is the entire point:
     * a member renames, and every mention of them ever posted follows, with nothing in the
     * stored content rewritten.
     *
     * This is not theoretical. In live data a reply renders "@ianhatesguitars" linking to WP
     * user 1 (Ian, whose handle is now `iandavlin`) — while a DIFFERENT member holds the slug
     * `ianhatesguitars` today. Reader sees one person's handle, click lands on another.
     *
     * $db is retained for signature compatibility with the callers; mentions no longer read
     * forums.person (see bb_mirror__mention_identities for why).
     */
    function bb_mirror_resolve_mentions(string $html, PDO $db): string
    {
        if (trim($html) === '') return $html;

        // 1. @mentions → the member's current handle.
        $anchorRe = '~<a\b([^>]*)>(.*?)</a>~is';
        $uuidRe   = '~data-lg-uuid=([\'"])([0-9a-fA-F-]{36})\1~';
        $wpRe     = '~\{\{mention_user_id_(\d+)\}\}~';

        if (preg_match_all($anchorRe, $html, $ms, PREG_SET_ORDER)) {
            $wpIds = [];
            $uuids = [];
            foreach ($ms as $m) {
                if (preg_match($uuidRe, $m[1], $u))       $uuids[] = strtolower($u[2]);
                elseif (preg_match($wpRe, $m[1], $w))     $wpIds[] = (int)$w[1];
            }

            if ($wpIds || $uuids) {
                $ident = bb_mirror__mention_identities($wpIds, $uuids);

                $html = preg_replace_callback($anchorRe, function ($m) use ($ident, $uuidRe, $wpRe) {
                    if (preg_match($uuidRe, $m[1], $u)) {
                        $who = $ident['uuid'][strtolower($u[2])] ?? null;
                    } elseif (preg_match($wpRe, $m[1], $w)) {
                        $who = $ident['wp'][(int)$w[1]] ?? null;
                    } else {
                        return $m[0];   // an ordinary link — not ours, leave it exactly as-is
                    }

                    // Member gone, or a private profile whose /u/ page 404s for everyone else:
                    // render the handle as plain text. A mention must never become a dead link.
                    if (!$who || empty($who['slug']) || !empty($who['private'])) {
                        $txt = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'));
                        $txt = '@' . ltrim($txt, '@');
                        return htmlspecialchars($txt === '@' ? '@member' : $txt, ENT_QUOTES, 'UTF-8');
                    }

                    // Keep class="bp-suggestions-mention": bb_mirror_format_snippet() keys its
                    // mention detection on it (→ class="bb-mention"), which the anon leak-scrub
                    // then keys on in turn. Dropping it would silently un-gate handles to anon.
                    $slug = (string)$who['slug'];
                    return '<a class="bp-suggestions-mention" href="'
                         . htmlspecialchars('/u/' . rawurlencode($slug), ENT_QUOTES)
                         . '" rel="nofollow">@'
                         . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '</a>';
                }, $html) ?? $html;
            }
        }

        // A placeholder loose in the text (not inside an anchor) would leak raw braces onto
        // the page. Nothing should emit one; neutralise rather than render '{{…}}'.
        if (str_contains($html, '{{mention_user_id_')) {
            $html = preg_replace($wpRe, '', $html) ?? $html;
        }

        // 2. Auto-link bare URLs — but ONLY in the segments OUTSIDE existing
        //    <a>…</a>. BuddyBoss bodies sometimes store <a href=url>url</a>;
        //    linkifying that anchor's text would nest anchors (malformed: the
        //    HTML parser then splits it into an empty + a real anchor).
        $linkify = function (string $seg): string {
            return preg_replace_callback(
                '~(^|[\s>])(https?://[^\s<>"]+)~i',
                function ($mm) {
                    $url = $mm[2];
                    $trail = '';
                    while ($url !== '' && strpbrk(substr($url, -1), '.,;:!?)') !== false) {
                        $trail = substr($url, -1) . $trail;
                        $url   = substr($url, 0, -1);
                    }
                    return $mm[1] . '<a href="' . htmlspecialchars($url, ENT_QUOTES)
                         . '" target="_blank" rel="noopener nofollow">'
                         . htmlspecialchars($url, ENT_QUOTES) . '</a>' . $trail;
                },
                $seg
            );
        };
        $parts = preg_split('~(<a\b[^>]*>.*?</a>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $seg) {
            if ($i % 2 === 0) $parts[$i] = $linkify($seg); // even = outside anchors
        }
        $html = implode('', $parts);

        return $html;
    }
}

if (!function_exists('bb_mirror_paragraphs')) {
    /**
     * Display-time paragraph reconstruction for raw-newline content_html.
     *
     * The PG `content_html` column is MIXED: most rows carry <p> paragraphs, but a
     * minority of legacy BuddyBoss imports are raw text with \r\n line breaks and
     * NO block tags — those render as a "wall of text" because HTML collapses
     * newlines to whitespace. This is a RENDER-time fix only: NOT a data migration
     * and NOT the reverted sync-time wpautop. If the html already carries a
     * block-level tag we return it untouched (covers the rows that are fine); a row
     * with no newlines is left alone (nothing to rebuild). Otherwise blank-line-
     * delimited blocks become <p> and remaining single newlines become <br>.
     * Inline markup (already-resolved mentions, auto-linked URLs, <a>/<strong>/…)
     * is preserved. Idempotent: re-running on its own output is a no-op (the <p>
     * makes the block-tag guard trip).
     */
    function bb_mirror_paragraphs(string $html): string
    {
        if ($html === '') return $html;
        // No line breaks anywhere → nothing to reconstruct (covers single-line and
        // fully-tag-structured-on-one-line bodies).
        if (strpos($html, "\n") === false && strpos($html, "\r") === false) {
            return $html;
        }
        $t = str_replace(["\r\n", "\r"], "\n", $html);

        // Tokenise into existing BLOCK elements (left untouched) and the raw text
        // BETWEEN them. Crucial for MIXED rows: BuddyBoss bodies are often raw
        // \n\n text with a block chunk appended (e.g. an "<p>Images</p>" gallery) —
        // bailing on the mere presence of a block tag left the raw body collapsed
        // (topic 71640). We only paragraph-wrap the raw text segments; anything
        // already inside a block element is preserved verbatim. Non-nested blocks
        // (the shape BuddyBoss emits); inline tags (<a>/<strong>/<img>) stay inside
        // the text run and get wrapped with it.
        $blockEl = '(?:<(?:p|h[1-6]|blockquote|pre|figure|ul|ol|table|div)\b[^>]*>.*?'
                 . '</(?:p|h[1-6]|blockquote|pre|figure|ul|ol|table|div)>|<(?:hr|br)\s*/?>)';
        $parts = preg_split('~(' . $blockEl . ')~is', $t, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) return $html;

        $out = '';
        foreach ($parts as $seg) {
            if ($seg === '') continue;
            // A captured block element → keep as-is.
            if (preg_match('~^\s*(?:<(?:p|h[1-6]|blockquote|pre|figure|ul|ol|table|div)\b|<(?:hr|br)\s*/?>)~i', $seg)) {
                $out .= $seg;
                continue;
            }
            // Raw text run → blank lines become <p>, single newlines become <br>.
            if (trim($seg) === '') continue;
            foreach (preg_split('/\n[ \t]*\n+/', trim($seg)) as $block) {
                $block = trim($block);
                if ($block === '') continue;
                $out .= '<p>' . str_replace("\n", "<br>\n", $block) . "</p>\n";
            }
        }
        return $out !== '' ? $out : $html;
    }
}

if (!function_exists('bb_mirror_format_snippet')) {
    /**
     * Teaser-safe formatted excerpt: resolves mentions + URLs (via
     * bb_mirror_resolve_mentions) then walks the DOM emitting ONLY text and
     * <a> anchors (mentions + links), truncated to ~$limit visible chars. All
     * other tags are dropped (their text flows inline). Output is safe HTML.
     */
    function bb_mirror_format_snippet(string $html, int $limit, PDO $db, bool $preserve_breaks = false): string
    {
        $html = bb_mirror_resolve_mentions($html, $db);
        if (trim($html) === '') return '';

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__bbroot">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $root = $doc->getElementById('__bbroot');
        if (!$root) return '';

        $budget = $limit;
        $out    = '';

        $take = function (string $t) use (&$budget): string {
            if ($budget <= 0 || $t === '') return '';
            if (mb_strlen($t) > $budget) {
                $t = rtrim(mb_substr($t, 0, $budget)) . '…';
                $budget = 0;
            } else {
                $budget -= mb_strlen($t);
            }
            return $t;
        };

        $walk = function ($node) use (&$walk, &$out, &$budget, $take, $preserve_breaks) {
            foreach ($node->childNodes as $c) {
                if ($budget <= 0) return;
                if ($c->nodeType === XML_TEXT_NODE) {
                    $out .= htmlspecialchars($take($c->nodeValue), ENT_QUOTES, 'UTF-8');
                } elseif ($c->nodeType === XML_ELEMENT_NODE) {
                    $tag = strtolower($c->nodeName);
                    if ($tag === 'a') {
                        $href = $c->getAttribute('href');
                        $txt  = $take($c->textContent);
                        $isMention = str_contains($c->getAttribute('class'), 'bp-suggestions-mention')
                                  || str_starts_with(ltrim($c->textContent), '@');
                        if ($href !== '' && bb_mirror__safe_href($href)) {
                            $cls = $isMention ? ' class="bb-mention"' : '';
                            $ext = str_starts_with($href, 'http') ? ' target="_blank" rel="noopener nofollow"' : '';
                            $out .= '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '"' . $cls . $ext . '>'
                                  . htmlspecialchars($txt, ENT_QUOTES, 'UTF-8') . '</a>';
                        } else {
                            $out .= htmlspecialchars($txt, ENT_QUOTES, 'UTF-8');
                        }
                    } else {
                        $is_block = in_array($tag, ['p', 'br', 'li', 'blockquote', 'div'], true);
                        // Break ENTERING a block (so "text<div>more</div>" → "text<br>more",
                        // not "textmore"). Only when content already precedes it.
                        if ($preserve_breaks && $is_block && $out !== '') {
                            $out .= '<br>';
                        }
                        $walk($c);
                        // ...and leaving it. Old callers just get a space (no structure).
                        if ($budget > 0 && $is_block) {
                            $out .= $preserve_breaks ? '<br>' : ' ';
                        }
                    }
                }
            }
        };
        $walk($root);

        if ($preserve_breaks) {
            $out = preg_replace('/(?:<br>\s*){3,}/', '<br><br>', $out);   // cap runs of breaks
            $out = preg_replace('/^(?:<br>\s*)+/', '', $out);             // no leading break
            $out = preg_replace('/(?:<br>\s*)+$/', '', $out);             // no trailing break
        }
        return trim($out);
    }
}

if (!function_exists('bb_mirror_render_reply_stub')) {
    /**
     * Render one .reply-stub row.
     *
     * @param array       $r               keys: reply_id, author_name, author_slug, excerpt, created_at, reply_image_url
     * @param bool        $is_child        indented child styling (one tier — the tree is flattened to 2 visual levels)
     * @param bool        $collapse_image  hide+defer the image behind a "Show image" button (loads on click)
     * @param bool        $show_reply_btn  emit a per-reply "Reply" button (opens the reply modal nested)
     * @param string|null $reply_to_author for depth ≥ 2: the parent author, shown as a "↪ @name" prefix
     */
    function bb_mirror_render_reply_stub(array $r, bool $is_child = false, bool $collapse_image = false, bool $show_reply_btn = false, ?string $reply_to_author = null): void
    {
        // Defense-in-depth discussion-visibility mask (the reply identity choke point).
        // Callers scrub member-only authors at data-prep (so the "↪ @parent" deep-reply
        // prefix is masked too); this re-applies for any caller that carries
        // discussion_visibility but didn't pre-mask. Idempotent — skips if already
        // masked, and logged-in / 'public' rows fall straight through (zero cost).
        if (empty($r['_visibility_masked']) && function_exists('lg_bb_mirror_mask_visibility')) {
            lg_bb_mirror_mask_visibility($r, lg_bb_mirror_can_post());
        }
        $ra          = htmlspecialchars($r['author_name'] ?: 'Anonymous');
        $rslug       = $r['author_slug'] ?? null;
        $raw_text    = trim(strip_tags($r['excerpt'] ?? ''));
        // Logged-out contact scrub (Ian 2026-06-10): emails + @handles never
        // reach anonymous eyes. See _anon-scrub.php.
        if (function_exists('lg_bb_mirror_can_post') && !lg_bb_mirror_can_post()) {
            require_once __DIR__ . '/../_anon-scrub.php';
            $raw_text = lg_scrub_anon_contacts($raw_text);
        }
        $reply_short = mb_substr($raw_text, 0, 160);
        $reply_rest  = mb_strlen($raw_text) > 160 ? mb_substr($raw_text, 160) : '';
        $rtime_r     = $r['created_at'] ? feed_rel_time((string)$r['created_at']) : '—';
        $av_slug     = $rslug ?: ($r['author_name'] ?: 'anonymous');
        $classes     = 'reply-stub' . ($is_child ? ' reply-stub--child' : '');
        $rid_attr    = isset($r['reply_id']) ? ' data-reply-id="' . (int)$r['reply_id'] . '"' : '';
        // Reply author's WP user id (forums.person.id IS the WP user id) — lets the
        // client mark the viewer's OWN rows so they can edit/delete them (Ian
        // 2026-06-11). Absent on masked-anon rows; that's fine (anon replies retired).
        $aid_attr    = !empty($r['author_id']) ? ' data-author-id="' . (int)$r['author_id'] . '"' : '';
        echo '<div class="' . $classes . '"' . $rid_attr . $aid_attr . '>';
        echo '<div class="reply-stub__head">';
        echo bb_mirror_avatar($r['author_name'] ?: 'Anonymous', $av_slug, $is_child ? 22 : 28, $r['avatar_url'] ?? null);
        if ($rslug) {
            echo '<a class="reply-stub__author" href="/u/' . rawurlencode((string)$rslug) . '">' . $ra . '</a>';
        } else {
            echo '<span class="reply-stub__author">' . $ra . '</span>';
        }
        // Admin/mod reveal (anon-rebuild lane): is_anon replies keep the real author
        // for moderators + this marker; for non-mods the row was scrubbed upstream
        // (author_name "Anonymous", slug/avatar absent) so identity never reaches here.
        if (!empty($r['_anon_revealed'])) {
            echo ' <span class="lg-anon-marker" title="This member chose to post anonymously">(posted anonymously)</span>';
        }
        echo '<time class="reply-stub__time">' . $rtime_r . '</time>';
        if ($show_reply_btn && isset($r['reply_id']) && lg_bb_mirror_can_post()) {
            echo '<button class="reply-stub__reply" type="button"'
               . ' data-reply-to="' . (int)$r['reply_id'] . '"'
               . ' data-reply-to-author="' . htmlspecialchars($r['author_name'] ?: 'Anonymous', ENT_QUOTES) . '"'
               // slug = the BuddyBoss nicename → seeds a real @mention in the composer
               // (BB parses @slug on save → mention + notification). Omit if anon.
               . ($rslug ? ' data-reply-to-slug="' . htmlspecialchars((string)$rslug, ENT_QUOTES) . '"' : '')
               . '>&#8617; Reply</button>';
        }
        // Moderator Edit + Trash — emitted for every reply, revealed only under
        // .feed--can-moderate (set client-side when auth says can_edit_others).
        // The PUT/DELETE endpoints re-check caps server-side regardless of the UI.
        // Logged-out viewers never edit: skip the buttons AND the data-reply-raw
        // payload (it carried the raw body incl. contact handles — Ian 2026-06-10).
        if (isset($r['reply_id']) && (!function_exists('lg_bb_mirror_can_post') || lg_bb_mirror_can_post())) {
            // Carry the COMPLETE stored body (not the truncated stub excerpt) so the
            // inline Quill edit editor can load + round-trip the real reply. Raw HTML
            // (mention placeholders unresolved — they round-trip as-is and re-resolve
            // on render); falls back to the excerpt if no content_html was passed.
            $edit_raw = (string)($r['content_html'] ?? '');
            echo '<button class="reply-stub__edit" type="button" data-reply-id="' . (int)$r['reply_id'] . '"'
               . ($edit_raw !== '' ? ' data-reply-raw="' . htmlspecialchars($edit_raw, ENT_QUOTES) . '"' : '')
               . ' title="Edit reply" aria-label="Edit reply">&#9998;</button>';
            echo '<button class="reply-stub__trash" type="button" data-reply-id="' . (int)$r['reply_id'] . '"'
               . ' title="Trash reply" aria-label="Trash reply">&#128465;</button>';
        }
        echo '</div>';
        echo '<div class="reply-stub__body">';
        if ($reply_to_author !== null && $reply_to_author !== '') {
            echo '<span class="reply-stub__reply-to">&#8618; @' . htmlspecialchars($reply_to_author) . '</span> ';
        }
        if (!empty($r['excerpt_html'])) {
            // Pre-formatted by the caller (bb_mirror_format_snippet): resolved
            // @mentions + clickable URLs, already truncated + safe HTML.
            $lg_xh = (string)$r['excerpt_html'];
            if (function_exists('lg_bb_mirror_can_post') && !lg_bb_mirror_can_post()) {
                require_once __DIR__ . '/../_anon-scrub.php';
                // mention anchors leak the handle in text + href — neutralize whole
                $lg_xh = preg_replace('~<a\b[^>]*class="[^"]*bb-mention[^"]*"[^>]*>.*?</a>~is', '@member', $lg_xh) ?? $lg_xh;
                $lg_xh = lg_scrub_anon_contacts($lg_xh);
            }
            echo '<span class="reply-stub__excerpt">' . $lg_xh . '</span>';
        } elseif ($reply_short !== '') {
            echo '<span class="reply-stub__excerpt">' . htmlspecialchars($reply_short);
            if ($reply_rest) {
                echo '<span class="reply-stub__full" hidden>' . htmlspecialchars($reply_rest) . '</span>'
                   . '<button class="reply-stub__expand" type="button">… more</button>';
            }
            echo '</span>';
        }
        if (!empty($r['reply_image_url'])) {
            $iu = htmlspecialchars(lg_cover_src((string)$r['reply_image_url']) ?? '');
            if ($collapse_image) {
                // Teaser context: keep the image hidden AND unloaded (data-src, no
                // src) until the reader opens the reply — keeps the feed card compact.
                echo '<button class="reply-stub__img-open" type="button">&#128247; Show image</button>'
                   . '<img class="reply-stub__img reply-stub__img--deferred" data-src="' . $iu . '" alt="" hidden>';
            } else {
                echo '<img class="reply-stub__img" src="' . $iu . '" alt="" loading="lazy">';
            }
        }
        echo '</div>'; // close .reply-stub__body
        // Reaction bar (ec9a30e: replies are a reactable target). Counts come from
        // the page's batch read stashed by _feed.php / _topic-replies.php; the picker
        // + write are wired generically by forums.js on .fcr (post_type='reply').
        if (isset($r['reply_id']) && function_exists('feed_reactions_bar')) {
            $rid = (int)$r['reply_id'];
            echo '<div class="reply-stub__actions">';
            feed_reactions_bar('reply', $rid, $GLOBALS['__bb_reply_rx']['reply:' . $rid] ?? []);
            echo '</div>';
        }
        echo '</div>'; // close .reply-stub
    }
}

// Reaction-bar renderers — shared here (not in _feed.php) so BOTH the feed teaser
// and the lazy full-thread endpoint (_topic-replies.php, which doesn't load
// _feed.php) can emit identical .fcr markup for reply reactions. Guarded so
// _feed.php's historical definitions (now removed) can't double-declare.
if (!function_exists('feed_rx_glyph')) {
    // One palette reaction's inner glyph (emoji char or static image). Mirrors
    // comments.php's lg_c_rx_glyph so the feed reaction UI matches the modal's.
    function feed_rx_glyph(array $rx): string
    {
        if (($rx['type'] ?? '') === 'image') {
            // NOT lazy: these 18px glyphs render inside the hidden, off-screen
            // .fcr-palette popup — lazy-loading never fires there (no viewport
            // intersection), so a custom image renders blank when the picker opens.
            return '<img class="fcr-img" src="'
                 . htmlspecialchars(LG_REACTIONS_ASSET_BASE . ($rx['file'] ?? ''), ENT_QUOTES)
                 . '" width="18" height="18" alt="">';
        }
        return '<span class="fcr-emoji">' . htmlspecialchars($rx['char'] ?? '') . '</span>';
    }
}

if (!function_exists('feed_reactions_bar')) {
    // Server-render the reaction control: count chips (palette order, non-zero only)
    // + an "add reaction" trigger revealing the full palette. Inert until forums.js
    // wires it; counts render for logged-out viewers too (read-only). No-op when the
    // reactions engine isn't loaded (count read failed → degrade clean).
    function feed_reactions_bar(string $postType, int $itemId, array $counts): void
    {
        if (!function_exists('lg_reactions_palette')) return; // engine read failed → skip
        $palette = lg_reactions_palette();
        $chips = '';
        foreach ($palette as $rx) {
            $n = (int) ($counts[$rx['slug']] ?? 0);
            if ($n <= 0) continue;
            $chips .= '<button type="button" class="fcr-chip" data-slug="' . htmlspecialchars($rx['slug'], ENT_QUOTES)
                    . '" title="' . htmlspecialchars($rx['label'], ENT_QUOTES) . '">' . feed_rx_glyph($rx)
                    . '<span class="fcr-n">' . $n . '</span></button>';
        }
        $opts = '';
        foreach ($palette as $rx) {
            $opts .= '<button type="button" class="fcr-opt" data-slug="' . htmlspecialchars($rx['slug'], ENT_QUOTES)
                   . '" title="' . htmlspecialchars($rx['label'], ENT_QUOTES) . '">' . feed_rx_glyph($rx) . '</button>';
        }
        echo '<div class="fcr" data-post-type="' . htmlspecialchars($postType, ENT_QUOTES)
           . '" data-item-id="' . $itemId . '">'
           . '<span class="fcr-chips">' . $chips . '</span>'
           . '<button type="button" class="fcr-add" aria-label="Add reaction">&#9786;<span>+</span></button>'
           . '<span class="fcr-palette" hidden>' . $opts . '</span>'
           . '</div>';
    }
}


// Save / bookmark toggle — shared here (not _feed.php) so the standalone
// single-topic page (which loads _reply-render.php, NOT _feed.php) can emit the
// same .fc-save button forums.js hydrates. Binary per-card save ->
// discovery.saved_posts via the WP-cookie door (/archive-api/v0/save-post).
// Logged-out viewers get the button but the GET resolves anon -> it stays inert.
// Guarded so _feed.php's historical definition (now removed) can't double-declare.
if (!function_exists('feed_save_btn')) {
    function feed_save_btn(string $postType, int $itemId): void
    {
        static $ICO = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M12 2.6l2.95 5.98 6.6.96-4.77 4.65 1.13 6.57L12 17.66 6.09 20.76l1.13-6.57L2.45 9.54l6.6-.96z"/></svg>';
        echo '<button type="button" class="fc-save" data-save data-post-type="' . htmlspecialchars($postType, ENT_QUOTES)
           . '" data-item-id="' . $itemId . '" aria-pressed="false" aria-label="Save" title="Save">'
           . $ICO . '<span class="fc-save__lbl">Save</span></button>';
    }
}

// Inline SHARE control (desktop feed cards) — a bare [data-share-topic] marker;
// the forums.js desktop SHARE module (window.lgShareTopic) reads the closest card's
// data-share-url (+ .fc-title text) and runs the Web Share API w/ copy-link fallback.
// Desktop-only by CSS (.fc-share shown @ >=641); mobile cards keep hub-polish.js's own
// lg-act-share. Shared here so any card-rendering partial can emit it.
if (!function_exists('feed_share_btn')) {
    function feed_share_btn(): void
    {
        static $ICO = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>';
        echo '<button type="button" class="fc-share" data-share-topic aria-label="Share" title="Share">'
           . $ICO . '<span class="fc-share__lbl">Share</span></button>';
    }
}
