/* groups-design lane — minimal CDP driver (no deps).
 *
 * Node 20 has WebSocket behind a flag, so run with:
 *     node --experimental-websocket cdp.js <port> <url> <injectFile> <outPng> <width> <height> [cookieJSON]
 *
 * Read-only: navigates, injects a design mock at runtime, screenshots, exits.
 * It NEVER writes to the serve. The page is a real dev2 page; the mock paints over it,
 * which is the whole point — the header/fonts/colour tokens in the shot are genuine.
 */
const fs = require('fs');

const [, , portArg, url, injectFile, outPng, wArg, hArg, cookieFile] = process.argv;
const PORT = +portArg, W = +wArg, H = +hArg;
const MOBILE = W <= 480;

const http = require('http');
const get = (path) => new Promise((res, rej) => {
  http.get({ host: '127.0.0.1', port: PORT, path }, (r) => {
    let b = ''; r.on('data', (c) => (b += c)); r.on('end', () => res(JSON.parse(b)));
  }).on('error', rej);
});

(async () => {
  // Chrome can take a moment to open the debug port after launch.
  let targets, lastErr;
  for (let i = 0; i < 60; i++) {
    try { targets = await get('/json/list'); break; }
    catch (e) { lastErr = e; await new Promise((r) => setTimeout(r, 250)); }
  }
  if (!targets) throw new Error(`CDP port ${PORT} never came up: ${lastErr}`);

  const page = targets.find((t) => t.type === 'page');
  if (!page) throw new Error('no page target');

  const ws = new WebSocket(page.webSocketDebuggerUrl);
  let id = 0;
  const pending = new Map();
  const events = [];

  // If the injected mock navigates the page (e.g. it clicks a real <a href>), the target
  // is torn down, every pending CDP promise is orphaned, the event loop empties, and node
  // exits 0 having printed NOTHING — a hard failure that reads exactly like a skipped shot.
  // That cost a whole confusing debug round. Never again: fail loudly.
  let done = false;
  const die = (why) => {
    if (done) return;
    done = true;
    console.error('CDP FAIL: ' + why);
    process.exit(1);
  };
  ws.addEventListener('close', () => die('devtools socket closed before the screenshot — '
    + 'the page almost certainly navigated away mid-inject (a real <a href> got clicked?)'));
  ws.addEventListener('error', (e) => die('devtools socket error: ' + (e && e.message)));
  process.on('exit', (code) => {
    if (!done && code === 0) {
      // Belt and braces: an empty event loop must never masquerade as success.
      console.error('CDP FAIL: exited without taking a screenshot');
      process.exitCode = 1;
    }
  });

  const send = (method, params = {}) => new Promise((res, rej) => {
    const msgId = ++id;
    pending.set(msgId, { res, rej });
    ws.send(JSON.stringify({ id: msgId, method, params }));
  });

  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) {
      const { res, rej } = pending.get(m.id);
      pending.delete(m.id);
      m.error ? rej(new Error(m.method + ': ' + JSON.stringify(m.error))) : res(m.result);
    } else if (m.method) events.push(m.method);
  });

  await new Promise((r) => ws.addEventListener('open', r));

  await send('Page.enable');
  await send('Runtime.enable');
  await send('Network.enable');

  // Exact viewport — 390 (mobile) / 1280 (desktop), per the lane rules.
  await send('Emulation.setDeviceMetricsOverride', {
    width: W, height: H, deviceScaleFactor: 2, mobile: MOBILE,
  });
  if (MOBILE) {
    await send('Emulation.setUserAgentOverride', {
      userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 '
        + '(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
    });
    await send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
  }

  // Auth cookies (WP logged_in + the dev cookie-gate, if the caller minted any).
  if (cookieFile && fs.existsSync(cookieFile)) {
    const cookies = JSON.parse(fs.readFileSync(cookieFile, 'utf8'));
    if (cookies.length) await send('Network.setCookies', { cookies });
  }

  await send('Page.navigate', { url });

  // Wait for load, then let the site's own JS (header, feed, bottom-nav) settle.
  await new Promise((res) => {
    const t = setInterval(() => {
      if (events.includes('Page.loadEventFired')) { clearInterval(t); res(); }
    }, 100);
    setTimeout(() => { clearInterval(t); res(); }, 20000);
  });
  await new Promise((r) => setTimeout(r, 2500));

  // Paint the mock over the real page.
  const src = fs.readFileSync(injectFile, 'utf8');
  const out = await send('Runtime.evaluate', {
    expression: src, awaitPromise: true, returnByValue: true,
  });
  if (out.exceptionDetails) {
    throw new Error('INJECT THREW: ' + JSON.stringify(out.exceptionDetails.exception || out.exceptionDetails));
  }
  await new Promise((r) => setTimeout(r, 1200));  // fonts/layout settle

  // VIEWPORT ONLY — do not re-add captureBeyondViewport. A full-page capture of the Hub
  // feed (thousands of px tall, at dsf=2) produces a PNG whose base64 blows Node's
  // WebSocket decompressed-message cap: "Max decompressed message size exceeded", which
  // kills the socket. It is also the wrong shot: these are 390x844 / 1280x900 mocks of a
  // surface, not full-page captures.
  const shot = await send('Page.captureScreenshot', { format: 'png' });
  fs.writeFileSync(outPng, Buffer.from(shot.data, 'base64'));
  console.log('shot ' + outPng + '  (' + W + 'x' + H + ')  ' + (out.result && out.result.value ? out.result.value : ''));

  done = true;
  ws.close();
  process.exit(0);
})().catch((e) => { console.error('CDP FAIL: ' + e.message); process.exit(1); });
