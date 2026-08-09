<?php
/**
 * Plugin Name: LG — front-end compose
 * Description: One screen, one route, for CREATING a managed CPT from the front
 *              end. Ian ruled Option A on 2026-08-03. Flag OFF by default.
 * Version:     0.1.0
 *
 * ══ WHAT THIS IS, AND THE ONE SENTENCE THAT SCOPES IT ═════════════════════════
 *
 * Ian, re-scoping the lane: "I can currently edit on the front end. That is fine.
 * I need to be able to COMPOSE on the front end with a easy front end form."
 * EDITING WAS NEVER THE PROBLEM. This file adds no edit path and deliberately
 * refuses to be one (see CREATE-ONLY below).
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
 * ══ CREATE-ONLY, AND WHY THAT IS A SECURITY PROPERTY ══════════════════════════
 *
 * This route can only ever create. It never accepts a post_id, so it cannot be
 * pointed at an existing post — no IDOR, and, more importantly, it is structurally
 * immune to the defect class this lane was told to not repeat:
 *
 *   lg-preserve-forum-subscription.php (live @ 10ea816) documents BuddyBoss
 *   treating the ABSENCE of a field as an instruction to DELETE the member's
 *   subscription. Our composer omitted bbp_topic_subscription, so every reply
 *   posted through our box silently unsubscribed its own author.
 *
 * ACF has the same shape: a field that is RENDERED and submitted empty is SAVED
 * as empty. On an edit form, dropping a field from the field list — exactly what
 * this design does with `featured_image`, which the mock removes — would wipe it.
 * On a create form there is no prior value to destroy, so the hazard cannot fire.
 * Keeping this route create-only is therefore not a scope cut; it is what makes
 * the omission in the field list safe. If a future change makes this form edit
 * an existing post, THAT change must render the complete field set or explicitly
 * preserve what it omits, and must say which.
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
 * ══ WHO GETS IT — AND WHAT IAN HAS NOT RULED ON ══════════════════════════════
 *
 * The capability system carries NO usable signal for who may create WHAT. The
 * natural check, create_posts, maps to edit_posts, held by 1,820 of 1,824 users
 * on dev2 and by subscriber + every looth tier on live (scope §6). Gating on it
 * would let ~1,850 accounts publish a video or an article unreviewed on day one.
 *
 * So there are two tiers, which is what backlog #16 already asks for:
 *
 *   OPEN   loothprint  any signed-in member holding the caps to submit one
 *                      (edit_posts + upload_files) -> publish
 *   GATED  everything  an explicit allow-list -> publish
 *          else        not served the form at all, and if that refusal ever has
 *                      a hole, post_status falls back to PENDING, so the worst
 *                      case is a moderation queue rather than a live page.
 *
 * Both layers are implemented on purpose. The refusal is the gate; the pending
 * fallback is what makes a bug in the gate survivable.
 *
 * ⚠️ IAN HAS NOT RULED ON THE ALLOW-LIST. §3 of the rulings answered the SHAPE
 * question (single screen) and nothing else. The mock's own closing section asked
 * him who administers the list and whether a member's loothprint publishes
 * immediately, and that ask is still open. What is implemented here is the
 * default the mock told him it would be — "a member's loothprint publishes
 * straight away; gated types from someone not on the list land as pending" — so
 * the code matches the picture he approved rather than quietly choosing something
 * he was not shown. LG_FRONTEND_COMPOSE_ALLOW exists so his answer is a one-line
 * change, not a rewrite.
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
 * Extra logins allowed to compose the GATED types, beyond those who already hold
 * edit_others_posts. Ian's answer to "who administers the list" replaces this.
 */
