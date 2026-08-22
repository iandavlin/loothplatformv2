<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * Internal: the featured-member SELECTABLE POOL, for the WP admin dash.
 * Backlog 18 (Ian 8/11), design rulings 8/14 (docs/IAN-RULINGS-2026-08-14.md
 * item 6). Piece #2 of three.
 *
 * Same shape as internal-byline-socials.php / internal-recap.php: the WP admin
 * page renders server-side on the WP pool, which cannot reach the profile_app
 * database — it asks here instead. Loopback + X-LG-Internal-Auth, same as every
 * other /internal/ endpoint (Whoami::verifyInternalAuth against
 * /etc/lg-internal-secret).
 *
 * Returns EVERY member who has opted in, regardless of their CURRENT profile
 * visibility — not just the currently-eligible ones. Ian's ADMIN DASH ruling
 * is a pool the admin can SEE, and the dash mock (dash.html) draws exactly this
 * case: a member who opted in and later went Private stays listed, marked
 * `eligible: false`, so the dash can explain why Feature is unavailable rather
 * than have them silently vanish. Their opt-in is untouched; they return to
 * `eligible: true` the moment they go Public again — this endpoint has nothing
 * to write, it only ever reports the live state.
 *
 * GET (no body) → { pool: [ { uuid, slug, display_name, avatar_url, tagline,
 *                              location, eligible, opted_in_at,
 *                              completeness: {...} }, ... ] }
 *   Sorted oldest-opted-in-first, matching the dash mock.
 *
 * `tagline` and `location` here are NOT visibility-filtered — this is a
 * trusted internal admin surface, same posture as Whoami's fuller internal
 * payloads. The PUBLIC card (built elsewhere, when a member is actually
 * selected) is what applies the public-facing visibility rules.
 *
 * ── `eligible` IS CONSENT + PRIVACY, AND NOTHING ELSE (#107, Ian 8/19) ───────
 * "opted in only" — profile completion NEVER narrows the pool. Consent is the
 * WHERE clause (featured_opt_in = true); `eligible` is the live privacy state
 * and nothing more. Completeness travels beside it as INFORMATION for the
 * admin's judgement, never as a wall. Verified unchanged for #107: card_ready
 * has never leaked into `eligible` here, so the wall Ian overruled lived only
 * in the dash.
 *
 * ── `card_renderable` — WHAT THE FRONT PAGE WILL ACTUALLY DO (#107) ──────────
 * Dropping the dash's refusal turns a legible "no" into a SILENT one unless the
 * dash can tell the admin what happens next, because the front-page resolver
 * keeps a guard of its own: lg_resolve_featured_member (archive-poc/web/index.php)
 * returns null — no band at all — when the avatar or the resolved role is empty,
 * since the card's template renders both UNCONDITIONALLY and would otherwise
 * ship an <img src=""> and a blank line to every visitor.
 *
 * So this reports that guard's own verdict, computed with the resolver's exact
 * rule rather than with card_ready. The two are NOT the same test and must not
 * be conflated:
 *   card_ready       photo + what_you_do + LOCATION  (Completeness::CARD_ITEMS)
 *   card_renderable  photo + role, where `role` is at_a_glance ONLY IF the
 *                    member's header block is public, else business_name
 * `card_blockers` names the CAUSE, because an empty role has two of them and
 * they call for opposite advice: `what_you_do` (nothing written) vs
 * `what_you_do_members_only` (written, but withheld from the public card).
 * Four of the five affected members on dev2 are the SECOND kind.
 * Location is absent here on purpose (the card hides its own missing location),
 * and the header-visibility rule is absent from card_ready — which is why a
 * member can read card_ready:true and still resolve to no band.
 *
 * THAT IS NOT HYPOTHETICAL. Measured on dev2 2026-08-20, the whole opted-in
 * pool: 8 members, card_ready true for 7 — but card_renderable true for only 3.
 * Rick Liftig, Stephen Martin, Eric Haskins and Karl Borum all read "Ready" in
 * the dash, all had an ENABLED Feature button, and all four resolve to role ''
 * and therefore to NO BAND, because their header block is members-only and
 * their business_name is a tail of their display name. The header-visibility
 * rule that causes it is correct and deliberate (index.php, 2026-08-16: a
 * members-only glance must not be republished on the public front page) — what
 * was missing is any surface that TOLD the admin. card_ready could not: it does
 * not know about header visibility. Hence this field.
 *
 * ⚠️ THIS MIRRORS A RULE THAT LIVES IN ANOTHER PROCESS. archive-poc is a
 * separate app this endpoint cannot call; the copy is deliberate and is kept
 * honest by gate 39 §F3, which goes RED if the resolver's guard changes without
 * this predictor following it.
 *
 * ── `consent_informed` / `glance_needs_ack` — #107, Ian 8/20 ─────────────────
 * "The tick is consent": with platform/config/featured-consent.php ON, the
 * featured card may repeat an opted-in member's one-liner even when their
 * header block is members-only. That unblocks four of the five members above —
 * measured here, not assumed: the same pool goes from 3 renderable to 7.
 *
 * But eight members ticked under the OLD copy, which never mentioned the
 * one-liner, and their consent may not be silently upgraded. So the resolver
 * republishes only for an INFORMED tick (made at or after informed_copy_since)
 * or one an admin knowingly accepted at selection time (`consent_ack`). Since
 * the dash records that acknowledgement whenever it warns, a member whose only
 * blocker was header visibility WILL render once featured — which is why
 * `card_renderable` changes meaning with the flag, and why it is computed
 * against the flag state here rather than hardcoded to either answer.
 *
 *   consent_informed     did this tick happen under the copy that says so?
 *                        null when the flag is off or no cutover is set — i.e.
 *                        "not a question anyone is asking yet", not "no".
 *   glance_needs_ack     featuring them WILL put members-only text on the
 *                        public front page, under a tick that predates the
 *                        wording. The dash says so and labels the button
 *                        "Feature anyway"; the click records the ack.
 *   header_vis_explicit  did they actually choose that visibility, or is it the
 *                        platform default? 1,917 of 1,933 members have never
 *                        set a header row, so this is the difference between
 *                        "they hid it" and "nobody ever asked them" — and it
 *                        changes what the admin should do about it.
 *
 * Same absent-key discipline as card_renderable: an OLDER dash reading a NEWER
 * endpoint, or the reverse, must degrade to silence, never to a guess.
 */

use Looth\ProfileApp\Completeness;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Whoami;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') profile_app_json(405, ['error' => 'method_not_allowed']);
if (!Whoami::verifyInternalAuth()) profile_app_json(401, ['error' => 'bad_secret']);

/* THREE dots, not two — api/v0 sits one directory deeper than u.php and
   index.php, which use the same include with two. Getting this wrong is not a
   fatal: @include swallows it, the flag reads as off, and the dash quietly
   predicts the pre-#107 answer forever. It is exactly the bug gate 39 §C4 was
   written for after it shipped once in me-featured.php, so §G4 resolves this
   path for real too rather than trusting the dot count. */
$fmConsentCfg = @include __DIR__ . '/../../../platform/config/featured-consent.php';
$fmConsentOn  = is_array($fmConsentCfg) && !empty($fmConsentCfg['enabled']);
/* PER-BOX OVERRIDE, gitignored (#200, 2026-08-22). This layer did not exist and
   the file DID: dev2's serving checkout has carried featured-consent.local.php
   saying enabled => true since 2026-08-20 with nothing reading it, so the box
   was believed to be running the consent rule ON while running it OFF.
   MERGED PER KEY, unlike u.php's boolean-only read, because this endpoint reads
   BOTH: `enabled` decides whether the question is asked at all, and
   `informed_copy_since` decides which ticks count as informed. Overriding one
   without the other is the "flag reads as working, does nothing" pairing gate
   39 §G1 exists to catch — an ON with a null cutover means nobody is informed,
   so both keys must be able to travel together in the same override file. */
$fmConsentLoc = @include __DIR__ . '/../../../platform/config/featured-consent.local.php';
if (is_array($fmConsentLoc)) {
    if (array_key_exists('enabled', $fmConsentLoc)) {
        $fmConsentOn = ($fmConsentLoc['enabled'] === true);
    }
    if (array_key_exists('informed_copy_since', $fmConsentLoc)) {
        if (!is_array($fmConsentCfg)) $fmConsentCfg = [];
        $fmConsentCfg['informed_copy_since'] = $fmConsentLoc['informed_copy_since'];
    }
}
foreach ([getenv('LG_FEATURED_CONSENT'), $_SERVER['LG_FEATURED_CONSENT'] ?? false] as $o) {
    if ($o !== false && $o !== '') $fmConsentOn = ($o === '1' || $o === 'true');
}
$fmInformedSince = is_array($fmConsentCfg) ? ($fmConsentCfg['informed_copy_since'] ?? null) : null;
$fmInformedTs    = ($fmConsentOn && is_string($fmInformedSince)) ? strtotime($fmInformedSince) : false;

$pg = Db::pg();
$rows = $pg->query(
    "SELECT u.id, u.uuid, u.slug, u.display_name, u.avatar_url, u.at_a_glance,
            u.business_name, u.location_city, u.location_region,
            u.profile_visibility, u.featured_opt_in_at,
            -- No row => Block::HEADER_DEFAULT ('members'), the same default the
            -- resolver assumes. Read here so card_renderable can apply the
            -- resolver's header-visibility rule; the tagline field above stays
            -- unfiltered (this is a trusted internal surface).
            coalesce((SELECT ps.visibility FROM profile_sections ps
                       WHERE ps.user_id = u.id AND ps.key = 'header'), 'members')
              AS header_visibility,
            -- Chosen, or merely defaulted? (#107) The dash tells the admin
            -- which, because 'they set this to members-only' and 'they have
            -- never touched their header settings' call for opposite advice,
            -- and the second describes 1,917 of 1,933 members.
            EXISTS (SELECT 1 FROM profile_sections ps2
                     WHERE ps2.user_id = u.id AND ps2.key = 'header')
              AS header_vis_explicit
       FROM users u
      WHERE u.featured_opt_in = true
      ORDER BY u.featured_opt_in_at ASC NULLS LAST"
)->fetchAll();

$pool = [];
foreach ($rows as $r) {
    $uid = (int) $r['id'];
    $tagline = trim((string) $r['at_a_glance']);
    if ($tagline === '') {
        $biz = Completeness::deEscape($r['business_name']);
        // Same "is it just a slice of the display name" test Completeness uses
        // for the score — a business_name that IS the display name's tail is
        // not a tagline, it is the same three words twice.
        if ($biz !== '' && !str_ends_with((string) $r['display_name'], $biz)) $tagline = $biz;
    }
    $loc = trim(implode(', ', array_filter([$r['location_city'], $r['location_region']])));

    // ── The resolver's OWN verdict, its rule reproduced exactly ──────────────
    // Deliberately NOT $tagline above: that one ignores header visibility, so a
    // member whose glance is members-only reads as having a tagline here while
    // the resolver sees none. Getting this wrong is the whole point of the
    // field — a confidently wrong prediction is worse than no prediction.
    //
    // #107: with the consent flag ON the resolver may repeat a members-only
    // glance, so this prediction follows the flag. `$mayRepublish` is TRUE for
    // any opted-in member here — not only the informed ones — because the dash
    // records the admin's acknowledgement for the rest at the moment they are
    // featured, and the resolver honours that. So the honest answer to "will
    // the front page draw a band if I click Feature" is yes for both. Whether
    // the admin should click is a DIFFERENT question, and it is answered by
    // glance_needs_ack below rather than by pretending the card is broken.
    $glanceRaw    = trim((string) $r['at_a_glance']);
    $headerPublic = $r['header_visibility'] === 'public';
    $optedTs      = $r['featured_opt_in_at'] !== null ? strtotime((string) $r['featured_opt_in_at']) : false;
    $informed     = ($fmInformedTs !== false && $optedTs !== false) ? ($optedTs >= $fmInformedTs) : false;
    $mayRepublish = $fmConsentOn && $glanceRaw !== '';

    $resolverRole = ($headerPublic || $mayRepublish) ? $glanceRaw : '';
    if ($resolverRole === '') {
        $biz = Completeness::deEscape($r['business_name']);
        if ($biz !== '' && !str_ends_with((string) $r['display_name'], $biz)) $resolverRole = $biz;
    }

    // Featuring them republishes members-only text under a tick that predates
    // the wording that describes it. Not a defect and not a refusal — a thing
    // the admin is entitled to know before clicking, per Ian's "his call per
    // pick". False the moment they re-confirm (the tick re-stamps), and false
    // for a member whose glance is already public, since nothing is being
    // republished there at all.
    $needsAck = $mayRepublish && !$headerPublic && !$informed;
    // TWO DIFFERENT CAUSES OF AN EMPTY ROLE, and they need OPPOSITE advice.
    // Measured on dev2 2026-08-20: of the 5 members whose card cannot render,
    // FOUR already have a one-liner — it is simply members-only, so the public
    // card may not repeat it. Telling those four to "add a one-line what you
    // do" is confidently wrong advice about a field they already filled in;
    // only Carl Ioriatti has genuinely written nothing. So the blocker names
    // the cause, not just the symptom.
    // Under the consent flag `what_you_do_members_only` stops being a blocker at
    // all — $resolverRole is non-empty for those members now, so this branch is
    // simply not reached for them. It stays for the flag-OFF state, which is
    // still the shipped default. Whether the code is reachable is decided by
    // the flag, not by deleting the branch.
    $blockers = [];
    if (trim((string) $r['avatar_url']) === '') $blockers[] = 'photo';
    if ($resolverRole === '') {
        $blockers[] = $glanceRaw !== '' && !$headerPublic
            ? 'what_you_do_members_only'
            : 'what_you_do';
    }

    $pool[] = [
        'uuid'          => $r['uuid'],
        'slug'          => $r['slug'],
        'display_name'  => $r['display_name'],
        'avatar_url'    => $r['avatar_url'],
        'tagline'       => $tagline,
        'location'      => $loc,
        // Consent + privacy ONLY (Ian 8/19, #107) — completion never narrows this.
        'eligible'      => $r['profile_visibility'] === 'public',
        'opted_in_at'   => $r['featured_opt_in_at'],
        'completeness'  => Completeness::forUser($uid),
        // Information, never permission: the dash shows these, it does not
        // refuse on them.
        'card_renderable' => $blockers === [],
        'card_blockers'   => $blockers,
        // #107 consent state. `consent_informed` is null — not false — when the
        // flag is off or no cutover is set: the question is not being asked, and
        // a false there would read as "this member declined something".
        'consent_informed'    => ($fmConsentOn && $fmInformedTs !== false) ? $informed : null,
        'glance_needs_ack'    => $needsAck,
        'header_vis_explicit' => (bool) $r['header_vis_explicit'],
    ];
}

/* ── #200: CANDIDATES — the members Ian can PIN ──────────────────────────────
 *
 * Ian, 2026-08-22: "The override I wanted would still have them on the frontpage
 * even if they didn't meet the criteria." The pool above is the self-serve list
 * and is unchanged; this is the ADDITIVE half — every real member, searchable,
 * so an admin can place someone who has not ticked the box.
 *
 * ⚠️ ONLY ON REQUEST, and only with a query. 1,934 public members live on this
 * box; returning them unasked would make every dash page load carry a
 * Completeness computation per member for a list nobody is reading. Absent `q`,
 * this key is absent from the payload entirely — which an older dash also reads
 * correctly as "this endpoint does not offer candidates", the same absent-key
 * discipline card_renderable uses.
 *
 * `eligible` MEANS THE SAME THING HERE AS ABOVE, and that is the point: privacy,
 * and nothing else. A member whose profile is Private is RETURNED, marked
 * ineligible, so the dash can say "cannot be pinned — their profile is Private"
 * instead of leaving Ian to wonder why a name he searched for is not there. That
 * refusal is keeper's ruling of 2026-08-22 upholding this lane's recommendation:
 * a pinned pick does not bypass a member's own profile_visibility, because that
 * is their switch rather than one of the platform's bars.
 *
 * NO card_renderable HERE, DELIBERATELY. That field predicts the resolver's
 * avatar+role guard — and for a pinned pick the resolver does not apply that
 * guard at all, so reporting it would be a confidently wrong answer to "will
 * this show?" (it always will). What the admin needs instead is what the card
 * will actually SAY, so the fields it can be built from travel plainly:
 * `has_photo` and `public_role`, the latter computed with the pinned rule — the
 * glance only when the header block is public, never on a consent the member
 * has not given.
 */
$candidates = null;
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    // ILIKE over the three fields an admin would type. Bounded at 25: a search
    // is for finding a person you have in mind, and a longer list is a worse
    // answer, not a more complete one.
    //
    // ⚠️ AND AN EXACT UUID MATCH, which is not a convenience — it is load
    // bearing. FeaturedMemberDash::handle_pin() re-resolves the member by uuid
    // before writing, so that the display name snapshotted into
    // featured_history and the privacy refusal both come from this endpoint
    // rather than from the submitted form. Without this clause that lookup
    // finds nobody every time and every pin fails with "could not be looked
    // up" — caught by reading the two files together, before either ran.
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
    $cst = $pg->prepare(
        "SELECT u.id, u.uuid, u.slug, u.display_name, u.avatar_url, u.at_a_glance,
                u.business_name, u.location_city, u.location_region,
                u.profile_visibility, u.featured_opt_in,
                coalesce((SELECT ps.visibility FROM profile_sections ps
                           WHERE ps.user_id = u.id AND ps.key = 'header'), 'members')
                  AS header_visibility
           FROM users u
          WHERE (u.display_name ILIKE :q OR u.slug ILIKE :q OR u.business_name ILIKE :q
                 OR u.uuid::text = :exact)
          ORDER BY (u.uuid::text = :exact) DESC, (u.display_name ILIKE :q) DESC, u.display_name ASC
          LIMIT 25"
    );
    $cst->execute([':q' => $like, ':exact' => $q]);

    $candidates = [];
    foreach ($cst->fetchAll() as $r) {
        // The PINNED role rule, which is the resolver's rule with the consent
        // routes closed: a member who never ticked has given no consent for
        // their members-only one-liner to be republished, so it is used ONLY
        // when their header block is already public. Mirrors
        // lg_fm_card_role($..., $pinned = true) in archive-poc/web/index.php —
        // a copy across two processes, kept honest by gate 94 §C the same way
        // gate 39 §F3 keeps card_renderable honest.
        $glanceRaw = trim((string) $r['at_a_glance']);
        $role = ($r['header_visibility'] === 'public') ? $glanceRaw : '';
        if ($role === '') {
            $biz = Completeness::deEscape($r['business_name']);
            if ($biz !== '' && !str_ends_with((string) $r['display_name'], $biz)) $role = $biz;
        }
        $candidates[] = [
            'uuid'         => $r['uuid'],
            'slug'         => $r['slug'],
            'display_name' => $r['display_name'],
            'avatar_url'   => $r['avatar_url'],
            'location'     => trim(implode(', ', array_filter([$r['location_city'], $r['location_region']]))),
            // Privacy only — the one criterion pinning does not override.
            'eligible'     => $r['profile_visibility'] === 'public',
            // Already in the self-serve pool? Then they do not need pinning, and
            // the dash says so rather than offering two routes to one member.
            'opted_in'     => (bool) $r['featured_opt_in'],
            'has_photo'    => trim((string) $r['avatar_url']) !== '',
            // What the pinned card's second line will actually say — '' means
            // the card draws with no role line at all, which is allowed for a
            // pinned pick and is worth showing before the click, not after.
            'public_role'  => $role,
        ];
    }
}

profile_app_json(200, $candidates === null
    ? ['pool' => $pool]
    : ['pool' => $pool, 'candidates' => $candidates]);
