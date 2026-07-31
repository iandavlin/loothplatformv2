#!/usr/bin/env python3
"""Build Ian's slug ruling-queue page from the dry-run plan. PII-free by construction:
display_names that ARE email addresses are redacted, and no email column is ever read."""
import csv, html, collections, sys

PLAN = '/home/ubuntu/lane-reports/slug-provision/plan-fixed.tsv'
OUT  = '/home/ubuntu/projects/footer-mockups/slug-rulings.html'

rows = list(csv.DictReader(open(PLAN), delimiter='\t'))
by = collections.defaultdict(list)
for r in rows:
    by[r['cat']].append(r)

def nm(r):
    # Raw display_name rows carry storage damage — a literal "&amp;" (see Slug::derive's
    # docblock). Escaping that again renders "&amp;" on the page, so decode first.
    n = html.unescape((r.get('name') or '').strip().strip('"'))
    return '<an email address>' if '@' in n else n

# same-display-name groups inside the collision bucket
coll = by.get('3-COLLISION-NEEDS-RULING', [])
g = collections.Counter(nm(r).lower() for r in coll)
paired = sum(v for v in g.values() if v > 1)
groups = sum(1 for v in g.values() if v > 1)
singles = sum(1 for v in g.values() if v == 1)

bare = by.get('7-HELD-CONTESTED-BARE', [])
dup  = by.get('8-HELD-DUPLICATE-NAME', [])
mail = by.get('0d-NAME-IS-AN-EMAIL', [])
noh  = by.get('0-NO-HONEST-SLUG', [])
shrt = by.get('0b-NAME-TOO-SHORT', [])
total = len(bare)+len(dup)+len(coll)+len(mail)+len(noh)+len(shrt)

def chips(items, f=nm, limit=None):
    xs = [f(r) for r in items]
    if limit: xs = xs[:limit]
    return ''.join(f'<span class=c>{html.escape(x)}</span>' for x in xs)

CARDS = [
 ("A", len(bare), "Contested bare first names",
  "You already ruled on these on 29 Jul: a first name that other members also carry goes to "
  "<b>nobody</b>. Nothing has changed — measured again today, all 41 are still contested "
  "(<b>matt</b> &times;20, <b>tom</b> &times;16, <b>dan</b> &times;14, <b>scott</b> &times;11).",
  "Your ruling stands and these members keep their <code>patreon_</code> URL indefinitely.",
  "Confirm it stands. The alternative — hand each to whoever got there first — is the "
  "allocation-by-import-accident you refused.",
  chips(bare)),
 ("B", paired + len(dup), "Two accounts, one name",
  f"{paired} members across {groups} name-groups clean to the same handle, plus {len(dup)} held "
  "outright. Typically a personal and a business address for what may be one person.",
  "Nothing. They stay as they are.",
  "<b>This is a merge question, not a slug question</b> — and it is the biggest single "
  "block. Resolve it in the dupe-merge lane and most of this queue disappears on its own.",
  chips([r for r in coll if g[nm(r).lower()] > 1], limit=24) + chips(dup)),
 ("C", singles, "Handle already taken",
  "Their name cleans to a handle another member already holds. A numeric suffix is ruled out, "
  "so there is nothing automatic left to try.",
  "They stay on the <code>patreon_</code> URL.",
  "Leave them until B is resolved — some of these unblock themselves when a duplicate merges.",
  chips([r for r in coll if g[nm(r).lower()] == 1])),
 ("D", len(mail), "Their name IS an email address",
  "Six members have an email address in the display-name field. We will not publish part of "
  "an email as a public URL.",
  "They stay on the <code>patreon_</code> URL — which is the safe outcome.",
  "Leave them. Fixing this means asking those six for a real name, which is a member-contact "
  "job, not a slug job.",
  ""),
 ("E", len(noh), "Names we will not latinize",
  "Korean, Chinese and Japanese names. Turning 祁磊 into <code>qi-lei</code> is not cleaning a "
  "name, it is renaming a person, and we do not do it silently.",
  "They stay on the <code>patreon_</code> URL.",
  "Leave them until members can pick their own handle. Guessing is the one option to rule out.",
  chips(noh)),
 ("F", len(shrt), "Too short for a handle",
  "The floor is 3 characters, and these names are under it.",
  "They stay on the <code>patreon_</code> URL.",
  "Lowering the floor to 2 would free three of them; one is a single letter and needs its own "
  "answer. Small, and entirely your call.",
  chips(shrt)),
]

