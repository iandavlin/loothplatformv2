# HANDOFF — lane `191-licence-modal` (#191)

| | |
|---|---|
| Branch | `191-licence-modal`, pushed. Cut from `c1f38be`; **4 commits behind main** at time of writing (main gained gate 91 and three others) |
| Issue | **#191**, `approved`, label `page` — **and it is not a lanes-page issue; the seventh in seven days.** See `docs/domains/PAGE.md` |
| Gate | **92**, `compose-licence`, minted by keeper. Registered in `run-all.sh` and `docs/CRAFT-STANDARD.md` |
| Preview | `https://dev2.loothgroup.com/preview/191-licence-modal/compose/?type=loothprint` |
| Merge | waiting on keeper + Ian. **One expected conflict, see "For keeper" below** |

---

## What was wrong

One of four Creative Commons options on the Loothprint compose form described a
licence that does not exist:

> BY ND NC (Credit given to creator, No Derivatives, **Adaptations shared with same terms**)

"No Derivatives" and "adaptations shared with same terms" contradict each other —
the second clause is Share-Alike. Members were picking legal terms off that
sentence.

**The letters were always right.** BY-NC-ND is a real licence; the English beside
them was not. That is the whole basis on which correcting stored values was safe,
and it is written into the migration and the code.

---

## THE COUNT (the charter's report-first item)

| store | dev2 | live |
|---|---|---|
| `loothprint_creative_commons` postmeta | **3 posts, all published** | the **same 3**, all published |
| baked `_lg_layout_v2` blocks | the same 3 | the same 3 |
| `_lg_layout_v2_rendered_html` (anon render cache) | **1** (51126 only) | not checked — see below |

`33871` stewmac-offset-diamond-fret-file-handle · `51126`
dura-gold-stickyback-sandpaper-roll-storage · `57824`
endoscope-positioning-device-the-endo-stay

`loothcut_creative_commons` is a **separate field**, 11 rows, all on the correct
BY-NC-SA string. Unaffected. Only the fourth choice was ever wrong.

### ⚠️ I MISSED THE THIRD STORE. Keeper found it, and the lesson is general

The migration was written for two stores, ran, verified, and its sweep — which
asked about **the two keys it already knew about** — reported zero left. There was
a third: `_lg_layout_v2_rendered_html`, WpRenderer's anon render cache.

- It was **not being served**: updating `_lg_layout_v2` fires `updated_post_meta`
  → `Plugin::on_post_meta_changed` → `invalidate_render_cache()`. But that
  function is **one line** — `delete_post_meta($post_id, …_RENDERED_AT_META)` —
  so the stale HTML body stays in the row for ever. **133 posts carry one.**
- **The cure is a one-line change of question**: not `WHERE meta_key = …` for keys
  you thought of, but `SELECT meta_key, COUNT(*) … WHERE meta_value LIKE … GROUP
  BY meta_key`. That found it, and also surfaced **17 rows in `_elementor_data`**
  (old templates and revisions — out of scope, recorded so nobody re-finds them
  and panics). The migration now sweeps that way and labels each key in/out of
  scope.
- It is **deleted, not patched**. A cache is derived data; a hand-edited cache
  agrees with nothing and can disagree with its source later. Verified: the page
  re-renders correctly from cold.

---

## What shipped

**`platform/mu-plugins/lg-frontend-compose.php`**

- `lg_fc_licences()` — one table: stored value, plain summary (`can`/`cannot`),
  legal-text filename, and the legacy strings it `supersedes`.
- The choices are overridden **in code** at `acf/prepare_field`, not by editing
  ACF field `field_6564e26df56ba` — survives a wp-admin edit, and reaches live by
  pull (a DB edit would not).
- **`lg_fc_licence_forward()` at render** — the line that stops this fix breaking
  the edit form. See the trap below.
- The hint stops nudging: *"How other people may use your print files and
  photos."* (After `171ab17` did the same to the paywall control.)
- The **ⓘ**, appended via `acf/get_field_label`, and a native `<dialog>` printed
  **after** `acf_form()` — plain summary first, complete legal text below, for
  whatever is **checked at open time**.

**`platform/licences/`** — all four CC 4.0 legal codes, verbatim from
creativecommons.org, checksummed in a README, read with `dirname(__DIR__)` (the
same idiom `lg_fc_enabled()` uses). **No new `mu-plugins/` symlink needed**, which
is why they are not under there.

**`tools/migrations/191-licence-label.php`** — three literal ids, all three
stores, dry-run by default, idempotent, refuses on ambiguous letters.

**`tools/gates/compose-licence-gate.py`** (+ red-first) — gate 92.

**`platform/nginx/lane-preview-191-licence-modal.conf`** — a URL Ian can click.

---

## Traps this lane paid for

### 1. An ACF radio's choice KEY is its stored value

