#!/usr/bin/env node
/**
 * editor-test.js — headless-chrome assertions for the editor pipeline mockup.
 *
 * Loads mockup/editor-pipeline.html, runs DOM assertions, reports pass/fail.
 * The mockup is the v2 editor framework in miniature; passing here means the
 * production wiring (Phase 4) just needs to replicate the framework, not
 * invent new bugs.
 *
 * Connects to headless Chrome via CDP on 127.0.0.1:9333 by default.
 * Start one with:
 *   sudo docker run -d --name lg-headless --rm --network host \
 *     zenika/alpine-chrome --no-sandbox \
 *     --remote-debugging-address=0.0.0.0 --remote-debugging-port=9333 \
 *     --disable-gpu --headless --hide-scrollbars about:blank
 *
 * Usage:
 *   bin/editor-test.js                       # all assertions
 *   bin/editor-test.js --case=wireMarkers    # one assertion
 *   bin/editor-test.js --cdp=host:port       # different CDP endpoint
 *
 * Exit code: 0 = all pass, 1 = any fail.
 */

const path = require('path');
const fs = require('fs');
const { spawn } = require('child_process');
const http = require('http');

const CDP_HOST = process.env.LG_CDP_HOST || '127.0.0.1';
const CDP_PORT = process.env.LG_CDP_PORT || 9333;
const HTTP_PORT = process.env.LG_HTTP_PORT || 8765;

let CDP;
try { CDP = require('chrome-remote-interface'); }
catch (e) {
  console.error("editor-test: missing dependency 'chrome-remote-interface'. Install with:");
  console.error("  npm install chrome-remote-interface");
  process.exit(2);
}

const MOCKUP_URL = `http://127.0.0.1:${HTTP_PORT}/mockup/editor-pipeline.html`;
const PROJECT_ROOT = path.resolve(__dirname, '..');
const SCREENSHOT_DIR = path.resolve(__dirname, '..', 'tests', 'output', 'editor-screenshots');
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

/* ── Assertions ────────────────────────────────────────────────── */
/* Each case is { name, run(client) → { pass, msg } } */

