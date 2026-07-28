// COMPOSER-V2 PHASE 3 — THE EXIT TEST.
//
// The plan's stated gate: "desktop reply mint+bell e2e". This posts a REAL desktop
// reply through the REAL door (the §4e discussion modal's Reply CTA -> composer v2)
// on a throwaway topic, and leaves behind the ids a store-level check can assert on.
//
// It deliberately does NOT assert mint/bell from the page. A rendered mention proves
// the RENDER path resolved something; it cannot prove what was stored, and a
// sanitize-on-read surface will happily show you a mention that was never minted.
// The store assertions run in SQL against wp_posts (mint) and profile_app.notifications
// (bell) — see the runbook block at the foot of this file.
//
// Requires: EXIT_TOPIC_ID, EXIT_TOPIC_SLUG, EXIT_FORUM_SLUG, EXIT_MENTION (profile slug).
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const { installJsOverride } = require('./_helpers');

const ENV_PATH = process.env.WP_COOKIES_ENV || '/tmp/mentions-verify/wp-cookies.env';
const TOPIC_ID = process.env.EXIT_TOPIC_ID;
const TOPIC_SLUG = process.env.EXIT_TOPIC_SLUG;
const FORUM_SLUG = process.env.EXIT_FORUM_SLUG;
const MENTION = process.env.EXIT_MENTION || 'dan-erlewine';

function readEnv() {
  const env = {};
  for (const line of fs.readFileSync(ENV_PATH, 'utf8').split('\n')) {
    const i = line.indexOf('=');
    if (i > 0) env[line.slice(0, i).trim()] = line.slice(i + 1).trim();
  }
  return env;
}

