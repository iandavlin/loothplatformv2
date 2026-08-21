<?php
/**
 * Plugin Name: LG — front-end compose
 * Description: One screen, one route, for CREATING and EDITING a managed CPT from
 *              the front end. Ian ruled Option A 2026-08-03, edit 2026-08-09.
 *              Flag OFF by default.
 * Version:     0.1.0
 *
 * ══ WHAT THIS IS, AND THE ONE SENTENCE THAT SCOPES IT ═════════════════════════
 *
 * Ian, re-scoping the lane: "I can currently edit on the front end. That is fine.
 * I need to be able to COMPOSE on the front end with a easy front end form."
 * COMPOSE was the original problem; editing a DISCUSSION already worked and was
 * explicitly fine. Editing a LOOTHPRINT did not — the legacy edit pages render
 * zero ACF fields under every parameter tried — and Ian added it on 2026-08-09,
 * so this file now does both. See EDIT below for the one thing it needs that
 * create does not.
 *
 * Ruling: docs/IAN-RULINGS-2026-08-03.md §3 — "Front-end compose — Option A,
 * single screen", chosen over the 3-step wizard he approved for discussions.
 * Scope + evidence: docs/FRONTEND-COMPOSE-SCOPE.md (§7 is this build's list).
 * Mock: /footer-mockups/frontend-compose/.
 *
 * The scope's finding is why this file is small: THE EASY FORM AND THE RENDERER
 * ALREADY EXIST. The per-type field groups are alive ACF groups written for
 * members; acf_form() renders them; and lg-layout-v2 SYNTHESIZES a loothprint's
 * whole standalone page from exactly the postmeta those fields collect
 * (Plugin.php:257 $synth, default_loothprint_layout() at :344). Only the front
 * door was missing. This is the door — not a new form, and not a new renderer.
 *
 * ══ THE ROUTE SHAPE IS LOAD-BEARING ══════════════════════════════════════════
 *
 * ONE route, post type as a QUERY parameter: /compose/?type=loothprint
 *
 * NOT /compose/loothprint/. WordPress canonical-redirects any URL whose final
 * segment matches a CPT slug straight to that CPT's archive, before any handler
 * of ours can run. Re-measured on this box 2026-08-09, as a logged-in admin:
 *
 *     /compose/loothprint/          301 -> /loothprint/
 *     /new/loothprint/              301 -> /loothprint/
 *     /compose/?type=loothprint     404   (ours to define)
 *
 * The 301 is the dangerous half: a redirect-following check gets 200 and a page
 * full of loothprints, which reads as "my form failed to render" rather than
 * "my route never existed". A query parameter cannot collide with any CPT slug,
 * present or future. tools/gates/compose-gate.py declared this contract before
 * the build and is why it is honoured here rather than rediscovered.
 *
 * Claimed at template_redirect priority -10, matching lg-dev2-power.php: /compose/
 * is an is_404() to WP, and lg-error-pages.php renders the branded 404 and exit()s
 * at priority 0. A handler at the default priority never runs.
 *
 * No rewrite rule, so nothing to flush — one less deploy coupling on a box where
 * the symlink set is already not in the repo.
 *
 * ══ EDIT, AND THE HAZARD THAT TURNED OUT NOT TO EXIST ════════════════════════
 *
 * This route was create-only until 2026-08-09. Two arguments were given for that,
 * and only one of them was true.
 *
 * The TRUE one: taking no post_id means it cannot be aimed at somebody else's
 * post. Now that it does take one, that protection has to be MADE rather than
 * inherited — see `?id=` in lg_fc_route(): the type is derived from the STORED
 * post (never from `?type=`), and `current_user_can('edit_post', $id)` is the
 * gate. That single call resolves ownership and the moderator case together,
 * which is why the superseded edit scope found it discriminated perfectly where a
 * capability check could not.
 *
 * The FALSE one was that create-only made it structurally immune to the defect
 * class this lane was told not to repeat:
 *
 *   lg-preserve-forum-subscription.php (live @ 10ea816) documents BuddyBoss
 *   treating the ABSENCE of a field as an instruction to DELETE the member's
 *   subscription. Our composer omitted bbp_topic_subscription, so every reply
 *   posted through our box silently unsubscribed its own author.
 *
 * ⚠️ I ASSERTED ACF HAS THE SAME SHAPE, AND IT DOES NOT. Corrected 2026-08-09
 * when Ian asked for front-end EDIT and the claim stopped being background. This
 * header used to say that dropping a field from an edit form's field list "would
 * wipe it" — that is FALSE, and measured false on a throwaway draft through ACF's
 * real save handler (tools/frontend-compose/clobber-probe.php, 6/6):
 *
 *   OMITTED from the form   -> nothing is posted for it -> ACF leaves it ALONE.
 *                              Featured image, gallery and a URL field all
 *                              survived a save that posted only the licence.
 *   RENDERED and cleared    -> IS saved empty. That one is real, and it is the
 *                              member's own intent, not a defect.
 *
 * So an edit form may safely show a SUBSET of the fields; omission is not
 * destruction. The BuddyBoss defect this was modelled on is genuinely different:
 * bbp_update_reply() reads the ABSENCE of a field as an instruction to delete,
 * which ACF never does. I had the right instinct about the class and the wrong
 * mechanism, and a safety argument resting on a false premise is worth less than
 * no argument, so it is corrected rather than quietly softened.
 *
 * CREATE-ONLY IS STILL RIGHT FOR THIS ROUTE, for the reason that does hold: it
 * accepts no post_id, so it cannot be pointed at somebody else's post. That is an
 * IDOR property, not a data-preservation one.
 *
 * THE EDIT SLICE (Ian, 2026-08-09) THEREFORE NEEDS EXACTLY ONE THING THIS ROUTE
 * DOES NOT: an ownership check. `current_user_can('edit_post', $id)` discriminates
 * correctly for edit — the superseded edit scope established that — and it is the
 * whole difference. It must NOT be built by relaxing this route's create-only
 * rule; it is a separate entry point with its own gate.
 *
 * The plugin header's other half of that lesson — "pass explicit intent" — is
 * applied literally below: every wp_insert_post argument is stated, including the
 * ones WordPress would have defaulted correctly anyway (post_author). A default
 * that happens to be right is not the same as an intent that was expressed.
 *
 * ══ THE FORM IS REGISTERED SERVER-SIDE, SO ITS SETTINGS NEVER TRAVEL ═════════
 *
 * acf_form() has two transports for its settings (form-front.php:536):
 *   - unregistered: the whole settings array is acf_encrypt()'d into a hidden
 *     _acf_form input and posted back by the client;
 *   - registered:   only the form ID travels, and the settings are looked up
 *     server-side from the in-request store.
 *
 * We register. post_type, post_status and post_author are therefore recomputed
 * FROM THE AUTHENTICATED USER on the POST request and are never client-supplied
 * in any form — encrypted or not. This is the same IDOR-proofing reply.php:205
 * documents for discussions, and it is the difference between "the client cannot
 * easily forge this" and "the client does not supply this".
 *
 * ══ WHO GETS IT — RULED 2026-08-09: ALL MEMBERS ══════════════════════════════
 *
 * Ian: allow-list = ALL MEMBERS, drop the gated-tier path entirely. So it is
 * deleted, not disabled. A tier system with one tier left in it is something the
 * next reader has to disprove, and an unused `lg_fc_on_allow_list()` would read
 * as a gate that merely happens to be switched off.
 *
 * He ruled this having been shown the argument the other way — the scope's §6
 * said the natural capability (`create_posts` -> `edit_posts`) is held by 1,820
 * of 1,824 accounts here, so gating on it discriminates nobody. That is now the
 * intended behaviour rather than the problem.
 *
 * ⚠️ THE FLAG IS THEREFORE THE ONLY SAFETY LEFT. Nothing else narrows who gets
 * this once it is on, which is exactly why the shared config stays OFF by
 * default and why flipping it is Ian's call and not a lane's.
 *
 * WHAT STILL REFUSES SOMEONE IS A FUNCTIONAL FLOOR, NOT A POLICY. `upload_files`
 * is what the photo field needs to work at all, and the gallery is REQUIRED — a
 * member without it cannot complete the form, so serving it would be showing a
 * door that cannot open. `edit_posts` is WordPress's own precondition for the
 * post existing. Those accounts are not members-who-may-not-post; they are
 * accounts that could not post regardless. If Ian wants even them to see the
 * form, lg_fc_may_compose() is the one place to change.
 *
 * ══ THE FLAG ═════════════════════════════════════════════════════════════════
 *
 * platform/config/frontend-compose.php, 'enabled' => false. Member-facing, so
 * OFF-default is the house rule and this one has no reason to deviate: a new
 * capability, not a repair for ongoing damage, so arriving inert is correct.
 *
 * It began as a constant here (copying LG_AUTHOR_SOCIALS_ALL_MEMBERS) and MOVED to
 * a shared tracked file when Ian ruled the entry point is a toggle inside the hub
 * composer — bb-mirror runs in a different pool and cannot see a WP constant, and
 * two flags would let the toggle and the form disagree. See lg_fc_enabled().
 *
 * A tracked PHP FILE, NOT an env var, and that is deliberate. Two recorded traps
 * make env the wrong carrier on this box: WP cron has no Environment= at all, and
 * an fpm fastcgi_param lands in $_SERVER but never in getenv(), so a getenv()-only
 * flag serves OFF on the very preview URL built for Ian to click.
 *
 * Flag OFF is asserted byte-identical against a fixture recorded BEFORE this file
 * existed (tools/gates/fixtures/compose-flag-off.json — /compose/ was a stable
 * 5,106-byte 404 from /srv/lg-shared/errors/404.html, hashed three times a second
 * apart to prove it carries no nonce or timestamp). The gate READS the constant
 * rather than hardcoding a state, so flipping the default needs no gate edit.
 *
 * ══ ASSETS LOAD ON INTENT, AND CANNOT DO OTHERWISE ═══════════════════════════
 *
 * Craft law (docs/CRAFT-STANDARD.md): editors and composers load on intent, never
 * eagerly, and never for anon. The CSS here is inlined into this one route's
 * response, which is a stronger guarantee than an enqueue that is merely
 * conditional: there is no URL for it, so no page can pull it in by accident and
 * anon never receives a byte of it. Nothing is enqueued globally; nothing is
 * registered outside the route; with the flag off this file adds no hook that
 * produces output.
 */

defined('ABSPATH') || exit;

/* ─────────────────────────────────────────────────────────── the flag ───── */

/**
 * Is the route live for THIS request?
 *
 * ⚠️ THE SOURCE OF TRUTH IS A SHARED TRACKED FILE, NOT A CONSTANT HERE, and that
 * changed when Ian ruled the entry point is a TYPE TOGGLE inside the hub
 * composer. The toggle is rendered by bb-mirror — a different FPM pool with no
 * WordPress loaded, which cannot see a constant defined in this file. A second
 * flag over there would let the two disagree: toggle on / form off renders a
 * control whose iframe 404s, which is the "UI lies" class. One file, read by
 * both, makes that unreachable. Same split, same fix, as
 * platform/config/post-follow.php.
 *
 * FAILS CLOSED. An unreadable config is OFF and says so in the log, because the
 * failure mode of guessing ON here is a member-facing surface nobody decided to
 * open.
 *
 * TWO OVERRIDE SOURCES, both only settable by infrastructure: getenv() for a pool
 * or CLI harness, $_SERVER for a single nginx location — a lane preview sets
 * fastcgi_param, which lands in $_SERVER but NOT reliably in getenv(). Reading
 * only one would serve OFF on the very preview URL built for Ian to click.
 */
function lg_fc_enabled(): bool
{
    static $on = null;
    if ($on !== null) {
        return $on;
    }
    if (getenv('LG_FC_PREVIEW') === '1' || (($_SERVER['LG_FC_PREVIEW'] ?? '') === '1')) {
        return $on = true;
    }
    $path = dirname(__DIR__) . '/config/frontend-compose.php';
    if (!is_readable($path)) {
        error_log('[lg-frontend-compose] tracked config unreadable at ' . $path . ' — OFF (fail-closed)');
        return $on = false;
    }
    $raw = require $path;
    $on  = (is_array($raw) && ($raw['enabled'] ?? false) === true);

    // Per-box override, gitignored, same pattern as archive-poc/_flags.local.php:
    // dev2 runs compose ON via this file while the TRACKED default stays false,
    // so a live pull can never launch the composer as a side effect. Sitting in
    // this loader (not an FPM pool env) means wp-cli, FPM and gate 35 all read
    // the SAME truth — the pool-env attempt made the gate and the serve disagree
    // (gate saw OFF, members saw ON) and gate 35 rightly went red, 8/15 night.
    $local = dirname(__DIR__) . '/config/frontend-compose.local.php';
    if (is_readable($local)) {
        $lraw = require $local;
        if (is_array($lraw) && array_key_exists('enabled', $lraw)) {
            $on = ($lraw['enabled'] === true);
        }
    }

    return $on;
}

/**
 * Is the member-facing paywall toggle switched on?
 *
 * Same shape as lg_fc_enabled() above and for the same recorded reasons — tracked
 * file, then a gitignored per-box override, then two preview sources because a
 * fastcgi_param lands in $_SERVER but not reliably in getenv().
 *
 * ⚠️ THE PREVIEW VARIABLE IS NOT LG_FC_PREVIEW, and that is the one line worth
 * reading twice. lane-preview-frontend-compose.conf sets LG_FC_PREVIEW as a
 * fastcgi_param, so a literal copy of the reader above would have armed the
 * paywall on every compose preview URL — a flag switched on by a different
 * feature's preview.
 *
 * Fails CLOSED on an unreadable config, like its sibling: a toggle that cannot
 * read its own switch must not start writing tier terms.
 */
function lg_fc_paywall_enabled(): bool
{
    static $on = null;
    if ($on !== null) {
        return $on;
    }
    if (getenv('LG_FC_PAYWALL_PREVIEW') === '1' || (($_SERVER['LG_FC_PAYWALL_PREVIEW'] ?? '') === '1')) {
        return $on = true;
    }
    $path = dirname(__DIR__) . '/config/loothprint-paywall.php';
    if (!is_readable($path)) {
        error_log('[lg-frontend-compose] paywall config unreadable at ' . $path . ' — OFF (fail-closed)');
        return $on = false;
    }
    $raw = require $path;
    $on  = (is_array($raw) && ($raw['enabled'] ?? false) === true);

    $local = dirname(__DIR__) . '/config/loothprint-paywall.local.php';
    if (is_readable($local)) {
        $lraw = require $local;
        if (is_array($lraw) && array_key_exists('enabled', $lraw)) {
            $on = ($lraw['enabled'] === true);
        }
    }
    return $on;
}

/** The tier taxonomy the toggle drives, and the two slugs Ian named. */
const LG_FC_PAYWALL_TAX    = 'tier';
const LG_FC_PAYWALL_BEHIND = 'looth-lite';
const LG_FC_PAYWALL_PUBLIC = 'public';

/**
 * THE WHOLE RULE, as a pure function: given the tier slugs a post carries now and
 * what the member chose, what should be written — or nothing at all?
 *
 * Pure on purpose. It takes no post, touches no database and calls no WordPress,
 * so its truth table can be executed exhaustively with `php -r` on a box where
 * this feature is not deployed — which is the only honest way to test a rule
 * whose real home is a mu-plugin symlinked out of the serving checkout.
 *
 * ══ WHY FOUR CASES AND NOT TWO ══════════════════════════════════════════════
 *
 * The obvious mapping — behind => looth-lite, not behind => public — SILENTLY
 * DOWNGRADES a looth-pro post to looth-lite the first time its author saves it,
 * because a two-state toggle has no way to say "pro". The member did not ask for
 * that and would never see it happen. So:
 *
 *   behind     + already non-public (lite OR pro)  ->  null   (preserve)
 *   behind     + public, or no term at all         ->  looth-lite
 *   not behind + non-public                        ->  public
 *   not behind + already public                    ->  null   (nothing to do)
 *
 * "Already non-public" means CARRIES ANY non-public term, so a post holding both
 * public and looth-pro hits the preserve branch rather than being flattened.
 *
 * The two null cases are not an optimisation. wp_set_object_terms() fires
 * set_object_terms even when nothing changes, and that hook re-bakes the
 * standalone blob — so "no write" has to mean no call, not an idempotent one.
 *
 * No loothprint on this box is looth-pro today (161 lite / 9 public / 4 none), so
 * the preserve branch costs nothing now and cannot bite later. It is a one-way
 * door either way: a pro post un-ticked and re-ticked comes back lite, because
 * the control genuinely does not carry "pro". Stated rather than hidden.
 */
