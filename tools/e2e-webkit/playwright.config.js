// Real-WebKit regression harness for the mobile reply/composer stack.
//
// WHY THIS EXISTS (2026-07-24): three bugs shipped through a headless-Chromium
// harness that only reproduced on Ian's real iPhone — (1) backdrop taps silently
// dropped on a plain <div> (needed cursor:pointer), (2) the mention list collapsed
// to one row once the real keyboard compressed the visual viewport, (3) a
// touchstart-pick killed list scrolling. Chromium's emulation passes all three.
// Playwright WebKit runs the SAME engine family as iOS Safari, closing most of
// that gap. (It is still not iOS: no real on-screen keyboard, no Safari UI chrome,
// no PWA service-worker layer — Ian's phone remains the final gate. The GH-Actions
// macOS iOS-Simulator tier is the next rung when we want CI truth closer to device.)
//
// Auth: cookies come from /tmp/mentions-verify/wp-cookies.env (dev-gate + WP admin),
// the same env file the chrome-dev-login harness maintains. Traffic goes through the
// REAL public URL (Cloudflare -> this box), the same path a phone takes.
//
// RAM PROTOCOL (keeper-watch incident 2026-07-24 ~04:58): a WebKit run spawns ~2x
// WPEWebProcess (~660MB total) and took the 3.8GB box to 86MB avail with other lanes
// resident. Before running: (1) board-flag that a heavy browser suite is starting,
// (2) confirm NO other browser engine is up (pgrep -f 'chrome-linux64|WPEWebProcess|
// headless_shell'), (3) keep workers:1 (below), (4) suite must be the ONLY engine on
// the box until it exits. One engine at a time is box law, and Playwright engines
// count — not just lg-chrome.
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests',
  timeout: 60_000,
  retries: 0,
  workers: 1,            // dev2 has 3.8GB RAM; one browser at a time (box law)
  reporter: [['list'], ['json', { outputFile: 'results.json' }]],
  use: {
    baseURL: 'https://dev2.loothgroup.com',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'iphone-webkit',
      use: {
        ...devices['iPhone 13'],   // 390x844 dpr3, mobile, hasTouch, WebKit
        browserName: 'webkit',
      },
    },
    {
      name: 'desktop-webkit',
      use: {
        browserName: 'webkit',
        viewport: { width: 1280, height: 900 },
      },
    },
  ],
});