test.describe('phase 3 exit — desktop reply mint + bell', () => {
  test.skip(({ isMobile }) => !!isMobile, 'desktop-webkit project only');
  test.skip(!TOPIC_ID, 'EXIT_TOPIC_ID not set');

  test('a desktop reply posts through composer v2 and announces itself', async ({ page, context }) => {
    const e = readEnv();
    await context.addCookies([
      { domain: '.dev2.loothgroup.com', name: 'loothdev_auth', value: e.GATE_VAL, path: '/', secure: true, httpOnly: true },
      { domain: 'dev2.loothgroup.com', name: e.LOGGED_IN_NAME, value: e.LOGGED_IN_VAL, path: '/', secure: true, httpOnly: true },
      { domain: 'dev2.loothgroup.com', name: e.AUTH_NAME, value: e.AUTH_VAL, path: '/', secure: true, httpOnly: true },
    ]);
    await installJsOverride(page);

    // The looth_id bounce first — /profile-api (mention-suggest) is 401 without it.
    await page.goto('/looth-auth/issue?return=' +
      encodeURIComponent('/hub/?topic=' + FORUM_SLUG + '%2F' + TOPIC_SLUG),
      { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#lg-dmodal', { timeout: 25_000 });

    // listen for the dmodal-refresh contract BEFORE posting
    await page.evaluate(() => {
      window.__lgPosted = [];
      document.addEventListener('lg:reply-posted', (ev) => window.__lgPosted.push(ev.detail));
    });

    // THE DOOR: the modal's injected reply CTA. A real click on a real visible control.
    const cta = page.locator('#lg-dmodal .lg-dmodal__opacts [data-frm-open]').first();
    await cta.waitFor({ state: 'visible', timeout: 20_000 });
    await cta.click();

    // composer v2, not frm
    await page.waitForSelector('#looth-comp-sheet.is-open', { timeout: 15_000 });
    expect(await page.evaluate(() => {
      const o = document.getElementById('frm-overlay'); return !o || o.hidden;
    })).toBe(true);

    const editor = page.locator('#looth-comp-sheet .ql-editor');
    await editor.waitFor({ timeout: 20_000 });
    await editor.click();

    const marker = 'p3exit-' + (process.env.EXIT_STAMP || 'x');
    await editor.pressSequentially(marker + ' hello ', { delay: 40 });

    // REAL mention pick through the shared .lg-mnt engine — typing a raw @slug would
    // prove nothing about the pipeline that G8 broke.
    //
    // MUST be a real mouse click, not element.click(). The panel picks on MOUSEDOWN
    // on desktop (forums.js:381) and explicitly SWALLOWS synthetic clicks (:380,
    // "swallow synthetic click"), so a JS .click() dispatched from page.evaluate
    // fires the one event the engine ignores. The first run of this spec did exactly
    // that, inserted nothing, posted "@dan-" as plain text, and still went green on
    // the lg:reply-posted assertion. Hence the insertion check below.
    await editor.pressSequentially('@' + MENTION.slice(0, 4), { delay: 90 });
    await page.waitForSelector('.lg-mnt .lg-mnt__i', { timeout: 15_000 });
    const rowIdx = await page.evaluate((slug) => {
      const rows = [...document.querySelectorAll('.lg-mnt .lg-mnt__i')];
      const i = rows.findIndex((r) => (r.textContent || '').toLowerCase().includes(slug.split('-')[0]));
      return i < 0 ? 0 : i;
    }, MENTION);
    const row = page.locator('.lg-mnt .lg-mnt__i').nth(rowIdx);
    const picked = (await row.textContent()).trim().slice(0, 60);
    await row.click();                       // REAL mouse events -> mousedown -> pick
    console.log('MENTION_ROW_PICKED=' + picked);

    // GATE: the mention must actually be IN the editor before we post. Without this
    // the spec will happily submit a plain-text "@dan-" and report success.
    await page.waitForFunction(() => {
      const ed = document.querySelector('#looth-comp-sheet .ql-editor');
      return !!ed && !!ed.querySelector('.bp-suggestions-mention, [data-lg-uuid], .lgmention, a[href*="mention"]');
    }, null, { timeout: 10_000 });
    const composed = await page.evaluate(() =>
      document.querySelector('#looth-comp-sheet .ql-editor').innerHTML);
    console.log('COMPOSED_HTML=' + composed.slice(0, 300));

    const post = page.locator('#looth-comp-sheet #lgc-post');
    await expect(post).toBeEnabled({ timeout: 10_000 });
    await post.click();

    // the composer closes on success
    await page.waitForSelector('#looth-comp-sheet.is-open', { state: 'detached', timeout: 25_000 })
      .catch(async () => {
        await page.waitForFunction(() =>
          !document.querySelector('#looth-comp-sheet.is-open'), null, { timeout: 15_000 });
      });

    // THE DMODAL REFRESH CONTRACT — frm used to be the only emitter (forums.js
    // :2888/:2923) and the dmodal listens (:4524). This is the assertion that a
    // grep-for-the-string spec could not make.
    const posted = await page.evaluate(() => window.__lgPosted || []);
    console.log('LG_REPLY_POSTED=' + JSON.stringify(posted));
    expect(posted.length).toBeGreaterThan(0);
    expect(String(posted[0].topicId)).toBe(String(TOPIC_ID));

    console.log('EXIT_MARKER=' + marker);
  });
});

/* STORE ASSERTIONS (run after this spec; they are the real gate):
 *
 *   MINT — the reply must carry the canonical uuid-anchored mention, in the STORE:
 *     SELECT post_content FROM wp_posts
 *      WHERE post_type='reply' AND post_parent=$TOPIC AND post_content LIKE '%p3exit-%';
 *     expect: <a class="bp-suggestions-mention" data-lg-uuid="…" href="{{mention_user_id_N}}">
 *
 *   BELL — profile_app.notifications must have gained a mention row for the target:
 *     SELECT type,target_kind,target_id FROM notifications
 *      WHERE user_uuid='<target uuid>' ORDER BY created_at DESC LIMIT 3;
 *
 * Both must hold WITHOUT the bbp_new_reply backstop having fired — reply.php owns
 * the write and raises lg_bb_mirror_reply_owned, so the hook stands down. If the
 * hook is what minted, the reply still looks right and the architecture is still
 * broken, which is exactly the distinction phase 3 exists to make.
 *
 * ── RESULT, keeper window 2026-07-27 20:33–20:36 UTC, serve carrying the
 *    _mention-ingest.php guard fix. Topic 72212. ──────────────────────────────
 *
 *   DESKTOP (this spec, real modal CTA -> composer v2 -> real mouse mention pick):
 *     reply 72215 stored:
 *       <p>p3exit-203347 hello
 *          <a class="bp-suggestions-mention" href="{{mention_user_id_8}}">@dan-erlewine</a></p>
 *     bell: forum.mention | reply | target 72212 | anchor 72215 | actor_count 1
 *
 *   MOBILE (same component, lrs pill -> composer, real TOUCH pick):
 *     reply 72216 stored: …href="{{mention_user_id_8}}">@dan-erlewine</a>
 *     bell: forum.mention | reply | target 72212 | anchor 72216 | actor_count 1
 *
 *   dan-erlewine notifications 2 -> 4; mention-type rows 0 -> 2. wp_user_id 8 is
 *   dan-erlewine, so both resolved the right identity.
 *
 *   BACKSTOP STOOD DOWN: exactly ONE bell per reply (actor_count 1, two rows for
 *   two replies). Had the bbp_new_reply hook also minted/rung, the double-fire
 *   would show here. Combined with the deletion gate — zero native reply-creates
 *   in the served JS — reply.php owned both writes.
 *
 * ── FRESH RED→GREEN, keeper window 2026-07-28 00:00–00:04 UTC (post-reboot bytes,
 *    signed grant). Same topic, same spec, same composer, ONE variable: whether the
 *    serve carried the _mention-ingest.php guard fix. ─────────────────────────────
 *
 *   Editor bytes were IDENTICAL on both runs (the pre-insert anchor still carries
 *   data-lg-uuid=6b3cd71f-… in both), so the difference is the server, not the client:
 *
 *   RED   — serve at baseline, guard admits data-lg-uuid: NO
 *     reply 72230 stored: …<a class="bp-suggestions-mention" href="/u/dan-erlewine"
 *                            rel="nofollow"><span>Dan Erlewine</span></a>
 *     MINTED=NO. kses stripped data-lg-uuid; no {{mention_user_id_N}} token.
 *     BELL: NONE. dan-erlewine total 4 / mention 2 — UNCHANGED.
 *
 *   GREEN — serve carrying the fix, guard admits data-lg-uuid: YES
 *     reply 72231 stored: …<a class="bp-suggestions-mention"
 *                            href="{{mention_user_id_8}}">@dan-erlewine</a>
 *     MINTED=YES.
 *     BELL: notifications id 576 | forum.mention | reply | target 72212 |
 *           anchor 72231 | actor_count 1 | 2026-07-28 00:02:48Z
 *     dan-erlewine total 4→5, mention 2→3.
 *
 *   The e2e leg passed on BOTH runs and lg:reply-posted fired {topicId:72212} both
 *   times — which is the point: a green spec proves the door, and ONLY the store
 *   query separates a working mention from a silently dropped one.
 *
 *   Backstop stood down again: exactly one new row, actor_count 1. The RED run
 *   raised NO notification at all (ids 573/574 predate it by ~3h and belong to
 *   other users), so nothing fired twice and nothing fired spuriously.
 *
 *   Serve restored to the sealed baseline and proven: HEAD fa67f02 / tree
 *   74b39757… / branch main / porcelain EMPTY / file md5 a53bada0… — all MATCH,
 *   restored with `git checkout HEAD -- <path>` (see OVERLAY-SERVE-DEPLOY.md on why
 *   the bare form is unsafe on a shared clone). Engines parked, pgrep -x = 0.
 */