function lg_fc_paywall_target(array $current, string $choice): ?string
{
    $nonPublic = false;
    foreach ($current as $slug) {
        if (is_string($slug) && $slug !== '' && $slug !== LG_FC_PAYWALL_PUBLIC) {
            $nonPublic = true;
            break;
        }
    }
    /* Ian, 8/21, after a submitted print came back with NO tier at all:
       "It should either be public for anyone looth lite for paywalled."
       So the control always lands on ONE of the two — never nothing.

       THE BUG THIS REPLACES: 'public' returned null unless the post was ALREADY
       paywalled, so a brand-new post (no terms yet) got no write and shipped
       untiered — which is what he screenshotted.

       The one preserve that survives, and why: choosing 'behind' on a post that
       is already Looth PRO keeps PRO. Pro is behind the paywall, so the member's
       intent is already satisfied, and writing Lite there would be a silent
       DEMOTION nobody asked for. Choosing 'public' is always honoured — that is
       an explicit member decision to open it up. */
    if ($choice === 'behind') {
        return $nonPublic ? null : LG_FC_PAYWALL_BEHIND;
    }
    return LG_FC_PAYWALL_PUBLIC;
}

/**
 * What the member chose, as a strict two-value read — the same shape as
 * lg_fc_comment_status(), so there is no injection surface and the value is only
 * ever consumed after ACF has verified the form nonce.
 *
 * DEFAULTS TO 'behind', which is Ian's ruling and also the safe direction: a
 * missing or mangled field must never publish somebody's print files wider than
 * they meant.
 */
function lg_fc_paywall_choice(): string
{
    $v = isset($_POST['lg_fc_paywall']) ? (string) $_POST['lg_fc_paywall'] : '';
    return $v === 'public' ? 'public' : 'behind';
}

/** The tier slugs a post carries right now. [] for a post with no terms. */
function lg_fc_paywall_current(int $post_id): array
{
    $terms = wp_get_object_terms($post_id, LG_FC_PAYWALL_TAX, ['fields' => 'slugs']);
    return is_wp_error($terms) ? [] : array_values((array) $terms);
}

/**
 * Write the member's paywall choice, on the way out of an ACF save.
 *
 * Priority 26: AFTER lg_fc_promote_draft (25) has given the post its real status,
 * so the term lands on a post that exists in the state it will be published in.
 *
 * FLAG OFF => NOT HOOKED AT ALL (see the add_action below), so this is not merely
 * an early return: with the switch off nothing observes the save and no term can
 * move.
 */
function lg_fc_paywall_apply($post_id): void
{
    if (!is_numeric($post_id)) {
        return;
    }
    $post_id = (int) $post_id;
    $type    = get_post_type($post_id);
    $types   = lg_fc_types();
    // Only a type that DECLARES a paywall control. Without this, any future type
    // routed through this form would silently acquire a tier write.
    if (!$type || empty($types[$type]['paywall'])) {
        return;
    }
    // The control has to have been ON SCREEN for its absence to mean anything.
    // Guarding on the flag rather than on the POST field is deliberate: a request
    // that simply omits the field must fall to the ruled default, not skip.
    if (!lg_fc_paywall_enabled()) {
        return;
    }
    /* ⚠️ THIS SAVE MUST BE OUR FORM'S, and without this line it need not be.
       acf/save_post fires for EVERY ACF save on the site, wp-admin included —
       where this control is not rendered at all. lg_fc_paywall_choice() defaults
       to 'behind' when the field is absent (the safe direction for our form, and
       Ian's ruling), so an admin saving a PUBLIC loothprint in wp-admin would
       have had it silently moved behind the paywall. A default that is right in
       one context is a data change in another.

       $_POST['_acf_form'] carries the REGISTERED FORM ID for a registered form
       (ACF form-front.php:337-342) — which this route registers precisely so its
       settings never travel with the POST — and wp-admin posts no such field.
       So this identifies our screen exactly, and cannot be forged into a wider
       permission: it only decides whether we read a field we already validate,
       on a post the caller has already passed current_user_can('edit_post') for. */
    if ((string) ($_POST['_acf_form'] ?? '') !== 'lg-fc-' . $type) {
        return;
    }
    $target = lg_fc_paywall_target(lg_fc_paywall_current($post_id), lg_fc_paywall_choice());
    if ($target === null) {
        return;   // preserve — and NOT an idempotent write; see lg_fc_paywall_target()
    }
    $term = get_term_by('slug', $target, LG_FC_PAYWALL_TAX);
    if (!$term || is_wp_error($term)) {
        error_log('[lg-frontend-compose] paywall: no `' . LG_FC_PAYWALL_TAX . '` term for slug ' . $target);
        return;
    }
    // INT term ids, never the slug string: `tier` is hierarchical, and passing
    // names/slugs there goes through term lookup-or-CREATE.
    wp_set_object_terms($post_id, [(int) $term->term_id], LG_FC_PAYWALL_TAX, false);
}

/* Hooked only when the switch is on, so OFF adds no observer of the save path. */
if (lg_fc_paywall_enabled()) {
    add_action('acf/save_post', 'lg_fc_paywall_apply', 26);   // AFTER promote (25)
}

const LG_FC_PATH = 'compose';

/* The reaper's handle on a compose draft, and how long a never-returned one
   survives. MARKING the row rather than inferring from status means the sweep
   can never mistake an auto-draft made elsewhere — by wp-admin, say — for ours. */
const LG_FC_DRAFT_META     = '_lg_fc_draft';
const LG_FC_DRAFT_TTL_DAYS = 30;

/* ──────────────────────────────────────────────── the per-type registry ───── */

/**
 * What this route can compose, in the order the mock draws it.
 *
 * ⚠️ COPY PROVENANCE — READ THIS BEFORE CHANGING A LABEL.
 *
 * `label` and `hint` are the MOCK's words: the ones Ian saw and approved on
 * 2026-08-03. `acf_label` records what the field group actually says, verbatim,
 * so the two are never confused again.
 *
 * They are NOT the same, and the mock says they are. Its lede claims "The words
 * in it aren't invented — every label and hint below is the copy that already
 * exists for this post type", and its evidence table cites two ACF strings
 * ("Add one or more image(s) of your print in action", "leave default if
 * unsure") that appear nowhere in the drawn form. Checked field by field against
 * the live group on 2026-08-09: essentially every label in the mock is new copy.
 * The mock drew the SPIRIT of the ACF wording, not the wording.
 *
 * This build ships the mock's words because those are the ones that were ruled
 * on, and reverting to the ACF strings would make the form materially unlike the
 * picture that was approved ("3D File Upload ZIP File" for "The print files").
 * But it is a copy change Ian has not been told he is making, so it is written
 * down here, raised in the report, and drawn for him side by side rather than
 * left as a silent substitution. Flipping any row back is a one-line edit.
 *
 * `synth` records that lg-layout-v2 builds the page from this meta,
 * which is the reason only these types are offered — a form for a non-synthesized
 * type would produce a post with no page (scope §3.2).
 */
function lg_fc_types(): array
{
    /**
     * The registry is filterable so a second type (event is the scope's
     * recommended next one, §4) can be added without editing this function, and
     * and so tools/frontend-compose/access-probe.php can register a throwaway
     * type to prove an UNKNOWN type is refused without shipping a second one.
     *
     * This is a CODE-level extension point, not user input: nothing here reads a
     * request. lg_fc_may_compose() still refuses any type not in the returned
     * map.
     */
    return apply_filters('lg_fc_types', [
        'loothprint' => [
            'synth'    => true,
            'title'    => 'Share a Loothprint',
            'sub'      => 'Your 3D print, free for the rest of the group to make.',
            'sub_narrow' => 'Your 3D print, free for the group to make.',
            'submit'   => 'Post it',
            'foot'     => 'Your hero image is the first photo unless you pick another.',
            // ORDER IS THE MOCK'S, and getting it needs a word of explanation.
            // acf_form()'s post_title/post_content args APPEND their pseudo-fields
            // ABOVE everything else (form-front.php:463-476), which puts "Tell
            // people about it" second — the mock has it fourth, after the files.
            // So those args are left false and '_post_title'/'_post_content' are
            // named in the field list instead: render_form registers them as
            // local fields BEFORE resolving the list, so they resolve by key and
            // sit wherever they are put. Verified both resolve rather than
            // assumed — a silently-unresolved selector renders as nothing at all.
            'fields'   => [
                // The 4th column is VESTIGIAL as of 2026-08-16 and is kept only so the
                // rows stay readable in one shape: it used to mark a field for the
                // "Add extras" fold, and that fold is gone by Ian's ruling. Nothing
                // reads it now, so every value is false — a true would describe a
                // construct that no longer exists.
                // name                          label                    hint                                                             (unused) acf_label
                ['_post_title',                  "What’s it called?",     '',                                                              false, 'Title of your Loothprint'],
                ['loothprint_more_images',       'Show it off',           'One or more photos of your print — finished, or better still in use.', false, 'Add one or more image(s) of your print in action'],
                /* EDIT RULING (Ian, 2026-08-16 via keeper): REPLACING the ZIP is
                   ALLOWED — an edit may swap the file, not merely add to it. Ruled
                   after the question was raised that replacing changes what people
                   have already downloaded; it is settled, not provisional. */
                ['loothprint_3d_file',           'The print files',       'A ZIP with your STLs — and the editable source too, if you’re happy to share it.', false, '3D File Upload ZIP File'],
                ['_post_content',                'Tell people about it',  'What it does, what it’s for, anything worth knowing before they print it.', false, 'Summary'],
                ['loothprint_category',          'What kind of print is it?', '',                                                          false, 'Type of Loothprint'],
                ['content_topic_broad_terms',    'And roughly what area of work?', '',                                                     false, 'Content Topic'],
                ['loothprint_creative_commons',  'Licence',               'The usual choice — leave it unless you know you want something else.', false, 'Creative Commons Use License (leave default if unsure)'],
                ['loothprint_video_instructions','A video of it in use',  '',                                                              false, 'Video instructions for use/build'],
            ],
            /* REMOVED FROM THE FORM 2026-08-16, Ian testing live: "remove tip jar
               and onshape". loothprint_onshape_link and loothprint_buy_me_a_coffee
               are gone from the field list only.
               ⚠️ THE DATA IS UNTOUCHED AND STILL RENDERS, AND IAN RULED THAT IS
               CORRECT (2026-08-16, via keeper): LEAVE AS-IS. lg-layout-v2
               Plugin.php (~530-564) synthesises both into page callouts, and on
               dev2 today 7 published loothprints carry an Onshape link and 14 carry
               a tip jar, out of 168. Those keep rendering. The fields are dead FOR
               NEW POSTS ONLY and there is NO DATA MIGRATION — nothing deletes a
               member's link, and the ~21 posts that have one keep showing it even
               though the author can no longer edit or clear it from this form.
               Recorded here rather than left as an open question, because the
               numbers are the reason the ruling went the way it did. */
            // Rendered by us, not by ACF — see lg_fc_comment_status().
            'comments' => ['label' => 'Let people comment', 'acf_label' => 'Commenting'],
            /* THE PAYWALL TOGGLE (Ian, 2026-08-21). Declared per-type rather than
               assumed, because "behind the paywall" is a Loothprint sentence: a
               type whose content is not gated would get a control that decides
               nothing. lg_fc_own_controls() renders it only when this is set. */
            'paywall'  => [
                'label'  => 'Who can get the files?',
                'behind' => 'Members only',
                'public' => 'Anyone',
                'hint'   => 'Members only keeps your print files behind the paywall. This is the usual choice.',
            ],
            // The mock drops the featured_image control and promises the footer
            // line above instead. lg_fc_hero_from_gallery() is what keeps that
            // promise. NB "unless you pick another" has no control in the mock —
            // raised as an open question rather than invented here.
            'hero_from' => 'loothprint_more_images',
        ],
    ]);
}

/* ────────────────────────────────────────────────────────────── the gate ───── */

/**
 * May this user compose this type? The ONLY answer to that question.
 *
 * ══ IAN RULED ALL MEMBERS, 2026-08-09 — THE ALLOW-LIST IS GONE ═══════════════
 *
 * The previous version carried two tiers and an allow-list, because the scope's
 * §6 argued that letting ~1,850 accounts publish a video unreviewed was too
 * broad. Ian ruled the other way: ALL MEMBERS, and drop the gated path entirely.
 * So it is deleted rather than left dormant — a tier system with one tier in it
 * is a thing the next reader has to disprove, and `lg_fc_on_allow_list()` sitting
 * unused would read as a gate that is merely switched off.
 *
 * THE FLAG IS NOW THE ONLY SAFETY, which is the deliberate consequence of that
 * ruling and is why the shared config stays OFF by default: nothing else
 * narrows who gets this once it is on.
 *
 * WHAT REMAINS IS NOT A GATE, IT IS A FUNCTIONAL FLOOR, and the distinction
 * matters because Ian ruled on the gate and not on this. `upload_files` is what
 * the photo field needs to work at all — the gallery is REQUIRED, so a member
 * without it cannot complete the form, and serving it to them would be showing a
 * door that cannot open. `edit_posts` is what `create_posts` maps to for these
 * types, i.e. WordPress's own precondition for the post existing. Refusing those
 * accounts is the honest behaviour; it is not a policy narrower than "all
 * members", because they are not members who could post anyway.
 *
 * If that reading is wrong and Ian wants even those accounts to see the form,
 * this is the one place to change.
 */
function lg_fc_may_compose(string $type, int $user_id = 0): bool
{
    $types = lg_fc_types();
    if (!isset($types[$type])) {
        return false;
    }
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id) {
        return false;
    }
    $user = get_userdata($user_id);
    if (!$user) {
        return false;
    }

    // Functional floor, not policy — see the header above.
    return user_can($user, 'edit_posts') && user_can($user, 'upload_files');
}

/**
 * Is this post's page FROZEN away from its fields?
 *
 * A loothprint's page is SYNTHESIZED from its meta — that is the whole reason the
 * compose form is cheap. But Plugin::load_layout() gives an explicit
 * `_lg_layout_v2` blob priority over synthesis, and managed singles already carry
 * an "Edit page" button (FeEditor::render_header_button) whose save writes exactly
 * that blob (EditorRest.php:290).
 *
 * So a member who edits their page once has, silently, decoupled it from their
 * fields — and every later edit through THIS form would change the data and not
 * the page. Proven on a throwaway, not inferred:
 * tools/frontend-compose/synth-freeze-probe.php (the body froze at the blob's
 * text and a subsequent field change never reached the page).
 *
 * ⚠️ RULED 2026-08-14, and the ruling changes what this notice MEANS. Ian: "I want
 * all the old posts and the new posts to be handled by layout-v2." So a stored page
 * is no longer an anomaly to warn about — it is the intended state for every
 * loothprint. This form is DETAILS ONLY; the page belongs to layout-v2.
 *
 * The notice therefore stops proposing that the layout be removed (that would now
 * be working against the ruling) and states the split plainly instead: title and
 * hero reach the page because post-header reads them live; description, photos,
 * print files and licence are BAKED into the stored blocks and do not.
 *
 * ⚠️ THE PRINT FILES ARE THE ONE THAT BITES, and it is reported to Ian rather than
 * designed around: a member replaces their ZIP, the form says saved, and the page
 * keeps offering the OLD download. It still works, so nobody notices it is wrong.
 * Whether the form should keep those pieces of the page in step is his call and is
 * on the decision page; until he answers, this notice is what stops it lying.
 */
