<?php
/**
 * 191-licence-label.php — correct the contradictory Creative Commons description
 * on the three loothprints that stored it. ALL THREE copies.
 *
 *   dry run :  wp --path=/var/www/dev eval-file tools/migrations/191-licence-label.php
 *   apply   :  LG191_APPLY=1 wp --path=/var/www/dev eval-file tools/migrations/191-licence-label.php
 *
 * (sudo strips the environment — use `sudo -u looth-dev env LG191_APPLY=1 wp …`.)
 *
 * ── WHAT THIS IS, AND WHY IT IS SAFE ────────────────────────────────────────
 * The option those three members chose read:
 *
 *     BY ND NC (Credit given to creator, No Derivatives,
 *               Adaptations shared with same terms)
 *
 * "No Derivatives" and "adaptations shared with same terms" contradict each
 * other — the second clause belongs to Share-Alike, a different licence.
 *
 * ⚠️ THE LETTERS WERE ALWAYS RIGHT. BY-NC-ND is a real Creative Commons licence.
 * What was wrong is the English gloss beside the letters, which described terms
 * that licence does not have. So this corrects a DESCRIPTION to match the
 * licence its author actually chose; it does not change anyone's licence. That
 * is the entire basis on which Ian approved it (via keeper, 2026-08-21:
 * "Correct all three, both copies").
 *
 * AND IT IS THE ONLY THING THIS MAY DO. If a row's LETTERS are not BY/NC/ND, or
 * are ambiguous, that would be a different act — changing what someone licensed
 * — so this REFUSES that row, says so, and leaves it exactly as it is.
 *
 * ── THREE COPIES, NOT ONE ───────────────────────────────────────────────────
 * Only 4 of 172 loothprints are synthesized at render; the rest have the licence
 * sentence BAKED into their stored `_lg_layout_v2` blocks. A migration that
 * touched only the postmeta would leave the wrong text still rendering on the
 * page — the copy a member actually reads.
 *
 * ⚠️ AND THERE IS A THIRD, which the first version of this script missed and
 * keeper found: `_lg_layout_v2_rendered_html`, WpRenderer's anon render cache
 * (133 posts carry one; one of our three did). It is NOT served after this runs
 * — updating `_lg_layout_v2` fires `updated_post_meta`, which reaches
 * Plugin::on_post_meta_changed and invalidates — but invalidation only DELETES
 * THE TIMESTAMP (`invalidate_render_cache`, one line), so the stale HTML body
 * sits in the row indefinitely, still holding the contradictory sentence.
 *
 * THE LESSON, WHICH IS BIGGER THAN THIS FIELD: "how many copies of this string
 * are stored" is a question for the DATABASE, not for a reading of the code. The
 * first sweep here asked about two keys it already knew about and reported zero
 * left. Asking `GROUP BY meta_key` over every row holding the string is what
 * found the third — and 17 more in `_elementor_data`, which are out of scope and
 * recorded so the next person does not re-find them and panic.
 *
 * IT IS DELETED, NOT PATCHED. A cache is derived data: a hand-edited cache is a
 * row that agrees with nothing and can silently disagree with its source later.
 * Deleting makes the next anon view regenerate it from the corrected blocks.
 *
 * ── RULES IT KEEPS ──────────────────────────────────────────────────────────
 *  · THREE LITERAL IDS. No LIKE, no glob, no "all posts matching" — keeper's
 *    instruction, and the right one: a pattern that matched more than it should
 *    would rewrite member data nobody authorised.
 *  · IDEMPOTENT. It rewrites only an EXACT match of the legacy string. A post
 *    already correct is reported and left untouched, so a second run is a no-op
 *    and a re-run after a partial failure is safe.
 *  · IT PRINTS BEFORE AND AFTER for every id, in all three stores.
 *  · DRY RUN BY DEFAULT.
 *  · dev2 ONLY. Live writes are Ian's; this script never reaches for another box.
 *    The same three ids on live are handed to him as a command to run.
 *
 * ⚠️ EVERYTHING IS INSIDE ONE FUNCTION. `wp eval-file` runs its file in FUNCTION
 * scope, so a top-level `$TABLE` is not a global and a helper reading it gets
 * null — a recorded box trap that produced green-looking code and zero-row
 * queries. Nothing here relies on file scope.
 *
 * ⚠️ IT DOES NOT USE THE MU-PLUGIN'S lg_fc_licences(). It could, and that would
 * be less duplication — but a migration that reads its target strings from code
 * that is itself being changed cannot be reasoned about after the fact, and a
 * later edit to that table would silently change what this script does to a
 * database. The two strings are written out here, once, deliberately.
 */

