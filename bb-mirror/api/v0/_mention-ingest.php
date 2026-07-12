<?php
/**
 * Mention INGEST — mint the stable storage form for @mentions at write time.
 *
 * The render side (bb_mirror_resolve_mentions) resolves a STABLE reference to the
 * member's CURRENT handle. This is the write side that MINTS that stable reference so a
 * freshly-posted mention carries one. Without it, a member who renames breaks their own
 * FUTURE mentions: BuddyBoss's parser only recognises @<wp-nicename>, so @<new-handle>
 * (a slug that no longer equals the nicename) would never be linked at all.
 *
 * Two inputs, one canonical output:
 *   • plain text  "@kevin-smith"                       (typed by hand, or the composer
 *     autocomplete degraded to text)                    → resolve slug → identity
 *   • an anchor the autocomplete inserted carrying
 *     data-lg-uuid="<uuid>"                             → resolve uuid → identity
 *
 * Both become the ONE proven-kses-safe storage shape (see the username-mentions RESUME:
 * data-lg-uuid + the legacy `{{mention_user_id_N}}` href both survive wp_kses_post()):
 *
 *   <a class="bp-suggestions-mention" data-lg-uuid="<uuid>" href="{{mention_user_id_N}}">@<slug></a>
 *
 * The uuid is the immutable native id the render side keys on; the `{{…}}` href keeps the
 * legacy BuddyBoss forum page rendering the mention as a live link. class is retained
 * because the render + anon-leak-scrub chain keys on it (dropping it un-gates handles to
 * logged-out visitors).
 *
 * Resolution is against profile_app.users.slug — the handle the member CONTROLS — over the
 * loopback mention-resolve endpoint (internal-exempt, no cookie needed). Unresolved tokens
 * (not a real member, archived) are left as literal text: a mention is only minted for a
 * real identity.
 */

declare(strict_types=1);