function lg_fc_page_is_frozen(int $post_id): bool
{
    if (!$post_id) {
        return false;
    }
    if (!class_exists('\LG\LayoutV2\Plugin')) {
        return false;   // layout-v2 absent — nothing to be frozen by
    }
    $synth = ['event', 'loothprint', 'loothcuts', 'useful_links', 'document', 'member-benefit'];
    if (!in_array(get_post_type($post_id), $synth, true)) {
        return false;   // non-synth types are blob-driven by design
    }
    // Use layout-v2's OWN constant rather than spelling the key here. I first
    // wrote two guesses ('lg_layout_v2' and '_lg_layout_v2') hoping one would
    // stick — a key that never matches reports "not frozen" for every post and
    // the warning would simply never appear, which is the silent-no-op class this
    // file keeps running into.
    $key = defined('LG_LAYOUT_V2_META_KEY') ? LG_LAYOUT_V2_META_KEY : '_lg_layout_v2';
    return !empty(get_post_meta($post_id, $key, true));
}

/**
 * Every member publishes. There is no pending path any more.
 *
 * The old 'pending' fallback existed as a safety valve UNDER the allow-list: if
 * the refusal ever had a hole, the hole produced a moderation queue instead of a
 * live page. With no allow-list there is nothing for it to be a valve on, and
 * leaving it would silently hold back posts Ian has said should publish.
 *
 * Kept as a function rather than inlined because the value is still computed
 * server-side from the authenticated user on the request that WRITES, never
 * carried from the request that rendered — that property is what stops a client
 * choosing its own post_status, and it survives the ruling.
 */
function lg_fc_post_status(string $type, int $user_id): string
{
    /* RE-RULED 2026-08-19 (#93), reversing the 8/? removal above: Ian tested
       compose on dev2 ("It works") and set the flip's last gate — member
       submissions arrive PENDING and email him; the moderation queue is the
       point now, not a valve. Moderators (edit_others_posts) still publish
       directly: Ian composing must not moderate himself. Still computed
       server-side from the authenticated user on the WRITE request. */
    return user_can($user_id, 'edit_others_posts') ? 'publish' : 'pending';
}

/**
 * Explicit intent for comments, mapped from our own control rather than from the
 * frontend-admin `allow_comments` ACF field type.
 *
 * Two reasons not to use the field: it is provided by frontend-admin-pro rather
 * than ACF Pro, so whether it saves through a plain acf_form() is an untested
 * dependency; and comment_status is a wp_insert_post argument we are already
 * stating explicitly, so routing it through meta would be the longer way round.
 *
 * The read is a strict two-value mapping, so there is no injection surface, and
 * the value is only ever CONSUMED after ACF has verified the form nonce.
 */
function lg_fc_comment_status(): string
{
    $v = isset($_POST['lg_fc_comments']) ? (string) $_POST['lg_fc_comments'] : '';
    return $v === 'closed' ? 'closed' : 'open';
}

/* ───────────────────────────────────────────────────────────── the route ───── */

add_action('template_redirect', 'lg_fc_route', -10);

/**
 * The member's WORKING DRAFT for this type — found, or created.
 *
 * Ian, 2026-08-15, on the media model: "Do what you recommend" — a hidden draft
 * exists from compose-open, every upload parents to it FROM BIRTH, the picker
 * queries by post_parent so each post literally has its own library, an
 * abandoned compose leaves a resumable invisible draft, and a generous reaper
 * clears the never-returned ones.
 *
 * ── WHY A REAL ROW HAS TO EXIST FIRST ───────────────────────────────────────
 * Measured before this changed: with post_id => 'new_post' the post does not
 * exist while the member fills the form, so an upload has nothing to parent to.
 * It lands as post_parent = 0 and STAYS there — I uploaded through the real
 * picker, abandoned, and the row survived with the file on disk and nothing
 * referencing it. Neither our build nor WordPress core sweeps unattached media.
 * A row that exists from the start is what makes both halves of the ruling
 * possible at once: uploads have a parent, and "this post's library" is a real
 * query rather than a wish.
 *
 * STATUS IS 'auto-draft' ON PURPOSE. It is excluded from the front end, from
 * archives and from search, so a half-filled loothprint cannot surface. The
 * status becomes the real one only on submit (lg_fc_promote_draft).
 *
 * REUSED, NOT RE-CREATED: a member who opens compose five times must not leave
 * five drafts. Their newest un-promoted draft of this type is handed back.
 */
function lg_fc_working_draft(string $type, int $user_id): int
{
    if ($user_id <= 0) return 0;

    $existing = get_posts([
        'post_type'        => $type,
        'post_status'      => 'auto-draft',
        'author'           => $user_id,
        'numberposts'      => 1,
        'orderby'          => 'ID',
        'order'            => 'DESC',
        'fields'           => 'ids',
        'meta_key'         => LG_FC_DRAFT_META,
        'suppress_filters' => false,
    ]);
    if (!empty($existing[0])) return (int) $existing[0];

    /* TITLE MUST NOT BE EMPTY. wp_insert_post() REFUSES a post with no title,
       content and excerpt — wp_insert_post_empty_content — and returns an error
       rather than a row. Measured: with post_title '' the draft was never
       created, lg_fc_working_draft returned 0, and the form silently fell back
       to 'new_post', i.e. straight back to the orphan behaviour this replaces.
       'Auto Draft' is WordPress's own placeholder for exactly this row
       (get_default_post_to_edit), and the member's real title overwrites it on
       submit. */
    $id = wp_insert_post([
        'post_type'      => $type,
        'post_status'    => 'auto-draft',
        'post_author'    => $user_id,
        'post_title'     => 'Auto Draft',
        'comment_status' => lg_fc_comment_status(),
    ], true);
    if (is_wp_error($id) || !$id) {
        /* Say WHY in the log rather than falling back mutely: the fallback is
           the OLD unparented behaviour, so a silent one hides a regression that
           looks exactly like nothing happening. */
        error_log('lg-fc: could not create working draft for ' . $type . ': '
            . (is_wp_error($id) ? $id->get_error_message() : 'insert returned 0'));
        return 0;
    }

    /* The reaper's handle. Marking the row rather than inferring from status
       means the sweep can never mistake somebody else's auto-draft — one made
       by wp-admin, say — for ours. */
    update_post_meta($id, LG_FC_DRAFT_META, time());
    return (int) $id;
}

/**
 * Promote the working draft to a real post on submit.
 *
 * acf_form()'s `new_post` argument only applies when post_id === 'new_post';
 * we hand it a numeric id, so the status transition is ours to make. Doing it
 * here rather than in `new_post` is what keeps the row invisible for the whole
 * time the member is typing.
 */
function lg_fc_promote_draft($post_id): void
{
    $post_id = (int) $post_id;
    if ($post_id <= 0) return;
    if (!get_post_meta($post_id, LG_FC_DRAFT_META, true)) return;   // not ours

    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'auto-draft') return;

    $status = lg_fc_post_status($post->post_type, (int) $post->post_author);
    wp_update_post([
        'ID'             => $post_id,
        'post_status'    => $status,
        'comment_status' => lg_fc_comment_status(),
    ]);
    delete_post_meta($post_id, LG_FC_DRAFT_META);   // no longer the reaper's business

    /* #93: a member submission entering the moderation queue emails Ian at
       that moment — the queue is only real if he hears about it. Sent on the
       pending path only; his own direct publishes stay silent. */
    if ($status === 'pending') {
        $author = get_userdata((int) $post->post_author);
        wp_mail(
            get_option('admin_email'),
            'Pending review: ' . get_the_title($post_id),
            "A member submitted a " . $post->post_type . " for review.\n\n"
            . "Title:  " . get_the_title($post_id) . "\n"
            . "Member: " . ($author ? $author->display_name : ('user #' . $post->post_author)) . "\n\n"
            . "Review: " . admin_url('post.php?post=' . $post_id . '&action=edit') . "\n"
        );
    }
}
add_action('acf/save_post', 'lg_fc_promote_draft', 25);   // after the fields are written

/**
 * EACH POST HAS ITS OWN LIBRARY (Ian). Both media fields are scoped to the post
 * being composed, so the picker lists that post's uploads and nothing else.
 *
 * Forced HERE rather than in the ACF field config because the config is data in
 * the database: an admin editing the field group could silently widen it back to
 * the whole site, and nothing would fail. Measured before this: the photos field
 * was already `uploadedTo`, but the PRINT FILES field was `all`.
 */
function lg_fc_scope_library(array $field): array
{
    $field['library'] = 'uploadedTo';
    return $field;
}
add_filter('acf/load_field/name=loothprint_more_images', 'lg_fc_scope_library');
add_filter('acf/load_field/name=loothprint_3d_file',     'lg_fc_scope_library');
add_filter('acf/load_field/name=loothcut_cnc_file',      'lg_fc_scope_library');

/**
 * THE REAPER. A never-returned draft and everything uploaded into it, cleared
 * after LG_FC_DRAFT_TTL_DAYS.
 *
 * Generous on purpose (Ian: "approx 30 days"): an abandoned compose is usually
 * an interrupted one, and the draft is resumable until this runs.
 *
 * ⚠️ CHILDREN ARE DELETED EXPLICITLY. WordPress's own wp_delete_auto_drafts()
 * removes the POST and leaves its attachments behind — which is the exact
 * orphan this whole change exists to stop, and it would have failed silently
 * had we assumed core handled it.
 */
function lg_fc_reap_drafts(): int
{
    $cut = time() - (LG_FC_DRAFT_TTL_DAYS * DAY_IN_SECONDS);
    $ids = get_posts([
        'post_type'        => array_keys(lg_fc_types()),
        'post_status'      => 'auto-draft',
        'numberposts'      => 200,
        'fields'           => 'ids',
        'meta_key'         => LG_FC_DRAFT_META,
        'suppress_filters' => false,
    ]);
    $gone = 0;
    foreach ($ids as $id) {
        if ((int) get_post_meta($id, LG_FC_DRAFT_META, true) > $cut) continue;
        foreach (get_children(['post_parent' => $id, 'post_type' => 'attachment', 'numberposts' => -1, 'fields' => 'ids']) as $att) {
            wp_delete_attachment($att, true);
        }
        wp_delete_post($id, true);
        $gone++;
    }
    return $gone;
}
add_action('lg_fc_reap_drafts_event', 'lg_fc_reap_drafts');

/**
 * Schedule the reaper — ONLY while the feature is on.
 *
 * With the flag off nothing is scheduled at all, so "flag OFF ⇒ no cron event"
 * is a real assertion rather than a hopeful one. Unscheduling on the way down
 * matters just as much: an event left armed after the feature is switched off
 * would keep deleting rows for a feature nobody can reach, and it would do it
 * from WP-cron, which carries no environment to explain itself.
 */
function lg_fc_sync_reaper_schedule(): void
{
    $on = lg_fc_enabled();
    $next = wp_next_scheduled('lg_fc_reap_drafts_event');
    if ($on && !$next) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'lg_fc_reap_drafts_event');
    } elseif (!$on && $next) {
        /* CLEAR THE HOOK, NOT "THE NEXT ONE". wp_unschedule_event() removes a
           SINGLE timestamped occurrence, so if the cron array ever holds two
           entries for this hook the flag going off leaves one of them armed —
           and an armed reaper deletes drafts and their attachments daily for a
           feature nobody can reach. Measured: with two entries planted, the old
           line healed one and left the other, and gate 46's assertion 7 went
           red on exactly that. wp_clear_scheduled_hook() takes them all, so one
           WP load with the flag off is enough to disarm however many exist. */
        wp_clear_scheduled_hook('lg_fc_reap_drafts_event');
    }
}
add_action('init', 'lg_fc_sync_reaper_schedule');

function lg_fc_route(): void
{
    // Flag OFF: not merely "renders nothing" but "returns before anything is
    // registered, enqueued or emitted", which is what makes the byte-identical
    // assertion true rather than approximately true.
    if (!lg_fc_enabled()) {
        return;
    }

    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    if ($path !== LG_FC_PATH) {
        return;
    }

    $types = lg_fc_types();
    $edit  = isset($_GET['id']) ? absint($_GET['id']) : 0;
    /* Set for a NEW compose once type and permission are settled: the member's
       hidden working draft, so uploads have a parent from birth. */
    $draft = 0;
    // EMBED: the same form, without the page furniture, for the hub composer's
    // type toggle (Ian, 2026-08-09). See lg_fc_page_open() for why this is still
    // a complete document rather than a fragment.
    $embed = !empty($_GET['embed']);

    // ── EDIT MODE (Ian, 2026-08-09: members edit their own loothprints) ───────
    //
    // THE TYPE IS DERIVED FROM THE POST, never from the query string. `?type=`
    // is ignored entirely once `?id=` is present, so a caller cannot name one
    // type and be handed another type's field list — the same "re-check on the
    // STORED post type, never a client-supplied one" rule reply.php:205
    // documents for discussions.
    if ($edit) {
        $post = get_post($edit);
        $type = $post ? $post->post_type : '';

        // Not a post, or not a type this route composes -> the ordinary 404.
        // Deliberately indistinguishable from "no such post": a member probing
        // ids should not learn which ones exist.
        if (!$post || !isset($types[$type])) {
            return;
        }
        if (!is_user_logged_in()) {
            return;
        }
        // THE WHOLE GATE FOR EDIT, and the only thing edit needs that create
        // does not. current_user_can('edit_post', $id) resolves ownership AND
        // the moderator case in one call, which is why the superseded edit scope
        // found it discriminated perfectly where a capability check could not.
        if (!current_user_can('edit_post', $edit) || !lg_fc_may_compose($type)) {
            lg_fc_refuse($types[$type]['title'] ?? 'This post');
            exit;
        }
    } else {
        $type = isset($_GET['type']) ? sanitize_key((string) $_GET['type']) : '';

        // An unknown type, and an anonymous visitor, both get the ordinary 404.
        // We do not redirect anon to sign-in: a visitor who guessed the URL
        // learns nothing from a 404.
        if (!isset($types[$type]) || !is_user_logged_in()) {
            return; // falls through to lg-error-pages.php's branded 404
        }
        if (!lg_fc_may_compose($type)) {
            lg_fc_refuse($types[$type]['title'] ?? 'This form');
            exit;
        }

        /* AFTER the permission gate, never before: creating the draft is a
           WRITE, and a refused visitor must not be able to make rows. */
        $draft = lg_fc_working_draft($type, get_current_user_id());
    }

    // Registered so the settings never travel with the POST. Built here, on THIS
    // request, from THIS user — so the POST recomputes them rather than trusting
    // whatever the GET produced.
    $uid = get_current_user_id();
    $t   = $types[$type];
    // On edit, post_id is the REAL id and new_post is unused — ACF branches on
    // post_id === 'new_post'. post_status and post_author are deliberately NOT
    // restated for an edit: they are the member's existing values and changing
    // them is not what "edit your loothprint" means. comment_status is ours to
    // set either way, because the control is on screen either way.
    acf_register_form([
        'id'                 => 'lg-fc-' . $type,
        /* DRAFT-FIRST. A hidden auto-draft exists before the member types, so an
           upload has a parent FROM BIRTH and `uploadedTo` scoping has something
           real to scope to. Measured before this changed: with 'new_post' the
           post does not exist yet, so an upload lands post_parent = 0 and STAYS
           there — neither this build nor WordPress core sweeps unattached media.
           'new_post' is kept as the fallback for the case where the draft could
           not be created, which is the old behaviour rather than a hard failure. */
        'post_id'            => $edit ?: ($draft ?: 'new_post'),
        'new_post'           => ($edit || $draft) ? [] : [
            'post_type'      => $type,
            'post_status'    => lg_fc_post_status($type, $uid),
            'post_author'    => $uid,
            'comment_status' => lg_fc_comment_status(),
        ],
        // Both false on purpose — the pseudo-fields are ordered via 'fields'.
        'post_title'         => false,
        'post_content'       => false,
        'fields'             => lg_fc_acf_field_names($type),
        'form'               => true,
        'honeypot'           => true,
        'submit_value'       => $t['submit'],
        'updated_message'    => '',
        // ACF emits its OWN <form>, so ours must be it rather than wrap it. A
        // wrapper produced nested forms, the browser closed the outer one, and
        // the comments control below ended up OUTSIDE the form — present, styled,
        // and never submitted. Exactly the "a control that looks right and writes
        // nothing" class this lane was warned about, found by looking at the
        // rendered page rather than at the code.
        'form_attributes'    => [
            'class'             => 'acf-form lgfc__form',
            'data-lgfc-type'    => $type,
        ],
        /* $edit rides along so the controls we render ourselves can be PREFILLED
           from the post. Without it the comments chip re-opened comments the
           author had closed, and the paywall chip would have misreported the
           post's real gating. */
        'html_after_fields'  => lg_fc_own_controls($t, $edit),
        'html_submit_button' => '<input type="submit" class="lgfc__submit" value="%s" />'
            . '<span class="lgfc__foot">' . esc_html($t['foot']) . '</span>',
        /* #93: a member's new post arrives PENDING, so its permalink would 404
           at them — send them back to compose with the review banner instead.
           Same server-side status decision as the write path; moderators keep
           landing on their published post. */
        'return'             => $edit
            ? add_query_arg('lg_fc', 'saved', get_permalink($edit) ?: home_url('/'))
            : (lg_fc_post_status($type, get_current_user_id()) === 'pending'
                /* ⚠️ THE ROUTE IS /compose/?type=<t>, NOT /compose/<t>/. The path
                   form 404s, and WP's redirect_canonical then GUESSES the post-type
                   archive — which is how a member landed on the OLD THEME's bare
                   /loothprint/ page instead of their thank-you (Ian, 8/21, with a
                   screenshot). Build the route the way the route is registered. */
                ? add_query_arg(['type' => $type, 'lg_fc' => 'review'], home_url('/compose/'))
                : add_query_arg('lg_fc', 'posted', get_permalink() ?: home_url('/'))),
    ]);

    add_action('wp_enqueue_scripts', 'lg_fc_shed_site_chrome', PHP_INT_MAX);

    // Processes the submission (nonce-checked) and redirects on success. Must run
    // before any output.
    acf_form_head();

    lg_fc_render($type, $edit, $embed);
    exit;
}

