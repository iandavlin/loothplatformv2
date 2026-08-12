#!/usr/bin/env node
/**
 * notif-text-exec.js — EXECUTE each bell's label function and prove no storable
 * notification type renders as a bare actor name.
 *
 *   node tools/gates/lib/notif-text-exec.js <file> <fnName> <actorsFn> <escFn> <type,type,…>
 *
 * notif-bridge lane, 2026-08-08. The other half of gate 16.
 *
 * WHY THIS EXISTS SEPARATELY FROM THE `case '…':` SCAN. Presence of a case label is a
 * PROXY for having a sentence, and the proxy can pass while the goal fails — nothing
 * stops someone writing `case 'forum.x': return esc(who);`, which is character-for
 * -character the defect this gate was built for. "Assert the goal, not the thing next
 * to it." So this extracts the real function, runs it, and looks at what a member
 * would actually read.
 *
 * The extraction is deliberately dumb — find the function header, take to its closing
 * brace at the same indent — because a parser dependency is not worth it and a
 * mis-extraction fails LOUD (the Function constructor throws) rather than quietly
 * passing.
 *
 * Exit 0 = every type produced a sentence, 1 = at least one bare name, 2 = could not
 * extract or run (no verdict).
 */
'use strict';

const fs = require('fs');
const [, , file, fnName, actorsFn, escFn, typesCsv] = process.argv;
if (!file || !fnName || !typesCsv) {
  console.error('usage: notif-text-exec.js <file> <fnName> <actorsFn> <escFn> <types>');
  process.exit(2);
}

let src;
try { src = fs.readFileSync(file, 'utf8'); }
catch (e) { console.error('CANNOT RUN: ' + e.message); process.exit(2); }

const start = src.indexOf('function ' + fnName + '(');
if (start < 0) { console.error(`CANNOT RUN: ${fnName} not found in ${file}`); process.exit(2); }

// The header's own indentation tells us what its closing brace looks like.
const lineStart = src.lastIndexOf('\n', start) + 1;
const indent    = src.slice(lineStart, start);
const closer    = '\n' + indent + '}';
const end       = src.indexOf(closer, start);
if (end < 0) { console.error(`CANNOT RUN: no closing brace for ${fnName}`); process.exit(2); }
const fnSrc = src.slice(start, end + closer.length);

// Stubs for the two helpers the label function calls. Deliberately IDENTIFIABLE:
// the actor name is a fixed string, so "the whole output is just the name" is an
// exact comparison rather than a heuristic.
const NAME = 'Karl Borum';
const stubEsc    = (s) => String(s);
const stubActors = (n) => ((n.actor && n.actor.name) || 'Someone');

let fn;
try {
  fn = new Function(escFn, actorsFn, 'return (' + fnSrc + ')')(stubEsc, stubActors);
} catch (e) {
  console.error('CANNOT RUN: could not build ' + fnName + ': ' + e.message);
  process.exit(2);
}

let bad = 0;
for (const type of typesCsv.split(',').filter(Boolean)) {
  let out;
  try { out = fn({ type, actor: { name: NAME }, actor_count: 1 }); }
  catch (e) { console.log(`  THREW ${type}: ${e.message}`); bad++; continue; }

  const bare = (out === NAME || out === 'Someone' || String(out).trim() === '');
  if (bare) { bad++; console.log(`  BARE  ${type} -> ${JSON.stringify(out)}`); }
  else      { console.log(`  ok    ${type} -> ${JSON.stringify(out)}`); }
}

if (bad) {
  console.log(`  ${bad} type(s) render with no sentence — a member sees a name and nothing else`);
  process.exit(1);
}
process.exit(0);
