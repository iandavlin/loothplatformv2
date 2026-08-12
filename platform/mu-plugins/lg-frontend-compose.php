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
 * this once it is on, which is exactly why LG_FRONTEND_COMPOSE stays OFF by
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
 * LG_FRONTEND_COMPOSE, default FALSE, copying LG_AUTHOR_SOCIALS_ALL_MEMBERS
 * (lg-author-socials.php:48). Member-facing, so OFF-default is the house rule and
 * this one has no reason to deviate: it is a new capability, not a repair for
 * ongoing damage, so arriving inert is correct.
 *
 * A tracked PHP constant, NOT an env var, and that is deliberate. Two recorded
 * traps make env the wrong carrier on this box: WP cron has no Environment= at
 * all, and an fpm fastcgi_param lands in $_SERVER but never in getenv(), so a
 * getenv()-only flag serves OFF on the very preview URL built for Ian to click.
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

if (!defined('LG_FRONTEND_COMPOSE')) {
    define('LG_FRONTEND_COMPOSE', false);
}

/**
 * Is the route live for THIS request?
 *
 * The tracked constant is the answer everywhere that matters, and it is FALSE.
 * The one exception is the lane preview: Ian's toggle loads this route in an
 * iframe from /preview/frontend-compose/, and with the flag off that iframe
 * would 404 and the toggle would look broken for a reason that has nothing to do
 * with the toggle.
 *
 * LG_FC_PREVIEW IS A fastcgi_param, WHICH IS WHY THIS IS SAFE. Only an nginx conf
 * can set one — never a query string, never a client header — so the arm exists
 * on exactly one path (platform/nginx/lane-preview-frontend-compose.conf) and
 * nowhere else. /compose/ on the real vhost stays exactly as flagged.
 *
 * Read from $_SERVER and NOT getenv(): recorded box trap — a fastcgi_param lands
 * in $_SERVER only, so a getenv()-only check serves OFF on the very preview URL
 * the param was added for.
 */
function lg_fc_enabled(): bool
{
    if (LG_FRONTEND_COMPOSE) {
        return true;
    }
    return !empty($_SERVER['LG_FC_PREVIEW']);
}

