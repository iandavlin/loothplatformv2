# SESSION HANDOFF — lane `frontend-compose`, **the layout-v2 lane**

**Written 2026-08-14. Assume you are a fresh lane with zero context: this is your
charter. Everything below was measured on this box — where something is unproven
it says so — see OPEN CHECK.**

| | |
|---|---|
| Branch | `frontend-compose`, pushed, tip **`e2c19e4`** |
| Base | rebased onto main earlier this session; **re-check, main moves fast** |
| State | **38 commits, NOTHING MERGED.** Working tree clean |
| Flags | `lg-layout-v2/config/{license,download,taxonomy}-block.php` — all three `'enabled' => false` |
| Merge | **NOT merge-ready.** One browser check is open (below) |
| Box | ⚠️ **root disk 100% full, Redis wedged** — see BOX FAULT below |
| Prior | `handoffs/2026-08-14-layout-v2-charter.md` — the audit that started this |

---

## CHARTER (Ian, 8/14: *"Yes — build all four"*)

1. **LICENCE block** — ✅ built. Four CC choices as choices.
2. **PRINT FILES** via the download block + file picker — ✅ built, and **smaller
   than the charter thought** (see the correction below).
3. **ITEMS EDITOR** for `callout` (CAD link + tip jar) — ✅ **ALREADY EXISTED.
   Nothing was built, and nothing needed to be.** See the correction below.
4. **WORK-TYPE taxonomy block** (Type of Loothprint + Content Topic) — ✅ built.
   The only one of the four that was genuinely missing.

All flags are OFF-default. Nothing member-facing has moved.

### Block 3 was already built — do not rebuild it

`lg-fe-editor.js` `doEdit()` has a hardcoded `type === 'callout'` branch: for
list variants (links/files/people/data) it opens `openItemsModal()`, a full
add/remove/reorder repeater over `items[]` that saves via REST. `callout`'s
render.php emits `<script data-lg-callout-state>` in editor mode to seed it, and
`MetaBox` has a matching `array_of_objects` repeater. **So the CAD link and the
tip jar have been editable in v2 all along.**

The audit called them gaps because it read `manifest.editor.inline_editable_props`
— and its own methodology note said *"read it there; do not infer from the
renderer."* **That instruction was the trap.** The manifest is not the whole
truth: `lg-fe-editor.js` carries per-type branches that no manifest declares. To
know whether a prop is editable you must check BOTH the manifest AND the
editor's per-type branches.

---

## ⚠️ THE CORRECTION THAT MATTERS MOST

**The previous handoff's premise for block 2 was wrong, and it was wrong in a way
that flattered the plan.** It came from `LIKE '%"type":"download"%'` against
`_lg_layout_v2`, which is **PHP-serialized, not JSON** — so the query matched
almost nothing and "the page does not use the download block" was an artifact of
the measurement. Re-measured with `wp eval`:

| | |
|---|---|
| loothprints total | **172** |
| with a STORED layout | **168** |
| no layout → SYNTHESIZED | **4** |
| stored layouts using `download` + `file_id` | **168 (all of them)** |
| stored layouts baking a download URL | **0** |
| file_ids that still match the form's current file | **168 — zero drift** |

**Consequences, both of which reshaped the work:**

* **The stale-ZIP defect is LATENT, not active.** It bites only when a member
  uploads a *new* file (new attachment id) while the stored layout keeps the old
  one. Nobody has yet. Fixed structurally anyway — see block 2 below.
* **Anything that only changes the SYNTHESIZER reaches 4 posts.** That was true
  of the whole licence block as first built. Fixed by the read-path upgrade below.

**Rule for the next lane: `_lg_layout_v2` is serialized. Read it with `wp eval`
and `get_post_meta`, never with a SQL `LIKE` for JSON.**

---

## WHAT IS BUILT

### Block 1 — `license` (commits `7057bbb`, `3afd0d7`, `580cd03`)

* `lg-layout-v2/blocks/license/` + `src/Licenses.php` (the model: 4 codes, their
  clauses, deed URLs, the ACF-choice recogniser) + CC glyphs in `src/Icons.php`.
* **`code: ""` means FOLLOW THE POST** — resolves `loothprint_creative_commons`
  live at render, the same pattern `post-header` uses for title/hero. So the form
  and the page cannot disagree. An explicit code deliberately pins it instead.
* **A post with no licence gets NO default invented.** Renders nothing.
* Real framework picker `license-choice` (`EditorPickers` + `lg-fe-editor.js`),
  choices shipped from `Licenses::picker_choices()` so the editor cannot offer a
  licence the renderer cannot draw. The licence is **deliberately not**
  inline-editable — retyping prose is the failure the block exists to end.
