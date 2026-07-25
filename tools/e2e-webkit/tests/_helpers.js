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

// Tap the Reply action on the first topic card -> opens the lrs thread sheet with
// the lcp composer sheet auto-opened on top (the current Reply-intent flow).
async function openTopicComposer(page) {
  await page.goto('/hub/', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.feed-card[data-topic-id] .lg-act-replies', { timeout: 20_000 });
  const reply = page.locator('.feed-card[data-topic-id] .lg-act-replies').first();
  await reply.scrollIntoViewIfNeeded();
  await reply.tap();
  await page.waitForSelector('#looth-comp-sheet.is-open', { timeout: 10_000 });
}

// Type @mik into the composer input with real key events and wait for the panel.
async function typeMention(page, q = '@mik') {
  const input = page.locator('#lcp-input');
  await input.tap();
  await input.pressSequentially(q, { delay: 90 });
  await page.waitForSelector('.lg-mnt .lg-mnt__i', { timeout: 8_000 });
}

module.exports = { addAuthCookies, openTopicComposer, typeMention };