/**
 * The ACF field names for a type, in mock order. post_title / post_content are
 * excluded: ACF appends its own _post_title / _post_content pseudo-fields for
 * those, and those are the ones its save handler reads
 * (form-front.php:266-273). Referencing the field group's frontend-admin
 * post_title field instead would render something that looks right and writes
 * nothing.
 */
function lg_fc_acf_field_names(string $type): array
{
    return array_column(lg_fc_types()[$type]['fields'], 0);
}

/**
 * The controls we render ourselves, placed INSIDE ACF's form via
 * html_after_fields. See lg_fc_comment_status() for why comments is ours.
 */
function lg_fc_own_controls(array $t, int $edit = 0): string
{
    $label = esc_html($t['comments']['label']);
    $hero  = !empty($t['hero_from']) ? lg_fc_hero_control() : '';

    /* ⚠️ PREFILLED FROM THE POST, NOT HARDCODED — and this is a fix, not a
       flourish. This control shipped with `checked` nailed to "Yes", so EDITING a
       post whose author had turned comments off silently re-opened them on save:
       lg_fc_comment_status() reads the posted value, and the posted value was
       always "open" because that is what the form rendered. A data-changing
       defect in the save path, found while adding the control below it.
       Create keeps "Yes" — that is a default, and defaults are fine; it is the
       EDIT case where a default overwrites an answer. */
    $cOpen   = ($edit <= 0) || get_post_field('comment_status', $edit) !== 'closed';
    $cYes    = $cOpen ? ' checked' : '';
    $cNo     = $cOpen ? '' : ' checked';

    return $hero . <<<HTML
<div class="acf-field lgfc-field lgfc__own" data-name="lg_fc_comments">
  <div class="acf-label"><label>{$label}</label></div>
  <div class="acf-input"><div class="lgfc__chips">
    <label class="lgfc__chip"><input type="radio" name="lg_fc_comments" value="open"{$cYes}> <span>Yes</span></label>
    <label class="lgfc__chip"><input type="radio" name="lg_fc_comments" value="closed"{$cNo}> <span>No</span></label>
  </div></div>
</div>
HTML
    . lg_fc_paywall_control($t, $edit);
}

/**
 * "Who can get the files?" — Ian's paywall toggle, 2026-08-21.
 *
 * Returns '' when the switch is off or the type declares no paywall, so OFF is
 * genuinely no bytes rather than a hidden control. Built as a heredoc appended to
 * the string above (never interleaved with `?>`), because this route's OFF state
 * is asserted BYTE-IDENTICAL and PHP eats the newline after a close tag but not
 * the whitespace before an open tag — the recorded 8-byte indentation leak.
 *
 * ── THE PREFILL TELLS THE TRUTH, WHICH IS NOT THE SAME AS THE DEFAULT ───────
 * Create defaults to "Members only", which is Ian's ruling and the safe
 * direction. An EDIT reflects what the post ACTUALLY is: a loothprint carrying no
 * tier term at all is not gated today, so it prefills as "Anyone" even though a
 * new post would default the other way. Showing "Members only" on a post that is
 * in fact public would be the form lying about the thing it is asking you to
 * change — and the member would have to notice to avoid changing it by accident.
 */
function lg_fc_paywall_control(array $t, int $edit = 0): string
{
    if (empty($t['paywall']) || !lg_fc_paywall_enabled()) {
        return '';
    }
    $p      = $t['paywall'];
    $label  = esc_html($p['label']);
    $behind = esc_html($p['behind']);
    $public = esc_html($p['public']);
    $hint   = esc_html($p['hint']);

    $isBehind = ($edit <= 0)
        ? true                                                   // Ian's ruled default
        : (lg_fc_paywall_target(lg_fc_paywall_current($edit), 'public') !== null);
    $bSel = $isBehind ? ' checked' : '';
    $pSel = $isBehind ? '' : ' checked';

    return <<<HTML
<div class="acf-field lgfc-field lgfc__own" data-name="lg_fc_paywall">
  <div class="acf-label"><label>{$label}</label>
    <p class="description">{$hint}</p></div>
  <div class="acf-input"><div class="lgfc__chips">
    <label class="lgfc__chip"><input type="radio" name="lg_fc_paywall" value="behind"{$bSel}> <span>{$behind}</span></label>
    <label class="lgfc__chip"><input type="radio" name="lg_fc_paywall" value="public"{$pSel}> <span>{$public}</span></label>
  </div></div>
</div>
HTML;
}

/**
 * "Your hero image is the first photo unless you pick another" — the control that
 * sentence has been promising.
 *
 * Ian, 2026-08-16, testing live: "There is no featured image". The footer prose
 * has claimed a picker since the form shipped and there was never one to click:
 * prose promising an absent control, which is a defect class in its own right
 * because a member reads it and goes looking.
 *
 * EMPTY AND JS-FILLED ON PURPOSE. The photos live in ACF's gallery field, which
 * builds its own DOM after ACF boots and mutates it on every add/remove/reorder.
 * Rendering a server-side copy of that list would be a second source of truth
 * that goes stale the moment somebody drags a photo — so the strip mirrors the
 * gallery live instead, and stays hidden until there is something to choose.
 *
 * The hidden input carries only the chosen attachment id; lg_fc_hero_pick()
 * re-validates it server-side (attachment, and parented to THIS post) because a
 * hidden input is a member-controlled value.
 */
function lg_fc_hero_control(): string
{
    return <<<HTML
<div class="acf-field lgfc-field lgfc__own lgfc__hero" data-name="lg_fc_hero" hidden>
  <div class="acf-label"><label>Which photo leads?</label>
    <p class="description">This is the one people see first, in the feed and at the top of your page.</p></div>
  <div class="acf-input">
    <input type="hidden" name="lg_fc_hero" value="">
    <div class="lgfc__herostrip" role="radiogroup" aria-label="Choose the lead photo"></div>
  </div>
</div>
HTML;
}

/**
 * Drop the site chrome from this standalone page.
 *
 * The first render pulled 91 scripts and 37 stylesheets — the whole BuddyBoss,
 * BuddyPress, FluentForms and theme stack — into a page that is one card on an
 * empty background. It also painted a floating "ADMIN →" pill over the form.
 *
 * A DENY-LIST, NOT AN ALLOW-LIST, and that is the important decision. acf_form()
 * for this type needs the gallery, the media modal, select2, the colour picker
 * and their dependency closure; an allow-list that misses one member of that
 * closure breaks the photo uploader SILENTLY — the field still renders, the
 * button just stops working — which is precisely the kind of defect a page-level
 * screenshot passes and a member reports. Naming what we know we do not want is
 * the direction of error we can afford.
 *
 * wp_head() itself is still called, because it is what prints ACF's own
 * enqueues. This trims what it prints; it does not bypass it.
 */
function lg_fc_shed_site_chrome(): void
{
    $drop = apply_filters('lg_fc_drop_handle_prefixes', [
        'bp-', 'bb-', 'buddy', 'fluent', 'fea-', 'wp-ulike', 'tutor', 'meprlms',
        // ⚠️ THESE TWO LOOK LIKE THEY CONTRADICT THE SITE CHROME ADDED 2026-08-16
        // (Ian: "can we get the header and footer so it looks like a normal
        // page?"). They do not, and the distinction matters if you ever tidy this
        // list: the chrome here is NOT enqueued. lg_fc_page_open() prints a plain
        // <link> to /lg-shared/site-header.css and requires the partial directly,
        // so it is untouched by a dequeue pass. Dropping these handles still keeps
        // out whatever ELSE enqueues them site-wide (lg-layout-v2 does, on every
        // page), which would otherwise load the same stylesheet twice.
        // Remove them only if you also stop printing that <link>.
        'lg-shared-site-header', 'lg-site-footer', 'lg-wd-',
        'twentytwentyfive', 'wp-block-library', 'global-styles',
    ]);

    foreach ([wp_scripts(), wp_styles()] as $dep) {
        foreach ((array) $dep->queue as $handle) {
            foreach ($drop as $prefix) {
                if (strpos($handle, $prefix) === 0) {
                    $dep->dequeue($handle);
                    break;
                }
            }
        }
    }
}

/* ──────────────────────────────────────────────────────── the save path ───── */

/**
 * Keep the footer's promise: "Your hero image is the first photo unless you pick
 * another." The mock removes the featured-image control, so without this the
 * post has no hero and the synthesized post-header renders bare.
 *
 * Guarded on "unless you pick another": if a thumbnail is already set we leave it
 * alone. Today nothing can set one from this form, so the guard is defensive —
 * but it is the difference between deriving a default and overwriting a choice,
 * and that is the whole subject of this lane's hard constraint.
 */
/**
 * The hero the member actually PICKED, if they picked one.
 *
 * Ian, 2026-08-16, testing live: "There is no featured image". He was right, and
 * the sharper version of it is that this form's own footer has been PROMISING a
 * picker — "Your hero image is the first photo unless you pick another" — while no
 * control to pick another existed. Prose promising an absent control.
 *
 * The server half was already here and already correct: lg_fc_hero_from_gallery()
 * bails the moment a thumbnail is set ("the member picked one — never overwrite
 * it"). So this only had to give them a way to set it. Runs at 19, BEFORE the
 * auto-pick at 20, so a deliberate choice always beats "first photo".
 *
 * VALIDATED, not trusted: the id must be an attachment, and it must belong to
 * THIS post — the draft-first model parents every upload to the post from birth,
 * so post_parent is the honest test. Without it a member could point their hero
 * at somebody else's image by editing one hidden input.
 */
function lg_fc_hero_pick($post_id): void
{
    if (!is_numeric($post_id)) {
        return;
    }
    $post_id = (int) $post_id;
    if (!isset($_POST['lg_fc_hero'])) {
        return;
    }
    $att = absint($_POST['lg_fc_hero']);
    if (!$att) {
        return;   // "no explicit pick" — leave it to the auto-pick at 20
    }
    if (get_post_type($att) !== 'attachment') {
        return;
    }
    if ((int) wp_get_post_parent_id($att) !== $post_id) {
        return;   // not this post's media — refuse rather than reassign
    }
    set_post_thumbnail($post_id, $att);
}
add_action('acf/save_post', 'lg_fc_hero_pick', 19);   // BEFORE the auto-pick
add_action('acf/save_post', 'lg_fc_hero_from_gallery', 20);

function lg_fc_hero_from_gallery($post_id): void
{
    if (!is_numeric($post_id)) {
        return;
    }
    $post_id = (int) $post_id;
    $type    = get_post_type($post_id);
    $types   = lg_fc_types();
    if (!isset($types[$type]) || empty($types[$type]['hero_from'])) {
        return;
    }
    if (get_post_thumbnail_id($post_id)) {
        return; // the member picked one — never overwrite it
    }
    $gallery = get_field($types[$type]['hero_from'], $post_id);
    if (!is_array($gallery) || !$gallery) {
        return;
    }
    $first = reset($gallery);
    $att   = is_array($first) ? ($first['ID'] ?? 0) : (int) $first;
    if ($att) {
        set_post_thumbnail($post_id, (int) $att);
    }
}

/* ───────────────────────────────────────────────────────────── rendering ───── */

function lg_fc_refuse(string $what): void
{
    status_header(403);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    lg_fc_page_open('Not open to your account');
    echo '<div class="lgfc__card"><div class="lgfc__h">'
       . '<h1>' . esc_html($what) . ' isn’t open to your account yet</h1>'
       . '<p>Posting this type is limited at the moment. If you think it should be '
       . 'open to you, say so and it can be.</p></div></div>';
    lg_fc_page_close();
}

/**
 * The way BACK to the hub composer, taken from ?back= and refused unless it is a
 * bare same-site PATH.
 *
 * The hub hands this over when a member taps Loothprint (forums.js §type-toggle)
 * so that switching back to Discussion reopens the wizard where they left it —
 * and so the round trip works unchanged under the lane-preview prefix, where the
 * hub is NOT at /hub/. Hard-coding /hub/ here would send a previewing Ian out of
 * the preview and into the real site.
 *
 * OPEN-REDIRECT GUARD: a value carrying a scheme, a host, a backslash, or a
 * leading `//` is discarded rather than sanitised. This lands in a Location-ish
 * position on a page any member can reach, and "clean it up and use it anyway"
 * is how those become exploitable.
 */
function lg_fc_back_path(): string
{
    $raw = isset($_GET['back']) && is_string($_GET['back']) ? wp_unslash($_GET['back']) : '';
    if ($raw === '') return '';
    $raw = trim($raw);
    if ($raw[0] !== '/') return '';                 // must be a path
    if (strpos($raw, '//') === 0) return '';        // protocol-relative
    if (strpos($raw, '\\') !== false) return '';      // backslash tricks
    if (preg_match('~^/[^/]*:~', $raw)) return '';  // scheme-ish
    if (strpos($raw, "\n") !== false || strpos($raw, "\r") !== false) return '';
    return esc_url_raw($raw);
}