const CASES = [
  {
    name: 'wireMarkers-idempotence',
    description: 'Running wireMarkers twice does not double-bind handlers',
    async run(client, page, { Runtime }) {
      const { result } = await Runtime.evaluate({
        expression: `(() => {
          /* Idempotence: re-rendering the article + re-running wireMarkers
             should produce the same wiring state, the same number of pills,
             and no double-binding (each block has exactly one pill). */
          window.renderArticle();
          window.wireMarkers(document);
          const firstCount = new Map(window.wiringState).size;
          const firstPills = document.querySelectorAll('.lg-edit-pill').length;

          /* Re-render + re-wire (simulates the post-mutation re-hydration path). */
          window.renderArticle();
          window.wireMarkers(document);
          const secondCount = new Map(window.wiringState).size;
          const secondPills = document.querySelectorAll('.lg-edit-pill').length;

          /* Each editable block should have exactly one pill. */
          const blocks = document.querySelectorAll('.lg-editable-block');
          const doubledBlocks = [...blocks].filter(b => b.querySelectorAll(':scope > .lg-edit-pill').length > 1);

          return JSON.stringify({
            firstCount, secondCount,
            firstPills, secondPills,
            doubledBlocks: doubledBlocks.length,
          });
        })()`,
        returnByValue: true
      });
      const r = JSON.parse(result.value);
      const pass = r.firstCount === r.secondCount && r.firstPills === r.secondPills && r.doubledBlocks === 0;
      return { pass, msg: pass ? `${r.secondCount} markers, ${r.secondPills} pills stable across re-runs, no doubling` : `drift: count ${r.firstCount}→${r.secondCount}, pills ${r.firstPills}→${r.secondPills}, doubled ${r.doubledBlocks}` };
    }
  },

  {
    name: 'empty-embed-form-failsafe',
    description: 'Empty embed URL form has onsubmit="return false" failsafe',
    async run(client, page, { Runtime }) {
      const { result } = await Runtime.evaluate({
        expression: `(() => {
          const form = document.querySelector('[data-lg-embed-url-form]');
          if (!form) return JSON.stringify({ found: false });
          const onsubmit = form.getAttribute('onsubmit');
          return JSON.stringify({
            found: true,
            hasFailsafe: onsubmit === 'return false',
            attr: onsubmit,
          });
        })()`,
        returnByValue: true
      });
      const r = JSON.parse(result.value);
      if (!r.found) return { pass: false, msg: 'no empty embed form in mockup' };
      return { pass: r.hasFailsafe, msg: r.hasFailsafe ? 'failsafe present' : `expected onsubmit="return false", got: ${r.attr}` };
    }
  },

  {
    name: 'partial-fail-no-silent-submit',
    description: 'After partial JS-load failure, empty embed form still does not native-submit',
    async run(client, page, { Runtime, Network }) {
      let didNavigate = false;
      const navHandler = ({ frame }) => { if (frame.parentId === undefined) didNavigate = true; };

      const { Page } = client;
      Page.frameNavigated(navHandler);

      const { result } = await Runtime.evaluate({
        expression: `(async () => {
          /* Simulate partial fail */
          window.wiringMode = 'partial-fail';
          /* re-render the article */
          renderArticle();
          wireMarkers(document);

          /* Try submitting the empty form */
          const form = document.querySelector('[data-lg-embed-url-form]');
          if (!form) return JSON.stringify({ error: 'no form' });
          const input = form.querySelector('[data-lg-embed-url-input]');
          input.value = 'https://example.com/test';

          let submitted = false;
          let nativeSubmit = false;
          try {
            const ev = new Event('submit', { bubbles: true, cancelable: true });
            const result = form.dispatchEvent(ev);
            submitted = true;
            nativeSubmit = result;  /* if true, the event was NOT prevented */
          } catch (e) { return JSON.stringify({ error: String(e) }); }

          return JSON.stringify({
            submitted,
            nativeSubmit,
            url: location.href,
          });
        })()`,
        returnByValue: true,
        awaitPromise: true,
      });
      const r = JSON.parse(result.value);
      if (r.error) return { pass: false, msg: r.error };
      /* Native submit was prevented by the onsubmit="return false" failsafe even
         though wireMarkers didn't bind a JS handler. */
      const pass = !r.nativeSubmit && !didNavigate;
      return { pass, msg: pass ? 'failsafe prevented native submit' : `native submit fired: nativeSubmit=${r.nativeSubmit} didNavigate=${didNavigate}` };
    }
  },

  {
    name: 'pill-buttons-present',
    description: 'Every wired block has its declared pill buttons rendered',
    async run(client, page, { Runtime }) {
      const { result } = await Runtime.evaluate({
        expression: `(() => {
          /* Reset first */
          window.wiringMode = 'normal';
          renderArticle();
          wireMarkers(document);

          const pills = document.querySelectorAll('.lg-edit-pill');
          const counts = [...pills].map(p => p.querySelectorAll('button').length);
          return JSON.stringify({
            pillCount: pills.length,
            allHavePills: counts.every(c => c >= 2),
          });
        })()`,
        returnByValue: true
      });
      const r = JSON.parse(result.value);
      const pass = r.pillCount > 0 && r.allHavePills;
      return { pass, msg: pass ? `${r.pillCount} pills, all populated` : `pill count: ${r.pillCount}, all populated: ${r.allHavePills}` };
    }
  },

  {
    name: 'editable-props-bound',
    description: 'Blocks with editable props get contenteditable=true after wiring',
    async run(client, page, { Runtime }) {
      const { result } = await Runtime.evaluate({
        expression: `(() => {
          const editables = document.querySelectorAll('[data-lg-prop-editable]');
          const allTrue = [...editables].every(el => el.getAttribute('contenteditable') === 'true');
          return JSON.stringify({ count: editables.length, allTrue });
        })()`,
        returnByValue: true
      });
      const r = JSON.parse(result.value);
      const pass = r.count > 0 && r.allTrue;
      return { pass, msg: pass ? `${r.count} editable props, all bound` : `count: ${r.count}, all bound: ${r.allTrue}` };
    }
  },

  {
    name: 'patch-roundtrip',
    description: 'Empty embed submit triggers simulated patch + DOM splice',
    async run(client, page, { Runtime }) {
      const { result } = await Runtime.evaluate({
        expression: `(async () => {
          /* Reset */
          window.wiringMode = 'normal';
          renderArticle();
          wireMarkers(document);

          const form = document.querySelector('[data-lg-embed-url-form]');
          const input = form.querySelector('[data-lg-embed-url-input]');
          const initialLogEntries = document.querySelectorAll('#mut-log .entry').length;

          input.value = 'https://www.youtube.com/watch?v=jNQXAC9IVRw';
          form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

          /* Wait for the simulated 400ms server response + DOM splice */
          await new Promise(r => setTimeout(r, 700));

          const afterLogEntries = document.querySelectorAll('#mut-log .entry').length;
          const stillEmpty = !!document.querySelector('.mock-empty-embed');

          return JSON.stringify({
            initialLogEntries,
            afterLogEntries,
            gained: afterLogEntries - initialLogEntries,
            stillEmpty,
          });
        })()`,
        returnByValue: true,
        awaitPromise: true,
      });
      const r = JSON.parse(result.value);
      const pass = r.gained >= 3 && !r.stillEmpty;
      return { pass, msg: pass ? `roundtrip complete: ${r.gained} log entries, empty form replaced` : `gained=${r.gained} stillEmpty=${r.stillEmpty}` };
    }
  },
];