const LG_FC_PATH = 'compose';

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
                // name                          label                    hint                                                             extra  acf_label
                ['_post_title',                  "What’s it called?",     '',                                                              false, 'Title of your Loothprint'],
                ['loothprint_more_images',       'Show it off',           'One or more photos of your print — finished, or better still in use.', false, 'Add one or more image(s) of your print in action'],
                ['loothprint_3d_file',           'The print files',       'A ZIP with your STLs — and the editable source too, if you’re happy to share it.', false, '3D File Upload ZIP File'],
                ['_post_content',                'Tell people about it',  'What it does, what it’s for, anything worth knowing before they print it.', false, 'Summary'],
                ['loothprint_category',          'What kind of print is it?', '',                                                          false, 'Type of Loothprint'],
                ['content_topic_broad_terms',    'And roughly what area of work?', '',                                                     false, 'Content Topic'],
                ['loothprint_creative_commons',  'Licence',               'The usual choice — leave it unless you know you want something else.', false, 'Creative Commons Use License (leave default if unsure)'],
                ['loothprint_video_instructions','A video of it in use',  '',                                                              true,  'Video instructions for use/build'],
                ['loothprint_onshape_link',      'Onshape / CAD link',    '',                                                              true,  'Onshape Project Link'],
                ['loothprint_buy_me_a_coffee',   'Tip jar',               'Buy Me A Coffee or similar, if you’d like one.',                true,  "Link to your Buy Me A Coffee or other 'leave me a tip' site (optional)"],
            ],
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
 * ruling and is why LG_FRONTEND_COMPOSE stays OFF by default: nothing else
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
        'post_id'            => $edit ?: 'new_post',
        'new_post'           => $edit ? [] : [
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
            'data-lgfc-extras'  => implode(',', lg_fc_extra_field_names($type)),
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
<div class="acf-field lgfc-field lgfc__own" data-lgfc-extra="1" data-name="lg_fc_comments">
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

function lg_fc_render(string $type, int $edit = 0, bool $embed = false): void
{
    $t = lg_fc_types()[$type];

    // WP resolved /compose/ as a 404 query before we claimed it; say otherwise.
    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');

    // The mock's labels replace ACF's for the duration of this render only.
    add_filter('acf/prepare_field', 'lg_fc_relabel', 20);

    lg_fc_page_open($t['title'], $embed);
    ?>
<div class="lgfc__card">
  <div class="lgfc__h">
    <h1><?php echo esc_html($t['title']); ?></h1>
    <p class="lgfc__sub lgfc__sub--wide"><?php echo esc_html($t['sub']); ?></p>
    <p class="lgfc__sub lgfc__sub--narrow"><?php echo esc_html($t['sub_narrow'] ?? $t['sub']); ?></p>
  </div>
  <?php acf_form('lg-fc-' . $type); ?>
</div>
    <?php
    lg_fc_page_close();
    remove_filter('acf/prepare_field', 'lg_fc_relabel', 20);
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
    return $field;
}

/**
 * The field names the mock folds away under "Add extras".
 *
 * Emitted as a data attribute and matched in JS by data-name, rather than by
 * pushing a custom key through ACF's wrapper array. ACF decides for itself which
 * wrapper keys it will render, and a fold that silently stops folding because an
 * ACF upgrade dropped an unknown attribute is a defect nobody would look for.
 */
function lg_fc_extra_field_names(string $type): array
{
    $out = [];
    foreach (lg_fc_types()[$type]['fields'] as $f) {
        if (!empty($f[3])) {
            $out[] = $f[0];
        }
    }
    return $out;
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
<style><?php echo lg_fc_css(); ?></style>
</head>
<body class="lgfc-body<?php echo $embed ? ' lgfc-body--embed' : ''; ?>">
<main class="lgfc<?php echo $embed ? ' lgfc--embed' : ''; ?>">
    <?php
}

function lg_fc_page_close(): void
{
    ?>
</main>
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

/* ---- the extras fold ---- */
.lgfc__fold{margin:4px 0 0;border:1px solid var(--lg-line,#e3ddd0);border-radius:11px;
  background:var(--lg-paper,#fdfdfa);overflow:hidden}
.lgfc__foldt{display:flex;align-items:center;gap:9px;width:100%;background:none;border:0;
  padding:13px 14px;font:700 13.5px/1 var(--lg-font-sans,system-ui,sans-serif);
  color:var(--lg-sage-d,#6b7c52);cursor:pointer;text-align:left}
.lgfc__foldt .cv{margin-left:auto;color:var(--lg-mute,#6b6f6b);font-size:12px;font-weight:600}
.lgfc__foldb{padding:0 14px 6px;display:none}
.lgfc__fold[open] .lgfc__foldb,.lgfc__fold.is-open .lgfc__foldb{display:block}
.lgfc__fold.is-open .lgfc__foldt .cv::after{content:" \25B2"}
.lgfc__fold:not(.is-open) .lgfc__foldt .cv::after{content:" \25BC"}

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

/* ---- embed: the same card, no page furniture (it is inside an iframe) ---- */
.lgfc-body--embed{background:transparent}
.lgfc--embed{max-width:none;padding:0}
.lgfc--embed .lgfc__card{border:0;border-radius:0;box-shadow:none}

/* The dark theme re-points the tokens, so only surfaces with no suitable token
   need saying twice. --lg-paper and --lg-rust-tint are light-only in the current
   palette, so they are restated rather than left to a fallback that would stay
   cream on a dark card. */
html[data-lguser-theme="dark"] .lgfc{--lg-paper:#20241f;--lg-rust-tint:#3a2320}
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
(function () {
  var form = document.querySelector('[data-lgfc-extras]');
  if (!form) return;
  var names = (form.getAttribute('data-lgfc-extras') || '').split(',').filter(Boolean);
  var nodes = [];
  names.forEach(function (n) {
    var el = form.querySelector('.acf-field[data-name="' + n + '"]');
    if (el) nodes.push(el);
  });
  form.querySelectorAll('[data-lgfc-extra]').forEach(function (el) {
    if (nodes.indexOf(el) === -1) nodes.push(el);
  });
  if (!nodes.length) return;

  var fold = document.createElement('div');
  fold.className = 'lgfc__fold';
  var btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'lgfc__foldt';
  btn.setAttribute('aria-expanded', 'false');
  btn.innerHTML = 'Add extras <span class="cv">' + nodes.length + ' optional</span>';
  var body = document.createElement('div');
  body.className = 'lgfc__foldb';
  fold.appendChild(btn);
  fold.appendChild(body);

  nodes[0].parentNode.insertBefore(fold, nodes[0]);
  nodes.forEach(function (el) { body.appendChild(el); });

  btn.addEventListener('click', function () {
    var open = fold.classList.toggle('is-open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
})();
JS;
}