(function () {

    // The exact string those three rows hold, and the exact string to write.
    $legacy = 'BY ND NC (Credit given to creator, No Derivatives, Adaptations shared with same terms)';
    $fixed  = 'BY NC ND (Credit given to creator, Non-Commercial only, No Derivatives)';

    $ids   = [33871, 51126, 57824];
    $key   = 'loothprint_creative_commons';
    $lay   = '_lg_layout_v2';
    $cache = '_lg_layout_v2_rendered_html';
    $stamp = '_lg_layout_v2_rendered_at';   // WpRenderer's freshness stamp
    $apply = getenv('LG191_APPLY') === '1';

    echo $apply ? "MODE: APPLY (writing)\n" : "MODE: DRY RUN (nothing is written)\n";
    echo "SITE: " . home_url() . "\n";
    echo str_repeat('=', 78) . "\n";

    /* THE LETTERS GUARD. Read only the token part before the first '(' and
       require exactly {BY, NC, ND} — order-insensitive, because the legacy
       string spells them BY ND NC and the corrected one BY NC ND, and it is the
       SET of letters that says which licence this is. Anything else stops. */
    $letters = function (string $v): array {
        $head = trim(strtok($v, '('));
        $toks = preg_split('/\s+/', strtoupper($head), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($toks);
        return $toks;
    };
    $want = $letters($legacy);          // ['BY','NC','ND']

    $changed = ['meta' => 0, 'layout' => 0, 'cache' => 0];
    $skipped = 0;
    $refused = [];

    foreach ($ids as $id) {
        $post = get_post($id);
        echo "\n#$id  " . ($post ? $post->post_name . '  [' . $post->post_type . '/'
                                   . $post->post_status . ']' : '*** NO SUCH POST ***') . "\n";
        if (!$post) {
            $refused[] = "$id: no such post";
            continue;
        }

        /* ── copy 1: the postmeta ─────────────────────────────────────────── */
        $cur = (string) get_post_meta($id, $key, true);
        echo "  meta   before : " . ($cur === '' ? '(empty)' : $cur) . "\n";

        if ($cur === $fixed) {
            echo "  meta   after  : (unchanged — already correct)\n";
            $skipped++;
        } elseif ($cur !== $legacy) {
            // Not the string we came for. Say why, and touch nothing.
            $got = $letters($cur);
            $why = ($got === $want)
                 ? 'same letters, different wording — NOT the string this migration knows'
                 : 'different licence letters (' . implode(' ', $got) . ')';
            echo "  meta   after  : *** REFUSED *** $why\n";
            $refused[] = "$id meta: $why";
        } else {
            echo "  meta   after  : $fixed\n";
            if ($apply) {
                update_post_meta($id, $key, $fixed);
                $now = (string) get_post_meta($id, $key, true);
                echo "  meta   verify : " . ($now === $fixed ? 'OK' : "*** WROTE $now ***") . "\n";
            }
            $changed['meta']++;
        }

        /* ── copy 2: the baked layout blocks ──────────────────────────────── */
        $raw = get_post_meta($id, $lay, true);
        $doc = is_string($raw) ? maybe_unserialize($raw) : $raw;
        if (!is_array($doc) || !isset($doc['blocks']) || !is_array($doc['blocks'])) {
            echo "  layout        : (no stored v2 layout — synthesized at render)\n";
            continue;
        }

        $hits = 0;
        foreach ($doc['blocks'] as $i => $blk) {
            if (!is_array($blk) || !isset($blk['body']) || !is_string($blk['body'])) {
                continue;
            }
            if (strpos($blk['body'], $legacy) === false) {
                continue;
            }
            /* SCOPED TO THE LICENCE CALLOUT, not to "any block containing the
               string". The synthesizer emits it as callout/note titled License
               (lg-layout-v2 Plugin.php); a block of any other shape holding that
               sentence is prose somebody wrote, and rewriting a member's prose is
               not what was approved. */
            $isLicence = (($blk['type'] ?? '') === 'callout')
                      && (($blk['variant'] ?? '') === 'note')
                      && (strcasecmp(trim((string) ($blk['title'] ?? '')), 'License') === 0);
            if (!$isLicence) {
                echo "  layout        : *** REFUSED *** block[$i] holds the string but is "
                   . "not the licence callout (" . ($blk['type'] ?? '?') . '/'
                   . ($blk['variant'] ?? '?') . "/\"" . ($blk['title'] ?? '') . "\")\n";
                $refused[] = "$id layout block[$i]: not the licence callout";
                continue;
            }
            echo "  layout before : " . $blk['body'] . "\n";
            $doc['blocks'][$i]['body'] = str_replace($legacy, $fixed, $blk['body']);
            echo "  layout after  : " . $doc['blocks'][$i]['body'] . "\n";
            $hits++;
        }

        if ($hits === 0) {
            $already = strpos(wp_json_encode($doc), $fixed) !== false;
            echo "  layout        : (unchanged — " . ($already ? 'already correct' : 'string not present') . ")\n";
        } elseif ($apply) {
            update_post_meta($id, $lay, $doc);
            $back = maybe_unserialize(get_post_meta($id, $lay, true));
            $stillWrong = strpos(wp_json_encode($back), $legacy) !== false;
            echo "  layout verify : " . ($stillWrong ? '*** LEGACY STRING STILL PRESENT ***' : 'OK') . "\n";
            $changed['layout'] += $hits;
        } else {
            $changed['layout'] += $hits;
        }

        /* ── copy 3: WpRenderer's anon render cache ───────────────────────── */
        $html = get_post_meta($id, $cache, true);
        if (!is_string($html) || $html === '') {
            echo "  cache         : (no rendered-html cache on this post)\n";
        } elseif (strpos($html, $legacy) === false) {
            echo "  cache         : (present, " . strlen($html) . " bytes, does not hold the legacy string)\n";
        } else {
            echo "  cache  before : " . strlen($html) . " bytes, HOLDS the legacy string\n";
            echo "  cache  after  : deleted — the next anon view re-renders from the corrected blocks\n";
            if ($apply) {
                delete_post_meta($id, $cache);
                delete_post_meta($id, $stamp);
                $gone = get_post_meta($id, $cache, true) === '';
                echo "  cache  verify : " . ($gone ? 'OK' : '*** STILL PRESENT ***') . "\n";
            }
            $changed['cache']++;
        }
    }

    echo "\n" . str_repeat('=', 78) . "\n";
    printf("%s  meta rows: %d   layout blocks: %d   stale caches: %d   already-correct: %d   refused: %d\n",
           $apply ? 'WROTE   ' : 'WOULD DO', $changed['meta'], $changed['layout'],
           $changed['cache'], $skipped, count($refused));
    foreach ($refused as $r) {
        echo "  REFUSED  $r\n";
    }
    if (!$apply) {
        echo "\nNothing was written. Re-run with LG191_APPLY=1 to apply.\n";
    }
    /* THE STANDING SWEEP. Not scoped to the three ids on purpose: it is the
       answer to "did this migration finish", and it has to be able to find a row
       the id list did not know about. Reported, never acted on. */
    global $wpdb;
    $left = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
        'loothprint_creative_commons', $legacy));
    $leftLayout = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
        '_lg_layout_v2', '%' . $wpdb->esc_like('BY ND NC (Credit given to creator, No Derivatives') . '%'));
    /* ⚠️ ASK THE DATABASE WHICH KEYS HOLD IT, do not list the keys you already
       know about. The first version of this sweep named two keys and reported
       zero left while a third store still held the string. This one groups over
       every row in wp_postmeta, so a copy nobody thought of shows up by itself. */
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, COUNT(*) c, COUNT(DISTINCT post_id) p FROM {$wpdb->postmeta}
         WHERE meta_value LIKE %s GROUP BY meta_key ORDER BY c DESC",
        '%' . $wpdb->esc_like('BY ND NC (Credit given to creator, No Derivatives') . '%'));
    echo "\nWHOLE-SITE SWEEP — every postmeta key still holding the legacy string:\n";
    if (!$rows) {
        echo "  (none)\n";
    }
    foreach ($rows as $r) {
        $mine = in_array($r->meta_key, ['loothprint_creative_commons', '_lg_layout_v2',
                                        '_lg_layout_v2_rendered_html'], true);
        printf("  %-34s %4d row(s) on %d post(s)%s\n", $r->meta_key, $r->c, $r->p,
               $mine ? '   *** THIS MIGRATION SHOULD HAVE CLEARED THIS ***'
                     : '   (not this migration: elementor templates / revisions, out of scope)');
    }
    echo "  named-key check: loothprint_creative_commons=$left  _lg_layout_v2=$leftLayout\n";
})();