* **Read-path upgrade (`580cd03`) is what makes it reach anyone.** 164 stored
  loothprint layouts (+7 loothcuts) hold the licence as a prose `callout:note`.
  `Plugin::upgrade_license_callouts()` swaps those for the block **on read,
  writing nothing** — flag off or an unrecognised body returns the layout
  untouched. No migration to reverse, no half-migrated corpus.
* The recogniser is **strict**: the body must be an EXACT ACF choice string
  (`Licenses::from_exact_prose`). Verified against the WHOLE corpus — 707 stored
  layouts, 346 `callout:note` blocks, 171 replaced, 175 left alone, and nothing
  left alone is a licence **except one**: post **71142** has a hand-written CC
  sentence and keeps its prose on purpose. Widening the rule means matching by
  resemblance, which is how an author's surrounding paragraph gets deleted.
* All 164 stored bodies already agree with the form's current answer, so turning
  the flag on changes how the licence **looks**, never which licence a page states.
* ⚠️ **THREE POSTS PUBLISH A LICENCE NO READER CAN SEE.** 72155, 72146 and 71927
  have a licence set in the form and **no licence block of any kind** in their
  stored layout — the newest, hand-authored-in-v2 ones. The read-path swap
  cannot help them: there is nothing to swap. They need the block INSERTED,
  which is the same "insert vs swap" decision the taxonomy block is waiting on.
  (A fourth, 71142, does show a licence — hand-written prose the strict
  recogniser deliberately leaves alone.) **Ian's call, reported not taken.**

### Block 4 — `taxonomy` (commit `067f62b`)

* `blocks/taxonomy/` — Loothprint Type + Content Topic as chips linking to their
  term archives. **`lint-block: clean`, the only clean block in the tree.**
* Reads terms LIVE. **No term picker on purpose** — the form owns the details,
  and a second editor for one value is how they end up disagreeing.
* Measured before building: of 168 published loothprints, **125 carry both
  taxonomies, 29 type only, 3 topic only, 11 neither**. The 11 render nothing.
* ⚠️ **`config/taxonomy-block.php` reaches FOUR POSTS** — the synthesizer only.
  The 168 stored layouts would need the block INSERTED. The licence work could
  *swap* a block already present; this would *add* content to 157 pages that has
  never appeared on any of them. **Where the chips belong on the page is Ian's
  design call, with a picture** — deliberately not taken by this lane.

### Block 2 — print files (commit `e4fb146`)

* **`blocks/download/render.php`: empty `file_id` now means FOLLOW THE POST**
  (`loothprint_3d_file` / `loothcut_cnc_file`, resolved at render). This is the
  real fix and it is **not flagged**, because it is invisible until a file is
  replaced. Safe because `media_resolver` is `WpMedia::resolve`, a **live**
  callable — checked, not assumed.
* **The `file` picker** (any mime). `download` previously had empty
  `inline_editable_props` and a null picker, so "swap the print file" was
  unreachable from the page. Clearing the file is a real choice — empty =
  follow the post — and the UI says so. Setting a file clears any baked `url`,
  which would otherwise outrank `file_id` and make the swap look like a no-op.
* **Synthesizer switch behind `config/download-block.php`** — governs **4 posts**.
  A separate flag from the licence one on purpose, so Ian can accept one and not
  the other.

---

## ✅ IAN RULED: V2 MAY INSERT (ruling 7, 2026-08-15)

*"V2 MAY INSERT a missing block into a stored page … Scope guard: inserts only
SURFACE what the author already declared in the form, never invent content.
Red-first gate on the insert path."* — **implemented, commit `d7ac5db`.**

* `lg-layout-v2/src/LayoutUpgrade.php` — the read-path rules as PURE functions.
  Not in `Plugin` on purpose: Plugin.php cannot be required twice inside a WP
  process that already booted v2 (class redeclaration), so a gate for it could
  only ever have run inside a maintenance window with the branch symlinked over
  the serve. Pure + separate ⇒ **the gate runs any time, with main serving.**
  Copy this shape for the next rule anyone wants gated.
* **The strict/loose asymmetry is the safety.** Replacing demands certainty (an
  exact ACF choice); NOT inserting demands only suspicion
  (`Licenses::looks_like_licence`). Post 71142's hand-written CC prose must
  suppress the insert or that page states its licence twice.
* **Gate: `tools/gates/license-insert-gate.py`** — 7 assertions over all 721
  stored layouts. OFF identity; LIVENESS (ON changes 648, so OFF is not
  vacuous); never invent; never duplicate; surface only; position before
  post-footer; idempotent. **GREEN**, and all five substantive rules were
  RED-FIRSTED (broken one at a time, each confirmed to turn it red, source
  snapshotted and restored byte-identical).
