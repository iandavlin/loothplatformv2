/* Self-test for contrast-probe.js's colour math. Run: node tools/gates/lib/contrast-probe-selftest.js
   WHY IT EXISTS: the probe's whole job is to produce a NUMBER that a gate then
   blocks a merge on. If the formula drifts, every assertion built on it turns
   into confident nonsense in whichever direction the drift went. The last two
   cases are the load-bearing ones — they are values a DIFFERENT lane computed by
   hand and wrote into bb-mirror/web/forums.css's comments, so reproducing them
   is independent confirmation rather than this file agreeing with itself. */
const fs = require('fs'), path = require('path');
const src = fs.readFileSync(path.join(__dirname, 'contrast-probe.js'), 'utf8');
const body = src.slice(src.indexOf('function parseColor'), src.indexOf('function desc('));
const { parseColor, ratio, over, hex } = new Function(body + '; return {parseColor,lum,ratio,over,hex};')();
const C = parseColor;
const cases = [
  ['rgb(0,0,0)',       'rgb(255,255,255)', 21.00, 'black on white'],
  ['rgb(255,255,255)', 'rgb(255,255,255)',  1.00, 'white on white'],
  ['rgb(118,118,118)', 'rgb(255,255,255)',  4.54, '#767676 on white — the AA boundary grey'],
  ['rgb(255,255,255)', 'rgb(21,23,26)',    17.96, 'white ink on the dark canvas #15171a'],
  ['rgb(135,152,106)', 'rgb(255,255,255)',  3.12, 'brand sage #87986a on white'],
  ['rgb(198,104,69)',  'rgb(21,23,26)',     4.68, 'brand rust #c66845 on #15171a (forums.css comment says 4.7)'],
  ['rgb(226,137,95)',  'rgb(21,23,26)',     6.82, 'lifted #e2895f on #15171a (forums.css comment says 6.8)'],
];
let bad = 0;
for (const [fg, bg, exp, label] of cases) {
  const got = ratio(C(fg), C(bg));
  const ok = Math.abs(got - exp) < 0.06;
  if (!ok) bad++;
  console.log((ok ? 'ok   ' : 'BAD  ') + got.toFixed(2) + '  expect ~' + exp.toFixed(2) + '   ' + label);
}
const comp = over(C('rgba(255,255,255,0.06)'), C('rgb(21,23,26)'));
if (hex(comp) !== '#232528') { bad++; console.log('BAD  alpha compositing: got ' + hex(comp) + ' expected #232528'); }
else console.log('ok   rgba(255,255,255,.06) over #15171a -> #232528 (not #ffffff)');
console.log(bad ? '\n' + bad + ' CASE(S) WRONG' : '\ncontrast math: all reference values reproduce');
process.exit(bad ? 1 : 0);