cards = ''.join(f'''
<section class=card>
  <div class=hd><span class=k>{k}</span><h2>{html.escape(t)}</h2><span class=n>{n}<small>members</small></span></div>
  <p>{d}</p>
  <div class=rec><b>Recommendation.</b> {rec}</div>
  <p class=inact><b>If you say nothing:</b> {inact}</p>
  {f'<div class=chips>{ch}</div>' if ch else ''}
</section>''' for k, n, t, d, inact, rec, ch in CARDS)

open(OUT, 'w').write(f'''<!doctype html><html lang=en><meta charset=utf-8>
<meta name=viewport content="width=device-width,initial-scale=1">
<title>Profile URLs still on patreon_ — what needs your call</title>
<style>
*{{box-sizing:border-box}}
body{{margin:0;padding:28px 18px 80px;background:#12141a;color:#e6e8ee;
 font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}}
.w{{max-width:820px;margin:0 auto}}
h1{{font-size:26px;line-height:1.25;margin:0 0 6px}}
.sub{{color:#9aa3b2;margin:0 0 26px}}
.hero{{background:#1a1d26;border:1px solid #2a2f3d;border-left:4px solid #4ea1ff;
 border-radius:10px;padding:18px 20px;margin:0 0 14px}}
.hero p{{margin:0 0 10px}} .hero p:last-child{{margin:0}}
.big{{font-size:19px;font-weight:600}}
.card{{background:#171a22;border:1px solid #262b38;border-radius:10px;padding:18px 20px;margin:14px 0}}
.hd{{display:flex;align-items:center;gap:12px;margin:0 0 10px;flex-wrap:wrap}}
.hd h2{{font-size:18px;margin:0;flex:1;min-width:180px}}
.k{{background:#4ea1ff;color:#0d1017;font-weight:700;width:26px;height:26px;border-radius:6px;
 display:grid;place-items:center;font-size:14px;flex:none}}
.n{{font-size:26px;font-weight:700;color:#4ea1ff;white-space:nowrap}}
.n small{{display:block;font-size:11px;color:#7c8598;font-weight:400;text-align:right;letter-spacing:.04em}}
.card p{{margin:0 0 10px;color:#c3c9d6}}
.rec{{background:#16241c;border:1px solid #2c4636;border-radius:8px;padding:11px 13px;margin:0 0 10px}}
.rec b{{color:#6ede9a}}
.inact{{font-size:14px;color:#8b93a5!important}}
.chips{{display:flex;flex-wrap:wrap;gap:5px;margin-top:12px}}
.c{{background:#222735;border:1px solid #2f3646;border-radius:5px;padding:2px 8px;font-size:12.5px;color:#aeb6c6}}
code{{background:#222735;padding:1px 5px;border-radius:4px;font-size:.9em}}
.note{{margin-top:30px;padding-top:18px;border-top:1px solid #262b38;color:#8b93a5;font-size:14px}}
b{{color:#e6e8ee}}
</style>
<div class=w>
<h1>Profile URLs still showing <code>patreon_&lt;id&gt;</code></h1>
<p class=sub>Measured on live, 31 Jul 2026 &middot; read-only &middot; nothing was written</p>

<div class=hero>
<p class=big>You asked why Scott is still at <code>/u/patreon_188933584</code> when the
backfill was supposed to fix it. It did not miss him — <b>it held him back on purpose,
because you asked it to.</b></p>
<p>&ldquo;Scott&rdquo; cleans to the handle <code>scott</code>, and <b>eleven</b> members
are called Scott. On 29 Jul you ruled that a first name several members share goes to
nobody. He is one of the 41 that ruling caught.</p>
<p>146 URLs still look like this. 35 are archived or dead accounts that are not members at
all. That leaves <b>{total} real members</b> — and <b>none</b> of them can be fixed
automatically without overturning one of your own decisions. So this is not a bug list.
It is six decisions.</p>
</div>
{cards}
<div class=note>
<p><b>Separately, two real bugs are fixed and waiting behind a switch.</b>
New members will no longer get stuck this way: the code that mints the URL could not tell a
<code>patreon_</code> placeholder from a handle a member actually owns, so it never upgraded
one once the real name arrived. It does now.</p>
<p>The second is worth knowing about: re-running the backfill today <b>would have handed out
all 41 contested first names</b> — the tool was measuring &ldquo;is this name contested?&rdquo;
against only the rows in front of it, and after a successful run there is almost nothing left
to look contested against. It reported &ldquo;withholding 0&rdquo;. Fixed, and it now holds
all 41. <b>Nothing should be re-run on live until that fix is deployed.</b></p>
<p>No member names that are email addresses are shown on this page.</p>
</div>
</div>''')
print(f'wrote {OUT}  ({total} members across 6 decisions)')
