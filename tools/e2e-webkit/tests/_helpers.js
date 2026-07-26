// Shared helpers: auth cookies from the chrome-dev-login env file + the
// open-a-topic-composer gesture used by every spec.
const fs = require('fs');

const ENV_PATH = process.env.WP_COOKIES_ENV || '/tmp/mentions-verify/wp-cookies.env';

function readEnv() {
  const env = {};
  for (const line of fs.readFileSync(ENV_PATH, 'utf8').split('\n')) {
    const i = line.indexOf('=');
    if (i > 0) env[line.slice(0, i).trim()] = line.slice(i + 1).trim();
  }
  return env;
}

async function addAuthCookies(context) {
  const env = readEnv();
  await context.addCookies([
    { domain: '.dev2.loothgroup.com', name: 'loothdev_auth', value: env.GATE_VAL, path: '/', secure: true, httpOnly: true },
    { domain: 'dev2.loothgroup.com', name: env.LOGGED_IN_NAME, value: env.LOGGED_IN_VAL, path: '/', secure: true, httpOnly: true },
    { domain: 'dev2.loothgroup.com', name: env.AUTH_NAME, value: env.AUTH_VAL, path: '/', secure: true, httpOnly: true },
  ]);
}

// PROVE-IT-GOES-RED harness (keeper 2026-07-26: "the honest bar is a spec that
// FAILS against the current code"). Point LGC_JS_OVERRIDE at any hub-polish.js —
// e.g. `git show 66da45f:webroot/hub-polish.js > /tmp/pre.js` — and every spec in
// the run executes against THOSE bytes instead of the served ones. A regression
// spec can then be shown red on the pre-fix build and green on the fix WITHOUT
// touching the docroot, which matters when the serve is an open Ian window.
async function installJsOverride(page) {
  const path = process.env.LGC_JS_OVERRIDE;
  if (!path) return;
  const body = fs.readFileSync(path, 'utf8');
  await page.route('**/hub-polish.js*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/javascript; charset=utf-8', body }));
}

// Tap the Reply action on the first topic card -> opens the lrs thread sheet with
// the lcp composer sheet auto-opened on top (the current Reply-intent flow).
async function openTopicComposer(page) {
  await installJsOverride(page);
  // First nav MUST be the looth_id bounce: /looth-auth/issue reads the WP cookie,
  // Set-Cookies the RS256 looth_id JWT and 302s to return= — without it every
  // /profile-api call (mention-suggest included) is 401 through the real edge.
  // (Loopback curls are exempt via the internal-IP check; the browser is not.)
  await page.goto('/looth-auth/issue?return=/hub/', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.feed-card[data-topic-id] .lg-act-replies', { timeout: 20_000 });
  const reply = page.locator('.feed-card[data-topic-id] .lg-act-replies').first();
  await reply.scrollIntoViewIfNeeded();
  await reply.tap();
  await page.waitForSelector('#looth-comp-sheet.is-open', { timeout: 10_000 });
}

// Type @mik into the composer editor with real key events and wait for the panel.
// COMPOSER-V2: the editor is the Quill contenteditable (.ql-editor), lazy-mounted
// after the sheet opens — wait for it before typing.
async function typeMention(page, q = '@mik') {
  await page.waitForSelector('#looth-comp-sheet .ql-editor', { timeout: 15_000 });
  const input = page.locator('#looth-comp-sheet .ql-editor');
  await input.tap();
  await input.pressSequentially(q, { delay: 90 });
  await page.waitForSelector('.lg-mnt .lg-mnt__i', { timeout: 8_000 });
}

// Editor text content (the composer is contenteditable now — no inputValue).
async function editorText(page) {
  return page.evaluate(() => {
    const e = document.querySelector('#looth-comp-sheet .ql-editor');
    return e ? e.textContent : '';
  });
}

module.exports = { addAuthCookies, openTopicComposer, typeMention, editorText, installJsOverride };