function lg_fc_render(string $type, int $edit = 0, bool $embed = false): void
{
    $t = lg_fc_types()[$type];

    // WP resolved /compose/ as a 404 query before we claimed it; say otherwise.
    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');

    // The mock's labels replace ACF's for the duration of this render only.
    // $edit rides along so the pseudo-fields can be given their CURRENT values —
    // see lg_fc_relabel()'s prefill block for why ACF does not do it for us here.
    $GLOBALS['lg_fc_editing'] = $edit;
    add_filter('acf/prepare_field', 'lg_fc_relabel', 20);
    /* ⚠️ AND AGAIN, TYPE-SCOPED AND LAST. Setting delay inside lg_fc_relabel was
       not enough on the served form — measured 8/21 with a real member session:
       the placeholder "Click to initialize TinyMCE" was still in the bytes, over
       the member's own markup, which is exactly what Ian screenshotted. Something
       downstream of priority 20 restores ACF's own delay. This runs on the
       type-scoped hook at 99, so it is the last word for wysiwyg fields, and it
       is removed with the others when the render ends. */
    add_filter('acf/prepare_field/type=wysiwyg', 'lg_fc_no_delay', 99);

    lg_fc_page_open($t['title'], $embed);
    ?>
<div class="lgfc__card">
<?php /* TYPE TOGGLE — Ian 2026-08-15: discussion stays the default and the two
         forms no longer share a modal, so the toggle has to exist on BOTH
         surfaces or the trip is one-way. Rendered only when the hub told us
         where it came from; a member who reached /compose/ directly gets no
         half-working control. */
      $lg_fc_back = lg_fc_back_path();
      if ($lg_fc_back !== '' && !$edit && !$embed): ?>
  <div class="lgfc__typetoggle" role="tablist" aria-label="What are you posting?">
    <a class="lgfc__typeopt" role="tab" aria-selected="false"
       href="<?php echo esc_url($lg_fc_back); ?>">Discussion</a>
    <span class="lgfc__typeopt is-on" role="tab" aria-selected="true">Loothprint</span>
  </div>
<?php endif; ?>
  <div class="lgfc__h">
    <h1><?php echo esc_html($t['title']); ?></h1>
    <p class="lgfc__sub lgfc__sub--wide"><?php echo esc_html($t['sub']); ?></p>
    <p class="lgfc__sub lgfc__sub--narrow"><?php echo esc_html($t['sub_narrow'] ?? $t['sub']); ?></p>
  </div>
  <?php if (($_GET['lg_fc'] ?? '') === 'review'): ?>
    <?php /* #93: the landing state for a member whose submission just went to
             the moderation queue — their post's permalink would 404 at them,
             so this banner IS the success page. */ ?>
    <div class="lgfc__frozen" role="status">
      <strong>Submitted — thank you!</strong>
      Your loothprint is in for review and will appear on the site once it’s
      approved. You’ll find it under your profile after that.
      <p class="lgfc__backhub">
        Taking you back to the Hub…
        <a class="lgfc__backhub-link" href="/hub/">Go now</a>
      </p>
    </div>
    <?php /* Ian, 8/21: "It should just say thank you for your post and then revert
             to the hub", and "also something about awaiting approval". The message
             carries the approval line; this returns them.

             THE LINK IS NOT DECORATION — it is the whole behaviour when scripting
             is off or the timer never fires, which is why it ships in the markup
             rather than being written by the script. The delay is long enough to
             read two sentences; a member who clicks first simply wins the race. */ ?>
    <script>
    (function () {
      var el = document.querySelector('.lgfc__backhub');
      if (!el) return;
      setTimeout(function () { window.location.href = '/hub/'; }, 5000);
    })();
    </script>
  <?php endif; ?>
  <?php if ($edit && lg_fc_page_is_frozen($edit)): ?>
    <div class="lgfc__frozen" role="status">
      <strong>Heads up — the page and these details are kept separately.</strong>
      Your changes here are saved. The title and main photo update the page straight
      away; the description, photos, print files and licence live on the page itself,
      so those will not change here. Tell us and we’ll update them.
    </div>
  <?php endif; ?>
  <?php acf_form('lg-fc-' . $type); ?>
</div>
    <?php
    lg_fc_page_close($embed);
    remove_filter('acf/prepare_field', 'lg_fc_relabel', 20);
    remove_filter('acf/prepare_field/type=wysiwyg', 'lg_fc_no_delay', 99);
    unset($GLOBALS['lg_fc_editing']);
}

/**
 * Swap ACF's stored label/instructions for the mock's, by field name, and mark
 * which fields belong in the "Add extras" fold.
 *
 * Only fields this route knows about are touched, and the filter is added and
 * removed around the render, so nothing else on the site can see it.
 */
/** The editor boots with the form, never on a click — see the registration. */
function lg_fc_no_delay($field)
{
    if (is_array($field)) {
        $field['delay'] = 0;
    }
    return $field;
}

function lg_fc_relabel($field)
{
    if (empty($field['name']) && empty($field['_name'])) {
        return $field;
    }
    $name = $field['_name'] ?? $field['name'];

    /* ⚠️ NO DELAYED EDITOR ON A MEMBER-FACING FORM (Ian, 2026-08-21, from his own
       screenshot of the form: he was looking at a grey bar reading "Click to
       initialize TinyMCE" sitting on top of his write-up rendered as LITERAL
       <p>test</p>).

       MEASURED on the served page before changing anything, which is what named
       the cause: the form ships

           <div class="acf-editor-toolbar">Click to initialize TinyMCE</div>
           <textarea class="wp-editor-area" name="acf[_post_content]">…

       That is ACF's `delay` setting (class-acf-field-wysiwyg.php:240,276-277):
       with it on, ACF renders a PLACEHOLDER and only boots TinyMCE when the
       member clicks. Until they do, the textarea shows the stored HTML as text —
       so on an EDIT the member is shown their own markup, and has no way to know
       the grey bar is a button.

       ACF's own default for this field is delay => 0 (:41), and its pseudo-field
       registration sets no delay at all (form-front.php:59-65) — so something
       else on this box turns it on. Forced OFF here rather than chased, because
       this filter is added and removed around THIS render only: whatever sets it,
       and whenever that changes, this form is unaffected.

       ⚠️ IT IS THE SAME ELEMENT AS GATE 47's OPEN RED. div.acf-editor-toolbar is
       the 712x40 slab of #f5f5f5 the dark sweep flags — one object, two symptoms,
       and this removes it entirely. The dark rule added for it stays as
       belt-and-braces: ACF still renders that div in other configurations. */
    if (($field['type'] ?? '') === 'wysiwyg') {
        $field['delay'] = 0;
    }

    // ACF's pseudo-fields for title and content.
    $map = [];
    foreach (lg_fc_types() as $t) {
        foreach ($t['fields'] as $f) {
            $key = $f[0] === 'post_title' ? '_post_title'
                 : ($f[0] === 'post_content' ? '_post_content' : $f[0]);
            $map[$key] = $f;
        }
    }
    if (!isset($map[$name])) {
        return $field;
    }

    [, $label, $hint] = $map[$name];
    $field['label']        = $label;
    $field['instructions'] = $hint;
    $field['wrapper']['class'] = trim(($field['wrapper']['class'] ?? '') . ' lgfc-field');

    /* RICH TEXT — Ian, 2026-08-16: "rich text with light tinymce controls".
       This is the parity decision the previous comment here said would have to be
       taken on purpose: the field was deliberately downgraded to a textarea so a
       member-facing composer would not drag TinyMCE in by an ACF default nobody
       chose. It is now chosen.

       A CUSTOM TOOLBAR, NOT ACF's 'basic'. ACF's basic maps to WordPress's teeny
       mode, which carries underline, strikethrough, blockquote and three
       alignments — more than "light", and every extra button is a tag the save
       path then has to allow. This toolbar is exactly bold, italic, the two
       lists, link/unlink and undo/redo, so the rule "nothing stored that the
       toolbar cannot make" is a statement about one list rather than a hope.

       DELAYED INIT (delay => 1) IS THE CRAFT LAW, not a preference: composers load
       on intent, never eagerly. TinyMCE is heavy and most visitors to this form
       are filling in a title and a file. ACF only boots the editor when the field
       is first clicked.

       media_upload OFF — the photo and ZIP fields own uploads here, and the media
       modal is already scoped to this post's own library by lg_fc_scope_library().
       A second, UNSCOPED uploader inside the editor would drive straight through
       that. */
    if ($name === '_post_content') {
        $field['type']         = 'wysiwyg';
        $field['toolbar']      = 'lgfc_light';
        $field['media_upload'] = 0;
        $field['tabs']         = 'visual';
        $field['delay']        = 1;
    }

    // ⚠️ PREFILL THE PSEUDO-FIELDS OURSELVES ON EDIT. ACF only fills _post_title
    // and _post_content from the post when they are requested via the
    // post_title/post_content ARGS (form-front.php:463-476). This form names them
    // in the `fields` list instead — the only way to get Ian's field ORDER, since
    // those args always append them at the top — and that path resolves the bare
    // local field with NO value.
    //
    // On CREATE that is invisible: there is nothing to prefill. On EDIT it is a
    // DATA-LOSS BUG, and the worst-shaped kind: the title renders EMPTY, and
    // clobber-probe.php already proved that a field which is rendered and
    // submitted empty is SAVED empty. A member opening their own loothprint to
    // fix a typo would have blanked its title and body by pressing Post.
    //
    // Found by LOOKING at the rendered form. My earlier check greped the whole
    // page for the title text and reported "prefilled" — it had matched the
    // <title> tag. Assert on the input's value attribute, not on the document.
    $edit_id = (int) ($GLOBALS['lg_fc_editing'] ?? 0);
    if ($edit_id) {
        if ($name === '_post_title') {
            $field['value'] = get_post_field('post_title', $edit_id);
        } elseif ($name === '_post_content') {
            $field['value'] = lg_fc_content_for_editor(
                (string) get_post_field('post_content', $edit_id));
        }
    }
    return $field;
}

/**
 * The ONE list of tags this field may contain. The toolbar can make exactly these
 * and nothing else, which is what makes the save filter a closed statement rather
 * than a guess. Add a button here and you must add its tag, and the reverse.
 */
function lg_fc_richtext_allowed(): array
{
    return [
        'p'      => [],
        'br'     => [],
        'strong' => [], 'b'  => [],
        'em'     => [], 'i'  => [],
        'ul'     => [], 'ol' => [], 'li' => [],
        // rel is forced below; target is allowed so an editor-made link survives
        // a round trip unchanged rather than being silently rewritten.
        'a'      => ['href' => [], 'title' => [], 'target' => [], 'rel' => []],
    ];
}

/**
 * Stored write-up -> what the editor should be handed.
 *
 * ⚠️ BACK-COMPAT, AND IT IS NOT HYPOTHETICAL. Measured on dev2 before writing
 * this: of 169 published loothprints, 43 already hold HTML, 54 are empty, and
 * 72 ARE PLAIN TEXT — 32 of those containing newlines. Hand plain text with
 * blank lines straight to a WYSIWYG and TinyMCE collapses it into one
 * paragraph: 32 members' write-ups silently lose their structure the first time
 * anyone opens the new editor and presses Post. That is the "eats line breaks"
 * half of the ruling, with a number on it.
 *
 * So: content that already contains markup is passed through untouched (running
 * wpautop over real HTML is how you get double paragraphs), and content that is
 * plain gets wpautop, which is the same transformation the front end has always
 * applied to it on display. The editor therefore shows what the reader already
 * sees, which is the only definition of "unchanged" that matters to a member.
 */
function lg_fc_content_for_editor(string $raw): string
{
    if (trim($raw) === '') {
        return '';
    }
    // A cheap, deliberate test: does this look like markup at all? strip_tags
    // changing the string is the same question the back-compat count above asked,
    // so the code and the measurement agree by construction.
    if ($raw !== strip_tags($raw)) {
        return $raw;
    }
    return wpautop($raw);
}

/**
 * Save-side sanitisation: nothing is stored that the toolbar cannot make.
 *
 * Runs on the RAW POST value before ACF/WordPress writes it, because the field is
 * a pseudo-field that lands in post_content rather than in meta — so an
 * acf/update_value filter never sees it.
 *
 * wp_kses to lg_fc_richtext_allowed(), then links are forced to rel="nofollow ugc"
 * — member-authored links on a public page, which is what ugc is for.
 */
function lg_fc_sanitize_richtext(string $html): string
{
    $clean = wp_kses($html, lg_fc_richtext_allowed());
    if ($clean !== '' && function_exists('wp_rel_ugc')) {
        /* ⚠️ wp_rel_ugc() RETURNS SLASHED OUTPUT. It is built for filter contexts
           that hand it slashed content, so it wp_slash()es on the way out — and
           only the <a> cases show it, which is why reading the other test rows
           would have missed it. The caller then applies its own wp_slash before
           handing the value back to WordPress, so leaving this slashed stores a
           member's link as href=\"/x\" — literal backslashes in their write-up,
           on every link, forever.

           This function's contract is therefore: takes raw, returns CLEAN AND
           UNSLASHED. Slashing belongs to the caller that talks to $_POST, in one
           place, so the two can never disagree about how many times it happened.

           Found by running the sanitiser over a link case, not by reading it. */
        $clean = wp_unslash(wp_rel_ugc($clean));
    }
    return $clean;
}

/**
 * The light toolbar itself. Registered as its own toolbar rather than overriding
 * ACF's, so nothing else that asks for 'basic' changes underneath us.
 */
/**
 * WIRE THE SANITISER. Defined-but-unhooked is indistinguishable from absent, and
 * this one guards what gets STORED, so it is hooked next to the function rather
 * than somewhere a tidy-up could separate them.
 *
 * ⚠️ WHY acf/pre_save_post AND NOT acf/update_value. _post_content is a
 * PSEUDO-field: its value lands in the post's own post_content, not in meta, so
 * the per-field update_value filter never runs for it. pre_save_post fires with
 * the raw $_POST still in hand and before ACF writes the post, which is the last
 * point where the submitted HTML can be replaced.
 *
 * Scoped to this form's own fields, so nothing else on the site that uses
 * acf_form() has its content rewritten by us.
 */
add_filter('acf/pre_save_post', function ($post_id) {
    if (!empty($_POST['acf']['_post_content']) && is_string($_POST['acf']['_post_content'])) {
        $_POST['acf']['_post_content'] =
            lg_fc_sanitize_richtext(wp_unslash($_POST['acf']['_post_content']));
        // Re-slash: WordPress expects the superglobal to still be slashed when it
        // writes. Unslashing without re-slashing is how a stray backslash ends up
        // in a member's write-up.
        $_POST['acf']['_post_content'] = wp_slash($_POST['acf']['_post_content']);
    }
    return $post_id;
}, 5);

add_filter('acf/fields/wysiwyg/toolbars', function (array $toolbars): array {
    $toolbars['lgfc_light'] = [
        1 => ['bold', 'italic', 'bullist', 'numlist', 'link', 'unlink', 'undo', 'redo'],
    ];
    return $toolbars;
});

/**
 * EMBED IS STILL A WHOLE DOCUMENT, DELIBERATELY, and it is meant to be iframed.
 *
 * The obvious alternative — return an HTML fragment and inject it into the hub
 * page — does not work without dragging ACF's entire front-end stack onto the
 * hub: the gallery, the media modal, select2 and their dependency closure are
 * printed by wp_head() on THIS route, and the hub has none of them. Injecting the
 * markup alone would produce a form whose photo picker silently does nothing,
 * which is the exact "a control that looks right and writes nothing" failure this
 * lane has already hit once.
 *
 * A same-origin iframe keeps that stack where it already works, and keeps this
 * route's CSS from leaking into the hub (and the hub's from leaking in). What
 * `embed` changes is only the page furniture: no outer padding, no page
 * background, no min-height — so the card fills the frame it is given.
 */
/**
 * The $ctx the shared site chrome wants, built from in-process WP state.
 *
 * Ian, 2026-08-16, mid-test on /compose/: "can we get the header and footer so it
 * looks like a normal page?" — he had just ruled the page-jump stays, so the page
 * he lands on has to read as one of ours rather than a bare form.
 *
 * HOUSE DOCTRINE, not invented here: a standalone page MIMICS the chrome, it never
 * renders the WP theme. This is a straight copy of lg-layout-v2's SiteHeader::viewer()
 * — the existing WordPress-side caller — so every WP surface feeds the shared header
 * identical identity. Role -> tier walks highest to lowest so a member holding several
 * looth roles gets the top one, matching Arbiter and InternalRestController.
 */
