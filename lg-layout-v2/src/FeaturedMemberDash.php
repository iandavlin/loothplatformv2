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
    // #200 — Ian places a member by hand. A SEPARATE action from FEATURE_ACTION
    // on purpose: the two write different things, warn about different things,
    // and mean different things to the member, so one handler doing both behind
    // a boolean would be one nonce away from a pin that nobody chose.
    public const PIN_ACTION     = 'lg_featured_member_pin';
    // #200 — the real off-switch, split out of Remove. Remove now clears the
    // pick and lets the band fall back (Ian's empty-pool law: the band must
    // never render as absent); this is the deliberate silence, and it is
    // labelled as such because the two are not the same intention.
    public const HIDE_ACTION    = 'lg_featured_member_hide';

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
        add_action('admin_post_' . self::PIN_ACTION,     [self::class, 'handle_pin']);
        add_action('admin_post_' . self::HIDE_ACTION,    [self::class, 'handle_hide']);
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

    private static function fetch_pool(string $search = '', string $status = 'all'): array
    {
        $secret = self::read_file_secret(self::INTERNAL_SECRET_FILE);
        if ($secret === '') return ['pool' => [], 'candidates' => null, 'candidate_counts' => null];
        $url = self::POOL_URL . ($search !== ''
            ? '?q=' . rawurlencode($search) . '&status=' . rawurlencode($status)
            : '');
        $resp = wp_remote_get($url, [
            'timeout' => 5, 'sslverify' => false,
            'headers' => ['Host' => self::resolve_host(), 'X-LG-Internal-Auth' => $secret],
        ]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            return ['pool' => [], 'candidates' => null, 'candidate_counts' => null];
        }
        $j = json_decode((string) wp_remote_retrieve_body($resp), true);
        return [
            'pool' => is_array($j['pool'] ?? null) ? $j['pool'] : [],
            // NULL, not [] — "this endpoint does not offer candidates" (older
            // than this dash, mid-deploy) and "your search found nobody" are
            // different answers and must not render alike. Same absent-key
            // discipline card_renderable already uses one field over.
            'candidates' => is_array($j['candidates'] ?? null) ? $j['candidates'] : null,
            'candidate_counts' => is_array($j['candidate_counts'] ?? null) ? $j['candidate_counts'] : null,
        ];
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

    /* Blocker key => the CLAUSE the dash says, phrased as the state it is in
       rather than as an instruction. An empty role has two causes needing
       opposite advice, and the common one is NOT the obvious one: on dev2
       2026-08-20, four of the five members whose card cannot render had
       already written a one-liner and had it withheld as members-only. Telling
       them to "add a one-line what you do" would be confident, specific and
       wrong about a field they had filled in. */
    private const CARD_BLOCKER_LABELS = [
        'photo'                    => 'has no photo',
        'what_you_do'              => 'has no one-line “what you do”',
        'what_you_do_members_only' => 'has their one-line “what you do” set to members-only, '
                                    . 'so the public card may not repeat it',
    ];

    /** The short form for the pool table's Card column. */
    private const CARD_BLOCKER_SHORT = [
        'photo'                    => 'a photo',
        'what_you_do'              => 'a one-line “what you do”',
        'what_you_do_members_only' => 'their one-liner made public (it is members-only)',
    ];

    /* ── WHAT WILL THE FRONT PAGE SHOW FOR THIS PICK? ─────────────────────
       Information, never permission — and since 200-latest-pick it is no longer
       even a warning, because there is nothing left to warn about.

       ⚠️ THIS METHOD USED TO TELL IAN A LIE, and it is the lie he reported.
       It returned:

           "Featured — but the front-page band will stay hidden, because <name>
            has no photo. The selection is saved and the band appears the moment
            that changes."

       That sentence was true of the old resolver, which threw a pool selection
       away whenever the member's role resolved empty. Ian, 2026-08-22: "Can we
       just make it so when I select a user they show up on the front page again
       first." The resolver now draws EVERY selection, so "the band will stay
       hidden" describes nothing that can happen any more — and a dash that says
       a saved pick will not appear, while it is appearing, is the same defect
       as the disappearance itself, wearing words.

       What survives is the genuinely useful half: a member with no photo or
       nothing public to say gets a THIN card — their name and a link — and Ian
       should know that before he picks them, not after. So this now describes
       the card he will get. It never says hidden, and it never refuses.

       An ABSENT card_renderable key still means the pool endpoint is older than
       this dash (a half-finished deploy), and the honest answer to "what will
       the front page do" is then "unknown" — so say nothing rather than
       describe a card we cannot predict. */
    public static function card_warning(array $member): ?string
    {
        if (!array_key_exists('card_renderable', $member)) return null;

        $blockers = is_array($member['card_blockers'] ?? null) ? $member['card_blockers'] : [];
        if (!$blockers) return null;   // a full card — nothing to say

        $parts = [];
        foreach ($blockers as $b) {
            $parts[] = self::CARD_BLOCKER_LABELS[$b] ?? $b;
        }
        $name = trim((string) ($member['display_name'] ?? ''));
        if ($name === '') $name = 'This member';
        $why = $parts ? implode(', and ', $parts) : 'has no photo or no one-line “what you do”';

        return 'Featured — they go on the front page now. Their card will be a thin one, because '
             . $name . ' ' . $why . '. It still shows their name and a link to their profile.';
    }

    /* ── DID THEY KNOW THIS WOULD HAPPEN? (#107, Ian 2026-08-20) ──────────
       "The tick is consent" — a member who ticks the featured box consents to
       their one-line "what you do" appearing on the public front-page card,
       even though their profile keeps it members-only. That is the ruling, and
       the card pipeline now honours it.

       It cannot be applied backwards in silence. Eight members ticked under
       copy that never mentioned the one-liner, and four of them are the very
       members the ruling unblocks. So the pool marks those picks
       `glance_needs_ack`, this says so in words, and the act of featuring them
       records the acknowledgement the resolver then honours — Ian's own
       "until they re-confirm OR Ian features them knowingly", with both
       clauses doing real work instead of one of them being a figure of speech.

       ABSENT KEY IS NOT FALSE, same discipline as card_warning(): a missing
       `glance_needs_ack` means the pool endpoint is older than this dash, and
       the honest answer is silence, not a reassuring nothing. */
    public static function consent_notice(array $member): ?string
    {
        if (!array_key_exists('glance_needs_ack', $member)) return null;
        if (empty($member['glance_needs_ack'])) return null;

        $name = trim((string) ($member['display_name'] ?? ''));
        if ($name === '') $name = 'This member';

        // Chosen or merely defaulted — opposite situations needing opposite
        // remedies, and the common one is NOT the one the words suggest:
        // 1,917 of 1,933 members have never set a header row at all, so
        // "members-only" is almost always the platform's choice, not theirs.
        $why = empty($member['header_vis_explicit'])
            ? ' (they have never opened their header settings — members-only is the platform default, not something they picked)'
            : ' (they set that themselves)';

        return $name . ' keeps their one-line “what you do” members-only' . $why
             . '. Featuring them publishes it on the public front page. They ticked the '
             . 'featured box before it said that would happen, so this one is your call: '
             . 'feature them anyway, or ask them to untick and re-tick to confirm.';
    }

    /* The same fact in the past tense, for the confirmation after a save. The
       admin has already clicked by then, so this states what is now true and
       what would put the consent on the record — it does not re-litigate. */
    public static function consent_notice_saved(array $member): ?string
    {
        if (self::consent_notice($member) === null) return null;

        $name = trim((string) ($member['display_name'] ?? ''));
        if ($name === '') $name = 'This member';

        return $name . '’s one-line “what you do” is now on the public front page, '
             . 'though it stays members-only everywhere else. They ticked before the box '
             . 'mentioned that, so this was your call — asking them to untick and re-tick '
             . 'would put their consent on the record under the new wording.';
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
        $pool = self::fetch_pool()['pool'];
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
        // ⚠️ AND SINCE 200-latest-pick THE RESOLVER HAS NO WALL EITHER.
        // This comment used to say the front-page resolver "keeps a guard of
        // its own (no avatar or no role => return null, no band), and it must,
        // because the card's template renders both unconditionally". Both
        // halves are now out of date: #200 put the template's avatar and role
        // behind !empty(), and 200-latest-pick removed the guard, because Ian
        // asked for the plain thing — "when I select a user they show up on the
        // front page again first".
        //
        // The history is kept because it is the reason this handler is shaped
        // the way it is. MEASURED 2026-08-20 on dev2: featuring Rick Liftig
        // (whom this dash called "Ready", enabled button and all) through this
        // very handler took the band off the front page entirely, 74,456 bytes
        // to 72,838, zero lg-fm__ markers. That silent removal is exactly what
        // Ian later reported, and it is now impossible — MEASURED again
        // 2026-08-22: Carl Ioriatti, opted in and public with an empty resolved
        // role, drew "This spot is open" as a pool pick before the change and
        // "Carl Ioriatti" after it.
        //
        // So the selection goes through — always — and the admin is told what
        // the front page will do with it: not whether it will appear, which is
        // now never in doubt, but whether the card will be a FULL one or their
        // name and a link. card_renderable remains the pool's report of the
        // resolver's verdict and is true for everyone; card_blockers is what
        // carries the shape.
        $warn = self::card_warning($member);

        // #107: featuring an old-copy ticker whose glance is members-only is
        // the "OR Ian features them knowingly" clause of the ruling. The click
        // IS the acknowledgement, so it is recorded with the selection — and
        // the front-page resolver republishes on nothing else. Its ABSENCE is
        // what protects the pick already live in config.json from changing
        // under the flag flip, so this is written on every save, explicitly
        // false included: a stale true left behind by an earlier selection
        // would be consent for a member who never gave it.
        $consentWarn = self::consent_notice($member);

        $user = wp_get_current_user();
        $res = self::post_config([
            'enabled'    => true,
            'member_uuid' => $uuid,
            'name'       => (string) $member['display_name'],
            'role'       => (string) $member['tagline'],
            'consent_ack' => $consentWarn !== null,
            // #200 — WRITTEN ON EVERY SAVE, FALSE INCLUDED, and for exactly the
            // reason consent_ack is. _config.php merges featured_member with
            // `$clean + $existing`, and PHP's `+` keeps the left operand's keys
            // and fills the rest from the right — so an OMITTED key PERSISTS. A
            // stale `pinned: true` left behind by an earlier hand-placement
            // would silently reclassify this consented member as one Ian placed
            // himself, which is a claim about consent and not a cosmetic label.
            'pinned'     => false,
            // where/bio/cta_href/cta_label are NOT set here — index.php
            // re-resolves the live card from profile_app on every request
            // ("live, not frozen"). name/role are stored only as the
            // featured_history snapshot's source (see _config.php).
            'chosen_by'  => $user && $user->user_login ? $user->user_login : 'wp-admin',
        ]);
        // The warning rides only on a SUCCESSFUL save — a failed write already
        // has an error to show, and stacking "it did not save" with "and it
        // would not have rendered" buries the one the admin must act on.
        // Both notices can be true at once — a member with no photo AND an
        // unconfirmed members-only one-liner — so they are joined rather than
        // one silently winning. Order is deliberate: what got published first,
        // then what still will not show.
        $saved = array_values(array_filter([self::consent_notice_saved($member), $warn]));
        self::redirect_back(
            $res['ok'] ? '' : urlencode((string) $res['error']),
            $res['ok'],
            $res['ok'] && $saved ? urlencode(implode(' ', $saved)) : ''
        );
    }

    /* ── REMOVE NO LONGER MEANS HIDE (#200, Ian's empty-pool law) ──────────
       "with zero eligible members and zero picks, the band must render the old
       hand-placed content or a designed fallback — never nothing."

       This used to post `enabled => false`, which took the whole band off the
       front page — the very hole the law forbids, reachable from a button
       labelled "Remove from front page" that reads like it means "remove this
       member". So Remove now clears the SELECTION and leaves the band enabled:
       index.php falls back to the tracked hand-placed card. Wanting no band at
       all is a different intention and has its own button, handle_hide().

       member_uuid is blanked rather than left in place, because a uuid sitting
       in config.json with nobody featured is what made "turn the flag off"
       remove the band instead of restoring it. */
    public static function handle_remove(): void
    {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', '', ['response' => 403]);
        check_admin_referer(self::NONCE_ACTION);

        $res = self::post_config([
            'enabled'     => true,
            'member_uuid' => '',
            'name'        => '',
            'role'        => '',
            'consent_ack' => false,
            'pinned'      => false,
        ]);
        self::redirect_back(
            $res['ok'] ? '' : urlencode((string) $res['error']),
            $res['ok'],
            $res['ok'] ? rawurlencode('Nobody is featured now, so the front page shows the standing card instead. The band itself is still on — use “Hide the band entirely” if you want the whole row gone.') : ''
        );
    }

    /* The deliberate silence. Separate button, separate words, because a person
       choosing to show nothing and a page failing to show something must never
       be the same state — that confusion is the whole of #200. */
    public static function handle_hide(): void
    {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', '', ['response' => 403]);
        check_admin_referer(self::NONCE_ACTION);

        $current = self::fetch_current();
        $uuid = (string) ($current['featured_member']['member_uuid'] ?? '');
        $res = self::post_config(['enabled' => false, 'member_uuid' => $uuid]);
        self::redirect_back(
            $res['ok'] ? '' : urlencode((string) $res['error']),
            $res['ok'],
            $res['ok'] ? rawurlencode('The featured band is now hidden completely — no card, no fallback. Feature or pin someone to bring it back.') : ''
        );
    }

    /* ── PIN: Ian places a member who has not ticked the box (#200) ────────
       Ian, 2026-08-22: "The override I wanted would still have them on the
       frontpage even if they didn't meet the criteria."

       What this does NOT do is as load-bearing as what it does: it never writes
       users.featured_opt_in. Consent stays the member's to give — gate 39 §D
       keeps me-featured.php off the admin-impersonation allowlist for the same
       reason, and pinning must not become the back door that rule closes.
       Nothing here touches profile_app at all; the pin lives in config.json. */
    public static function handle_pin(): void
    {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', '', ['response' => 403]);
        check_admin_referer(self::NONCE_ACTION);

        $uuid = isset($_POST['member_uuid']) ? sanitize_text_field(wp_unslash((string) $_POST['member_uuid'])) : '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            self::redirect_back('invalid%20member');
            return;
        }
        // Re-resolve against the endpoint rather than trusting the form: the
        // display name is snapshotted into featured_history, and the privacy
        // state is the one refusal a pin must still honour. Searching by the
        // uuid finds the member whatever the admin originally typed.
        $found = self::fetch_pool($uuid);
        $member = null;
        foreach (($found['candidates'] ?? []) as $c) {
            if (isset($c['uuid']) && strcasecmp((string) $c['uuid'], $uuid) === 0) { $member = $c; break; }
        }
        if ($member === null) {
            self::redirect_back(rawurlencode('that member could not be looked up just now — nothing was changed'));
            return;
        }
        // ⚠️ NO REFUSAL HERE, DELIBERATELY, AND IT USED TO BE ONE.
        // Ian, 2026-08-22: "If I select a user for featured member I want them
        // shown. Regardless of the status of their profile. Please strip the
        // saftey feature. I want to know what it is in the dash."
        // An earlier cut refused a Private profile. His ruling is that the
        // status is a FACT for winnowing, shown beside the name and in the
        // filter, never a wall — so the only thing that can stop a pin now is
        // not being able to find the member at all.
        $user = wp_get_current_user();
        $res = self::post_config([
            'enabled'     => true,
            'member_uuid' => $uuid,
            'name'        => (string) $member['display_name'],
            'role'        => (string) ($member['public_role'] ?? ''),
            // #107's own second clause, exercised: "until they re-confirm OR Ian
            // features them knowingly". Pinning IS featuring them knowingly, so
            // the acknowledgement is recorded rather than inferred later — and
            // the row he clicked from said, in words, what it would publish.
            // Today this changes nothing on either box: the featured-consent
            // flag is OFF, so no one-liner is republished for anybody.
            'consent_ack' => true,
            'pinned'      => true,
            'chosen_by'   => $user && $user->user_login ? $user->user_login : 'wp-admin',
        ]);
        self::redirect_back(
            $res['ok'] ? '' : urlencode((string) $res['error']),
            $res['ok'],
            $res['ok'] ? rawurlencode(self::pin_notice_saved($member)) : ''
        );
    }

    /* What just happened, in words, at the moment it becomes true — including
       the part Ian has to do himself. Consent-A (#107) says a member placed by
       his hand rather than their own tick is his call and he asks them
       personally; that sentence is the only place the platform can say so. */
    public static function pin_notice_saved(array $member): string
    {
        $name = trim((string) ($member['display_name'] ?? '')) ?: 'This member';
        $msg  = $name . ' is on the front page now — pinned by you, not opted in. '
              . 'They have not ticked the featured box, so nobody has asked them: that conversation is yours to have.';
        if (($member['public_role'] ?? '') === '') {
            $msg .= ' Their card shows a photo, their name and a link — no second line, because there is '
                  . 'nothing on their profile the public card is allowed to repeat.';
        }
        if (empty($member['has_photo'])) {
            $msg .= ' They have no photo, so the card is their name and a link alone.';
        }
        return $msg;
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
        $search  = isset($_GET['pinq']) ? sanitize_text_field(wp_unslash((string) $_GET['pinq'])) : '';
        // Ian, 2026-08-22: "The privacy status was more for a stat for winnowing
        // selections in the dash I thought." So status narrows the list he is
        // reading. Validated against the same four values the endpoint accepts —
        // an unknown one falls back to 'all', never to an empty list, because a
        // filter that silently matches nothing reads as "there is nobody".
        $status  = isset($_GET['pinst']) ? sanitize_key(wp_unslash((string) $_GET['pinst'])) : 'all';
        if (!in_array($status, ['all', 'consented', 'never', 'private'], true)) $status = 'all';
        $fetched = self::fetch_pool($search, $status);
        $pool    = $fetched['pool'];
        $candidates = $fetched['candidates'];
        $counts  = $fetched['candidate_counts'];
        $history = self::fetch_history();
        $fm      = is_array($current['featured_member'] ?? null) ? $current['featured_member'] : [];
        $currentUuid = (string) ($fm['member_uuid'] ?? '');
        $isLiveReal  = !empty($fm['enabled']) && $currentUuid !== '';
        // #200 — is the live pick one Ian placed, or one a member asked for?
        // Named everywhere it is shown, because "featured" now covers two quite
        // different things and only one of them involved the member agreeing.
        $isPinned    = $isLiveReal && !empty($fm['pinned']);
        $bandHidden  = empty($fm['enabled']);

        $notice = isset($_GET['updated']) ? (string) $_GET['updated'] : '';
        $error  = isset($_GET['err'])     ? (string) $_GET['err']     : '';
        $warn   = isset($_GET['warn'])    ? (string) $_GET['warn']    : '';
        $nonce  = wp_create_nonce(self::NONCE_ACTION);
        $postUrl = admin_url('admin-post.php');

        echo '<div class="wrap"><h1>Featured Member</h1>';
        echo '<p class="description">Two ways onto the front page. <strong>The pool</strong> is members who ticked '
           . '&ldquo;include me as a possible featured member&rdquo; on their own profile &mdash; they asked. '
           . '<strong>Pinning</strong> places anyone else, whether or not they meet the criteria &mdash; you asked, '
           . 'so you tell them. One at a time, either way. Clearing the pick never leaves the band empty: '
           . 'the front page falls back to the standing card.</p>';

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
            // THE HONEST NAME FOR EACH KIND. "Featured" alone would now cover a
            // member who agreed and a member who was never asked, and the
            // difference is the whole of consent-A.
            echo '<div class="notice notice-info"><p><strong>On the front page now:</strong> ' . esc_html($liveName)
               . ' &mdash; <strong>' . ($isPinned ? 'pinned by an admin' : 'opted in') . '</strong>. '
               . ($isPinned
                    ? 'They did not tick the featured box; someone placed them here. Ask them personally if that has not happened.'
                    : 'They ticked the featured box on their own profile.')
               . '</p></div>';
        } elseif ($bandHidden) {
            echo '<div class="notice notice-warning"><p><strong>The band is hidden entirely.</strong> '
               . 'No card and no fallback &mdash; the row does not render at all. Feature or pin someone to bring it back.</p></div>';
        } else {
            echo '<div class="notice notice-info"><p><strong>Nobody is featured right now.</strong> '
               . 'The front page is showing the standing fallback card, not an empty space &mdash; that is deliberate '
               . '(#200: the band never renders as absent).</p></div>';
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
                $consWarn = self::consent_notice($p);
                // One flag for the button: either kind of notice means the
                // admin is deciding something, not just clicking a name.
                $needsCare = $cardWarn !== null || $consWarn !== null;
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
                       . '<button type="submit" class="button" title="' . esc_attr(
                            'Clears the pick. The band stays up and shows the standing card — it does not go blank.'
                         ) . '">Clear this pick</button></form></td>';
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
                        // ⚠️ THIS COLUMN USED TO READ "Won't show yet". It was
                        // true of the old resolver and is false now — every
                        // selection reaches the front page since
                        // 200-latest-pick. A column telling Ian a pick will not
                        // show, beside a button that puts it on the front page,
                        // is the disappearance defect restated in words. What
                        // is still worth saying is the SHAPE of the card he
                        // will get, so that is what it says.
                        $missing = array_map(
                            static fn($b) => self::CARD_BLOCKER_SHORT[$b] ?? $b,
                            is_array($p['card_blockers'] ?? null) ? $p['card_blockers'] : []
                        );
                        echo '<td style="color:#8a6d1f"><strong>Thin card</strong><br>'
                           . '<span style="font-size:11.5px">shows, but has no '
                           . esc_html($missing ? implode(' and no ', $missing) : 'photo or one-line “what you do”')
                           . '</span></td>';
                    } elseif ($consWarn !== null) {
                        // The card WILL render — this is not a defect column
                        // entry, it is a consent one. Saying "won’t show yet"
                        // here would be the same wrong-label failure #107
                        // exists to fix, one category over.
                        echo '<td style="color:#8a6d1f"><strong>Will publish their one-liner</strong><br>'
                           . '<span style="font-size:11.5px">members-only on their profile; they ticked before the box said so</span></td>';
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
                       . '<button type="submit" class="button' . ($needsCare ? '' : ' button-primary') . '"'
                       . ($needsCare ? ' title="' . esc_attr(trim(($consWarn ?? '') . ' ' . ($cardWarn ?? ''))) . '"' : '')
                       . '>' . ($needsCare ? 'Feature anyway' : 'Feature') . '</button></form></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        /* ── PIN A MEMBER (#200) ────────────────────────────────────────────
           A SEARCH BOX, not a dropdown: 1,934 public members live on this box,
           and the endpoint only computes candidates when asked, so an unsearched
           page load costs nothing. */
        echo '<h2>Pin a member <span style="font-weight:400;color:#646970">&mdash; anyone, criteria or not</span></h2>';
        echo '<p class="description" style="max-width:70em">Puts <strong>anyone</strong> on the front page &mdash; ticked or not, '
           . 'public profile or not. Nothing here refuses a pick. The <strong>Status</strong> column is there to help you narrow '
           . 'the list, and the filter above it counts each kind; every profile link opens in a new tab so you can look before '
           . 'you choose. A member who has not ticked the box has not been asked &mdash; '
           . '<strong>that conversation is yours to have with them.</strong></p>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:10px 0 10px">'
           . '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">'
           . '<input type="hidden" name="pinst" value="' . esc_attr($status) . '">'
           . '<input type="search" name="pinq" value="' . esc_attr($search) . '" class="regular-text" '
           . 'placeholder="Search members by name, handle or business">'
           . ' <button type="submit" class="button">Search</button>'
           . ($search !== '' ? ' <a class="button-link" href="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '">clear</a>' : '')
           . '</form>';

        /* THE WINNOWING FILTER. Counts come from the endpoint and cover the WHOLE
           match set, not the 25 rows shown — a number that silently meant "of the
           page you can see" would be worse than no number, since narrowing is the
           entire job. Rendered only with a search in play, because a filter over
           nothing is furniture. */
        if ($search !== '' && is_array($counts)) {
            $labels = [
                'all'       => 'Everyone',
                'consented' => 'Consented',
                'never'     => 'Never asked',
                'private'   => 'Private profile',
            ];
            echo '<p style="margin:0 0 14px">';
            foreach ($labels as $key => $label) {
                $n = (int) ($counts[$key] ?? 0);
                $url = add_query_arg(['page' => self::PAGE_SLUG, 'pinq' => $search, 'pinst' => $key],
                                     admin_url('admin.php'));
                $on = ($status === $key);
                echo '<a href="' . esc_url($url) . '" style="display:inline-block;margin-right:6px;'
                   . 'padding:4px 10px;border-radius:999px;text-decoration:none;font-size:12px;'
                   . ($on ? 'background:#2271b1;color:#fff;font-weight:600' : 'background:#f0f0f1;color:#2c3338')
                   . '">' . esc_html($label) . ' <span style="opacity:.75">' . $n . '</span></a>';
            }
            echo '</p>';
        }

        if ($search === '') {
            // Say nothing rather than show an empty table — an empty table reads
            // as "no such members".
        } elseif ($candidates === null) {
            // NOT the same as "found nobody". A null means this dash asked an
            // endpoint that does not offer candidates (a half-finished deploy),
            // and "I could not look" must never render as "there is nobody".
            echo '<div class="notice notice-warning inline"><p><strong>Could not search.</strong> '
               . 'The profile service did not return a candidate list &mdash; it may be older than this page, '
               . 'or unreachable. This is not the same as finding nobody, so nothing is being claimed either way.</p></div>';
        } elseif (!$candidates) {
            echo '<p style="color:#646970">No member matches &ldquo;' . esc_html($search) . '&rdquo;.</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
               . '<th>Member</th><th>Status</th><th>Card would say</th><th>Where</th><th></th></tr></thead><tbody>';
            /* Status sits SECOND, beside the name, because it is the thing being
               winnowed on (Ian: "more for a stat for winnowing selections") and
               it should read left-to-right with what it describes rather than
               sitting at the far edge next to the button. */
            $statusLabel = [
                'consented' => ['Consented',       '#1a6b2a', 'they ticked the featured box themselves'],
                'never'     => ['Never asked',     '#646970', 'a public profile; they have not ticked the box'],
                'private'   => ['Private profile', '#8a6d1f', 'not publicly visible — pinning still shows them'],
            ];
            foreach ($candidates as $c) {
                $cUuid = (string) ($c['uuid'] ?? '');
                $isCur = $isLiveReal && strcasecmp($cUuid, $currentUuid) === 0;
                echo '<tr' . ($isCur ? ' style="background:#fcf9e8"' : '') . '>';
                /* "I want a link to check out their profile. Open in new tab."
                   rel=noopener as well, because target=_blank alone hands the
                   opened page a handle on this admin window. */
                $purl = (string) ($c['profile_url'] ?? ('/u/' . (string) $c['slug']));
                echo '<td><strong>' . esc_html((string) $c['display_name']) . '</strong><br>'
                   . '<a href="' . esc_url($purl) . '" target="_blank" rel="noopener noreferrer" '
                   . 'style="font-size:11.5px">/u/' . esc_html((string) $c['slug'])
                   . ' <span aria-hidden="true">&#8599;</span>'
                   . '<span class="screen-reader-text"> (opens in a new tab)</span></a></td>';
                /* An UNKNOWN status prints a dash and says the endpoint did not
                   answer — the same absent-key discipline as card_renderable. A
                   pool endpoint older than this dash must not be reported as
                   "never asked", which is a claim about the member. */
                $sl = $statusLabel[(string) ($c['status'] ?? '')] ?? null;
                echo '<td style="color:' . esc_attr($sl ? $sl[1] : '#787c82') . '"><strong>'
                   . ($sl ? esc_html($sl[0]) : '&mdash;') . '</strong><br>'
                   . '<span style="font-size:11.5px;color:#787c82">'
                   . esc_html($sl ? $sl[2] : 'this pool endpoint did not say') . '</span></td>';
                // What the card will SAY, stated before the click rather than
                // discovered after it — the lesson #107 paid for with Rick
                // Liftig, one category over.
                if (($c['public_role'] ?? '') !== '') {
                    echo '<td>' . esc_html((string) $c['public_role']) . '</td>';
                } else {
                    echo '<td style="color:#646970">their name and a link only<br>'
                       . '<span style="font-size:11.5px">nothing on their profile is public enough to repeat</span>'
                       . (!empty($c['glance_members_only'])
                            ? '<br><span style="font-size:11.5px;color:#8a6d1f">they have written one, but keep it members-only</span>'
                            : '')
                       . '</td>';
                }
                echo '<td>' . esc_html((string) ($c['location'] ?? '')) . '</td>';

                /* EVERY ROW GETS A BUTTON. Ian, 2026-08-22: "Please strip the
                   saftey feature." The Status column has already told him what he
                   is picking; nothing here refuses on it. The only row without a
                   button is the member already on the front page, and that is not
                   a refusal — there is simply nothing to do. */
                if ($isCur) {
                    echo '<td><span style="background:#edf7ee;color:#1a6b2a;border-radius:3px;padding:3px 7px;font-size:11px;font-weight:600">On now</span></td>';
                } else {
                    // A member already in the pool is FEATURED rather than pinned
                    // — same button, honest verb, and handle_pin records which
                    // it was from their live opt-in state, not from this label.
                    $verb = !empty($c['opted_in']) ? 'Feature' : 'Pin to front page';
                    echo '<td><form method="post" action="' . esc_url($postUrl) . '">'
                       . '<input type="hidden" name="action" value="' . esc_attr(self::PIN_ACTION) . '">'
                       . '<input type="hidden" name="member_uuid" value="' . esc_attr($cUuid) . '">'
                       . '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">'
                       . '<button type="submit" class="button" title="' . esc_attr(
                            'Puts them on the front page now, whatever their status. Their profile link opens in a new tab if you want to look first.'
                         ) . '">' . esc_html($verb) . '</button></form></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        /* The deliberate silence, kept away from Remove so the two intentions
           cannot be confused by a hurried click. */
        if (!$bandHidden) {
            echo '<h2>The band itself</h2>';
            echo '<p class="description">Clearing a pick leaves the band up with the standing card. This takes the whole row off the front page.</p>';
            echo '<form method="post" action="' . esc_url($postUrl) . '" style="margin:8px 0 4px">'
               . '<input type="hidden" name="action" value="' . esc_attr(self::HIDE_ACTION) . '">'
               . '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">'
               . '<button type="submit" class="button">Hide the band entirely</button></form>';
        }

        echo '<h2>Featured history</h2>';
        if (empty($history)) {
            echo '<div style="background:#fff;border:1px dashed #c3c4c7;border-radius:4px;padding:26px 18px;text-align:center;color:#646970">'
               . '<strong style="display:block;color:#1d2327;margin-bottom:4px">No one has been featured yet.</strong>'
               . 'Stints on the front page will be listed here with dates.</div>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Member</th><th>How</th><th>From</th><th>To</th><th>Chosen by</th></tr></thead><tbody>';
            foreach ($history as $h) {
                $to = $h['ended_at'] ? esc_html(date('j M Y', strtotime((string) $h['ended_at']))) : '<span style="color:#1a6b2a;font-weight:600">On now</span>';
                // ABSENT KEY IS NOT FALSE, the same discipline as card_warning().
                // A missing `pinned` means the history table predates
                // tools/migrations/200-featured-history-pinned.sql on this box,
                // and the honest answer is a dash, not the reassuring "opted in".
                // That distinction matters here more than most: this column is
                // what someone auditing consent would read.
                if (!array_key_exists('pinned', $h)) {
                    $how = '<span style="color:#787c82">—</span>';
                } elseif (!empty($h['pinned'])) {
                    $how = '<span style="color:#8a6d1f;font-weight:600">pinned</span>';
                } else {
                    $how = '<span style="color:#646970">opted in</span>';
                }
                echo '<tr><td>' . esc_html((string) $h['display_name']) . '</td>'
                   . '<td>' . $how . '</td>'
                   . '<td>' . esc_html(date('j M Y', strtotime((string) $h['started_at']))) . '</td>'
                   . '<td>' . $to . '</td>'
                   . '<td style="color:#646970">' . esc_html((string) ($h['chosen_by'] ?? '—')) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }
}