Correcting a label orphans every row holding the old one. **Whether that is a
blanked value or a locked-out member depends on `required`** — measured, not
assumed: this field is `required => 1`, so ACF **refuses the save** ("… value is
required") rather than storing the emptiness. So a member opening one of those
three posts would have been unable to save at all without changing its licence.
`lg_fc_licence_forward()` closes it **at render only**, and stays after the
migration because live keeps the old values until Ian runs the command and a fresh
cut of dev2 reintroduces them.

### 2. `acf/get_field_label` is the ONLY seam for markup in an ACF field label

`acf_get_field_label()` runs `esc_html()` over `$field['label']` **before** any
filter sees it, so appending markup in `acf/prepare_field` renders as visible
text. What the filter returns is then passed through `acf_esc_html()` — `wp_kses`
with `$allowedposttags` — which keeps `<button>` with `type`, `id`, `class` and
`aria-*` **intact** (verified against the exact markup, not read off the
allow-list). For a radio, ACF renders the label with **no `for` attribute**, so a
button inside it activates nothing else.

⚠️ And the ⓘ **is inside the form**, so `type="button"` is load-bearing — without
it, "read the licence" becomes "publish the half-finished loothprint". Gate leg
A9b. The dialog itself is outside the form so no control in it can submit.

### 3. Fixing the wording broke a DIFFERENT FILE, silently

There are **two licence tables**, and they cannot be merged (an mu-plugin must
not depend on a regular plugin's class):

- `lg_fc_licences()` — what the form **offers**, so what gets **stored**
- `Licenses::ACF_CHOICES` (`lg-layout-v2/src/Licenses.php`) — what the layout
  engine **recognises**

`Licenses::from_exact_prose()` matches the choice string **exactly**, on purpose
(a loose match would rewrite an author's prose). So correcting the wording made
it stop matching every post saved afterwards: `upgrade_license_callouts()` walks
past them and the licence block never renders. **Nothing errors.** Measured on
main: `from_exact_prose` of the corrected string → `''`.

**Both spellings are now load-bearing** — without the new one the migrated posts
break; without the old one **live** breaks, since its values are unchanged until
Ian runs the migration, as does any box cut from live.

I found this by **grepping the repo for the old string**, which is not a method.
Gate 92 **§F** now asserts the two tables agree. ⚠️ It has to load the branch's
copy **by absolute path in plain PHP**: under WordPress the autoloader resolves
`Licenses` out of the **serving checkout** — main — which is the broken state
being tested for. The probe echoes back the file it loaded and F1 asserts it.

**`picker_choices()` was checked and is fine** — the FE editor offers codes and
short names, not the prose strings, so nothing writes the old wording back.

### 4. Minting a gate number from MAIN is blind to a concurrent lane

`run-all.sh` on main stopped at 90, so **91 looked free — and was not**: #192 had
it on an unmerged branch. Diffing every live worktree is the only thing that shows
it:

```
for w in ~/worktrees/*/; do
  echo "$w $(git -C "$w" diff --name-only main...HEAD | grep -c tools/gates/run-all.sh)"
done
```

**Second near-collision in two days.** The same sweep is the cheap way to answer
"does my change overlap another lane" — it showed **no other live lane touching
`lg-frontend-compose.php`**.

### 5. The dev-gate cookie is `loothdev_auth`, not `loothdev`

`/etc/nginx/conf.d/loothdev-auth.conf`: *authorized = valid dev cookie | valid
tester cookie | **loopback** | exempt path*. Two consequences:

- The wrong cookie name gives a **styled 403** that a presence assertion can pass
  against. Only the liveness leg caught it.
- **`--resolve …:127.0.0.1` skips the gate entirely.** Verified: no cookie via
  loopback → the app answers; no cookie via the LAN address → 403. This is the
  same reason `gate-env.sh` pins to the LAN address, and it is worth knowing when
  a manual curl "works" and a gate does not.

⚠️ **CORRECTION to commit `c847ccd`'s message.** It says curl reached the page
from the LAN address while the browser got a 403. **That is wrong.** Both failed;
only the browser's failure was *reported*, because the gate aborted with
`CannotRun` and discarded fifteen already-recorded curl failures. The gate now
prints what it already measured, and **exits 1 rather than 2** when it has
findings — exit 2 reads as GATES INCOMPLETE and blocks every lane for what is one
branch's bug.

---

## Gate 92, and what its red-first found

**100 checks green** on the branch through the mu-mirror preview. Runs in ~32s.

**Red-first: 19 mutations + 2 no-op controls + 1 documented known-green — 22/22.** The
first pass was **12/18**, and four of the six misses were **real holes in the
gate**, which is the whole point of running one:

| miss | what it proved |
|---|---|
| M2 stayed green | **B1–B3 were tautological** — they called `lg_fc_licence_forward()` themselves, so deleting the line in `lg_fc_relabel()` that CALLS it changed nothing. They proved the function worked and nothing about whether the form reaches it. **B3b** now drives the real render filter. Same family as gate 88's §C2. |
| M13 stayed green | **D2 was vacuous** — the UA stylesheet paints every `<dialog>` with a Canvas background, so deleting our own background rule stayed green. **D2b** requires our card token. |
| M15 stayed green | **E2 was vacuous** — "flag OFF emits zero bytes" was true with the guard *deleted*, because wp-cli has no current user and the route refuses anon for another reason. An absence with no liveness half. **E2 now signs a real member in and asserts they WOULD be served.** |
| M4 → CANNOT-RUN | the abort path discarded findings and exited 2. Fixed (above). It also **leaked two probe users**, because teardown sat at the end of `main()` and an abort walked past it — teardown now runs on the abort path too. |
| M6 stayed green | **honest known-green, kept as `K1`.** Deleting our own `btn.focus()` leaves the focus-return leg green because a native `<dialog>` **restores focus by spec**. So C10 asserts the promise Ian was made, not our implementation of it. The explicit call stays — it is the only thing that keeps the promise on the no-`showModal` fallback path. |
| M16 → CANNOT-RUN | **a harness bug, not a finding**: the mutation built invalid PHP, so the page fatalled and every gate failed for the same uninteresting reason. A mutation must be **valid code that is wrong**. |

---

## Gates run

| gate | result |
|---|---|
| **92** `compose-licence` (new) | **GREEN, 100 checks** |
| 88 `compose-limits` | GREEN, 189 passed — including its §K dark-token leg over the new licence CSS |
| 68 `compose-chrome` | GREEN, 5 checks, both themes |
| 46 `compose-media` | GREEN — no orphans, unattached count unchanged (4880 → 4880) |

Run **individually**, not through `run-all.sh`, which exits early on main's
gate-72 red (#175). Those three are the neighbourhood this change touches; gate
88's dark-token leg is the one that would have caught a new colour with no dark
value, and it passed.

**Red-first: 22 cases, all as expected** — 19 mutations each reddening its own
named assertion, 2 no-op controls proven inert, 1 documented known-green.

---

## For keeper — merging this

**One expected conflict, and the resolution is "keep both".** This branch is 4
commits behind main, and main gained **gate 91** after the branch point. Both
`tools/gates/run-all.sh` and `docs/CRAFT-STANDARD.md` therefore have an addition
at the tail on each side. 92 is correct and free — keep 91 and 92.

Nothing else overlaps: no other live lane touches `lg-frontend-compose.php`.

### THE LIVE COMMAND — Ian runs it, after the merge and `lg-deploy`

Live is in the same state dev2 was: the **same three ids**, and sitewide exactly
3 and 3, so the id list is complete there too.

**Dry run first:**

```
sudo -u looth-dev wp --path=/var/www/dev eval-file \
  /home/ubuntu/loothplatformv2-clean/tools/migrations/191-licence-label.php
```

It must print `SITE: https://loothgroup.com` and
`WOULD DO  meta rows: 3   layout blocks: 3 … refused: 0`. Only then:

```
sudo -u looth-dev env LG191_APPLY=1 wp --path=/var/www/dev eval-file \
  /home/ubuntu/loothplatformv2-clean/tools/migrations/191-licence-label.php
```

⚠️ **The `env` prefix is not optional** — `sudo` strips the environment, so
`LG191_APPLY=1` set the ordinary way silently runs a **dry run** and reads as "it
did nothing".

The script prints the site it is on, refuses any row whose licence **letters**
differ, and a second run is a proven no-op.

---

## Reported, NOT fixed

- **wp-admin still offers the wrong label.** The fix is a code override,
  deliberately — but ACF field `field_6564e26df56ba` in the DB is untouched, so
  anyone editing a loothprint in wp-admin still sees the contradictory option.
  Needs Ian's call: a DB edit is not traceable to a commit and would not deploy.
- **Live's three posts stay wrong** until Ian runs the command above.
- **`invalidate_render_cache()` leaves the stale HTML body behind** on all 133
  posts that carry a render cache. Correct for *serving*; a landmine for any
  audit that asks "is this string still stored anywhere".
- **17 `_elementor_data` rows** on dev2 still hold the legacy string (old
  templates and revisions). Out of scope; recorded so nobody re-finds them.
- ACF's required-field refusal names the **raw** stored label ("Creative Commons
  Use License (leave default if unsure) value is required"), not "Licence",
  because validation does not run `acf/prepare_field`. Near-unreachable now.
- `emoji-picker-build`'s worktree looks **stale** — its `run-all.sh` diff is still
  on the old "GATE 1/19" numbering. Not mine, not touched; somebody should check
  whether it is alive.
