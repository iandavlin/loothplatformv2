<?php
/**
 * archive-poc/web/local.php — /local/ + /local/<slug>/ — Local Looths chapters.
 *
 * The Bento chapter page (Buck's design-lab winner, 2026-07-22): a member's home
 * for their regional group — identity, events, live discussion, local deals, and
 * a jump into the member map. Replaces the dead front-page Local Looths links
 * that 301'd to /hub/ after the BuddyPress /groups/ retirement.
 *
 * Data sources, all read-only, all guarded (a missing source degrades to an
 * honest empty state — never a fatal, never fake data):
 *   - Chapter roster + member counts: WP MySQL wp_bp_groups_members via the
 *     events app's read-only secret (/etc/lg-events-db). Falls back to the
 *     static snapshot below if the secret is unreadable from this pool.
 *   - Recent discussion: forums schema (same PG database as discovery).
 *   - Upcoming events: discovery.content_item (cpt=event), rendered in ET —
 *     matches the calendar.php timezone convention.
 *   - Local deals: LG_LOCAL_DEALS below — config-curated per chapter until a
 *     real deals model exists. Ships EMPTY on purpose: no invented coupons.
 *
 * Theme: light + dark following the member's Loothgroup setting with a system
 * fallback — the exact archive.css rule (html[data-lguser-theme="dark"] wins;
 * prefers-color-scheme applies only while no theme attr is stamped).
 *
 * NOTE (dmv-native lane): groups/chapters as a NATIVE feature (join flows,
 * who-can-post) is Ian's dmv-native lane. This page is the read-side front-end
 * on existing data; when chapters land natively, the join button + composer
 * here are the wire-up points (marked TODO(dmv-native) below).
 */
declare(strict_types=1);
require __DIR__ . '/_page-shell.php';
[$is_member, $tier] = lg_page_boot();

/* ---------- chapter roster (BP group ids are stable; counts refresh live) ---------- */
const LG_CHAPTERS = [
    'tri-state-looths-nyc'    => ['bp' => 38, 'name' => 'Tri State Looths (NYC)',  'region' => 'NYC · NJ · CT',                'snap' => 830],
    'socal-looths'            => ['bp' => 39, 'name' => 'SoCal Looths',            'region' => 'San Diego → Santa Barbara',    'snap' => 828],
    'sw-ontario-looths'       => ['bp' => 40, 'name' => 'SW Ontario Looths',       'region' => 'Southwestern Ontario',         'snap' => 340],
    'dmv-looths'              => ['bp' => 41, 'name' => 'DMV Looths',              'region' => 'DC · Maryland · Virginia',     'snap' => 342],
    'looth-troop-pnw'         => ['bp' => 42, 'name' => 'Looth Troop PNW',         'region' => 'Pacific Northwest',            'snap' => 343],
    'looths-of-ireland'       => ['bp' => 43, 'name' => 'Looths of Ireland',       'region' => 'Ireland',                      'snap' => 10],
    'middle-tennessee-looths' => ['bp' => 45, 'name' => 'Middle Tennessee Looths', 'region' => 'Middle Tennessee',             'snap' => 337],
    'basque-country-looths'   => ['bp' => 46, 'name' => 'Basque Country Looths',   'region' => 'Basque Country',               'snap' => 326],
    'ohio-local-looths'       => ['bp' => 47, 'name' => 'Ohio Local Looths',       'region' => 'Ohio',                         'snap' => 11],
];

/* Config-curated local deals per chapter slug. Ships empty: the card renders an
 * honest "no deals yet" state until chapter leads supply real ones (or a deals
 * model lands). Shape: ['biz' => ..., 'offer' => ..., 'fine' => ..., 'code' => ...] */
const LG_LOCAL_DEALS = [];

/* ---------- resolve which page we are ---------- */
$path = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';
$slug = '';
if (preg_match('~^/local/([a-z0-9][a-z0-9\-]*[a-z0-9])/?$~', $path, $m)) $slug = $m[1];
$chapter = $slug !== '' ? (LG_CHAPTERS[$slug] ?? null) : null;
if ($slug !== '' && $chapter === null) { header('Location: /local/', true, 302); exit; }

