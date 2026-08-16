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
               ⚠️ THE DATA IS UNTOUCHED AND STILL RENDERS. lg-layout-v2 Plugin.php
               (~530-564) synthesises both into page callouts, and on dev2 today 7
               published loothprints carry an Onshape link and 14 carry a tip jar,
               out of 168. So those keep showing on the page while no author can now
               edit or clear them. That is a RENDER/DATA question, raised to keeper
               rather than answered here — deleting members' links is not a
               form-side decision. */
            // Rendered by us, not by ACF — see lg_fc_comment_status().
            'comments' => ['label' => 'Let people comment', 'acf_label' => 'Commenting'],
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
    return 'publish';
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

    wp_update_post([
        'ID'             => $post_id,
        'post_status'    => lg_fc_post_status($post->post_type, (int) $post->post_author),
        'comment_status' => lg_fc_comment_status(),
    ]);
    delete_post_meta($post_id, LG_FC_DRAFT_META);   // no longer the reaper's business
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
        'html_after_fields'  => lg_fc_own_controls($t),
        'html_submit_button' => '<input type="submit" class="lgfc__submit" value="%s" />'
            . '<span class="lgfc__foot">' . esc_html($t['foot']) . '</span>',
        'return'             => $edit
            ? add_query_arg('lg_fc', 'saved', get_permalink($edit) ?: home_url('/'))
            : add_query_arg('lg_fc', 'posted', get_permalink() ?: home_url('/')),
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
function lg_fc_own_controls(array $t): string
{
    $label = esc_html($t['comments']['label']);
    return <<<HTML
<div class="acf-field lgfc-field lgfc__own" data-name="lg_fc_comments">
  <div class="acf-label"><label>{$label}</label></div>
  <div class="acf-input"><div class="lgfc__chips">
    <label class="lgfc__chip"><input type="radio" name="lg_fc_comments" value="open" checked> <span>Yes</span></label>
    <label class="lgfc__chip"><input type="radio" name="lg_fc_comments" value="closed"> <span>No</span></label>
  </div></div>
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
    unset($GLOBALS['lg_fc_editing']);
}

/**
 * Swap ACF's stored label/instructions for the mock's, by field name, and mark
 * which fields belong in the "Add extras" fold.
 *
 * Only fields this route knows about are touched, and the filter is added and
 * removed around the render, so nothing else on the site can see it.
 */
function lg_fc_relabel($field)
{
    if (empty($field['name']) && empty($field['_name'])) {
        return $field;
    }
    $name = $field['_name'] ?? $field['name'];

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

    // ACF's _post_content pseudo-field is a WYSIWYG, which would pull TinyMCE
    // into a member-facing composer. The mock draws a plain box, so it gets one.
    // Deliberate deviation, recorded: the discussion composer uses Quill, so if
    // rich text is later wanted here that is a parity decision to take on
    // purpose rather than inherit from an ACF default nobody chose.
    if ($name === '_post_content') {
        $field['type'] = 'textarea';
        $field['rows'] = 4;
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
            $field['value'] = get_post_field('post_content', $edit_id);
        }
    }
    return $field;
}

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
.lgfc .acf-taxonomy-field .acf-checkbox-list{max-height:184px;overflow-y:auto;
  border:1px solid var(--lg-line,#e3ddd0);border-radius:10px;padding:9px;
  background:var(--lg-paper,#fdfdfa);align-content:flex-start}
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
.lgfc .acf-field-file .acf-input>.acf-file-uploader{
  border:1px solid var(--lg-line,#e3ddd0);border-radius:11px;background:var(--lg-paper,#fdfdfa)}
.lgfc .acf-file-uploader .file-wrap,.lgfc .acf-file-uploader .show-if-value{padding:9px 11px}
.lgfc input[type=file]{font-size:13px;color:var(--lg-mute,#6b6f6b)}
.lgfc .acf-button,.lgfc .acf-gallery .acf-button{font:600 12.5px/1 var(--lg-font-sans,system-ui,sans-serif);
  border-radius:8px;padding:8px 12px;border:1px solid var(--lg-line,#e3ddd0);
  background:var(--lg-card-bg,#fff);color:var(--lg-sage-d,#6b7c52);cursor:pointer}

/* ---- our own controls ---- */
.lgfc__own{padding:15px 0}
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
   one fix — but the shade is Ian's to adjust, not load-bearing. */
html[data-lguser-theme="dark"] .lgfc li:has(input:checked)>label,
html[data-lguser-theme="dark"] .lgfc__chip:has(input:checked){
  background:#3d5233;border-color:#3d5233;color:#fff}
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
/* THE "ADD EXTRAS" ACCORDION IS GONE — Ian, 2026-08-16, testing live: "and the
   extras accordiian in general". Everything that used to fold now sits in the main
   body in its declared order, which is also why the registry's `extra` column is
   now false everywhere: nothing reads it, and leaving true values behind would
   describe a construct that no longer exists. */
JS;
}
