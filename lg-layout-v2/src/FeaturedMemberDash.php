<?php
/**
 * FeaturedMemberDash — admin authoring surface for backlog 18 (Ian 8/11);
 * all four design rulings recorded 2026-08-14 (docs/IAN-RULINGS-2026-08-14.md
 * item 6, after decide.html).
 *
 * Lives as a submenu under the LG Layout v2 top-level menu, beside
 * ArchivePocDash (same parent, same capability). Reads from TWO loopback
 * sources and writes to ONE:
 *
 *   READ  https://127.0.0.1/profile-api/v0/internal/featured-pool
 *         (profile-app pool: everyone who opted in + their completeness)
 *         Header: X-LG-Internal-Auth: <contents of /etc/lg-internal-secret>
 *
 *   READ  https://127.0.0.1/archive-api/v0/_featured-history
 *         (archive-poc history: every past + current stint)
 *         Header: X-LG-Config-Secret: <contents of /etc/lg-archive-poc-secret>
 *
 *   WRITE https://127.0.0.1/archive-api/v0/_config
 *         Body: { "featured_member": { "enabled", "member_uuid", "name",
 *                 "role", "where", "bio", "cta_href", "cta_label",
 *                 "chosen_by" } }
 *         Same webhook ArchivePocDash already writes to — its own
 *         history-tracking block (see _config.php) is what turns this POST
 *         into a featured_history row. ONE write path, by construction.
 *
 * ONE AT A TIME (ruling): Feature closes whatever is currently open (the
 * webhook's own logic) and opens the new selection — there is no running
 * order, no queue, nothing else to manage here.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class FeaturedMemberDash
{
    public const PARENT_SLUG = Dash::MENU_SLUG;           // 'lg-layout-v2'
    public const PAGE_SLUG   = 'lg-featured-member';
    public const CAPABILITY  = 'manage_options';
    public const NONCE_ACTION = 'lg_featured_member_dash';
    public const FEATURE_ACTION = 'lg_featured_member_feature';
    public const REMOVE_ACTION  = 'lg_featured_member_remove';

    public const CONFIG_SECRET_FILE   = '/etc/lg-archive-poc-secret';
    public const INTERNAL_SECRET_FILE = '/etc/lg-internal-secret';
    public const CONFIG_WEBHOOK_URL   = 'https://127.0.0.1/archive-api/v0/_config';
    // .php IS the URL — unlike CONFIG_WEBHOOK_URL below, no extensionless
    // rewrite exists for this endpoint (found in review 2026-08-15: the
    // extensionless form 404s). CONFIG_WEBHOOK_URL's own rewrite is legacy
    // debt from ArchivePocDash, not a pattern to repeat — simplest fix is to
    // just address the endpoint nginx actually serves.
    public const HISTORY_URL          = 'https://127.0.0.1/archive-api/v0/_featured-history.php';
    public const POOL_URL             = 'https://127.0.0.1/profile-api/v0/internal/featured-pool';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'register_page'], 12);
        add_action('admin_post_' . self::FEATURE_ACTION, [self::class, 'handle_feature']);
        add_action('admin_post_' . self::REMOVE_ACTION,  [self::class, 'handle_remove']);
    }

    public static function register_page(): void
    {
        add_submenu_page(
            self::PARENT_SLUG,
            'Featured Member',
            'Featured Member',
            self::CAPABILITY,
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    /* ── Host resolution — same detection ArchivePocDash uses, deliberately
       not shared code (that class isn't a dependency of this one; copying a
       four-line, already-proven check is cheaper than coupling two admin
       pages together for it). ─────────────────────────────────────────── */
    private static function resolve_host(): string
    {
        if (defined('LG_ARCHIVE_POC_DASH_HOST')) return (string) constant('LG_ARCHIVE_POC_DASH_HOST');
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (str_contains($host, 'dev.') || str_contains($host, 'dev2.') || str_contains($host, 'claude.loothgroup')) {
            return $host;
        }
        return 'loothgroup.com';
    }

    private static function read_file_secret(string $path): string
    {
        if (!is_readable($path)) return '';
        return trim((string) @file_get_contents($path));
    }

    /* ── Reads ─────────────────────────────────────────────────────────── */

    private static function fetch_current(): array
    {
        $resp = wp_remote_get(self::CONFIG_WEBHOOK_URL . '?effective=1', [
            'timeout' => 3, 'sslverify' => false,
            'headers' => ['Host' => self::resolve_host()],
        ]);
        if (is_wp_error($resp)) return [];
        $j = json_decode((string) wp_remote_retrieve_body($resp), true);
        return is_array($j) ? $j : [];
    }

    private static function fetch_pool(): array
    {
        $secret = self::read_file_secret(self::INTERNAL_SECRET_FILE);
        if ($secret === '') return [];
        $resp = wp_remote_get(self::POOL_URL, [
            'timeout' => 3, 'sslverify' => false,
            'headers' => ['Host' => self::resolve_host(), 'X-LG-Internal-Auth' => $secret],
        ]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) return [];
        $j = json_decode((string) wp_remote_retrieve_body($resp), true);
        return is_array($j['pool'] ?? null) ? $j['pool'] : [];
    }

    private static function fetch_history(): array
    {
        $secret = self::read_file_secret(self::CONFIG_SECRET_FILE);
        if ($secret === '') return [];
        $resp = wp_remote_get(self::HISTORY_URL, [
            'timeout' => 3, 'sslverify' => false,
            'headers' => ['Host' => self::resolve_host(), 'X-LG-Config-Secret' => $secret],
        ]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) return [];
        $j = json_decode((string) wp_remote_retrieve_body($resp), true);
        return is_array($j['history'] ?? null) ? $j['history'] : [];
    }

    /* ── Writes ────────────────────────────────────────────────────────── */

    private static function post_config(array $featuredMember): array
    {
        $secret = self::read_file_secret(self::CONFIG_SECRET_FILE);
        if ($secret === '') return ['ok' => false, 'error' => 'webhook secret not readable'];
        $resp = wp_remote_post(self::CONFIG_WEBHOOK_URL, [
            'method' => 'POST', 'timeout' => 4, 'sslverify' => false,
            'headers' => [
                'Host' => self::resolve_host(), 'Content-Type' => 'application/json',
                'X-LG-Config-Secret' => $secret,
            ],
            'body' => wp_json_encode(['featured_member' => $featuredMember]),
        ]);
        if (is_wp_error($resp)) return ['ok' => false, 'error' => $resp->get_error_message()];
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'webhook HTTP ' . $code . ' — ' . (string) wp_remote_retrieve_body($resp)];
        }
        return ['ok' => true];
    }

    /* ── THE SELECTION RULE, in one place both callers share ──────────────
       Ian, 2026-08-19 (#107): "opted in only". Consent is the tickbox — it is
       the pool's own WHERE clause, so anyone reaching this array has given it
       — and privacy is the live state the member can change at any time.
       NOTHING ELSE BLOCKS. Profile completion in particular does not: that was
       the wall he overruled.

       Returns null when the member may be featured, else the reason, ready to
       show. Kept free of every WP function on purpose so gate 39 §F can
       execute the shipped rule directly instead of pattern-matching the file
       for a refusal that may have moved. */
    public static function selection_block_reason(array $member): ?string
    {
        if (empty($member['eligible'])) return 'profile is Private';
        return null;
    }

    /** Blocker key => how the dash says it. Mirrors the pool's card_blockers. */
    private const CARD_BLOCKER_LABELS = [
        'photo'       => 'a photo',
        'what_you_do' => 'a one-line “what you do”',
    ];

    /* ── WILL THE FRONT PAGE ACTUALLY SHOW THIS PICK? ─────────────────────
       Information, never permission — a member with a warning is still
       selectable, which is the whole point of #107. This reads the pool's
       card_renderable/card_blockers, which reproduce the resolver's own guard
       (lg_resolve_featured_member); it deliberately does NOT read card_ready,
       which answers a different question and gets this wrong in both
       directions — it demands a location the card does not need, and cannot
       see the header visibility the card's role depends on. On dev2 2026-08-20
       that gap was 4 members wide: card_ready true, no band.

       An ABSENT card_renderable key is not `false`. It means the pool endpoint
       is older than this dash (a half-finished deploy), and the honest answer
       to "what will the front page do" is then "unknown" — so say nothing
       rather than warn about a card that may be perfectly fine. */
    public static function card_warning(array $member): ?string
    {
        if (!array_key_exists('card_renderable', $member)) return null;
        if (!empty($member['card_renderable'])) return null;

        $blockers = is_array($member['card_blockers'] ?? null) ? $member['card_blockers'] : [];
        $parts = [];
        foreach ($blockers as $b) {
            $parts[] = self::CARD_BLOCKER_LABELS[$b] ?? $b;
        }
        $missing = $parts ? implode(' and ', $parts) : 'a photo or a one-line “what you do”';
        $name = trim((string) ($member['display_name'] ?? 'This member'));

        return 'Featured — but the front-page band will stay hidden until '
             . ($name !== '' ? $name : 'this member') . ' adds ' . $missing . '. '
             . 'The selection is saved and will appear the moment they do.';
    }

    public static function handle_feature(): void
    {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', '', ['response' => 403]);
        check_admin_referer(self::NONCE_ACTION);

        $uuid = isset($_POST['member_uuid']) ? sanitize_text_field(wp_unslash((string) $_POST['member_uuid'])) : '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            self::redirect_back('invalid%20member');
            return;
        }

        // Find this member in the pool (not trusted from the form alone — the
        // pool call re-confirms opted-in AND resolves the display fields the
        // history row snapshots). A member who is opted-in-but-ineligible
        // (private profile) is present in the pool with eligible:false; that
        // is checked here too, so Feature can never fire on a row the dash
        // itself showed as unavailable.
        $pool = self::fetch_pool();
        $member = null;
        foreach ($pool as $p) {
            if (isset($p['uuid']) && strcasecmp((string) $p['uuid'], $uuid) === 0) { $member = $p; break; }
        }
        if ($member === null) {
            self::redirect_back('member%20not%20eligible');
            return;
        }
        // ONE rule, one caller — the render below asks the same function, so
        // the button an admin sees and the refusal this handler can give can
        // never drift apart (and gate 39 §F executes this exact function).
        $blocked = self::selection_block_reason($member);
        if ($blocked !== null) {
            self::redirect_back(urlencode($blocked));
            return;
        }
        // ── NO COMPLETENESS WALL. Ian, 2026-08-19 (#107): "I'd also like to be
        // able to select a member for features even if they don't hit the
        // completion numbers... the dash should allow me to select anyone",
        // clarified the same night to "opted in only". Consent is the ONE hard
        // gate; profile completion is information for his judgement and never
        // a refusal. The card_ready check that stood here is gone — see
        // selection_block_reason(), which is now the whole of the rule.
        //
        // WHAT REPLACED IT, AND WHY IT IS NOT THE SAME CHECK. The front-page
        // resolver keeps a guard of its own (lg_resolve_featured_member: no
        // avatar or no role => return null, no band), and it must, because the
        // card's template renders both unconditionally. Dropping the refusal
        // without saying anything would have turned a legible "no" into a
        // silent one: "Saved and pushed to archive-poc" followed by an empty
        // front page. MEASURED, not feared — 2026-08-20 on dev2, featuring
        // Rick Liftig (whom this dash called "Ready", enabled button and all)
        // through this very handler took the band off the front page entirely,
        // 74,456 bytes to 72,838, zero lg-fm__ markers.
        //
        // So the selection still goes through — always — and the admin is TOLD
        // what the front page will do with it. card_renderable is the pool's
        // report of the resolver's own verdict, which card_ready cannot stand
        // in for (it tests location, which the card does not need, and cannot
        // see header visibility, which the card's role does).
        $warn = self::card_warning($member);

        $user = wp_get_current_user();
        $res = self::post_config([
            'enabled'    => true,
            'member_uuid' => $uuid,
            'name'       => (string) $member['display_name'],
            'role'       => (string) $member['tagline'],
            // where/bio/cta_href/cta_label are NOT set here — index.php
            // re-resolves the live card from profile_app on every request
            // ("live, not frozen"). name/role are stored only as the
            // featured_history snapshot's source (see _config.php).
            'chosen_by'  => $user && $user->user_login ? $user->user_login : 'wp-admin',
        ]);
        // The warning rides only on a SUCCESSFUL save — a failed write already
        // has an error to show, and stacking "it did not save" with "and it
        // would not have rendered" buries the one the admin must act on.
        self::redirect_back(
            $res['ok'] ? '' : urlencode((string) $res['error']),
            $res['ok'],
            $res['ok'] && $warn !== null ? urlencode($warn) : ''
        );
    }

    public static function handle_remove(): void
    {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', '', ['response' => 403]);
        check_admin_referer(self::NONCE_ACTION);

        $current = self::fetch_current();
        $uuid = (string) ($current['featured_member']['member_uuid'] ?? '');
        $res = self::post_config(['enabled' => false, 'member_uuid' => $uuid]);
        self::redirect_back($res['ok'] ? '' : urlencode((string) $res['error']), $res['ok']);
    }

    private static function redirect_back(string $error, bool $updated = false, string $warn = ''): void
    {
        $url = add_query_arg(
            array_filter([
                'page'    => self::PAGE_SLUG,
                'updated' => $updated ? '1' : null,
                'err'     => $error !== '' ? $error : null,
                'warn'    => $warn !== '' ? $warn : null,
            ]),
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    /* ── Render ────────────────────────────────────────────────────────── */

    public static function render_page(): void
    {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden');

        $current = self::fetch_current();
        $pool    = self::fetch_pool();
        $history = self::fetch_history();
        $fm      = is_array($current['featured_member'] ?? null) ? $current['featured_member'] : [];
        $currentUuid = (string) ($fm['member_uuid'] ?? '');
        $isLiveReal  = !empty($fm['enabled']) && $currentUuid !== '';

        $notice = isset($_GET['updated']) ? (string) $_GET['updated'] : '';
        $error  = isset($_GET['err'])     ? (string) $_GET['err']     : '';
        $warn   = isset($_GET['warn'])    ? (string) $_GET['warn']    : '';
        $nonce  = wp_create_nonce(self::NONCE_ACTION);
        $postUrl = admin_url('admin-post.php');

        echo '<div class="wrap"><h1>Featured Member</h1>';
        echo '<p class="description">Members who have ticked &ldquo;include me as a possible featured member&rdquo; on their own profile. '
           . 'Featuring someone puts them on the front page immediately; removing them takes the band down. One at a time.</p>';

        // A warned save is NOT a plain success — saying only "Saved and pushed"
        // over a pick that renders nothing is the exact lie #107's measurement
        // caught the old dash telling (Rick Liftig, 2026-08-20). When there is
        // a warning it REPLACES the success notice rather than sitting under
        // it, so there is one unambiguous answer to "what did that just do".
        if ($notice === '1' && $warn === '') {
            echo '<div class="notice notice-success is-dismissible"><p>Saved and pushed to archive-poc.</p></div>';
        }
        if ($warn !== '') {
            echo '<div class="notice notice-warning"><p><strong>Saved.</strong> ' . esc_html(urldecode($warn)) . '</p></div>';
        }
        if ($error !== '')   echo '<div class="notice notice-error"><p><strong>Error:</strong> ' . esc_html(urldecode($error)) . '</p></div>';

        if ($isLiveReal) {
            $liveRow = null;
            foreach ($pool as $p) { if (strcasecmp((string) $p['uuid'], $currentUuid) === 0) { $liveRow = $p; break; } }
            $liveName = $liveRow ? (string) $liveRow['display_name'] : (string) ($fm['name'] ?? '(unknown)');
            echo '<div class="notice notice-info"><p><strong>On the front page now:</strong> ' . esc_html($liveName) . '</p></div>';
        } else {
            echo '<div class="notice notice-warning"><p><strong>No one is on the front page.</strong> The featured-member band is not rendering a real member'
               . (!empty($fm['enabled']) ? ' — the hand-typed fallback shows instead.' : '.') . '</p></div>';
        }

        echo '<h2>Selectable pool <span style="font-weight:400;color:#646970">&mdash; ' . count($pool) . ' member' . (count($pool) === 1 ? '' : 's') . ' opted in</span></h2>';
        if (empty($pool)) {
            echo '<div style="background:#fff;border:1px dashed #c3c4c7;border-radius:4px;padding:26px 18px;text-align:center;color:#646970">'
               . '<strong style="display:block;color:#1d2327;margin-bottom:4px">Nobody has opted in yet.</strong>'
               . 'Members join this list by ticking &ldquo;include me as a possible featured member&rdquo; on their own profile.</div>';
        } else {
            // "Profile" is a NUMBER SHOWN, not a gate (#107). "Card" answers the
            // only question that still has an operational consequence: will the
            // front page render this pick? Both are information; neither
            // disables anything.
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
               . '<th>Member</th><th>What they do</th><th>Profile</th><th>Where</th><th>Card</th><th></th></tr></thead><tbody>';
            foreach ($pool as $p) {
                $isCurrent = strcasecmp((string) $p['uuid'], $currentUuid) === 0 && $isLiveReal;
                $pct = (int) ($p['completeness']['pct'] ?? 0);
                $blocked  = self::selection_block_reason($p);
                $cardWarn = self::card_warning($p);
                echo '<tr' . ($isCurrent ? ' style="background:#fcf9e8"' : '') . '>';
                echo '<td><strong>' . esc_html((string) $p['display_name']) . '</strong><br><span style="color:#787c82;font-size:11.5px">/u/' . esc_html((string) $p['slug']) . '</span></td>';
                echo '<td>' . esc_html((string) $p['tagline']) . '</td>';
                echo '<td>' . $pct . '%</td>';
                echo '<td>' . esc_html((string) $p['location']) . '</td>';
                // $isCurrent is checked FIRST, ahead of eligibility. Found in
                // review 2026-08-15: eligibility can change AFTER a member is
                // featured (they can go Private at any time) — the ORIGINAL
                // order put "not eligible" first, so a currently-featured
                // member who went Private lost their Remove button entirely,
                // with no way to formally close out that stint (the history
                // row would stay open forever). Being the one currently live
                // always wins, regardless of whether they could be newly
                // selected right now.
                if ($isCurrent) {
                    echo '<td><span class="lg-fm-tag" style="background:#edf7ee;color:#1a6b2a;border-radius:3px;padding:3px 7px;font-size:11px;font-weight:600">On now</span></td>';
                    echo '<td><form method="post" action="' . esc_url($postUrl) . '">'
                       . '<input type="hidden" name="action" value="' . esc_attr(self::REMOVE_ACTION) . '">'
                       . '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">'
                       . '<button type="submit" class="button">Remove from front page</button></form></td>';
                } elseif ($blocked !== null) {
                    // The ONE remaining wall, and it is consent/privacy — never
                    // completion (#107).
                    echo '<td colspan="2" style="color:#646970"><strong>Not currently eligible</strong> — '
                       . esc_html($blocked) . '.</td>';
                } else {
                    // EVERY opted-in, public member gets a live button, however
                    // incomplete their profile. A card that cannot render yet is
                    // LABELLED, not disabled — Ian's call to make, with the
                    // consequence stated at the point he makes it.
                    if ($cardWarn !== null) {
                        $missing = array_map(
                            static fn($b) => self::CARD_BLOCKER_LABELS[$b] ?? $b,
                            is_array($p['card_blockers'] ?? null) ? $p['card_blockers'] : []
                        );
                        echo '<td style="color:#8a6d1f"><strong>Won’t show yet</strong><br>'
                           . '<span style="font-size:11.5px">needs '
                           . esc_html($missing ? implode(' and ', $missing) : 'a photo or a one-line “what you do”')
                           . '</span></td>';
                    } elseif (!array_key_exists('card_renderable', $p)) {
                        // Pool endpoint older than this dash — unknown, and says so
                        // rather than guessing with card_ready, which is a different test.
                        echo '<td style="color:#646970">—</td>';
                    } else {
                        echo '<td><span style="color:#787c82">Ready</span></td>';
                    }
                    echo '<td><form method="post" action="' . esc_url($postUrl) . '">'
                       . '<input type="hidden" name="action" value="' . esc_attr(self::FEATURE_ACTION) . '">'
                       . '<input type="hidden" name="member_uuid" value="' . esc_attr((string) $p['uuid']) . '">'
                       . '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">'
                       . '<button type="submit" class="button' . ($cardWarn === null ? ' button-primary' : '') . '"'
                       . ($cardWarn !== null ? ' title="' . esc_attr($cardWarn) . '"' : '')
                       . '>' . ($cardWarn !== null ? 'Feature anyway' : 'Feature') . '</button></form></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>Featured history</h2>';
        if (empty($history)) {
            echo '<div style="background:#fff;border:1px dashed #c3c4c7;border-radius:4px;padding:26px 18px;text-align:center;color:#646970">'
               . '<strong style="display:block;color:#1d2327;margin-bottom:4px">No one has been featured yet.</strong>'
               . 'Stints on the front page will be listed here with dates.</div>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Member</th><th>From</th><th>To</th><th>Chosen by</th></tr></thead><tbody>';
            foreach ($history as $h) {
                $to = $h['ended_at'] ? esc_html(date('j M Y', strtotime((string) $h['ended_at']))) : '<span style="color:#1a6b2a;font-weight:600">On now</span>';
                echo '<tr><td>' . esc_html((string) $h['display_name']) . '</td>'
                   . '<td>' . esc_html(date('j M Y', strtotime((string) $h['started_at']))) . '</td>'
                   . '<td>' . $to . '</td>'
                   . '<td style="color:#646970">' . esc_html((string) ($h['chosen_by'] ?? '—')) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }
}