if (!function_exists('lg_bb_mirror_mint_mentions')) {

    /**
     * Loopback resolve: handles + uuids → identity maps.
     * @return array{slug: array<string,array>, uuid: array<string,array>}
     */
    function lg_bb_mirror__resolve_handles(array $slugs, array $uuids): array
    {
        $slugs = array_values(array_unique(array_filter(array_map('strval', $slugs))));
        $uuids = array_values(array_unique(array_map('strtolower', $uuids)));
        if (!$slugs && !$uuids) return ['slug' => [], 'uuid' => []];

        $qs = [];
        if ($slugs) $qs[] = 'slugs=' . rawurlencode(implode(',', array_slice($slugs, 0, 100)));
        if ($uuids) $qs[] = 'uuids=' . rawurlencode(implode(',', array_slice($uuids, 0, 100)));

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://127.0.0.1/profile-api/v0/mention-resolve?' . implode('&', $qs),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_TIMEOUT        => 4,
            // Loopback is dev-gate authorized (src_local) AND the endpoint is loopback-only
            // in PHP — no cookie to forward, unlike the browser-scoped resolvers.
            CURLOPT_HTTPHEADER     => ['Host: ' . (defined('LG_BB_MIRROR_HOST') ? LG_BB_MIRROR_HOST : 'localhost')],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code !== 200 || !$body) {
            error_log('[lg-mention-ingest] resolve failed http=' . $code);
            return ['slug' => [], 'uuid' => []];
        }
        $data = json_decode((string) $body, true);
        return [
            'slug' => (array) ($data['by_slug'] ?? []),
            'uuid' => (array) ($data['by_uuid'] ?? []),
        ];
    }

    /** Build the ONE canonical, kses-safe storage anchor for a resolved member. */
    function lg_bb_mirror__mention_anchor(array $who): string
    {
        $slug = (string) ($who['slug'] ?? '');
        $uuid = (string) ($who['uuid'] ?? '');
        $wpId = $who['wp_user_id'] ?? null;
        // Legacy BuddyBoss placeholder when we have a WP id (keeps the WP forum page
        // linking it); a native-only member (no bridge) falls back to the real /u/ path,
        // which u.php 301s to the current handle if the member later renames.
        $href = ($wpId !== null && (int) $wpId > 0)
            ? '{{mention_user_id_' . (int) $wpId . '}}'
            : '/u/' . rawurlencode($slug);
        return '<a class="bp-suggestions-mention" data-lg-uuid="'
             . htmlspecialchars($uuid, ENT_QUOTES)
             . '" href="' . htmlspecialchars($href, ENT_QUOTES)
             . '">@' . htmlspecialchars($slug, ENT_QUOTES) . '</a>';
    }

    /**
     * Rewrite @mentions in a submitted reply/topic body into the stable storage shape.
     * No-op when the body carries no '@'. Idempotent: an already-minted anchor re-resolves
     * to the same (current) identity; plain @text inside an existing anchor is left alone.
     */
    function lg_bb_mirror_mint_mentions(string $content): string
    {
        if ($content === '' || strpos($content, '@') === false) return $content;

        // A single @token capture: preceded by start/space/'>'/'('/'[' (so an email's
        // local@domain never matches — the '@' there follows a word char). The slug
        // charset mirrors profile_app; a trailing sentence '.' is trimmed for lookup and
        // preserved after the anchor.
        $tokenRe = '/(^|[\s>(\[])@([A-Za-z0-9][A-Za-z0-9._-]{0,59})/u';
        $uuidRe  = '~data-lg-uuid=([\'"])([0-9a-fA-F-]{36})\1~';

        // Split into anchor / non-anchor segments; only text segments get @token scanning,
        // only data-lg-uuid anchors get re-canonicalised. Ordinary links are untouched.
        $parts = preg_split('~(<a\b[^>]*>.*?</a>)~is', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) return $content;

        // ── Pass 1: gather candidate slugs + uuids across every segment.
        $wantSlugs = [];
        $wantUuids = [];
        foreach ($parts as $i => $seg) {
            if ($i % 2 === 1) {                              // an <a>…</a> segment
                if (preg_match($uuidRe, $seg, $u)) $wantUuids[] = strtolower($u[2]);
                continue;
            }
            if (preg_match_all($tokenRe, $seg, $ms, PREG_SET_ORDER)) {
                foreach ($ms as $m) {
                    $wantSlugs[] = rtrim(mb_strtolower($m[2]), '.');
                }
            }
        }
        if (!$wantSlugs && !$wantUuids) return $content;

        $ident = lg_bb_mirror__resolve_handles($wantSlugs, $wantUuids);
        if (!$ident['slug'] && !$ident['uuid']) return $content;

        // ── Pass 2: rewrite.
        foreach ($parts as $i => $seg) {
            if ($i % 2 === 1) {
                // Re-canonicalise our own autocomplete anchor from the CURRENT identity;
                // a dead uuid degrades to plain @text so it never renders a broken anchor.
                if (preg_match($uuidRe, $seg, $u)) {
                    $who = $ident['uuid'][strtolower($u[2])] ?? null;
                    if ($who && !empty($who['slug'])) {
                        $parts[$i] = lg_bb_mirror__mention_anchor($who);
                    } else {
                        // Dead uuid → emit the visible @handle as plain text, never a broken anchor.
                        $txt = trim(strip_tags($seg));
                        $parts[$i] = htmlspecialchars('@' . ltrim($txt, '@'), ENT_QUOTES, 'UTF-8');
                    }
                }
                continue;
            }
            $parts[$i] = preg_replace_callback($tokenRe, function ($m) use ($ident) {
                $lead = $m[1];
                $raw  = $m[2];
                $key  = rtrim(mb_strtolower($raw), '.');
                $who  = $ident['slug'][$key] ?? null;
                if (!$who || empty($who['slug'])) {
                    return $m[0];                            // not a member — leave literal
                }
                // Re-append any trailing '.' that rtrim treated as sentence punctuation,
                // not part of the slug (the slug charset is ASCII, so strlen is safe).
                $dots = str_repeat('.', max(0, strlen($raw) - strlen($key)));
                return $lead . lg_bb_mirror__mention_anchor($who) . $dots;
            }, $seg) ?? $seg;
        }

        return implode('', $parts);
    }
}