if (!defined('LG_FRONTEND_COMPOSE_ALLOW')) {
    define('LG_FRONTEND_COMPOSE_ALLOW', '');
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
 * `tier`: 'open' = any member holding the submit capabilities; 'gated' = the
 * allow-list. `synth` records that lg-layout-v2 builds the page from this meta,
 * which is the reason only these types are offered — a form for a non-synthesized
 * type would produce a post with no page (scope §3.2).
 */
function lg_fc_types(): array
{
    return [
        'loothprint' => [
            'tier'     => 'open',
            'synth'    => true,
            'title'    => 'Share a Loothprint',
            'sub'      => 'Your 3D print, free for the rest of the group to make.',
            'sub_narrow' => 'Your 3D print, free for the group to make.',
            'submit'   => 'Post it',
            'foot'     => 'Your hero image is the first photo unless you pick another.',
            'fields'   => [
                // name                        label                    hint                                                             extra  acf_label
                ['post_title',                 "What’s it called?",     '',                                                              false, 'Title of your Loothprint'],
                ['loothprint_more_images',     'Show it off',           'One or more photos of your print — finished, or better still in use.', false, 'Add one or more image(s) of your print in action'],
                ['loothprint_3d_file',         'The print files',       'A ZIP with your STLs — and the editable source too, if you’re happy to share it.', false, '3D File Upload ZIP File'],
                ['post_content',               'Tell people about it',  'What it does, what it’s for, anything worth knowing before they print it.', false, 'Summary'],
                ['loothprint_category',        'What kind of print is it?', '',                                                          false, 'Type of Loothprint'],
                ['content_topic_broad_terms',  'And roughly what area of work?', '',                                                     false, 'Content Topic'],
                ['loothprint_creative_commons','Licence',               'The usual choice — leave it unless you know you want something else.', false, 'Creative Commons Use License (leave default if unsure)'],
                ['loothprint_video_instructions', 'A video of it in use', '',                                                            true,  'Video instructions for use/build'],
                ['loothprint_onshape_link',    'Onshape / CAD link',    '',                                                              true,  'Onshape Project Link'],
                ['loothprint_buy_me_a_coffee', 'Tip jar',               'Buy Me A Coffee or similar, if you’d like one.',                true,  "Link to your Buy Me A Coffee or other 'leave me a tip' site (optional)"],
            ],
            // Rendered by us, not by ACF — see lg_fc_comment_status().
            'comments' => ['label' => 'Let people comment', 'acf_label' => 'Commenting'],
            // The mock drops the featured_image control and promises the footer
            // line above instead. lg_fc_hero_from_gallery() is what keeps that
            // promise. NB "unless you pick another" has no control in the mock —
            // raised as an open question rather than invented here.
            'hero_from' => 'loothprint_more_images',
        ],
    ];
}

/* ────────────────────────────────────────────────────────────── the gate ───── */

/**
 * May this user compose this type? The ONLY answer to that question.
 *
 * Deliberately behaviour-shaped and capability-based rather than role-based: the
 * gate that matters is asserted by compose-gate.py against two real accounts, and
 * that gate does not know how the list is implemented, so Ian can change the
 * mechanism without rewriting the assertion.
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

    // The capabilities a member must already hold to submit this type at all.
    // These do NOT discriminate (scope §6) — they are a floor, not the gate.
    if (!user_can($user, 'edit_posts') || !user_can($user, 'upload_files')) {
        return false;
    }

    if ($types[$type]['tier'] === 'open') {
        return true;
    }

    return lg_fc_on_allow_list($user);
}

function lg_fc_on_allow_list(WP_User $user): bool
{
    if (user_can($user, 'edit_others_posts')) {
        return true;
    }
    $extra = array_filter(array_map('trim', explode(',', (string) LG_FRONTEND_COMPOSE_ALLOW)));
    return in_array($user->user_login, $extra, true);
}

/**
 * The safety valve (scope §6). Computed from the AUTHENTICATED user on the
 * request that writes, never carried from the request that rendered.
 *
 * An allow-listed author on a gated type publishes; anyone else lands pending.
 * They should never reach this — lg_fc_may_compose() already refused them — so
 * a 'pending' here means the refusal has a hole, and the hole produces a queue
 * instead of a live page.
 */
function lg_fc_post_status(string $type, int $user_id): string
{
    $types = lg_fc_types();
    if (($types[$type]['tier'] ?? '') === 'open') {
        return 'publish';
    }
    $user = get_userdata($user_id);
    return ($user && lg_fc_on_allow_list($user)) ? 'publish' : 'pending';
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
    if (!LG_FRONTEND_COMPOSE) {
        return;
    }

    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    if ($path !== LG_FC_PATH) {
        return;
    }

    $type  = isset($_GET['type']) ? sanitize_key((string) $_GET['type']) : '';
    $types = lg_fc_types();

    // An unknown type, and an anonymous visitor, both get the ordinary 404. We
    // do not redirect anon to sign-in: there is no entry point linking here yet,
    // so the only way to arrive signed-out is to have guessed the URL, and a 404
    // tells that visitor nothing about what exists.
    if (!isset($types[$type]) || !is_user_logged_in()) {
        return; // falls through to lg-error-pages.php's branded 404
    }

    if (!lg_fc_may_compose($type)) {
        lg_fc_refuse($types[$type]['title'] ?? 'This form');
        exit;
    }

    // Registered so the settings never travel with the POST. Built here, on THIS
    // request, from THIS user — so the POST recomputes them rather than trusting
    // whatever the GET produced.
    $uid = get_current_user_id();
    acf_register_form([
        'id'                 => 'lg-fc-' . $type,
        'post_id'            => 'new_post',
        'new_post'           => [
            'post_type'      => $type,
            'post_status'    => lg_fc_post_status($type, $uid),
            'post_author'    => $uid,
            'comment_status' => lg_fc_comment_status(),
        ],
        'post_title'         => true,
        'post_content'       => true,
        'fields'             => lg_fc_acf_field_names($type),
        'form'               => true,
        'honeypot'           => true,
        'uploader'           => 'basic',
        'submit_value'       => $types[$type]['submit'],
        'updated_message'    => '',
        'html_submit_button' => '<input type="submit" class="lgfc__submit" value="%s" />',
        'return'             => add_query_arg('lg_fc', 'posted', get_permalink() ?: home_url('/')),
    ]);

    // Processes the submission (nonce-checked) and redirects on success. Must run
    // before any output.
    acf_form_head();

    lg_fc_render($type);
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
    $out = [];
    foreach (lg_fc_types()[$type]['fields'] as $f) {
        if ($f[0] === 'post_title' || $f[0] === 'post_content') {
            continue;
        }
        $out[] = $f[0];
    }
    return $out;
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

function lg_fc_render(string $type): void
{
    $t = lg_fc_types()[$type];

    // WP resolved /compose/ as a 404 query before we claimed it; say otherwise.
    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');

    // The mock's labels replace ACF's for the duration of this render only.
    add_filter('acf/prepare_field', 'lg_fc_relabel', 20);

    lg_fc_page_open($t['title']);
    ?>
<form class="lgfc__card" method="post" data-lgfc-type="<?php echo esc_attr($type); ?>"
      data-lgfc-extras="<?php echo esc_attr(implode(',', lg_fc_extra_field_names($type))); ?>">
  <div class="lgfc__h">
    <h1><?php echo esc_html($t['title']); ?></h1>
    <p class="lgfc__sub lgfc__sub--wide"><?php echo esc_html($t['sub']); ?></p>
    <p class="lgfc__sub lgfc__sub--narrow"><?php echo esc_html($t['sub_narrow'] ?? $t['sub']); ?></p>
  </div>
  <div class="lgfc__b">
    <?php acf_form('lg-fc-' . $type); ?>

    <?php // Our own control, not ACF's — see lg_fc_comment_status(). ?>
    <div class="lgfc__own" data-lgfc-extra="1">
      <div class="lgfc__ownlabel"><?php echo esc_html($t['comments']['label']); ?></div>
      <div class="lgfc__chips">
        <label class="lgfc__chip"><input type="radio" name="lg_fc_comments" value="open" checked> <span>Yes</span></label>
        <label class="lgfc__chip"><input type="radio" name="lg_fc_comments" value="closed"> <span>No</span></label>
      </div>
    </div>
  </div>
  <div class="lgfc__f">
    <span class="lgfc__foot"><?php echo esc_html($t['foot']); ?></span>
  </div>
</form>
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

function lg_fc_page_open(string $title): void
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
<body class="lgfc-body">
<main class="lgfc">
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
.lgfc__b{padding:6px 21px 20px}

/* ---- one field ---- */
.lgfc .acf-fields>.acf-field{padding:15px 0;border-bottom:1px solid var(--lg-line,#e3ddd0);
  border-top:0;margin:0;width:auto;float:none}
.lgfc .acf-fields>.acf-field:last-child{border-bottom:0}
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

/* the licence list is long prose, so it stacks rather than wrapping as pills */
.lgfc .acf-field[data-name="loothprint_creative_commons"] .acf-radio-list{flex-direction:column;gap:5px}
.lgfc .acf-field[data-name="loothprint_creative_commons"] .acf-radio-list label{
  border-radius:10px;padding:10px 12px;width:100%;font-weight:600;line-height:1.35}

/* ---- gallery / file ---- */
.lgfc .acf-gallery,.lgfc .acf-field-file .acf-input>.acf-file-uploader{
  border:1px solid var(--lg-line,#e3ddd0);border-radius:11px;background:var(--lg-paper,#fdfdfa)}
.lgfc .acf-gallery{min-height:190px}
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

/* ---- footer ---- */
.lgfc__f{padding:15px 21px 19px;border-top:1px solid var(--lg-line,#e3ddd0);
  display:flex;align-items:center;gap:11px;flex-wrap:wrap}
.lgfc .acf-form-submit{margin:0;padding:0}
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
  .lgfc__b{padding:4px 16px 18px}
  .lgfc__f{padding:13px 16px 16px}
  .lgfc__foot{margin-left:0;flex:1 1 100%}
  /* 16px stops iOS zooming the viewport on focus */
  .lgfc .acf-input input[type=text],.lgfc .acf-input input[type=url],
  .lgfc .acf-input textarea{font-size:16px}
}

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
  var form = document.querySelector('.lgfc__card[data-lgfc-extras]');
  if (!form) return;
  var names = (form.getAttribute('data-lgfc-extras') || '').split(',').filter(Boolean);
  var nodes = [];
  names.forEach(function (n) {
    var el = form.querySelector('.acf-field[data-name="' + n + '"]');
    if (el) nodes.push(el);
  });
  form.querySelectorAll('[data-lgfc-extra]').forEach(function (el) { nodes.push(el); });
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
