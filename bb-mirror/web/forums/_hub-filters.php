<?php
declare(strict_types=1);
/**
 * Hub control-sidebar — filter parsing, facet counts, and the server-side
 * AND filter applied to the unified feed UNION. Site-wide /hub/ only.
 *
 * Model (hub-filter-nav-spec.md): AND across Type ∩ Category ∩ Author.
 *  - Type:     content kinds + "discussions" (forum topics). Multi = OR within.
 *  - Category: forum categories (repair/builds/…). Content carries NO category
 *              (content_item.forum_label is empty), so a Category filter narrows
 *              to forum threads only — content drops out under strict AND.
 *  - Author:   single author, matched by NAME (topic person ids and content WP
 *              user ids are different id spaces; the profile-app user_uuid
 *              unification is a later cross-lane increment).
 *
 * Filtering runs in SQL on the union's outer WHERE so pagination stays correct
 * (we never render-then-hide). Facet counts are computed over the tier-gated
 * unified set, independent of the current selection (matches the mockup).
 */

// Content-kind -> display label for the Type facet. Unlisted kinds title-case.
const HUB_TYPE_LABELS = [
    'discussions'  => 'Discussions',
    'video'        => 'Videos',
    'article'      => 'Articles',
    'loothprint'   => 'Loothprints',
    // 'event' removed from the Hub (Ian 2026-06-11: events live on /events/);
    // the feed union + facet counts exclude kind='event' to match.
    'sponsor-post' => 'Sponsors',
    'useful_links' => 'Useful Links',
    'shorty'       => 'Shorts',
    'benefit'      => 'Benefits',
    'loothcuts'    => 'Loothcuts',
    'document'     => 'Documents',
    'misc'         => 'Misc',
];

// Category key -> display label (keys produced by bb_mirror_cat_key()).
const HUB_CAT_LABELS = [
    'repair'      => 'Repair & Restoration',
    'builds'      => 'New Builds',
    'acoustic'    => 'Acoustic',
    'tools'       => 'Tools, Spaces & Robots',
    'business'    => 'Business',
    'market'      => 'Market Place',
    'sponsors'    => 'Sponsor Forums',
    'looths'      => 'Local Looths',
    'suggestions' => 'Suggestions',
    'general'     => 'General',
];

// Video-type taxonomy terms surfaced by the desktop "Shows" filter. Authoritative
// "what counts as a show" list (curated like HUB_TYPE_LABELS / HUB_CAT_LABELS, since
// discovery.tag does not record the source taxonomy). slug => display label. Add a
// row when a new show launches; the dropdown live-drops any show with zero video
// items. 'all-videos' is the term's catch-all and intentionally omitted.
const HUB_SHOW_TERMS = [
    'doug-and-dan'                              => 'Doug and Dan',
    'shop-tour'                                 => 'Shop Tour',
    'interview'                                 => 'Interview',
    'demo'                                      => 'Demo',
    'tutorials'                                 => 'Tutorials',
    'project-run-down'                          => 'Project Run Down',
    'loothing-for-dollars'                      => 'Loothing for Dollars',
    '3d-club'                                   => '3D Club',
    'council-of-elders'                         => 'Council of Elders',
    'practical-tube-amp-course'                 => 'Practical Tube Amp Course',
    'ding-kings'                                => 'Ding Kings',
    'vintage-schmintage'                        => 'Vintage Schmintage',
    'how-did-you-do-that'                       => 'How Did You Do That?',
    'marketing-club'                            => 'Marketing Club',
    'acoustic-guitar-builders-club'             => 'Acoustic Guitar Builders Club',
    'electric-guitar-builders-club'             => 'Electric Guitar Builders Club',
    'design-and-testing-of-the-acoustic-guitar' => 'Design & Testing of the Acoustic Guitar',
    'violin-repair-crash-course'                => 'Violin Repair Crash Course',
    'proper-loothing'                           => 'Proper Loothing',
    'hawfl'                                      => 'HAWFL',
    'sponsor-event'                             => 'Sponsor Event',
];

function hub_type_label(string $key): string
{
    return HUB_TYPE_LABELS[$key] ?? ucwords(str_replace(['_', '-'], ' ', $key));
}
function hub_cat_label(string $key): string
{
    return HUB_CAT_LABELS[$key] ?? ucfirst($key);
}

/**
 * Content tiers the current viewer may see (absence-model gating).
 * Ladder public<lite<pro — you see your tier and below. ADMINS/EDITORS bypass
 * gating (all tiers) so they can preview/manage everything; keyed off whoami
 * capabilities (same caps the shared header reads) so a plain member can't
 * self-elevate. caps may be a list of strings OR an assoc map (cap => true).
 */
function hub_content_tiers(): array
{
    $wa   = function_exists('lg_bb_mirror_whoami') ? lg_bb_mirror_whoami() : null;
    $caps = is_array($wa) ? (array)($wa['capabilities'] ?? []) : [];
    foreach (['manage_options', 'administrator', 'edit_others_posts', 'activate_plugins'] as $c) {
        if (!empty($caps[$c]) || in_array($c, $caps, true)) return ['public', 'lite', 'pro'];
    }
    $tier = (is_array($wa) && in_array($wa['tier'] ?? '', ['public', 'lite', 'pro'], true))
        ? (string)$wa['tier'] : 'public';
    $rank = ['public' => 0, 'lite' => 1, 'pro' => 2];
    return array_keys(array_filter($rank, fn($r) => $r <= $rank[$tier]));
}