function lg_fc_chrome_viewer(): array
{
    $user = wp_get_current_user();
    $auth = ($user instanceof WP_User) && (int) $user->ID > 0;

    $tier = 'public';
    if ($auth) {
        foreach (['looth4' => 'pro', 'looth3' => 'pro', 'looth2' => 'lite', 'looth1' => 'public'] as $role => $t) {
            if (in_array($role, (array) $user->roles, true)) { $tier = $t; break; }
        }
    }

    return [
        'authenticated' => $auth,
        'tier'          => $tier,
        'display_name'  => $auth ? (string) $user->display_name : '',
        'avatar_url'    => $auth ? (string) get_avatar_url($user->ID, ['size' => 96]) : null,
        'capabilities'  => [
            'manage_options'   => $auth && user_can($user->ID, 'manage_options'),
            'edit_archive_poc' => $auth && user_can($user->ID, 'edit_archive_poc'),
        ],
        // null = let the header lazy-load these over REST.
        'msg_unread'    => null,
        'notif_unread'  => null,
        // Compose is not a top-nav destination, so nothing is highlighted.
        'active_nav'    => '',
        'logout_url'    => wp_logout_url(home_url('/')),
        // Contract (Ian 2026-06-03): the account chip goes to /u/<slug>; /profile/edit
        // is only the slug-less fallback.
        'profile_url'   => ($auth && $user->user_nicename)
            ? '/u/' . rawurlencode((string) $user->user_nicename)
            : '/profile/edit',
    ];
}

/** The shared chrome partials, on disk. Absolute — NOT __DIR__-relative, which
 *  resolves through the mu-plugin symlink into the repo where these do not sit. */
const LG_FC_CHROME_HEADER = '/srv/lg-shared/site-header.php';
const LG_FC_CHROME_FOOTER = '/srv/lg-shared/site-footer.php';
const LG_FC_CHROME_CSS_FS = '/srv/lg-shared/site-header.css';

/** True when this render should carry the site chrome. */
function lg_fc_wants_chrome(bool $embed): bool
{
    // The embed variant is framed by another page that already has chrome — a
    // second copy inside the frame would be two headers on one screen.
    return !$embed && is_readable(LG_FC_CHROME_HEADER);
}

function lg_fc_page_open(string $title, bool $embed = false): void
{
    ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html($title); ?></title>
<?php wp_head(); ?>
<?php if (lg_fc_wants_chrome($embed)): ?>
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?php echo is_readable(LG_FC_CHROME_CSS_FS) ? (int) filemtime(LG_FC_CHROME_CSS_FS) : 1; ?>">
<?php endif; ?>
<style><?php echo lg_fc_css(); ?></style>
</head>
<body class="lgfc-body<?php echo $embed ? ' lgfc-body--embed' : ''; ?><?php echo lg_fc_wants_chrome($embed) ? ' lgfc-body--chrome' : ''; ?>">
<?php
if (lg_fc_wants_chrome($embed)) {
    require_once LG_FC_CHROME_HEADER;
    if (function_exists('lg_shared_render_site_header')) {
        lg_shared_render_site_header(lg_fc_chrome_viewer());
    }
}
?>
<main class="lgfc<?php echo $embed ? ' lgfc--embed' : ''; ?>">
    <?php
}

function lg_fc_page_close(bool $embed = false): void
{
    ?>
</main>
<?php
if (lg_fc_wants_chrome($embed)) {
    require_once LG_FC_CHROME_FOOTER;
    if (function_exists('lg_shared_render_site_footer')) {
        lg_shared_render_site_footer();
    }
}
?>
<script><?php echo lg_fc_js(); ?></script>
<?php wp_footer(); ?>
</body>
</html>
    <?php
}

/* ────────────────────────────────────────────────────────────── the chrome ───── */

/**
 * Inlined into this one route's response. See the header: this is a stronger
 * craft-law guarantee than a conditional enqueue, because there is no URL for it.
 *
 * DARK MODE COMES FREE, and that is why every colour is a token. The signal on
 * this platform is html[data-lguser-theme="dark"], a resolved app attribute set
 * by app-settings.js — NOT prefers-color-scheme. Dark re-points the --lg-* tokens
 * as inline styles on <html>, so a file that reads tokens follows automatically.
 * Light values live in each var() FALLBACK, exactly as lg-shared/site-header.php
 * and webroot/shop-layout-planner.css do it, which keeps light identical if the
 * dark palette is ever retuned. The mock was drawn in this palette already — its
 * --cream #fbfbf8 and --sage #87986a are the platform's own values — so the
 * translation is a rename, not a reinterpretation.
 *
 * Most of this file styles ACF's markup rather than our own: .acf-field is the
 * per-field wrapper, .acf-label > label the heading, .acf-label .description the
 * hint, .acf-required the asterisk the mock draws as a "NEEDED" pill.
 */