/* ---------- live member counts (WP MySQL, guarded) ---------- */
function lg_local_counts(): array {
    static $counts = null;
    if ($counts !== null) return $counts;
    $counts = [];
    try {
        $raw = @file_get_contents('/etc/lg-events-db');
        if ($raw === false) return $counts;
        $c = ['DB_HOST' => 'localhost', 'DB_NAME' => '', 'DB_USER' => '', 'DB_PASSWORD' => ''];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (preg_match('/^\s*([A-Z_]+)\s*=\s*(.*)\s*$/', $line, $m)) $c[$m[1]] = trim($m[2], " '\"");
        }
        $pdo = new PDO(
            "mysql:host={$c['DB_HOST']};dbname={$c['DB_NAME']};charset=utf8mb4",
            $c['DB_USER'], $c['DB_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
        );
        $ids = implode(',', array_map(fn($ch) => (int)$ch['bp'], LG_CHAPTERS));
        $q = $pdo->query("SELECT group_id, COUNT(*) n FROM wp_bp_groups_members
                          WHERE group_id IN ($ids) AND is_confirmed = 1 GROUP BY group_id");
        foreach ($q as $r) $counts[(int)$r['group_id']] = (int)$r['n'];
    } catch (Throwable $e) {
        error_log('[local] member-count read skipped: ' . $e->getMessage());
    }
    return $counts;
}
function lg_local_members(array $ch): int {
    $live = lg_local_counts();
    return $live[$ch['bp']] ?? (int)$ch['snap'];
}

/* ---------- recent chapter discussion (forums schema, guarded) ---------- */
function lg_local_topics(string $slug, int $limit = 4): array {
    try {
        $pdo = lg_archive_poc_pdo();
        $st = $pdo->prepare("SELECT t.title, t.author_slug, t.created_at
                               FROM forums.topic t JOIN forums.forum f ON f.id = t.forum_id
                              WHERE f.slug = :s ORDER BY t.created_at DESC LIMIT $limit");
        $st->execute([':s' => $slug]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[local] forum read skipped: ' . $e->getMessage());
        return [];
    }
}

/* ---------- upcoming events (discovery, ET — calendar.php convention) ---------- */
function lg_local_events(int $limit = 3): array {
    try {
        $pdo = lg_archive_poc_pdo();
        $now = time();
        $startE = lg_ts_epoch($pdo, 'event_start_at');
        $st = $pdo->prepare("SELECT title, url, " . lg_ts_sel($pdo, 'event_start_at', 'event_start_at') . "
                               FROM content_item
                              WHERE cpt = 'event' AND event_start_at IS NOT NULL AND $startE >= :now
                              ORDER BY event_start_at ASC LIMIT $limit");
        $st->execute([':now' => $now]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[local] events read skipped: ' . $e->getMessage());
        return [];
    }
}

/* Patreon-import placeholder handles ("patreon_70362340") still live in the
 * forums mirror until the nicename backfill lands (username-mentions lane).
 * Show an honest generic byline instead of the junk — real handles appear
 * automatically once the mirror re-syncs. */
function lg_local_is_junk_slug(string $s): bool {
    return (bool) preg_match('/^patreon[_-]?\d+$/i', $s);
}
function lg_local_byline(string $slug): string {
    return lg_local_is_junk_slug($slug) ? 'Looth member' : '@' . $slug;
}
function lg_local_initials(string $s): string {
    if (lg_local_is_junk_slug($s)) return 'LG';
    $parts = preg_split('/[\s\-]+/', trim(str_replace('-', ' ', $s))) ?: [];
    $a = strtoupper(substr($parts[0] ?? 'L', 0, 1));
    $b = strtoupper(substr($parts[1] ?? ($parts[0] ?? 'L'), 0, 1));
    return $a . $b;
}
function lg_local_ago(string $ts): string {
    $t = strtotime($ts); if (!$t) return '';
    $d = time() - $t;
    if ($d < 3600)       return max(1, (int)($d / 60)) . 'm ago';
    if ($d < 86400)      return (int)($d / 3600) . 'h ago';
    if ($d < 86400 * 30) return (int)($d / 86400) . 'd ago';
    return date('M j, Y', $t);
}

/* ============================ styling — Bento, light + dark ============================ */
$css = <<<'CSS'
/* Bento tokens — light is the default; dark follows the archive.css rule:
   1. html[data-lguser-theme="dark"]  — the member picked Dark in app settings
   2. OS prefers-color-scheme: dark, ONLY while no theme attr is stamped */
body.view-local{
  --bn-bg:var(--lg-cream,#fbfbf8); --bn-tile:#fff; --bn-line:var(--lg-line,#e3ddd0);
  --bn-ink:#2b2e26; --bn-head:#2b2e26; --bn-mute:var(--lg-mute,#6b6f6b);
  --bn-k:var(--lg-sage-d,#6b7c52); --bn-stat:var(--lg-sage-d,#6b7c52);
  --bn-id-bg:linear-gradient(120deg,#5f7048,#87986a 70%,#a0b183); --bn-id-sub:#e3ead4;
  --bn-chipbg:rgba(255,255,255,.16);
  --bn-evbg:var(--lg-sage-tint,#eef2e3); --bn-d-bg:var(--lg-sage-d,#6b7c52); --bn-d-ink:#fff;
  --bn-btn:#252a21; --bn-btn-ink:#fff;
  --bn-deal:#fbf0d8; --bn-deal-b:#eddab0; --bn-deal-h:#5a4415; --bn-deal-p:#a98544;
  --bn-bub:var(--lg-sage-tint,#eef2e3);
  background:var(--bn-bg);
}
html[data-lguser-theme="dark"] body.view-local{
  --bn-bg:#121417; --bn-tile:#1a1e22; --bn-line:#272c31;
  --bn-ink:#d8dbd4; --bn-head:#e8eae2; --bn-mute:#8d9389;
  --bn-k:#9cb37d; --bn-stat:#9cb37d;
  --bn-id-bg:linear-gradient(120deg,#232a1c,#31402a 70%,#3d5030); --bn-id-sub:#b7c4a5;
  --bn-chipbg:rgba(255,255,255,.1);
  --bn-evbg:#22262b; --bn-d-bg:#31402a; --bn-d-ink:#c8dba9;
  --bn-btn:#9cb37d; --bn-btn-ink:#15171a;
  --bn-deal:#26221a; --bn-deal-b:#4a4028; --bn-deal-h:#f2d795; --bn-deal-p:#a99668;
  --bn-bub:#22262b;
}
@media (prefers-color-scheme: dark){
  html:not([data-lguser-theme]) body.view-local{
    --bn-bg:#121417; --bn-tile:#1a1e22; --bn-line:#272c31;
    --bn-ink:#d8dbd4; --bn-head:#e8eae2; --bn-mute:#8d9389;
    --bn-k:#9cb37d; --bn-stat:#9cb37d;
    --bn-id-bg:linear-gradient(120deg,#232a1c,#31402a 70%,#3d5030); --bn-id-sub:#b7c4a5;
    --bn-chipbg:rgba(255,255,255,.1);
    --bn-evbg:#22262b; --bn-d-bg:#31402a; --bn-d-ink:#c8dba9;
    --bn-btn:#9cb37d; --bn-btn-ink:#15171a;
    --bn-deal:#26221a; --bn-deal-b:#4a4028; --bn-deal-h:#f2d795; --bn-deal-p:#a99668;
    --bn-bub:#22262b;
  }
}
body.view-local{color:var(--bn-ink)}
.bn-grid{display:grid;gap:12px;grid-template-columns:1fr 1fr;max-width:1240px;margin:8px auto 0}
.bn{border-radius:20px;padding:16px;background:var(--bn-tile);border:1px solid var(--bn-line);overflow:hidden;position:relative;min-width:0}
.bn-k{font:800 9.5px var(--lg-font-sans,sans-serif);letter-spacing:.14em;text-transform:uppercase;color:var(--bn-mute);display:flex;align-items:center;gap:6px;margin-bottom:8px}
.bn-k svg{width:12px;height:12px;fill:none;stroke:var(--bn-k);stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0}
.bn--id{grid-column:1/-1;background:var(--bn-id-bg);border:0;color:#fff;padding:22px 18px}
.bn--id .bn-k{color:rgba(255,255,255,.85)}
.bn--id .bn-k svg{stroke:#fff}
.bn--id h1{margin:0 0 4px;font:800 28px/1 var(--lg-font-serif,Georgia,serif);letter-spacing:-.01em;color:#fff}
.bn--id .bn-sub{font-size:12px;color:var(--bn-id-sub);font-weight:600}
.bn--id .bn-row{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
.bn--id .bn-chip{background:var(--bn-chipbg);border-radius:999px;padding:5px 12px;font-size:11px;font-weight:700;color:#fff;text-decoration:none}
.bn--stat b{display:block;font:800 32px/1 var(--lg-font-serif,Georgia,serif);color:var(--bn-stat)}
.bn--stat span{font-size:10.5px;font-weight:800;letter-spacing:.08em;color:var(--bn-mute)}
.bn--wide{grid-column:1/-1}
.bn-ev{display:flex;align-items:center;gap:11px;padding:10px;border-radius:14px;background:var(--bn-evbg);text-decoration:none;color:inherit}
.bn-ev+.bn-ev{margin-top:8px}
.bn-ev .bn-d{width:42px;height:42px;border-radius:12px;background:var(--bn-d-bg);color:var(--bn-d-ink);display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0}
.bn-ev .bn-d b{font:800 16px var(--lg-font-serif,Georgia,serif);line-height:1}
.bn-ev .bn-d span{font-size:8px;font-weight:800;letter-spacing:.1em}
.bn-ev h4{margin:0;font-size:13px;font-weight:700;color:var(--bn-head)}
.bn-ev .bn-m{font-size:11px;color:var(--bn-mute);font-weight:600}
.bn-go{margin-left:auto;background:var(--bn-btn);color:var(--bn-btn-ink);padding:7px 13px;font-size:11px;font-weight:800;border-radius:999px;text-decoration:none;flex-shrink:0}
.bn-topic{display:flex;gap:9px;align-items:flex-start;text-decoration:none;color:inherit}
.bn-topic+.bn-topic{margin-top:11px}
.bn-avi{width:32px;height:32px;border-radius:50%;background:var(--bn-d-bg);color:var(--bn-d-ink);display:flex;align-items:center;justify-content:center;font:700 11px var(--lg-font-sans,sans-serif);flex-shrink:0}
.bn-bub{background:var(--bn-bub);border-radius:13px;padding:8px 12px;min-width:0}
.bn-bub .bn-who{font-size:10.5px;font-weight:700;color:var(--bn-mute);margin-bottom:1px}
.bn-bub .bn-t{font-size:13px;font-weight:600;color:var(--bn-head);line-height:1.35}
.bn-cta{display:inline-flex;align-items:center;gap:7px;margin-top:13px;background:var(--bn-btn);color:var(--bn-btn-ink);border-radius:999px;padding:9px 16px;font-size:12px;font-weight:800;text-decoration:none}
.bn-cta svg{width:13px;height:13px;fill:none;stroke:currentColor;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round}
.bn--deal{background:var(--bn-deal);border-color:var(--bn-deal-b)}
.bn--deal h5{margin:0;font:800 15px var(--lg-font-serif,Georgia,serif);color:var(--bn-deal-h)}
.bn--deal p{margin:2px 0 0;font-size:11.5px;font-weight:600;color:var(--bn-deal-p)}
.bn-empty{font-size:12.5px;color:var(--bn-mute);line-height:1.55}
.bn--mem .bn-avrow{display:flex;margin-top:4px}
.bn--mem .bn-avi{margin-right:-9px;border:2.5px solid var(--bn-tile)}
.bn--mem p{font-size:11.5px;font-weight:700;color:var(--bn-mute);margin:12px 0 0}
/* chapter picker (/local/) */
.bn-pick{display:block;text-decoration:none;color:inherit;transition:transform .12s}
.bn-pick:hover{transform:translateY(-2px)}
.bn-pick h3{margin:0 0 2px;font:800 16px var(--lg-font-serif,Georgia,serif);color:var(--bn-head)}
.bn-pick .bn-m{font-size:11.5px;color:var(--bn-mute);font-weight:600}
.bn-pick .bn-n{font:800 22px var(--lg-font-serif,Georgia,serif);color:var(--bn-stat);margin-top:10px}
.bn-pick .bn-n span{font:700 10px var(--lg-font-sans,sans-serif);letter-spacing:.08em;color:var(--bn-mute);display:block}
@media (min-width:900px){
  .bn-grid{grid-template-columns:repeat(4,1fr)}
  .bn--id{grid-column:span 2;grid-row:span 2}
  .bn--id h1{font-size:38px}
  .bn--ev4{grid-column:span 2;grid-row:span 2}
  .bn--chat4{grid-column:span 2;grid-row:span 2}
  .bn-pick{grid-column:span 1 !important}
}
CSS;

/* ============================ render ============================ */
$icons = [
    'pin'   => '<svg viewBox="0 0 24 24"><path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
    'cal'   => '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    'chat'  => '<svg viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 0 1-8.5 8.5c-1.6 0-3.1-.4-4.4-1.2L3 20l1.2-5.1A8.38 8.38 0 0 1 12.5 3 8.38 8.38 0 0 1 21 11.5z"/></svg>',
    'tag'   => '<svg viewBox="0 0 24 24"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.2"/></svg>',
    'users' => '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/></svg>',
    'arrow' => '<svg viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>',
];

if ($chapter === null) {
    /* ---------------- /local/ — pick your chapter ---------------- */
    lg_page_open($is_member, 'Local Looths', 'Find your regional Looth Group chapter — events, discussion and local deals near you.', 'view-content view-local', '', $css);
    ?>
<h1>Local Looths</h1>
<p class="lg-page-sub">Nine regional chapters. Find yours — meetups, local discussion, and deals near you.</p>
<div class="bn-grid">
<?php foreach (LG_CHAPTERS as $s => $ch): ?>
  <a class="bn bn-pick" href="/local/<?= h($s) ?>/">
    <h3><?= h($ch['name']) ?></h3>
    <div class="bn-m"><?= h($ch['region']) ?></div>
    <div class="bn-n"><?= number_format(lg_local_members($ch)) ?><span>MEMBERS</span></div>
  </a>
<?php endforeach; ?>
</div>
<?php
    lg_page_close();
    exit;
}

/* ---------------- /local/<slug>/ — the Bento chapter page ---------------- */
$members = lg_local_members($chapter);
$topics  = $is_member ? lg_local_topics($slug) : [];   // topic titles are members-only
$events  = lg_local_events();
$deals   = LG_LOCAL_DEALS[$slug] ?? [];
$hubUrl  = '/hub/?forum=' . rawurlencode($slug);
$tz      = new DateTimeZone('America/New_York');

lg_page_open($is_member, $chapter['name'] . ' — Local Looths', 'The ' . $chapter['name'] . ' chapter — events, discussion and local deals.', 'view-content view-local', '', $css);
?>
<div class="bn-grid">
  <div class="bn bn--id">
    <div class="bn-k"><?= $icons['pin'] ?>YOUR LOCAL CHAPTER</div>
    <h1><?= h($chapter['name']) ?></h1>
    <div class="bn-sub"><?= h($chapter['region']) ?></div>
    <div class="bn-row">
      <a class="bn-chip" href="<?= h($hubUrl) ?>">Chapter discussion</a>
      <a class="bn-chip" href="/directory/members/">Member map</a>
      <a class="bn-chip" href="/local/">All chapters</a>
      <!-- TODO(dmv-native): native Join / membership state lands with Ian's chapters lane -->
    </div>
  </div>
  <div class="bn bn--stat"><b><?= number_format($members) ?></b><span>MEMBERS</span></div>
  <div class="bn bn--stat"><b><?= count($events) ?></b><span>UPCOMING EVENTS</span></div>

  <div class="bn bn--wide bn--ev4">
    <div class="bn-k"><?= $icons['cal'] ?>UPCOMING EVENTS</div>
<?php if ($events): foreach ($events as $e):
        $when = (new DateTimeImmutable('@' . (int)$e['event_start_at']))->setTimezone($tz); ?>
    <a class="bn-ev" href="<?= h((string)($e['url'] ?: '/events/')) ?>">
      <div class="bn-d"><b><?= h($when->format('j')) ?></b><span><?= h(strtoupper($when->format('M'))) ?></span></div>
      <div><h4><?= h((string)$e['title']) ?></h4>
        <div class="bn-m"><?= h($when->format('D · g:i A') . ' ET') ?></div></div>
      <span class="bn-go">Details</span>
    </a>
<?php endforeach; else: ?>
    <p class="bn-empty">No events on the calendar right now. Community events land on the
      <a href="/events/">events page</a> — chapter meetups will show here as they're scheduled.</p>
    <a class="bn-cta" href="/events/">Browse all events <?= $icons['arrow'] ?></a>
<?php endif; ?>
  </div>

  <div class="bn bn--wide bn--chat4">
    <div class="bn-k"><?= $icons['chat'] ?>CHAPTER DISCUSSION</div>
<?php /* Members-only: this card reads forums.topic directly, which would bypass
         the Hub's visibility enforcement — so the card itself gates on the
         viewer being signed in. Anonymous visitors get the pitch, not titles. */
      if (!$is_member): ?>
    <p class="bn-empty">Chapter members are planning meetups and trading local knowledge in
      here. Sign in to join the conversation.</p>
<?php elseif ($topics): foreach ($topics as $t): ?>
    <a class="bn-topic" href="<?= h($hubUrl) ?>">
      <div class="bn-avi"><?= h(lg_local_initials((string)$t['author_slug'])) ?></div>
      <div class="bn-bub">
        <div class="bn-who"><?= h(lg_local_byline((string)$t['author_slug'])) ?> · <?= h(lg_local_ago((string)$t['created_at'])) ?></div>
        <div class="bn-t"><?= h((string)$t['title']) ?></div>
      </div>
    </a>
<?php endforeach; else: ?>
    <p class="bn-empty">The <?= h($chapter['name']) ?> board is quiet — be the first to post.</p>
<?php endif; ?>
    <!-- TODO(dmv-native): inline composer when native chapter posting lands; until
         then the Hub forum is the single write path. -->
    <a class="bn-cta" href="<?= h($hubUrl) ?>">Open in the Hub <?= $icons['arrow'] ?></a>
  </div>

<?php if ($deals): foreach ($deals as $d): ?>
  <div class="bn bn--deal">
    <div class="bn-k"><?= $icons['tag'] ?>LOCAL DEAL</div>
    <h5><?= h((string)$d['biz']) ?></h5>
    <p><?= h((string)($d['offer'] ?? '')) ?><?= !empty($d['fine']) ? ' · ' . h((string)$d['fine']) : '' ?></p>
  </div>
<?php endforeach; else: ?>
  <div class="bn bn--deal">
    <div class="bn-k"><?= $icons['tag'] ?>LOCAL DEALS</div>
    <p class="bn-empty" style="color:var(--bn-deal-p)">No chapter deals posted yet. Know a local
      supplier who'd offer members a discount? Post it on the chapter board.</p>
  </div>
<?php endif; ?>

  <div class="bn bn--mem">
    <div class="bn-k"><?= $icons['users'] ?>MEMBERS NEAR YOU</div>
    <div class="bn-avrow">
<?php $shown = 0;
      foreach ($topics as $t) { if ($shown >= 4) break;
          echo '<div class="bn-avi">' . h(lg_local_initials((string)$t['author_slug'])) . '</div>'; $shown++; }
      if ($shown === 0) echo '<div class="bn-avi">' . h(lg_local_initials($slug)) . '</div>'; ?>
    </div>
    <p><?= number_format($members) ?> members in this chapter</p>
    <a class="bn-cta" href="/directory/members/">Open the member map <?= $icons['arrow'] ?></a>
  </div>
</div>
<?php
lg_page_close();