/**
 * Batch-resolve author profiles from profile-app (live avatars/names/bio), keyed
 * by WP user id. Mirrors archive-poc's pattern: loopback to /profile-api/v0/users
 * forwarding the visitor's cookies (gate + session). Both topic.author_id and
 * content_item.author_id are WP user ids (person is keyed on WP id), so one call
 * covers every card on the page.
 *
 * @param int[] $wp_ids
 * @return array<int,array{avatar_url:?string,slug:?string,display_name:?string,at_a_glance:?string}>
 */
/**
 * P6 — the logged-in viewer's muted author UUIDs, via Buck's me-mutes GET
 * (`/profile-api/v0/me/mutes` → {muted:[uuid,…]}). Identity is the viewer's own
 * session: we forward their Cookie, so the endpoint's Auth::requireUser() resolves
 * THIS viewer. Cross-DB is fine — we fetch the list (mutes live in profile_app),
 * we don't join across to looth PG. Returns a SET [uuid_lower => true].
 * Fails OPEN (empty set → no filtering) for anon / endpoint error so the feed
 * never breaks while Buck confirms the contract.
 */
function hub_viewer_muted_uuids(): array
{
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_COOKIE'])) return [];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://127.0.0.1/profile-api/v0/me/mutes',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_HTTPHEADER     => ['Host: ' . LG_BB_MIRROR_HOST, 'Cookie: ' . $_SERVER['HTTP_COOKIE']],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return [];          // anon (401) / error → fail open
    $data = json_decode($body, true);
    $set  = [];
    foreach ((array)($data['muted'] ?? []) as $u) {
        $u = strtolower(trim((string)$u));
        if ($u !== '') $set[$u] = true;
    }
    return $set;
}

/**
 * The logged-in viewer's SAVED posts (Saved-rail filter), via the engine's
 * my-saved endpoint (`/archive-api/v0/my-saved` → {authenticated, items:[…]}).
 * Identity = the viewer's WP-login cookie (the SAME door the ☆ hydrate uses —
 * runs on the looth-dev WP pool's get_current_user_id(), so it resolves real
 * members even when /whoami would call them anon). We forward the visitor's
 * Cookie; cross-DB is fine (we fetch the id list, the feed query does the WHERE).
 * Returns ['topics' => [int id,…], 'content' => ['cpt:id',…]]. Empty (anon /
 * error / no saves) → an empty set → the Saved view shows nothing (fail closed,
 * which is correct: an empty Saved list IS empty).
 */
function hub_viewer_saved_set(): array
{
    $out = ['topics' => [], 'content' => []];
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_COOKIE'])) return $out;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://127.0.0.1/archive-api/v0/my-saved',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_HTTPHEADER     => ['Host: ' . LG_BB_MIRROR_HOST, 'Cookie: ' . $_SERVER['HTTP_COOKIE']],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return $out;           // anon / error → empty
    $data = json_decode($body, true);
    foreach ((array)($data['items'] ?? []) as $it) {
        $pt = (string)($it['post_type'] ?? '');
        $id = (int)($it['item_id'] ?? 0);
        if ($pt === '' || $id <= 0) continue;
        if ($pt === 'topic') $out['topics'][]  = $id;
        else                 $out['content'][] = $pt . ':' . $id;
    }
    return $out;
}

function hub_resolve_profiles(array $wp_ids): array
{
    $wp_ids = array_values(array_unique(array_filter(array_map('intval', $wp_ids), fn($i) => $i > 0)));
    if (!$wp_ids || PHP_SAPI === 'cli') return [];

    $hdrs = ['Host: ' . LG_BB_MIRROR_HOST];
    if (!empty($_SERVER['HTTP_COOKIE'])) $hdrs[] = 'Cookie: ' . $_SERVER['HTTP_COOKIE'];

    $out = [];
    foreach (array_chunk($wp_ids, 100) as $chunk) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://127.0.0.1/profile-api/v0/users?wp_ids=' . rawurlencode(implode(',', $chunk)),
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
        if ($code === 200 && $body) {
            $data = json_decode($body, true);
            foreach ((array)($data['items'] ?? []) as $it) {
                if (!empty($it['wp_user_id'])) {
                    $out[(int)$it['wp_user_id']] = [
                        'avatar_url'   => $it['avatar_url']   ?? null,
                        'slug'         => $it['slug']         ?? null,
                        'display_name' => $it['display_name'] ?? null,
                        'bio'          => $it['bio'] ?? $it['at_a_glance'] ?? null,
                        'uuid'         => $it['uuid']         ?? null, // for viewer-mute author filter
                    ];
                }
            }
        }
    }
    return $out;
}