/* ── Runner ────────────────────────────────────────────────────── */

async function startHttpServer() {
  /* Tiny static server rooted at PROJECT_ROOT so headless Chrome can load
     mockup/ via http:// (file:// is blocked in --headless mode). */
  return new Promise((resolve, reject) => {
    const server = http.createServer((req, res) => {
      const safe = req.url.replace(/\?.*/, '').replace(/\.\./g, '');
      const file = path.join(PROJECT_ROOT, safe);
      fs.readFile(file, (err, data) => {
        if (err) { res.writeHead(404); res.end('not found'); return; }
        const ext = path.extname(file).toLowerCase();
        const types = { '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.json': 'application/json', '.png': 'image/png' };
        res.writeHead(200, { 'content-type': types[ext] || 'application/octet-stream' });
        res.end(data);
      });
    });
    server.listen(HTTP_PORT, '127.0.0.1', () => resolve(server));
    server.on('error', reject);
  });
}

async function run() {
  const args = parseArgs(process.argv.slice(2));
  const caseFilter = args.case;

  let server;
  try { server = await startHttpServer(); }
  catch (e) {
    console.error(`editor-test: cannot start http server on ${HTTP_PORT}: ${e.message}`);
    process.exit(2);
  }

  let client;
  try { client = await CDP({ host: CDP_HOST, port: CDP_PORT }); }
  catch (e) {
    console.error(`editor-test: cannot reach headless Chrome at ${CDP_HOST}:${CDP_PORT}`);
    console.error('  ' + e.message);
    console.error('See bin/editor-test.js header for how to start it.');
    server.close();
    process.exit(2);
  }

  const { Page, Runtime, Network } = client;
  await Network.enable();
  await Page.enable();
  await Runtime.enable();

  await Page.navigate({ url: MOCKUP_URL });
  await Page.loadEventFired();
  await new Promise(r => setTimeout(r, 500));   /* let init run */

  const cases = caseFilter ? CASES.filter(c => c.name === caseFilter) : CASES;
  if (!cases.length) {
    console.error(`editor-test: no case matches '${caseFilter}'`);
    console.error('  Available: ' + CASES.map(c => c.name).join(', '));
    await client.close();
    process.exit(2);
  }

  let passed = 0, failed = 0;
  for (const c of cases) {
    try {
      const r = await c.run(client, Page, { Runtime, Network });
      if (r.pass) {
        console.log(`  ✓ ${c.name}  — ${r.msg}`);
        passed++;
      } else {
        console.log(`  ✗ ${c.name}  — ${r.msg}`);
        failed++;
        try {
          const { data } = await Page.captureScreenshot({ format: 'png' });
          const f = path.join(SCREENSHOT_DIR, c.name + '.png');
          fs.writeFileSync(f, Buffer.from(data, 'base64'));
          console.log(`      screenshot: ${path.relative(process.cwd(), f)}`);
        } catch (e) { /* ignore screenshot failure */ }
      }
    } catch (e) {
      console.log(`  ✗ ${c.name}  — threw: ${e.message}`);
      failed++;
    }
  }

  await client.close();
  server.close();
  console.log(`\neditor-test: ${passed} passed, ${failed} failed`);
  process.exit(failed === 0 ? 0 : 1);
}

function parseArgs(argv) {
  const out = {};
  for (const a of argv) {
    const m = a.match(/^--([a-z-]+)=(.*)$/);
    if (m) out[m[1]] = m[2];
  }
  return out;
}

run().catch(e => { console.error(e); process.exit(1); });