* ⚠️ **NO NUMBER YET — not registered in `run-all.sh`.** 35 is this lane's
  compose gate. Keeper mints the next one; never mint your own.

---

## ⬜ ONE OPEN CHECK (was two — the second is now closed)

Both exist for the same reason: **the branch is not on the dev2 serve**, because
`/var/www/dev/wp-content/plugins/lg-layout-v2` symlinks into
`~/loothplatformv2-clean` (main). A branch's v2 behaviour is invisible on dev2.

1. **The pickers CLICKED.** Licence popover and file picker are drawn with the
   real editor CSS but have never been tapped. Ian's phone has beaten a green
   suite six times; markup is not a control.
2. ~~**The licence read-path swap RENDERING.**~~ ✅ **CLOSED.** Post 70937's
   REAL stored layout was pulled from the DB, run through the same recogniser,
   and rendered: exactly ONE block swapped, `lg-callout--note` gone, the licence
   rendered as `Attribution–NonCommercial–ShareAlike 4.0` with its three clause
   chips, and gallery / wysiwyg / download / post-header / post-footer all
   survived untouched. Verified through the real render pipeline — **not** in a
   browser, which is what check 1 still covers.

To do either you must point the plugin symlink at this worktree (and **restore
it** — the serving checkout only ever pulls), or use the lane preview.

---

## WHAT IAN HAS BEEN SHOWN, AND THE ONE THING HE OWES AN ANSWER ON

**Page:** `https://dev2.loothgroup.com/footer-mockups/frontend-compose-build/licence.html`
(also committed under `footer-mockups/` so it survives a box rebuild). Samples on
it are **real renderer output**, not drawn markup.

**Open question (non-blocking):** the ACF choice
`BY ND NC (Credit given to creator, No Derivatives, Adaptations shared with same terms)`
**contradicts itself** — ND forbids the adaptations SA would govern. 3 posts.
The block draws the licence its code names (BY-NC-ND) and drops the impossible
share-alike clause. **The stored wording is untouched** pending his yes/no on
fixing the form. Either way those 3 posts keep the same licence.

---

## FINDINGS ON MAIN THAT ARE NOT THIS LANE'S — someone should own them

1. **The v2 snapshot harness was UNRUNNABLE, not red** (fixed, `b1b6065`).
   `render-test.php` diffs 4 artifacts per fixture; the monorepo root
   `.gitignore`'s blanket `*.log` silently excluded every
   `tests/expected/<fixture>/validation.log`, so `file_get_contents` returned
   false into `strlen()` and `--all` **fataled on the first fixture**. With it
   running, **main is 0 passed / 10 failed**: `bundle.css` stale in all ten (it
   is a GLOBAL artifact copied into every fixture; `whos-talking`'s CSS landed
   after the last capture), plus real `rendered.html` drift in `embed-minimal`,
   `simple-article` and `loothprint-sample`. **Deliberately NOT mass-updated** —
   `--update-snapshots` rewrites all ten and buries the drift. Someone should
   decide what those three are.
2. **`bin/lint-block.php` was wrong about a SHIPPING block.** It knew a
   `gallery-items` picker nothing implements while rejecting the `gallery` that
   `blocks/gallery` declares and `lg-fe-editor.js` runs. M7 now derives from
   `EditorPickers::KNOWN`; M8 now lists what the editor actually renders.
3. **7 blocks declare pill buttons that do not exist** — brand-gallery,
   brand-hero, contact-form, event-header, featured-products, recent-posts,
   whos-talking declare `move-up`/`move-down`, which `lg-fe-editor.js` does not
   implement, so they draw the literal string "move-up". Pre-existing; reported
   not fixed, to keep this diff inside the charter.
4. **`gallery` and `embed-url` pickers are FRONT-END-EDITOR ONLY** — no
   `render()`/`sanitize()` arm in `EditorPickers`, so the admin metabox cannot
   edit those props. Recorded in that file.

---

## HOW TO WORK HERE

* **Build in the monorepo.** v2 is tracked (`lg-layout-v2/`, 232 files) and the
  docroot symlinks to `~/loothplatformv2-clean`. ⚠️ `~/projects/lg-layout-v2`
  also exists, differs in 68 files, has no `.git`, and is a **stale leftover** —
  editing it changes nothing while looking exactly like it should.
* **New block = the 7 steps in `lg-layout-v2/docs/BLOCK-ONBOARDING.md`.** Design
  doc first (`bin/block-new.php` refuses without one), then manifest, then CSS,
  then render.
* **Do NOT chase a clean `lint-block`.** No block in the tree is clean —
  `license` is 4, `callout` 15, `post-footer` 83. The shared conventions
  (`--lg-tags-border`, `@media` at-rules, bare root font-size) are tree-wide.
  Fix what is yours; do not reformat the tree.