/** Parse the active filter selection from the request. */
function hub_filters_parse(): array
{
    $csv = function (string $k): array {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string)($_GET[$k] ?? ''))
        ), fn($s) => $s !== ''));
    };
    return [
        'types'   => $csv('type'),                      // e.g. ['video','discussions']
        'cats'    => $csv('cat'),                        // parent categories (cat_key)
        'leaves'  => $csv('leaf'),                       // leaf subforums (subforum slug)
        'authors' => $csv('author'),                     // multi-select, by name (CSV)
        'q'       => trim((string)($_GET['q'] ?? '')),  // unified full-text query (AND dim)
        'saved'   => !empty($_GET['saved']),             // Saved-rail view (viewer's ☆ saves)
        'show'    => hub_show_validate((string)($_GET['show'] ?? '')), // single video-type term (Shows filter)
        // Multi exact-tag facet (cross-world), AND semantics — mirrors authors:
        // ?tag=frets,nut -> ['frets','nut']. Each CSV item normalized to a slug,
        // deduped, empties dropped.
        'tags'    => array_values(array_unique(array_filter(array_map('hub_slugify', $csv('tag'))))),
    ];
}

/** Whitelist a ?show= slug to a known video-type term (else ''). */
function hub_show_validate(string $slug): string
{
    $slug = trim($slug);
    return ($slug !== '' && array_key_exists($slug, HUB_SHOW_TERMS)) ? $slug : '';
}

/**
 * Shows facet (video-type taxonomy) for the desktop "Shows" dropdown.
 * The video-type terms are already materialized into discovery.tag (archive
 * indexer), so we count video items per known show slug in ONE PG pass - no WP
 * call, anon-safe. Returns [['slug','label','count'], ...] sorted by count desc,
 * dropping shows with no video items. HUB_SHOW_TERMS curates which tags are shows.
 */
function hub_show_terms(PDO $db): array
{
    $slugs = array_keys(HUB_SHOW_TERMS);
    if (!$slugs) return [];
    $ph = []; $binds = [];
    foreach ($slugs as $i => $sg) { $ph[] = ":ss$i"; $binds[":ss$i"] = $sg; }
    $sql = "SELECT t.slug, count(DISTINCT ci.id) AS n
              FROM discovery.tag t
              JOIN discovery.content_tag ct ON ct.tag_id = t.id
              JOIN discovery.content_item ci ON ci.id = ct.content_id AND ci.kind = 'video'
             WHERE t.slug IN (" . implode(',', $ph) . ")
             GROUP BY t.slug";
    $counts = [];
    try {
        $st = $db->prepare($sql);
        $st->execute($binds);
        foreach ($st->fetchAll() as $r) $counts[(string)$r['slug']] = (int)$r['n'];
    } catch (\Throwable $e) { return []; }
    $out = [];
    foreach (HUB_SHOW_TERMS as $slug => $label) {
        $n = $counts[$slug] ?? 0;
        if ($n > 0) $out[] = ['slug' => $slug, 'label' => $label, 'count' => $n];
    }
    usort($out, fn($a, $b) => $b['count'] <=> $a['count']);
    return $out;
}

/**
 * Resolve the active tags' display labels (+ counts) for the chipbar, as a map
 * slug => ['slug','label','count']. Multi-tag (AND) facet — one entry per
 * selected slug (deduped). Mirrors how the multi-author filter resolves names;
 * the chipbar renders one removable #<label> chip per entry. Empty input -> [].
 */
function hub_tag_terms(PDO $db, array $slugs, array $content_tiers): array
{
    $out = [];
    foreach ($slugs as $sg) {
        $sg = trim((string)$sg);
        if ($sg === '' || isset($out[$sg])) continue;
        $t = hub_tag_term($db, $sg, $content_tiers);
        if ($t) $out[$sg] = $t;
    }
    return $out;
}

/**
 * Active-tag facet (cross-world exact-tag count) for one slug.
 * Counts CONTENT items (via the content_tag slug, tier-gated, same exclusions as
 * the feed) + forum TOPICS (via the normalized topic.tags label) carrying that
 * slug, and resolves a display label. Returns ['slug','label','count'] or null
 * for an empty slug. Label prefers the canonical discovery.tag.label (content
 * world is slugged+labelled); falls back to a de-slugified form for topic-only
 * tags. ONE pass per world; anon-safe.
 */
