#!/usr/bin/env node
/**
 * Exercises the REAL postWithNonce()/refreshNonce() source out of game.js
 * against a stubbed network. Not a browser: a browser dependency would make the
 * gate flaky on a 2-core box, and a gate that goes DEAD blocks every lane.
 * Not a copy of the logic either -- it slices the shipped functions out of
 * game.js and evaluates those, so the harness cannot drift from what ships.
 *
 * Prints one JSON object per scenario. Exit 2 if the source could not be found,
 * which is a CANNOT RUN, not a pass.
 */
const fs = require('fs');
const vm = require('vm');

const GAME = process.argv[2];
const src = fs.readFileSync(GAME, 'utf8');

const START = src.indexOf('function refreshNonce()');
const END = src.indexOf("// Take today's allowance");
if (START < 0 || END < 0 || END <= START) {
    console.error('CANNOT-EXTRACT: refreshNonce/postWithNonce not found in ' + GAME);
    process.exit(2);
}
const block = src.slice(START, END);
if (!/function postWithNonce\(/.test(block)) {
    console.error('CANNOT-EXTRACT: postWithNonce not inside the sliced block');
    process.exit(2);
}

function run(scenario) {
    const calls = [];
    let nonceServed = 'FRESH-NONCE';
    const sandbox = {
        SCORE_API: '/archive-api/v0/guitardle-score',
        scoreAuth: { authenticated: true, nonce: 'STALE-NONCE' },
        retryEnabled: scenario.retryEnabled,
        todayString: () => '2026-08-15',
        console,
        fetch: (url, opts) => {
            const isPost = opts && opts.method === 'POST';
            calls.push({
                method: isPost ? 'POST' : 'GET',
                nonce: isPost ? opts.headers['X-WP-Nonce'] : null,
                body: isPost ? JSON.parse(opts.body) : null,
            });
            if (!isPost) {
                // the nonce re-fetch
                return Promise.resolve({
                    ok: scenario.refreshOk !== false,
                    json: () => Promise.resolve(
                        scenario.refreshOk === false ? {} : { nonce: nonceServed }),
                });
            }
            const n = calls.filter(c => c.method === 'POST').length;
            const status = scenario.postStatuses[n - 1];
            if (status === 'network-error') return Promise.reject(new Error('offline'));
            return Promise.resolve({ status, ok: status === 200 });
        },
    };
    vm.createContext(sandbox);
    vm.runInContext(block, sandbox);
    return sandbox.postWithNonce({ phrase_id: 42, won: true, moves: 6 })
        .then(() => ({ name: scenario.name, calls }))
        .catch(e => ({ name: scenario.name, threw: String(e), calls }));
}

const SCENARIOS = [
    { name: 'off_403_is_lost',        retryEnabled: false, postStatuses: [403] },
    { name: 'off_200_single_send',    retryEnabled: false, postStatuses: [200] },
    { name: 'on_403_then_retry_ok',   retryEnabled: true,  postStatuses: [403, 200] },
    { name: 'on_200_no_extra_work',   retryEnabled: true,  postStatuses: [200] },
    { name: 'on_403_twice_stops',     retryEnabled: true,  postStatuses: [403, 403] },
    { name: 'on_403_refresh_fails',   retryEnabled: true,  postStatuses: [403], refreshOk: false },
    { name: 'on_network_error',       retryEnabled: true,  postStatuses: ['network-error'] },
];

(async () => {
    const out = [];
    for (const s of SCENARIOS) out.push(await run(s));
    console.log(JSON.stringify(out, null, 1));
})();