* **Inert-addition testing, since the committed baselines are stale:** capture
  `tests/output/` to a scratch dir BEFORE your change, then diff `rendered.html`
  + `variables-resolved.json` after. `bundle.css` legitimately changes for every
  fixture (it is global), so it cannot be part of that check.
* **Gate numbers come from keeper. Never mint one.** 35 is this lane's. A gate
  for the licence/print-file assertions (OFF byte-identical / ON substitutes /
  absence paired with liveness) is still **owed a number** — ask.
* **Pictures for Ian:** `tools/frontend-compose/shots.py` already handles the
  five screenshot traps on this box. Publish under
  `~/projects/footer-mockups/…` (symlinked to `/var/www/dev/footer-mockups`) and
  give him a URL, never a path. Copy it into `footer-mockups/` in the repo too.
* **Reporting:** `SendMessage` to the keeper session (plain `ubuntu`, newest) for
  questions; `msg send ubuntu` for durable reports. Questions for Ian route
  THROUGH keeper. Report and keep working — do not park.

---

## TRAPS THIS LANE HAS PAID FOR — do not re-learn them

1. **`_lg_layout_v2` is SERIALIZED.** A SQL `LIKE` for JSON silently measures
   nothing and produces a confident wrong number. This cost the charter a block.
2. **Assert on the value, not the document.** Grepping a page for a title matched
   the `<title>` tag while the input was empty — hiding that a rendered-but-empty
   field **saves empty** and blanks the member's post.
3. **Counting CSS text is not counting a control**, and `querySelector` returns
   the FIRST match, not the right one.
4. **Hit-test before clicking** (`elementFromPoint`).
5. **A flex item with a set height still gets shrunk** (`flex: 0 0 auto`).
6. **WP's clock here runs 4h behind server local** — compare against
   `current_time()`, never `date`.
7. **Never name a script `enum.py`** — it shadows the stdlib.
8. **Restore the box every time.** 40 mu-plugins, none of them yours.
9. **An assertion that has never failed is not evidence.** Break it first. Both
   the licence parser test and the screenshot overflow detector were red-firsted
   in this session, and the overflow one only proved itself when a block was
   forced to 900px.

---

## ⚠️ BOX FAULT, NOT THIS LANE'S — read before trusting anything on dev2

**Root is 100% full** (29G, ~20MB free as of 2026-08-15 00:2x). Redis cannot
write its RDB snapshot, so it is in **MISCONF and refusing all writes**, and
dev2 has an `object-cache.php` drop-in — so **every `wp eval` dies** with
"Error establishing a Redis connection" and the WP object cache is broken.

**This explains the failed window.** With the branch symlinked over the serve,
the served HTML stayed BYTE-IDENTICAL through render-cache deletion, `wp cache
flush`, an FPM reload AND a full FPM restart with workers recycled — while
WP-CLI proved the branch's code was loaded and `load_layout(70937)` returned the
licence block. A Redis that refuses writes and returns stale reads produces
exactly that. My earlier guess ("something serves static HTML ahead of PHP") was
wrong about the mechanism.

⚠️ **So "verified on the dev2 serve" is unreliable for any check made while
Redis was wedged.** Re-verify anything recent.

Reported to keeper. Recommended one-line unblock (NOT run — shared service,
keeper's call):

    redis-cli config set stop-writes-on-bgsave-error no

It restores writes immediately and reverses by setting it back to `yes`, but it
MASKS the full disk, which still needs reclaiming. Top consumers: `/home` 13G
(worktrees 5.8G, dev1-import 1.3G, dev26-archive 700M, backups 681M), `/var`
7.9G.

### Three ways a gate goes VACUOUSLY GREEN here — all hit while writing gate 7

1. **`wp db query` swallows the result set** of some SELECTs and prints
   "Success: Query succeeded. Rows affected: -1" instead of rows. Read as an
   empty corpus, every assertion passes having measured nothing. Use straight
   `mysql -N -B`.
2. **`sudo` strips the environment.** SQL passed as an exported var arrives
   empty, mysql runs nothing, zero rows, green-by-emptiness. Pass it as a
   POSITIONAL ARG.
3. **`wp eval` is dead** while Redis is wedged — a gate built on it reports a
   BOX fault as if its own subject were unrunnable.

### Board messages: single quotes, NO backticks

`msg send ubuntu "…"` goes through bash, so backticked text is
command-substituted away before `msg` sees it. It cost this lane a gutted report
(a `redis-cli` recovery command was replaced by the literal word "OK") and cost
the hub-picker lane one on 7/08. Verify with `msg inbox` — note `msg thread`
returns the OLDEST ~100 messages, so tailing it looks like nothing arrived.