function hub_tag_term(PDO $db, string $slug, array $content_tiers): ?array
{
    $slug = trim($slug);
    if ($slug === '') return null;

    // Canonical label from the content tag store (slug is UNIQUE there).
    $label = '';
    try {
        $ls = $db->prepare("SELECT label FROM discovery.tag WHERE slug = :s LIMIT 1");
        $ls->execute([':s' => $slug]);
        $label = (string)($ls->fetchColumn() ?: '');
    } catch (\Throwable $e) { /* fall through to de-slug */ }
    if ($label === '') $label = ucwords(str_replace('-', ' ', $slug)); // topic-only / fallback

    // Content count (tier-gated; mirrors the feed's kind exclusions).
    $cn = 0;
    try {
        $tph = [];
        foreach ($content_tiers as $i => $t) $tph[] = ':tt' . $i;
        $tin = $tph ? implode(',', $tph) : "''";
        $cs = $db->prepare("SELECT count(DISTINCT ci.id) FROM discovery.content_item ci
                              JOIN discovery.content_tag ct ON ct.content_id = ci.id
                              JOIN discovery.tag t ON t.id = ct.tag_id
                             WHERE t.slug = :s AND ci.tier IN ($tin)
                               AND ci.kind NOT IN ('event','misc')");
        $cs->bindValue(':s', $slug);
        foreach ($content_tiers as $i => $t) $cs->bindValue(':tt' . $i, $t);
        $cs->execute();
        $cn = (int)$cs->fetchColumn();
    } catch (\Throwable $e) { /* leave 0 */ }

    // Topic count (normalized-label match; same public/status guard as the feed).
    $tn = 0;
    try {
        $ts = $db->prepare("SELECT count(*) FROM topic tp JOIN forum f ON f.id = tp.forum_id
                             WHERE tp.status='publish' AND f.visibility='public' AND tp.forum_id NOT IN (3876)
                               AND EXISTS (SELECT 1 FROM unnest(tp.tags) x
                                           WHERE trim(both '-' from regexp_replace(lower(x), '[^a-z0-9]+', '-', 'g')) = :s)");
        $ts->execute([':s' => $slug]);
        $tn = (int)$ts->fetchColumn();
    } catch (\Throwable $e) { /* leave 0 */ }

    return ['slug' => $slug, 'label' => $label, 'count' => $cn + $tn];
}

/**
 * Facet counts over the tier-gated unified set.
 * Returns ['types' => [key=>count, …, 'discussions'=>N], 'cats' => [catkey=>count]].
 * $forum_cat_map: forum_id => cat_key (from bb_mirror_build_cat_map()).
 */
function hub_facet_counts(PDO $db, array $content_tiers, array $forum_cat_map): array
{
    // -- Type counts: content kinds + Discussions (forum topics) --
    $tier_ph = [];
    foreach ($content_tiers as $i => $t) $tier_ph[] = ':ft' . $i;
    $tin = $tier_ph ? implode(',', $tier_ph) : "''";

    $tc = $db->prepare("SELECT kind, count(*) AS n FROM discovery.content_item
                         WHERE tier IN ($tin) AND kind NOT IN ('misc','event') GROUP BY kind");
    foreach ($content_tiers as $i => $t) $tc->bindValue(':ft' . $i, $t);
    $tc->execute();
    $types = [];
    foreach ($tc->fetchAll() as $r) $types[(string)$r['kind']] = (int)$r['n'];

    $disc = (int)$db->query("
        SELECT count(*) FROM topic t JOIN forum f ON f.id = t.forum_id
         WHERE t.status='publish' AND f.visibility='public' AND t.forum_id NOT IN (3876)
    ")->fetchColumn();
    $types['discussions'] = $disc;

    // -- Category counts: forum topics folded by cat_key (PHP-derived taxonomy) --
    $cats = [];
    $rows = $db->query("
        SELECT t.forum_id, count(*) AS n
          FROM topic t JOIN forum f ON f.id = t.forum_id
         WHERE t.status='publish' AND f.visibility='public' AND t.forum_id NOT IN (3876)
         GROUP BY t.forum_id
    ")->fetchAll();
    foreach ($rows as $r) {
        $key = $forum_cat_map[(int)$r['forum_id']] ?? 'general';
        $cats[$key] = ($cats[$key] ?? 0) + (int)$r['n'];
    }

    // Fold CONTENT into the same category counts (reconciled by forum_label slug).
    // Content-only labels (Perspective, Vintage) surface as their own categories.
    $ccs = $db->prepare("SELECT forum_label, count(*) AS n FROM discovery.content_item
                          WHERE tier IN ($tin) AND kind <> 'event' AND COALESCE(forum_label,'') <> '' GROUP BY forum_label");
    foreach ($content_tiers as $i => $t) $ccs->bindValue(':ft' . $i, $t);
    $ccs->execute();
    foreach ($ccs->fetchAll() as $r) {
        $key = hub_reconcile_cat_key((string)$r['forum_label']);
        $cats[$key] = ($cats[$key] ?? 0) + (int)$r['n'];
    }

    return ['types' => $types, 'cats' => $cats];
}

/** cat_key => [forum_id, …] inverted from the forum cat-map. */
function hub_cat_forum_ids(array $forum_cat_map): array
{
    $out = [];
    foreach ($forum_cat_map as $fid => $key) $out[$key][] = (int)$fid;
    return $out;
}

/** Lowercase hyphen slug (commas/punct dropped) — reconciles content labels
 *  (raw shared_category term names) to the forum-slug taxonomy. */
function hub_slugify(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string)$s, '-');
}

// Known forum<->content drift where slugs DON'T already match (the comma-only
// drift like "Tools, Spaces, Robots and Widgets" slugifies identically, so it's
// not listed). content-label-slug => forum-slug.
const HUB_LABEL_ALIASES = [
    'shop-organization'      => 'shop-organisation',
    'tools-jigs-and-fixtures' => 'tools-and-jigs',
];

/** Reconcile a raw content category label to a cat_key. Content-only parents
 *  (no matching forum keyword — Perspective, Vintage) become their own key. */
function hub_reconcile_cat_key(string $label): string
{
    $slug = hub_slugify($label);
    if ($slug === '') return 'general';
    $slug = HUB_LABEL_ALIASES[$slug] ?? $slug;
    $key  = bb_mirror_cat_key($slug);
    return ($key === 'general' && $slug !== 'general') ? $slug : $key;
}

/** cat_key => [raw forum_label, …] for matching content by category. */
function hub_content_cat_labels(PDO $db): array
{
    $out = [];
    foreach ($db->query("SELECT DISTINCT forum_label FROM discovery.content_item WHERE COALESCE(forum_label,'') <> ''")->fetchAll() as $r) {
        $label = (string)$r['forum_label'];
        $out[hub_reconcile_cat_key($label)][] = $label;
    }
    return $out;
}

/**
 * Category tree for the accordion rail: ordered parents (top forums + content-
 * only parents) each with leaf subforums, dual-source counts (topics + content),
 * and the data the filter needs. Parent key = cat_key (?cat=); leaf key = the
 * subforum SLUG (?leaf=, unique — leaf TITLES repeat across parents).
 *
 * Returns [ tree[], leaf_registry ] where:
 *   tree[]  = [ key,label,count,forum_ids[],content_labels[], leaves[ {key,label,
 *               count,forum_id,parent_key,sublabel} ] ]
 *   leaf_registry = leaf_key => {forum_id, parent_key, sublabel, parent_labels[]}
 */
function hub_category_tree(PDO $db, array $content_tiers, array $forum_cat_map): array
{
    // -- forums (top + children) --
    $rows = $db->query("
        SELECT id, slug, title, parent_forum_id, menu_order
          FROM forum
         WHERE visibility='public' AND status IN ('open','closed') AND id NOT IN (67251,3876)
         ORDER BY parent_forum_id NULLS FIRST, menu_order ASC
    ")->fetchAll();
    $tops = []; $kids = [];
    foreach ($rows as $r) {
        if ($r['parent_forum_id'] === null) $tops[] = $r;
        else $kids[(int)$r['parent_forum_id']][] = $r;
    }

    // -- topic counts per forum_id --
    $tcount = [];
    foreach ($db->query("
        SELECT t.forum_id, count(*) n FROM topic t JOIN forum f ON f.id=t.forum_id
         WHERE t.status='publish' AND f.visibility='public' AND t.forum_id NOT IN (3876)
         GROUP BY t.forum_id")->fetchAll() as $r) $tcount[(int)$r['forum_id']] = (int)$r['n'];

    // -- content counts: by forum_label (parent) and by (forum_label,subforum_label) (leaf) --
    $tin = [];
    foreach ($content_tiers as $i => $t) $tin[] = ':ct' . $i;
    $tinSql = $tin ? implode(',', $tin) : "''";
    $bindTiers = function ($st) use ($content_tiers) {
        foreach ($content_tiers as $i => $t) $st->bindValue(':ct' . $i, $t);
    };
    $cParent = [];
    $sp = $db->prepare("SELECT forum_label, count(*) n FROM discovery.content_item
                         WHERE tier IN ($tinSql) AND kind <> 'event' AND COALESCE(forum_label,'')<>'' GROUP BY forum_label");
    $bindTiers($sp); $sp->execute();
    foreach ($sp->fetchAll() as $r) $cParent[hub_reconcile_cat_key((string)$r['forum_label'])] = ($cParent[hub_reconcile_cat_key((string)$r['forum_label'])] ?? 0) + (int)$r['n'];

    $cLeaf = []; // parent_key . "\0" . leaf_slug => count
    $sl = $db->prepare("SELECT forum_label, subforum_label, count(*) n FROM discovery.content_item
                         WHERE tier IN ($tinSql) AND kind <> 'event' AND COALESCE(subforum_label,'')<>'' GROUP BY forum_label, subforum_label");
    $bindTiers($sl); $sl->execute();
    foreach ($sl->fetchAll() as $r) {
        $pk = hub_reconcile_cat_key((string)$r['forum_label']);
        $ls = hub_slugify((string)$r['subforum_label']);
        $ls = HUB_LABEL_ALIASES[$ls] ?? $ls;
        $cLeaf[$pk . "\0" . $ls] = ($cLeaf[$pk . "\0" . $ls] ?? 0) + (int)$r['n'];
    }

    $clabels = hub_content_cat_labels($db); // cat_key => [raw forum_label]

    // subtree topic count (forum + descendants)
    $subtreeIds = function (int $id) use (&$kids, &$subtreeIds): array {
        $ids = [$id];
        foreach ($kids[$id] ?? [] as $c) $ids = array_merge($ids, $subtreeIds((int)$c['id']));
        return $ids;
    };

    // Group top forums by cat_key so distinct forums that share a key (e.g. all
    // the 'looths' regional chapters) aggregate into ONE category row instead of
    // rendering as duplicate "Local Looths" rows.
    $groups = []; $order = [];
    foreach ($tops as $t) {
        $pkey = bb_mirror_cat_key((string)$t['slug']);
        if (!isset($groups[$pkey])) { $groups[$pkey] = []; $order[] = $pkey; }
        $groups[$pkey][] = $t;
    }

    $tree = []; $registry = []; $seenKeys = [];
    foreach ($order as $pkey) {
        $all_sub = []; $leaves = [];
        foreach ($groups[$pkey] as $t) {
            $pid = (int)$t['id'];
            foreach ($subtreeIds($pid) as $fid) $all_sub[] = $fid;
            foreach ($kids[$pid] ?? [] as $c) {
                $lid  = (int)$c['id'];
                $lkey = (string)$lid;   // forum_id — subforum SLUGS collide (e.g. two 'acoustic')
                $lsub = $subtreeIds($lid);
                $lt   = 0; foreach ($lsub as $fid) $lt += $tcount[$fid] ?? 0;
                $lslug = HUB_LABEL_ALIASES[hub_slugify((string)$c['title'])] ?? hub_slugify((string)$c['title']);
                $lc   = $cLeaf[$pkey . "\0" . $lslug] ?? 0;
                $leaves[] = [
                    'key' => $lkey, 'label' => (string)$c['title'], 'count' => $lt + $lc,
                    'forum_id' => $lid, 'parent_key' => $pkey, 'sublabel' => (string)$c['title'],
                ];
                $registry[$lkey] = [
                    'forum_ids' => $lsub, 'parent_key' => $pkey, 'sublabel' => (string)$c['title'],
                    'parent_labels' => $clabels[$pkey] ?? [],
                ];
            }
        }
        $all_sub = array_values(array_unique($all_sub));
        $tc = 0; foreach ($all_sub as $fid) $tc += $tcount[$fid] ?? 0;
        $tree[] = ['key' => $pkey, 'label' => hub_cat_label($pkey), 'count' => $tc + ($cParent[$pkey] ?? 0),
                   'forum_ids' => $all_sub, 'content_labels' => $clabels[$pkey] ?? [], 'leaves' => $leaves];
        $seenKeys[$pkey] = true;
    }

    // content-only parents (Perspective, Vintage…): a cat_key with content but no
    // forum subtree.
    foreach ($cParent as $pkey => $n) {
        if (isset($seenKeys[$pkey])) continue;
        $tree[] = ['key' => $pkey, 'label' => hub_cat_label($pkey), 'count' => $n,
                   'forum_ids' => [], 'content_labels' => $clabels[$pkey] ?? [], 'leaves' => []];
    }

    // Rail display order: Sponsor Forums sits just below Market Place (Ian).
    $si = null;
    foreach ($tree as $i => $p) if ($p['key'] === 'sponsors') { $si = $i; break; }
    if ($si !== null) {
        $sp = array_splice($tree, $si, 1)[0];
        $mi = count($tree);
        foreach ($tree as $i => $p) if ($p['key'] === 'market') { $mi = $i + 1; break; }
        array_splice($tree, $mi, 0, [$sp]);
    }

    return [$tree, $registry];
}

/**
 * Build the server-side AND filter for the union's outer WHERE.
 * Returns [clauses[], named_binds] — the caller assembles the WHERE. Operates on
 * the union's output columns: card_type, content_kind, forum_id, author_name,
 * content_forum_label, content_subforum_label.
 *
 * Type ∩ Category is now a clean AND across BOTH worlds (content carries category
 * labels — forum_label/subforum_label — reconciled to the forum taxonomy by slug,
 * so Category narrows content too, not just discussions). Predicate per card_type,
 * OR'd: (content AND contentPred) OR (topic AND topicPred).
 *
 * $content_cat_labels: cat_key => [raw forum_label, …] (parent reconciliation).
 */
function hub_filter_where(array $filters, array $forum_cat_map, array $content_cat_labels = [], array $leaf_registry = []): array
{
    $and   = [];
    $binds = [];

    $content_conds = [];
    $topic_conds   = [];

    // -- Type: discussions => topics; kinds => content --
    if (!empty($filters['types'])) {
        $kinds = []; $want_disc = false;
        foreach ($filters['types'] as $t) {
            if ($t === 'discussions') { $want_disc = true; continue; }
            $kinds[] = $t;
        }
        if ($kinds) {
            $ph = [];
            foreach ($kinds as $i => $k) { $ph[] = ":hk$i"; $binds[":hk$i"] = $k; }
            $content_conds[] = 'u.content_kind IN (' . implode(',', $ph) . ')';
        } else {
            $content_conds[] = 'FALSE'; // only Discussions chosen
        }
        $topic_conds[] = $want_disc ? 'TRUE' : 'FALSE';
    }

    // -- Shows: a single video-type taxonomy term (desktop "Shows" filter).
    //    Video content only (a forum topic never carries a show), matched via the
    //    already-materialized content_tag slug. The slug is whitelisted at parse
    //    time (hub_show_validate), so it is always a known show. --
    if (!empty($filters['show'])) {
        $content_conds[] = "u.content_kind = 'video' AND u.topic_id IN ("
            . "SELECT ct.content_id FROM discovery.content_tag ct "
            . "JOIN discovery.tag t ON t.id = ct.tag_id WHERE t.slug = :show_slug)";
        $binds[':show_slug'] = $filters['show'];
        $topic_conds[] = 'FALSE';
    }

    // -- Tag: exact-tag facet, CROSS-WORLD, MULTI-select with AND semantics — a
    //    post must carry ALL selected tags. Content reuses the proven Shows clause
    //    over the materialized content_tag slug; topics match a normalized
    //    topic.tags label, slugified at query time to the SAME rule as
    //    hub_slugify() (lower; punct→'-'; trim '-'), so the two stores unify on one
    //    canonical slug. One content_cond + one topic_cond PER tag — both are AND'd
    //    within their world (see the assembly below), so this is contains-ALL. The
    //    slugs are normalized at parse time ([a-z0-9-]); distinct binds per tag &
    //    branch (PDO emulation off can't reuse a placeholder). Fail-closed. --
    if (!empty($filters['tags'])) {
        foreach ($filters['tags'] as $i => $tg) {
            $content_conds[] = "u.topic_id IN ("
                . "SELECT ct.content_id FROM discovery.content_tag ct "
                . "JOIN discovery.tag t ON t.id = ct.tag_id WHERE t.slug = :tag_c$i)";
            $binds[":tag_c$i"] = $tg;
            $topic_conds[] = "EXISTS (SELECT 1 FROM unnest(u.tags) AS _tg "
                . "WHERE trim(both '-' from regexp_replace(lower(_tg), '[^a-z0-9]+', '-', 'g')) = :tag_t$i)";
            $binds[":tag_t$i"] = $tg;
        }
    }

    // -- Category: topics by forum subtree, content by reconciled forum_label --
    if (!empty($filters['cats'])) {
        $cat_forums = hub_cat_forum_ids($forum_cat_map);
        $ids = [];
        foreach ($filters['cats'] as $c) foreach ($cat_forums[$c] ?? [] as $fid) $ids[] = (int)$fid;
        $ids = array_values(array_unique($ids));
        $topic_conds[] = $ids ? ('u.forum_id IN (' . implode(',', $ids) . ')') : 'FALSE';

        $labels = [];
        foreach ($filters['cats'] as $c) foreach ($content_cat_labels[$c] ?? [] as $l) $labels[] = $l;
        $labels = array_values(array_unique($labels));
        if ($labels) {
            $lph = [];
            foreach ($labels as $i => $l) { $lph[] = ":ccl$i"; $binds[":ccl$i"] = $l; }
            $content_conds[] = 'u.content_forum_label IN (' . implode(',', $lph) . ')';
        } else {
            $content_conds[] = 'FALSE';
        }
    }

    // -- Leaf subforums: topics by leaf forum subtree, content by (parent
    //    forum_label AND that leaf's subforum_label). Multi = OR within. --
    if (!empty($filters['leaves'])) {
        $lids = []; $c_or = [];
        foreach ($filters['leaves'] as $i => $lk) {
            $reg = $leaf_registry[$lk] ?? null;
            if (!$reg) continue;
            foreach ($reg['forum_ids'] as $fid) $lids[] = (int)$fid;
            $plph = [];
            foreach ($reg['parent_labels'] as $j => $pl) { $k = ":lpl{$i}_{$j}"; $plph[] = $k; $binds[$k] = $pl; }
            $slk = ":lsl$i"; $binds[$slk] = $reg['sublabel'];
            $c_or[] = $plph
                ? "(u.content_forum_label IN (" . implode(',', $plph) . ") AND u.content_subforum_label = $slk)"
                : "(u.content_subforum_label = $slk)";
        }
        $lids = array_values(array_unique($lids));
        $topic_conds[]   = $lids ? ('u.forum_id IN (' . implode(',', $lids) . ')') : 'FALSE';
        $content_conds[] = $c_or ? ('(' . implode(' OR ', $c_or) . ')') : 'FALSE';
    }

    if ($content_conds || $topic_conds) {
        $cp = $content_conds ? implode(' AND ', $content_conds) : 'TRUE';
        $tp = $topic_conds   ? implode(' AND ', $topic_conds)   : 'TRUE';
        $and[] = "((u.card_type = 'content' AND ($cp)) OR (u.card_type = 'topic' AND ($tp)))";
    }

    // -- Author: multi-select, by name (across both worlds); OR within --
    if (!empty($filters['authors'])) {
        $ph = [];
        // Case-insensitive (Ian 2026-06-10: typing "dan erlewine" must match
        // "Dan Erlewine" — the exact IN produced 0 posts for a found author).
        foreach ($filters['authors'] as $i => $a) { $ph[] = "LOWER(:ha$i)"; $binds[":ha$i"] = $a; }
        $and[] = 'LOWER(u.author_name) IN (' . implode(',', $ph) . ')';
    }

    return [$and, $binds];
}

/* ----------------------------------------------------------------------------
 * Sticky mute (increment 2) — per-user, persisted in the `hub_mute` cookie
 * (interim; profile-app becomes the source of truth later). Muting a Type or
 * Category hides it from the feed entirely (server-side, not render-then-hide).
 * Cookie format: comma-separated tokens, "t:<typekey>" / "c:<catkey>".
 * -------------------------------------------------------------------------- */

function hub_mute_parse(): array
{
    // FACET MUTE RETIRED from filter surfaces (Ian 2026-06-11: "filters should
    // be filter only"). Stored hub_mute cookies are IGNORED so nothing stays
    // hidden with no control to unmute it; the serialize/toggle helpers below
    // stay (harmless) for the dead mute_toggle URLs. Author-mutes (/me/mutes)
    // are a separate member feature and unaffected.
    return ['types' => [], 'cats' => [], 'leaves' => []];
    $types = []; $cats = []; $leaves = [];
    foreach (array_filter(explode(',', (string)($_COOKIE['hub_mute'] ?? ''))) as $tok) {
        $tok = trim($tok);
        if (strpos($tok, 't:') === 0)      $types[]  = substr($tok, 2);
        elseif (strpos($tok, 'c:') === 0)  $cats[]   = substr($tok, 2);
        elseif (strpos($tok, 'l:') === 0)  $leaves[] = substr($tok, 2);
    }
    return [
        'types'  => array_values(array_unique(array_filter($types))),
        'cats'   => array_values(array_unique(array_filter($cats))),
        'leaves' => array_values(array_unique(array_filter($leaves))),
    ];
}

function hub_mute_serialize(array $muted): string
{
    $out = [];
    foreach ($muted['types']  as $t) $out[] = 't:' . $t;
    foreach ($muted['cats']   as $c) $out[] = 'c:' . $c;
    foreach (($muted['leaves'] ?? []) as $l) $out[] = 'l:' . $l;
    return implode(',', $out);
}

/** Flip one key in the muted set. $facet is 't'(type) / 'c'(cat) / 'l'(leaf). */
function hub_mute_apply_toggle(array $muted, string $facet, string $val): array
{
    $key = ['t' => 'types', 'c' => 'cats', 'l' => 'leaves'][$facet] ?? 'cats';
    $set = $muted[$key] ?? [];
    $i   = array_search($val, $set, true);
    if ($i === false) $set[] = $val; else array_splice($set, $i, 1);
    $muted[$key] = array_values($set);
    return $muted;
}

/**
 * Exclusion clauses for muted Types/Categories/Leaves (hide both worlds).
 * Returns [clauses[], binds] to AND into the union's outer WHERE.
 */
function hub_mute_clause(array $muted, array $forum_cat_map, array $content_cat_labels = [], array $leaf_registry = []): array
{
    $and = []; $binds = []; $n = 0;

    $kinds = []; $disc = false;
    foreach ($muted['types'] as $t) {
        if ($t === 'discussions') { $disc = true; continue; }
        $kinds[] = $t;
    }
    if ($disc) $and[] = "NOT (u.card_type = 'topic')";
    if ($kinds) {
        $ph = [];
        foreach ($kinds as $k) { $b = ':muk' . $n++; $ph[] = $b; $binds[$b] = $k; }
        $and[] = "NOT (u.card_type = 'content' AND u.content_kind IN (" . implode(',', $ph) . "))";
    }

    // Muted category -> hide its topics AND its content (forum_label).
    if (!empty($muted['cats'])) {
        $cat_forums = hub_cat_forum_ids($forum_cat_map);
        $ids = []; $labels = [];
        foreach ($muted['cats'] as $c) {
            foreach ($cat_forums[$c] ?? [] as $fid) $ids[] = (int)$fid;
            foreach ($content_cat_labels[$c] ?? [] as $l) $labels[] = $l;
        }
        $ids = array_values(array_unique($ids));
        if ($ids) $and[] = "NOT (u.card_type = 'topic' AND u.forum_id IN (" . implode(',', $ids) . "))";
        if ($labels) {
            $ph = [];
            foreach (array_unique($labels) as $l) { $b = ':mul' . $n++; $ph[] = $b; $binds[$b] = $l; }
            $and[] = "NOT (u.card_type = 'content' AND u.content_forum_label IN (" . implode(',', $ph) . "))";
        }
    }

    // Muted leaf -> hide its topics AND its content (parent forum_label + subforum_label).
    foreach (($muted['leaves'] ?? []) as $lk) {
        $reg = $leaf_registry[$lk] ?? null;
        if (!$reg) continue;
        $ids = array_values(array_unique(array_map('intval', $reg['forum_ids'])));
        if ($ids) $and[] = "NOT (u.card_type = 'topic' AND u.forum_id IN (" . implode(',', $ids) . "))";
        $plph = [];
        foreach ($reg['parent_labels'] as $pl) { $b = ':mlp' . $n++; $plph[] = $b; $binds[$b] = $pl; }
        $sb = ':mls' . $n++; $binds[$sb] = $reg['sublabel'];
        $cond = $plph
            ? "u.content_forum_label IN (" . implode(',', $plph) . ") AND u.content_subforum_label = $sb"
            : "u.content_subforum_label = $sb";
        $and[] = "NOT (u.card_type = 'content' AND ($cond))";
    }

    return [$and, $binds];
}