function lg_fc_css(): string
{
    return <<<'CSS'
.lgfc-body{margin:0;background:var(--lg-cream,#fbfbf8);color:var(--lg-ink,#323532);
  font-family:var(--lg-font-sans,-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif);
  -webkit-font-smoothing:antialiased}
.lgfc{max-width:760px;margin:0 auto;padding:26px 18px 96px}
.lgfc *,.lgfc *::before,.lgfc *::after{box-sizing:border-box}

.lgfc__card{border:1px solid var(--lg-line,#e3ddd0);border-radius:16px;
  background:var(--lg-card-bg,#fff);overflow:hidden;box-shadow:0 10px 34px rgba(38,41,37,.06)}
.lgfc__h{padding:19px 21px 15px;border-bottom:1px solid var(--lg-line,#e3ddd0)}
/* TYPE TOGGLE (Ian 8/15) — the same pill pair the hub composer shows, so the
   two surfaces read as one control rather than two designs. Discussion is an
   <a> back to the hub; Loothprint is a <span> because it is where you already
   are, and a button that does nothing is worse than no button. */
.lgfc__typetoggle{display:flex;gap:6px;padding:14px 21px 0}
.lgfc__typeopt{display:inline-block;padding:7px 15px;border-radius:999px;
  border:1px solid var(--lg-line,#e3ddd0);background:var(--lg-card,#fff);
  color:var(--lg-ink-soft,#565a55);font:600 13.5px/1 inherit;text-decoration:none;
  cursor:pointer}
.lgfc__typeopt:hover{border-color:var(--lg-sage,#87986a);color:var(--lg-ink,#262925)}
.lgfc__typeopt.is-on{background:var(--lg-sage,#87986a);border-color:var(--lg-sage,#87986a);
  color:#fff;cursor:default}
.lgfc__h h1{margin:0;font:700 19px/1.25 var(--lg-font-serif,Lora,Georgia,serif);
  color:var(--lg-charcoal,#1a1d1a)}
.lgfc__sub{margin:5px 0 0;color:var(--lg-mute,#6b6f6b);font-size:13.5px}
.lgfc__sub--narrow{display:none}
.lgfc .acf-form-fields{padding:6px 21px 20px}

/* ---- one field ----
   ACF lays its fields out as floated 50% columns and carries an inline
   width from each field's wrapper setting, so "single screen, one column"
   has to be taken rather than asked for. This is the one place !important
   is load-bearing: the inline style cannot be beaten otherwise, and the
   mock is a single column at BOTH widths. */
.lgfc .acf-fields>.acf-field{padding:15px 0;border-bottom:1px solid var(--lg-line,#e3ddd0);
  border-top:0;margin:0;float:none!important;width:100%!important;
  min-height:0;clear:both;display:block}
.lgfc .acf-fields>.acf-field:last-of-type{border-bottom:0}
.lgfc .acf-fields{border:0;padding-left:21px;padding-right:21px}
.lgfc .acf-field[data-name="_validate_email"]{display:none!important}
.lgfc .acf-label{margin:0 0 3px;padding:0}
.lgfc .acf-label label{font:700 14.5px/1.3 var(--lg-font-sans,system-ui,sans-serif);
  color:var(--lg-ink,#323532);margin:0}
.lgfc .acf-label .description,.lgfc .acf-label p.description{
  color:var(--lg-mute,#6b6f6b);font-size:12.8px;line-height:1.45;margin:2px 0 9px;font-style:normal}

/* the mock's NEEDED pill, from ACF's asterisk */
.lgfc .acf-required{font-size:0;color:transparent;margin-left:6px;vertical-align:2px}
.lgfc .acf-required::after{content:"needed";font:700 9.5px/1 var(--lg-font-sans,system-ui,sans-serif);
  letter-spacing:.06em;text-transform:uppercase;color:var(--lg-rust,#c66845);
  background:var(--lg-rust-tint,#fbeee8);border-radius:4px;padding:3px 5px}

/* ---- inputs ---- */
.lgfc .acf-input input[type=text],.lgfc .acf-input input[type=url],
.lgfc .acf-input input[type=email],.lgfc .acf-input textarea{
  width:100%;border:1px solid var(--lg-line,#e3ddd0);border-radius:9px;padding:10px 12px;
  font:500 14px/1.4 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#323532);
  background:var(--lg-paper,#fdfdfa)}
.lgfc .acf-input textarea{min-height:84px;resize:vertical}
.lgfc .acf-input input:focus,.lgfc .acf-input textarea:focus{
  outline:2px solid var(--lg-sage,#87986a);outline-offset:1px;border-color:var(--lg-sage,#87986a)}
.lgfc .acf-input input::placeholder,.lgfc .acf-input textarea::placeholder{color:var(--lg-mute,#6b6f6b);opacity:.62}

/* ---- the rich-text editor ---- */
/* TinyMCE arrives with wp-admin's own skin, which is a light grey toolbar and a
   white border — the same shape as the oEmbed slab fixed earlier tonight, and for
   the same reason: an admin control dropped into a member-facing page in a theme
   it was never drawn for. Chrome is styled from the form's tokens so both themes
   follow; the CONTENT is inside an iframe and is handled in JS, because page CSS
   cannot cross that boundary. */
.lgfc .acf-editor-wrap,.lgfc .wp-editor-container{
  border:1px solid var(--lg-line,#e3ddd0);border-radius:9px;overflow:hidden;
  background:var(--lg-paper,#fdfdfa)}
.lgfc .wp-editor-container .mce-panel,.lgfc .mce-toolbar-grp,.lgfc .mce-toolbar{
  background:var(--lg-paper,#fdfdfa);border-color:var(--lg-line,#e3ddd0)}
.lgfc .mce-btn button{color:var(--lg-ink,#323532)}
.lgfc .mce-ico{color:var(--lg-ink,#323532)}
.lgfc .mce-btn:hover,.lgfc .mce-btn.mce-active{background:var(--lg-sage-tint,#eef2e3)}
.lgfc .mce-statusbar,.lgfc .mce-path{display:none}
/* The delayed editor shows a plain textarea until it is clicked; make that
   placeholder read like the other fields rather than like a bare box. */
.lgfc .acf-editor-wrap .wp-editor-area{border:0;background:var(--lg-paper,#fdfdfa);
  color:var(--lg-ink,#323532);min-height:120px;padding:10px 12px;
  font:500 14px/1.5 var(--lg-font-sans,system-ui,sans-serif)}

/* ---- the video oEmbed ---- */
/* ⚠️ THIS FIELD HAD NEVER BEEN STYLED HERE, and that only became visible tonight.
   It lived inside the "Add extras" fold, so it rendered collapsed and the dark
   sweep never reached it. Ian's ruling 1 pulled it into the main body, and gate 47
   immediately went red on TWO BRIGHT SURFACES in dark mode — measured #ffffff at
   716x293 (div.acf-oembed) and #f9f9f9 at 714x250 (its inner div.canvas). Zero
   contrast failures, because neither box holds text; they are luminance slabs, and
   only the surface half of that gate could see them.

   The lesson worth keeping: MOVING A CONTROL INTO VIEW IS A THEME CHANGE. Nothing
   about this field changed except that it is now visible, and it arrived carrying
   ACF's admin-white defaults into a dark page.

   Styled with the SAME tokens as the text inputs above rather than with hardcoded
   dark values, so light stays coherent and dark follows automatically —
   --lg-paper is already re-pointed to #20241f for .lgfc in dark, which is why
   there is no separate dark rule here. Hardcoding #20241f would have fixed the
   gate and left light mode painted with a dark-mode colour. */
.lgfc .acf-oembed{border:1px solid var(--lg-line,#e3ddd0);border-radius:9px;
  background:var(--lg-paper,#fdfdfa);overflow:hidden}
.lgfc .acf-oembed .title{padding:0;border-bottom:1px solid var(--lg-line,#e3ddd0)}
.lgfc .acf-oembed .title .input-search{border:0;border-radius:0;background:none}
.lgfc .acf-oembed .canvas{background:var(--lg-paper,#fdfdfa);min-height:0;
  color:var(--lg-mute,#6b6f6b)}
/* The empty preview is a 250px void until a URL is pasted. Give it a real height
   only once it has something to show; empty, it is a slim hint rather than a slab. */
.lgfc .acf-oembed .canvas:not(:has(iframe)):not(:has(img)){min-height:64px;
  display:flex;align-items:center;justify-content:center}
.lgfc .acf-oembed .canvas-media{width:100%}
.lgfc .acf-oembed .canvas-media iframe,.lgfc .acf-oembed .canvas-media img{
  display:block;width:100%;border:0}

/* ---- chips: taxonomy checkbox lists and the licence radio ---- */
.lgfc .acf-checkbox-list,.lgfc .acf-radio-list{list-style:none;margin:0;padding:0;
  display:flex;gap:6px;flex-wrap:wrap}
.lgfc .acf-checkbox-list li,.lgfc .acf-radio-list li{margin:0;padding:0;line-height:1}
.lgfc .acf-checkbox-list label,.lgfc .acf-radio-list label{
  display:inline-block;font:600 12.3px/1 var(--lg-font-sans,system-ui,sans-serif);
  border:1px solid var(--lg-line,#e3ddd0);border-radius:999px;padding:8px 12px;
  color:var(--lg-mute,#6b6f6b);background:var(--lg-paper,#fdfdfa);cursor:pointer}
.lgfc .acf-checkbox-list input,.lgfc .acf-radio-list input{position:absolute;opacity:0;
  width:1px;height:1px;pointer-events:none}
.lgfc li:has(input:checked)>label{background:var(--lg-sage-d,#6b7c52);
  border-color:var(--lg-sage-d,#6b7c52);color:#fff}
.lgfc li:has(input:focus-visible)>label{outline:2px solid var(--lg-sage,#87986a);outline-offset:2px}

/* The taxonomy lists are the REAL terms, and there are a lot of them —
   loothprint_type runs to a dozen and shared_category is hierarchical. The mock
   drew a tidy six ("Jig, Tool, Fixture, Clamp, Replacement part, Other") that do
   not exist in either taxonomy, so the real ones are shown and given a bounded,
   scrollable box rather than being invented to fit the drawing. */
.lgfc .acf-field-taxonomy .acf-input>ul,
/* THE TAXONOMY PICKER (Ian: "make modal or something slick"). The old 184px
   scrolling chip box is REPLACED, not restyled — it stays in the DOM as the
   submitted source of truth and is hidden here. Kept visually-hidden rather than
   display:none so a checkbox never becomes unfocusable-but-required, which is a
   validation dead end no member could resolve.
   ⚠️ AND IT ZEROES ITS OWN PADDING AND max-height, at taxonomy-field specificity.
   Verified against the deployed page by injection: with only the plain
   `.lgfc .lgfc-taxo__src` selector the box still measured 20x20 — position and
   clip applied, but a higher-specificity rule's padding held it open, so the old
   list was still a visible smudge under the new control. The rule this file used
   to carry is removed here, but "hidden" must not depend on that removal having
   happened: any leftover padding anywhere would otherwise re-open it. */
.lgfc .acf-field-taxonomy .lgfc-taxo__src,.lgfc .lgfc-taxo__src{
  position:absolute;width:1px;height:1px;min-height:0;max-height:none;
  margin:-1px;padding:0;border:0;overflow:hidden;
  clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap}
/* the closed row — it STATES THE ANSWER, which is what the old box lost */
.lgfc-taxo__trig{display:flex;align-items:center;gap:9px;width:100%;text-align:left;cursor:pointer;
  border:1px solid var(--lg-line,#e3ddd0);border-radius:11px;background:var(--lg-paper,#fdfdfa);
  padding:12px 13px;font:600 13.5px/1.25 var(--lg-font-sans,system-ui,sans-serif);
  color:var(--lg-ink,#323532)}
.lgfc-taxo__trig:hover{border-color:var(--lg-sage,#87986a)}
.lgfc-taxo__lab{flex:0 0 auto}
.lgfc-taxo__val{font-weight:400;color:var(--lg-ink,#323532);min-width:0;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap}
.lgfc-taxo__val.is-empty{color:var(--lg-mute,#6b6f6b)}
.lgfc-taxo__car{margin-left:auto;color:var(--lg-mute,#6b6f6b);font-size:17px;line-height:1}
/* the sheet: a panel under the row on desktop, full-width on a phone */
.lgfc-taxo__sheet{margin-top:8px;border:1px solid var(--lg-line,#e3ddd0);border-radius:13px;
  background:var(--lg-card-bg,#fff);box-shadow:0 18px 40px -20px rgba(26,29,26,.35);
  display:flex;flex-direction:column;max-height:340px;overflow:hidden}
.lgfc-taxo__sheet[hidden]{display:none}
.lgfc-taxo__h{display:flex;align-items:center;gap:9px;padding:11px 13px;
  border-bottom:1px solid var(--lg-line,#e3ddd0)}
.lgfc-taxo__t{margin:0;font:700 13.5px/1 var(--lg-font-sans,system-ui,sans-serif);
  color:var(--lg-ink,#323532)}
.lgfc-taxo__x{margin-left:auto;border:0;background:none;color:var(--lg-mute,#6b6f6b);
  font-size:15px;line-height:1;cursor:pointer;padding:4px}
.lgfc-taxo__search{padding:10px 13px 8px}
.lgfc-taxo__search input{width:100%;border:1px solid var(--lg-line,#e3ddd0);border-radius:9px;
  background:var(--lg-paper,#fdfdfa);padding:9px 11px;
  font:400 13px/1.3 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#323532)}
.lgfc-taxo__b{overflow-y:auto;padding:0 13px 12px;display:flex;flex-wrap:wrap;gap:6px;
  align-content:flex-start}
.lgfc-taxo__opt{font:600 12.3px/1 var(--lg-font-sans,system-ui,sans-serif);
  border:1px solid var(--lg-line,#e3ddd0);border-radius:999px;padding:8px 12px;
  color:var(--lg-mute,#6b6f6b);background:var(--lg-paper,#fdfdfa);cursor:pointer}
.lgfc-taxo__opt:hover{border-color:var(--lg-sage,#87986a)}
.lgfc-taxo__opt.is-on{background:var(--lg-sage-d,#6b7c52);border-color:var(--lg-sage-d,#6b7c52);color:#fff}
.lgfc-taxo__none{margin:2px 0 0;font:400 12.5px/1.5 var(--lg-font-sans,system-ui,sans-serif);
  color:var(--lg-mute,#6b6f6b)}
.lgfc-taxo__f{margin-top:auto;border-top:1px solid var(--lg-line,#e3ddd0);padding:10px 13px;
  display:flex;gap:8px}
.lgfc-taxo__clear,.lgfc-taxo__done{font:700 12.5px/1 var(--lg-font-sans,system-ui,sans-serif);
  border-radius:9px;padding:10px 14px;cursor:pointer;
  border:1px solid var(--lg-line,#e3ddd0);background:var(--lg-card-bg,#fff);color:var(--lg-ink,#323532)}
.lgfc-taxo__done{margin-left:auto;background:var(--lg-sage-d,#6b7c52);
  border-color:var(--lg-sage-d,#6b7c52);color:#fff}
/* DARK — the selected chip and the primary button take the SAME darkened fill as
   the licence chips, for the reason recorded there: --lg-sage-d flips LIGHTER in
   dark, so white ink on it measures 1.85:1. */
html[data-lguser-theme="dark"] .lgfc-taxo__opt.is-on,
html[data-lguser-theme="dark"] .lgfc-taxo__done{
  background:#3d5233;border-color:#3d5233;color:#fff}
.lgfc .acf-checkbox-list ul{display:flex;flex-wrap:wrap;gap:6px;list-style:none;
  margin:6px 0 0 12px;padding:0;flex-basis:100%}

/* the licence list is long prose, so it stacks rather than wrapping as pills */
.lgfc .acf-field[data-name="loothprint_creative_commons"] .acf-radio-list{flex-direction:column;gap:5px}
.lgfc .acf-field[data-name="loothprint_creative_commons"] .acf-radio-list label{
  border-radius:10px;padding:10px 12px;width:100%;font-weight:600;line-height:1.35}

/* ---- gallery ----
   ACF gives .acf-gallery a FIXED 400px height and absolutely positions its
   inner panes, so an empty gallery is a 400px void — which is what the first
   render showed, and the mock draws a compact drop zone. Unwinding the
   positioning is what lets the box size to its contents.
   The empty state is styled through :empty so it reads as the mock's drop zone
   without a second element to keep in sync: when ACF puts an attachment in
   there, the dashed zone stops applying by itself. */
.lgfc .acf-gallery{height:auto!important;min-height:0;border:0;background:none}
.lgfc .acf-gallery-main,.lgfc .acf-gallery-attachments,.lgfc .acf-gallery-toolbar{
  position:static;width:auto;height:auto;padding:0;border:0;background:none}
.lgfc .acf-gallery-attachments{min-height:64px}
/* :has(), NOT :empty. ACF leaves a whitespace text node inside the attachments
   container, and :empty does not match an element containing one — so the
   drop-zone styling silently never applied and the field rendered as a blank
   64px gap. Measured in the live DOM (1 child node, innerHTML "\n\t\t\t\t\t")
   rather than inferred from the screenshot, which only showed "nothing there".
   :has() asks the question that was actually meant: are there any attachments? */
.lgfc .acf-gallery-attachments:not(:has(.acf-gallery-attachment)){display:flex;
  flex-direction:column;align-items:center;justify-content:center;gap:3px;min-height:104px;
  border:1.5px dashed var(--lg-sage,#87986a);border-radius:11px;
  background:var(--lg-paper,#fdfdfa)}
.lgfc .acf-gallery-attachments:not(:has(.acf-gallery-attachment))::before{content:"Drop photos here";
  font:700 13.5px/1.3 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-sage-d,#6b7c52)}
.lgfc .acf-gallery-attachments:not(:has(.acf-gallery-attachment))::after{content:"or tap to choose · JPG, PNG, HEIC";
  font-size:12.3px;color:var(--lg-mute,#6b6f6b)}
.lgfc .acf-gallery-toolbar{margin-top:10px}
.lgfc .acf-gallery-toolbar .acf-hl{display:flex;align-items:center;gap:8px;
  list-style:none;margin:0;padding:0}
.lgfc .acf-gallery-toolbar .acf-fr{margin-left:auto}
.lgfc .acf-gallery-side{border-radius:11px;border:1px solid var(--lg-line,#e3ddd0)}

/* ---- file ---- */
/* THE PRINT-FILES ROW — Ian, 2026-08-16, testing live: "The print files is weird,
   please make look nice". MEASURED BEFORE REDRAWING rather than restyled on a
   hunch: the control rendered as a 21px-tall sliver reading "No file selected
   [Add File]", directly beneath a 104px dashed drop-zone for photos. Beside the
   thing above it, it read as an afterthought — which is exactly what he saw.

   ⚠️ STYLED ON .hide-if-value, NOT ON A has-value CLASS. My first instinct was
   `.acf-file-uploader:not(.has-value)`, and it would have been wrong: this build
   never sets that class — measured in the live DOM, the uploader's class list is
   exactly "acf-file-uploader", and `has-value` appears ZERO times in the served
   page. The drop-zone would then have stayed on top of a chosen file. Also note
   this page loads NO ACF stylesheet at all, so nothing here can be assumed from
   how ACF looks in wp-admin.

   .hide-if-value IS the empty state by definition — ACF hides it the instant a
   file lands and reveals .show-if-value — so the drop-zone look disappears on its
   own with no state class to track. */
.lgfc .acf-field-file .acf-input>.acf-file-uploader{border:0;background:none}
.lgfc .acf-file-uploader>.hide-if-value{
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;
  min-height:104px;padding:14px;
  border:1.5px dashed var(--lg-sage,#87986a);border-radius:11px;
  background:var(--lg-paper,#fdfdfa)}
.lgfc .acf-file-uploader>.hide-if-value::before{content:"Drop your ZIP here";
  font:700 13.5px/1.3 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-sage-d,#6b7c52)}
.lgfc .acf-file-uploader>.hide-if-value::after{content:"or tap to choose \00b7 STLs, and the source too if you like";
  font-size:12.3px;color:var(--lg-mute,#6b6f6b);order:2;text-align:center}
/* ACF's "No file selected" wording and its button share ONE <p>, so the text
   cannot be display:none'd without taking the only tap target with it. Zero the
   paragraph and give the size back to the button. */
.lgfc .acf-file-uploader>.hide-if-value p{font-size:0;margin:8px 0 0;order:3}
.lgfc .acf-file-uploader>.hide-if-value p .acf-button{font-size:12.5px}
/* the chosen-file state stays a solid card, so "empty" and "filled" read apart */
.lgfc .acf-file-uploader>.show-if-value{
  border:1px solid var(--lg-line,#e3ddd0);border-radius:11px;background:var(--lg-paper,#fdfdfa)}
.lgfc .acf-file-uploader .file-wrap,.lgfc .acf-file-uploader .show-if-value{padding:9px 11px}
.lgfc input[type=file]{font-size:13px;color:var(--lg-mute,#6b6f6b)}
.lgfc .acf-button,.lgfc .acf-gallery .acf-button{font:600 12.5px/1 var(--lg-font-sans,system-ui,sans-serif);
  border-radius:8px;padding:8px 12px;border:1px solid var(--lg-line,#e3ddd0);
  background:var(--lg-card-bg,#fff);color:var(--lg-sage-d,#6b7c52);cursor:pointer}

/* ---- our own controls ---- */
.lgfc__own{padding:15px 0}
/* the hero picker's strip — small, so it reads as "which of these", not a gallery */
.lgfc__herostrip{display:flex;gap:8px;flex-wrap:wrap}
.lgfc__heroopt{padding:0;border:2px solid transparent;border-radius:10px;background:none;
  cursor:pointer;line-height:0;overflow:hidden;outline-offset:2px}
.lgfc__heroopt img{width:66px;height:66px;object-fit:cover;border-radius:8px;display:block}
.lgfc__heroopt:hover{border-color:var(--lg-line,#e3ddd0)}
.lgfc__heroopt.is-on{border-color:var(--lg-sage-d,#6b7c52)}
.lgfc__heroopt.is-on img{filter:none}
.lgfc__heroopt:not(.is-on) img{filter:saturate(.72) brightness(.94)}
.lgfc__hero[hidden]{display:none}
.lgfc__ownlabel{font:700 14.5px/1.3 var(--lg-font-sans,system-ui,sans-serif);margin:0 0 8px}
.lgfc__chips{display:flex;gap:6px;flex-wrap:wrap}
.lgfc__chip{font:600 12.3px/1 var(--lg-font-sans,system-ui,sans-serif);
  border:1px solid var(--lg-line,#e3ddd0);border-radius:999px;padding:8px 12px;
  color:var(--lg-mute,#6b6f6b);background:var(--lg-paper,#fdfdfa);cursor:pointer}
.lgfc__chip input{position:absolute;opacity:0;width:1px;height:1px;pointer-events:none}
.lgfc__chip:has(input:checked){background:var(--lg-sage-d,#6b7c52);
  border-color:var(--lg-sage-d,#6b7c52);color:#fff}


/* ---- footer: ACF's own submit row, dressed as the mock's ---- */
.lgfc .acf-form-submit{margin:0;padding:15px 21px 19px;
  border-top:1px solid var(--lg-line,#e3ddd0);
  display:flex;align-items:center;gap:11px;flex-wrap:wrap}
.lgfc__submit,.lgfc .acf-form-submit input[type=submit]{
  font:700 14px/1 var(--lg-font-sans,system-ui,sans-serif);border-radius:10px;padding:13px 22px;
  border:1px solid var(--lg-sage-d,#6b7c52);background:var(--lg-sage-d,#6b7c52);color:#fff;cursor:pointer}
.lgfc__submit:hover{filter:brightness(1.06)}
.lgfc__foot{margin-left:auto;color:var(--lg-mute,#6b6f6b);font-size:12.3px}

/* ACF's own error styling, in our palette */
.lgfc .acf-error-message{background:var(--lg-rust-tint,#fbeee8);color:var(--lg-error,#b3261e);
  border:1px solid var(--lg-rust,#c66845);border-radius:9px;padding:10px 12px;font-size:13px;margin:0 0 12px}
.lgfc .acf-field.acf-error .acf-input input,.lgfc .acf-field.acf-error .acf-input textarea{
  border-color:var(--lg-error,#b3261e)}

/* ---- phone ---- */
@media (max-width:640px){
  .lgfc{padding:14px 12px 84px}
  .lgfc__card{border-radius:14px}
  .lgfc__h{padding:16px 16px 13px}
  .lgfc__h h1{font-size:17px}
  .lgfc__sub--wide{display:none}
  .lgfc__sub--narrow{display:block}
  .lgfc .acf-fields{padding-left:16px;padding-right:16px}
  .lgfc .acf-form-submit{padding:13px 16px 16px}
  .lgfc__foot{margin-left:0;flex:1 1 100%}
  /* 16px stops iOS zooming the viewport on focus */
  .lgfc .acf-input input[type=text],.lgfc .acf-input input[type=url],
  .lgfc .acf-input textarea{font-size:16px}
}

/* ---- the frozen-page warning (see lg_fc_page_is_frozen) ---- */
.lgfc__frozen{margin:0 21px 4px;padding:11px 13px;border-radius:10px;
  border:1px solid var(--lg-rust,#c66845);background:var(--lg-rust-tint,#fbeee8);
  color:var(--lg-ink,#323532);font-size:13.4px;line-height:1.5}
.lgfc__frozen strong{display:block;margin-bottom:2px}
@media (max-width:640px){.lgfc__frozen{margin:0 16px 4px}}

/* ---- embed: the same card, no page furniture (it is inside an iframe) ---- */
.lgfc-body--embed{background:transparent}
.lgfc--embed{max-width:none;padding:0}
.lgfc--embed .lgfc__card{border:0;border-radius:0;box-shadow:none}

/* The dark theme re-points the tokens, so only surfaces with no suitable token
   need saying twice. --lg-paper and --lg-rust-tint are light-only in the current
   palette, so they are restated rather than left to a fallback that would stay
   cream on a dark card. */
html[data-lguser-theme="dark"] .lgfc{--lg-paper:#20241f;--lg-rust-tint:#3a2320}
/* SELECTED CHIPS IN DARK — Ian 2026-08-15: "compose works well. Needs some dark
   mode love." Gate 47 caught this on its first real run; measured, 1.85:1.

   THE CAUSE IS A TOKEN THAT FLIPS LIGHTNESS WHILE ITS INK DOES NOT. The selected
   rules pair `background:var(--lg-sage-d,#6b7c52)` with a hardcoded `color:#fff`.
   That is right for the FALLBACK — white on #6b7c52 is 4.54:1 — but --lg-sage-d
   resolves to #b0c693 in dark (archive.css re-points it to --lguser-accent-d), and
   white on #b0c693 is 1.85:1. Illegible, and a light slab in a dark page.

   ⚠️ WHY NOT JUST RE-POINT --lg-sage-d FOR .lgfc, which was my first instinct:
   this route uses that ONE token BOTH ways — as a FILL behind white ink (here and
   the chip), and as INK on a dark surface (lines ~1265, ~1281, ~1299), where the
   light value is exactly right. Re-pointing it would fix these two and turn those
   three dark-on-dark. So the fill sites are named explicitly instead.

   ⚠️ AND WHY NOT DARK INK ON THE LIGHT SAGE (#15171a on #b0c693 = 9.70:1, the
   pairing pwa.js already documents): it clears the contrast bar and still leaves a
   luminance-0.52 slab, which is the "bright surface in dark mode" half of the same
   gate finding. Darkening the FILL clears both.

   #ffffff on #3d5233 = 8.56:1, fill luminance 0.073. Keeps the light-mode idiom —
   light ink on a sage fill — rather than inventing a new dark treatment.

   THE GATE SAW ONE OF THESE; THERE WERE THREE. Only the default-selected licence
   renders selected on load, so the type-list label and the .lgfc__chip carry the
   identical defect and appear the moment a member picks anything. One cause, so
   one fix — but the shade is Ian's to adjust, not load-bearing.

   ⚠️ #179 (2026-08-21) WENT LOOKING FOR A FOURTH SITE AND THERE ISN'T ONE — and
   the way that was established is the point. The charter carried this forward as
   "hardcoded #fff on --lg-sage-d, still present at ~1617/1675/1683". Reading the
   source, `.lgfc__submit` (below) looks like a fourth instance of exactly this
   pair and is NOT covered by the selector here, so it was written up as one.

   MEASURED ON THE SERVED PAGE IN DARK INSTEAD, which is what this comment block
   argues for three paragraphs up, and the reasoning was wrong:

       .lgfc__submit   background #222629   colour #e5e7e1   ratio 12.23:1
       --lg-sage-d resolves to #b0c693, as documented — the button just is not
       painted with it; something later in the cascade wins.

   So the button is FINE in dark, no override was added for it, and the charter's
   item is CLOSED as already-fixed rather than fixed again. A rule that pairs
   var(--lg-sage-d) with #fff in the source is a candidate, not a finding.

   ⚠️ AND A REAL GATE BLIND SPOT, FOUND ON THE WAY: the dark sweep reports "88
   text elements measured" and never looked at this button at all, because an
   <input>'s label is a `value` attribute and not a text node. The form's PRIMARY
   control is invisible to its own contrast gate. It happens to be fine; the next
   input-valued control on this form would not be checked either. Recorded, not
   fixed here — it is the gate's shape, not this file's. */
html[data-lguser-theme="dark"] .lgfc li:has(input:checked)>label,
html[data-lguser-theme="dark"] .lgfc__chip:has(input:checked){
  background:#3d5233;border-color:#3d5233;color:#fff}
/* THE ACTUAL OPEN GATE-47 RED, measured on main 2026-08-21: the WYSIWYG toolbar
   is a 712x40 slab of #f5f5f5 in dark mode. It carries no text, so the contrast
   pass never looks at it and only the bright-surface leg catches it. ACF's own
   stylesheet paints it, so it is corrected here rather than there.
   ⚠️ NOT VERIFIABLE FROM A BRANCH ON THIS BOX: this mu-plugin is symlinked out of
   the serving checkout, so gate 47 measures MAIN until a merge and a pull. Re-run
   after the pull with:
     python3 tools/frontend-compose/dark-contrast-sweep.py --width 1280
     python3 tools/frontend-compose/dark-contrast-sweep.py --width 390 */
html[data-lguser-theme="dark"] .lgfc .acf-editor-toolbar,
html[data-lguser-theme="dark"] .lgfc .mce-toolbar-grp,
html[data-lguser-theme="dark"] .lgfc .mce-panel{
  background:#222629;border-color:#2c312d}
html[data-lguser-theme="dark"] .lgfc__card{box-shadow:0 10px 34px rgba(0,0,0,.28)}
CSS;
}

/**
 * Progressive enhancement, and nothing that the form needs in order to work.
 *
 * With JS off the extras are simply visible: every control is still present,
 * still labelled and still submits. The fold is a tidiness affordance, so it is
 * built by moving real fields into a <div> rather than by rendering a second
 * copy of them — there is exactly one input per field in the DOM at all times,
 * which is what keeps "the member's intent is what gets saved" true.
 */
function lg_fc_js(): string
{
    return <<<'JS'
/* THE RICH-TEXT EDITOR'S DARK SKIN — the half page CSS cannot reach.
   TinyMCE renders the write-up inside an IFRAME with its own document, so no rule
   in this page applies to it. wp-admin's default content style is black on white,
   which in dark mode is a full-width white slab: exactly the class that reddened
   gate 47 tonight on the oEmbed field.

   ⚠️ IT CANNOT BE DONE SERVER-SIDE. content_style via tiny_mce_before_init is
   fixed at render, and our dark is a CLIENT attribute (html[data-lguser-theme])
   resolved after paint — not prefers-color-scheme. So the theme has to be read
   when the frame appears, and re-read when it changes.

   The editor is DELAY-INIT by design (composers load on intent), so the iframe
   does not exist at page load and there is nothing to hook at boot. A
   MutationObserver is what survives that: it fires whenever the frame is created,
   however long the member waits before clicking. */
(function () {
  var field = document.querySelector('.lgfc .acf-field[data-name="_post_content"]');
  if (!field) return;

  function tokens() {
    var cs = getComputedStyle(document.querySelector('.lgfc') || document.body);
    var g  = function (n, f) { return (cs.getPropertyValue(n) || '').trim() || f; };
    return {
      bg:   g('--lg-paper', '#fdfdfa'),
      ink:  g('--lg-ink',   '#323532'),
      link: g('--lg-sage-d', '#6b7c52'),
      mute: g('--lg-mute',  '#6b6f6b')
    };
  }

  function paint(doc) {
    if (!doc || !doc.head) return;
    var t = tokens();
    var el = doc.getElementById('lgfc-skin');
    if (!el) {
      el = doc.createElement('style');
      el.id = 'lgfc-skin';
      doc.head.appendChild(el);
    }
    /* Deliberately narrow: surface, ink, links and the placeholder. The toolbar
       decides what MARKUP exists; this only decides what colour it is. */
    el.textContent =
      'html,body{background:' + t.bg + ' !important;color:' + t.ink + ' !important;}' +
      'body{font:500 14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;' +
      'margin:10px 12px;}' +
      'a{color:' + t.link + ';}' +
      'p{margin:0 0 .75em;}' +
      'body.mce-content-body[data-mce-placeholder]:not(.mce-visualblocks)::before{color:' + t.mute + ';}';
  }

  function frames() {
    Array.prototype.forEach.call(field.querySelectorAll('iframe'), function (f) {
      var doc = null;
      try { doc = f.contentDocument; } catch (e) { return; }   /* never cross-origin here, but be safe */
      if (doc) {
        paint(doc);
        /* TinyMCE rewrites its own document during init; paint again once it settles. */
        if (!f.__lgfcPainted) { f.__lgfcPainted = 1; setTimeout(function () { paint(f.contentDocument); }, 400); }
      }
    });
  }

  new MutationObserver(frames).observe(field, { childList: true, subtree: true });
  /* And follow the theme if the member flips it with the editor already open. */
  new MutationObserver(frames).observe(document.documentElement,
    { attributes: true, attributeFilter: ['data-lguser-theme'] });
  frames();
})();

/* THE TAXONOMY PICKER — Ian, 2026-08-16: "The taxo pickers are wierd. Make modal
   or something slick please", and he picked the sheet-with-search shape from the
   mock.

   MEASURED FIRST: "what kind of print is it?" holds 18 terms and "area of work"
   holds 36, both hierarchical, both poured into a 184px scrolling box. The
   complaint has a second half he did not have to name — WHAT YOU ALREADY PICKED
   CAN SCROLL OUT OF SIGHT, so the box showed neither the options nor your answer.
   The closed row here always states the answer; that part is the actual defect
   rather than a matter of taste.

   ⚠️ IT DRIVES ACF'S OWN INPUTS AND NEVER REPLACES THEM. The original checkboxes
   stay in the DOM as the single source of truth — hidden, still named, still
   submitted — and every tap in the sheet toggles the real input and fires change.
   So the form posts exactly what it always did, ACF's own save path is untouched,
   and NO SERVER CHANGE IS NEEDED for any of this. Rebuilding the field would have
   meant owning its serialisation forever.

   Terms are FLATTENED deliberately: at 18 and 36 a search box finds a term faster
   than a tree does, and the hierarchy here is one level and mostly cosmetic. If a
   deeper taxonomy ever uses this, the nesting is still in the DOM to read. */
(function () {
  var fields = document.querySelectorAll('.lgfc .acf-field-taxonomy');
  if (!fields.length) return;

  fields.forEach(function (field) {
    var list = field.querySelector('.acf-checkbox-list, .acf-radio-list');
    if (!list) return;
    var inputs = Array.prototype.slice.call(list.querySelectorAll('input[type=checkbox], input[type=radio]'));
    if (inputs.length < 6) return;      /* short lists were never the problem */

    var labelEl = field.querySelector('.acf-label label');
    var title   = labelEl ? labelEl.textContent.replace(/\*/g, '').trim() : 'Choose';
    var multi   = inputs[0].type === 'checkbox';

    var terms = inputs.map(function (inp) {
      var li = inp.closest('li');
      var t  = li ? li.textContent.trim() : inp.value;
      return { input: inp, text: t };
    });

    field.classList.add('lgfc-taxo');
    list.classList.add('lgfc-taxo__src');          /* hidden, still submitted */

    var trig = document.createElement('button');
    trig.type = 'button';
    trig.className = 'lgfc-taxo__trig';
    trig.setAttribute('aria-haspopup', 'dialog');
    trig.setAttribute('aria-expanded', 'false');

    var sheet = document.createElement('div');
    sheet.className = 'lgfc-taxo__sheet';
    sheet.hidden = true;
    sheet.setAttribute('role', 'dialog');
    sheet.setAttribute('aria-label', title);
    sheet.innerHTML =
      '<div class="lgfc-taxo__h"><p class="lgfc-taxo__t"></p>' +
      '<button type="button" class="lgfc-taxo__x" aria-label="Close">&#10005;</button></div>' +
      '<div class="lgfc-taxo__search"><input type="search" autocomplete="off" ' +
      'placeholder="Type to narrow\u2026" aria-label="Search ' + title.replace(/"/g, '') + '"></div>' +
      '<div class="lgfc-taxo__b"></div>' +
      '<div class="lgfc-taxo__f"><button type="button" class="lgfc-taxo__clear">Clear</button>' +
      '<button type="button" class="lgfc-taxo__done">Done</button></div>';

    sheet.querySelector('.lgfc-taxo__t').textContent = title;
    var body   = sheet.querySelector('.lgfc-taxo__b');
    var search = sheet.querySelector('.lgfc-taxo__search input');

    field.querySelector('.acf-input').appendChild(trig);
    field.querySelector('.acf-input').appendChild(sheet);

    function chosen() {
      return terms.filter(function (t) { return t.input.checked; });
    }
    function paintTrigger() {
      var c = chosen();
      trig.innerHTML = '';
      var strong = document.createElement('span');
      strong.className = 'lgfc-taxo__lab';
      strong.textContent = title;
      var val = document.createElement('span');
      val.className = 'lgfc-taxo__val';
      /* The closed row STATES THE ANSWER — the thing the old box lost. */
      val.textContent = c.length ? c.map(function (t) { return t.text; }).join(', ')
                                 : 'Choose\u2026';
      if (!c.length) val.classList.add('is-empty');
      var car = document.createElement('span');
      car.className = 'lgfc-taxo__car'; car.textContent = '\u203A';
      trig.appendChild(strong); trig.appendChild(val); trig.appendChild(car);
    }
    function paintBody() {
      var q = (search.value || '').trim().toLowerCase();
      body.innerHTML = '';
      var shown = 0;
      terms.forEach(function (t) {
        if (q && t.text.toLowerCase().indexOf(q) === -1) return;
        shown++;
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'lgfc-taxo__opt' + (t.input.checked ? ' is-on' : '');
        b.setAttribute('aria-pressed', t.input.checked ? 'true' : 'false');
        b.textContent = t.text;
        b.addEventListener('click', function () {
          if (!multi) { terms.forEach(function (o) { o.input.checked = false; }); }
          t.input.checked = multi ? !t.input.checked : true;
          t.input.dispatchEvent(new Event('change', { bubbles: true }));
          paintBody(); paintTrigger();
          if (!multi) close();
        });
        body.appendChild(b);
      });
      if (!shown) {
        var none = document.createElement('p');
        none.className = 'lgfc-taxo__none';
        none.textContent = 'Nothing matches \u201C' + search.value + '\u201D';
        body.appendChild(none);
      }
    }
    function open()  { sheet.hidden = false; trig.setAttribute('aria-expanded', 'true');
                       paintBody(); setTimeout(function () { search.focus(); }, 30); }
    function close() { sheet.hidden = true;  trig.setAttribute('aria-expanded', 'false');
                       search.value = ''; trig.focus(); }

    trig.addEventListener('click', function () { sheet.hidden ? open() : close(); });
    sheet.querySelector('.lgfc-taxo__x').addEventListener('click', close);
    sheet.querySelector('.lgfc-taxo__done').addEventListener('click', close);
    sheet.querySelector('.lgfc-taxo__clear').addEventListener('click', function () {
      terms.forEach(function (t) {
        if (t.input.checked) {
          t.input.checked = false;
          t.input.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
      paintBody(); paintTrigger();
    });
    search.addEventListener('input', paintBody);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !sheet.hidden) close();
    });

    paintTrigger();
  });
})();

/* THE HERO PICKER — mirrors ACF's gallery, live.
   The gallery builds and rebuilds its own DOM (add, remove, reorder), so the strip
   is rebuilt from it on every mutation rather than rendered once. It hides itself
   whenever there is nothing to choose, which is also the state the form opens in,
   so an empty form never shows an empty control.
   DEFAULT = FIRST, matching the server: lg_fc_hero_from_gallery() takes the first
   photo when nothing is picked, so the strip shows that same one as selected and
   the picture never disagrees with what will be saved. */
(function () {
  var wrap = document.querySelector('.lgfc__hero');
  if (!wrap) return;
  var input = wrap.querySelector('input[name="lg_fc_hero"]');
  var strip = wrap.querySelector('.lgfc__herostrip');
  var gal   = document.querySelector('.acf-field[data-name="loothprint_more_images"]');
  if (!input || !strip || !gal) return;

  function shots() {
    return Array.prototype.slice.call(
      gal.querySelectorAll('.acf-gallery-attachment')
    ).map(function (el) {
      var img = el.querySelector('img');
      var id  = el.getAttribute('data-id') || (el.querySelector('input') || {}).value || '';
      return { id: String(id || ''), src: img ? img.getAttribute('src') : '' };
    }).filter(function (s) { return s.id && s.src; });
  }

  function paint() {
    var list = shots();
    if (list.length < 2) {          /* nothing to choose between */
      wrap.hidden = true;
      if (!list.length) input.value = '';
      return;
    }
    wrap.hidden = false;
    var current = input.value;
    if (!current || !list.some(function (s) { return s.id === current; })) {
      current = list[0].id;         /* default = first, same as the server */
      input.value = current;
    }
    strip.innerHTML = '';
    list.forEach(function (s) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'lgfc__heroopt' + (s.id === current ? ' is-on' : '');
      b.setAttribute('role', 'radio');
      b.setAttribute('aria-checked', s.id === current ? 'true' : 'false');
      b.setAttribute('aria-label', 'Use this photo as the lead');
      b.innerHTML = '<img alt="" src="' + s.src.replace(/"/g, '&quot;') + '">';
      b.addEventListener('click', function () {
        input.value = s.id;
        paint();
      });
      strip.appendChild(b);
    });
  }

  paint();
  new MutationObserver(paint).observe(gal, { childList: true, subtree: true });
})();

/* THE "ADD EXTRAS" ACCORDION IS GONE — Ian, 2026-08-16, testing live: "and the
   extras accordiian in general". Everything that used to fold now sits in the main
   body in its declared order, which is also why the registry's `extra` column is
   now false everywhere: nothing reads it, and leaving true values behind would
   describe a construct that no longer exists. */
JS;
}
