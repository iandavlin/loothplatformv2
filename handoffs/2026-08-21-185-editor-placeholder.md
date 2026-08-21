# 185-editor-placeholder — the member must never read their own HTML tags

**Issue #185 · branch `185-editor-placeholder` · 2026-08-21 · DONE, awaiting merge**

Ian screenshotted the compose form's write-up field showing a grey bar reading
**"Click to initialize TinyMCE"** directly above his content rendered as literal
`<p>test</p>`. Two fixes had already been attempted and **neither reached the
served bytes**.

---

## The cause — it was our own file, and both attempts died on the same line

`lg_fc_relabel()`'s `_post_content` block in
`platform/mu-plugins/lg-frontend-compose.php` set `$field['delay'] = 1`
explicitly. ACF's own default for the field is `delay => 0`
(`class-acf-field-wysiwyg.php:41`) and `acf_form()`'s pseudo-field registration
sets none at all (`form-front.php:59-65`) — **that assignment was the only thing
that ever turned it on.**

### How it was proven, in one pass

Not by reading filters. By **bisecting `acf/prepare_field` by priority** on a real
render — a probe registered at a ladder of priorities, printing the value at each
rung:

```
prio 19 → delay=0  label="Content"
prio 21 → delay=1  label="Tell people about it"
```

The only callback between those rungs is `lg_fc_relabel` at 20. **Ours.**

That also answered the question the issue opened with, as a by-product:
**`acf/prepare_field` DOES fire for the pseudo `_post_content` field.** It is
registered as a real local field via `acf_add_local_field()` and rendered through
`acf_render_fields()` → `acf_render_field_wrap()` → `acf_prepare_field()`.

### Why each earlier attempt was a no-op — same reason, twice

| | what it did | why it never took |
|---|---|---|
| lane 179 | `delay = 0` at the **top of `lg_fc_relabel`** | the `_post_content` block ~40 lines **below, in the same function**, overwrote it |
| keeper | `lg_fc_no_delay` on `acf/prepare_field/type=wysiwyg` @ 99 | the type-scoped variation is dispatched by `_acf_apply_hook_variations` at **generic priority 10** — so it fired *before* `lg_fc_relabel` at 20, and was overwritten too |

⚠️ **A type-scoped ACF filter is not "later" than a generic one.** It runs *inside*
the generic hook's priority-10 slot. General fact about ACF, not about this form.

**Both dead filters were deleted.** Leaving a no-op filter in place is how the
next person loses an hour — and one of them carried a comment asserting something
false ("something downstream of priority 20 restores ACF's own delay").

---

## The craft-law question, settled with a measurement

CRAFT-STANDARD:26 reads *"composers, admin tooling load on intent (click/focus),
**never for anon**"*. Two facts make this fix compliant rather than an exception
that needs defending:

1. **`/compose/` 404s for anon** — `lg_fc_route()` returns before anything is
   registered or emitted for a logged-out visitor.
2. **The delay never saved the download.** Measured on the rendered form with
   `user_can_richedit()` true — what a real browser gets — **`wp-tinymce-js` is
   enqueued in BOTH states.** The delay deferred one `tinymce.init()` call, not
   an asset. (Under curl the script is absent in both states, because
   `user_can_richedit()` is false there; that is a harness artifact, not the
   member's experience.)

The measurement is quoted in the code comment so nobody reverts it on the law's
authority.

---

## Measured, before and after

Rendered through the identical path (`lg_fc_route()` → `acf_form()` → ACF's
renderer) with the flag armed and **liveness asserted on every run**:

| | main (serving checkout) | this branch |
|---|---|---|
| `Click to initialize TinyMCE` | **1** | **0** |
| `<div class="acf-editor-toolbar"` | **1** | **0** |
| editor wrap class | `…wp-editor-wrap tmce-active delay` | `…wp-editor-wrap tmce-active` |
| the form arrived (liveness) | 3 | 3 |
| write-up field present | 1 | 1 |
| edit form: stored content prefilled | 1 | 1 |

Same result on the **create** form and the **edit** form. The prefill row matters:
it proves the fix did not disturb the data-loss guard beside it.

The `before` numbers were also taken over **real HTTP as a real member** against
the deployed form (`/compose/?type=loothprint`, member cookie minted by
`member_cookies()`): 257,145 B, 1 placeholder, 1 toolbar div, wrap carries
`delay`. That is the state dev2 serves today.

---

## The gate

`tools/gates/loothprint-paywall-gate.py` P4. **The expectation was not edited.**
Two matcher defects were fixed, both found while fixing #185:

1. **It could never have gone green.** It matched the bare string
   `acf-editor-toolbar`, which also lives in the page's own dark-mode CSS rule for
   that class — a rule that deliberately **stays** (ACF still renders that div in
   other configurations). On the fixed form: bare string **2**, markup **0**. So a
   correct fix would have left it red for a reason nowhere near the defect.
   `feedback-red-first-that-stays-green`, in mirror image.
2. **It could pass vacuously.** It sat outside the `form_arrived` guard, so a
   login page contains no toolbar and the one claim under test passed having
   measured nothing. `feedback-absence-assertion-needs-liveness`.

Now it asserts `<div class="acf-editor-toolbar"` **plus** the ` delay` class on the
wrap, paired with a positive assertion that the editor is on the page at all.
Red-first, against four real rendered forms:

```
main   create   liveness ok   RED     branch create   liveness ok   GREEN
main   edit     liveness ok   RED     branch edit     liveness ok   GREEN
```

**On dev2 today the gate is RED on P4, correctly** — the mu-plugin is symlinked
out of the serving checkout, so dev2 serves `main`. It goes green **on the merge
and the pull, by the fix**.

---

## Gate 47 — an honest partial answer

Gate 47's named open red is *the 712×40 `#f5f5f5` slab*, which **is**
`div.acf-editor-toolbar`. That finding **cannot survive**: this form no longer
emits that div at all (measured 0 on both create and edit).

⚠️ **But do not read that as "gate 47 goes green."** Booting TinyMCE with the form
puts **live editor chrome inside `.lgfc__card`**, which the sweep walks — where
before it saw only the static placeholder slab. The existing dark rules cover
`.mce-panel`, `.mce-toolbar-grp`, `.mce-toolbar`, `.mce-btn`, `.mce-ico` and hide
`.mce-statusbar`/`.mce-path`; anything they miss becomes a **new** finding on the
same surface. `dark-contrast-sweep.py` takes only `--width` and always drives the
serve, so this **cannot be measured from a branch**. Re-run after the pull:

```
python3 tools/frontend-compose/dark-contrast-sweep.py --width 1280
python3 tools/frontend-compose/dark-contrast-sweep.py --width 390
```

---

## Two traps worth carrying to any lane

1. **A gitignored `.local.php` flag makes a branch render NOTHING, silently.**
   `lg_fc_enabled()` fail-closes when its config is unreadable, and the `enabled`
   switch lives in `platform/config/frontend-compose.local.php` — present **only
   in the serving checkout**. The first branch render produced a 0-byte page in
   which "0 placeholders" was true and meaningless. Only the liveness assertion
   caught it. Arm it with `LG_FC_PREVIEW=1`, and remember **`sudo` strips the
   env**: `sudo -n -u looth-dev env LG_FC_PREVIEW=1 wp …`.
2. **You can render a branch's mu-plugin without touching the serve.** WP-CLI's
   `--require=<file>` runs *before* WordPress boots, so a file doing
   `define('WPMU_PLUGIN_DIR', '/tmp/…')` redirects the whole mu-plugin set. Mirror
   the serve's 43 symlinks in, swap the one file for the branch's, and prove which
   file loaded with `ReflectionFunction::getFileName()`. Non-mutating — nothing on
   the serve changes. It is **not** a browser: `user_can_richedit()` is false under
   wp-cli and curl, so ACF renders `html-active` where a browser gets
   `tmce-active`. Assert something that does not depend on it (the `delay` class
   does not).

---

## What I did NOT do

- **No browser verification of this branch.** The compose form is a mu-plugin
  symlinked out of the serving checkout and `lane-preview.sh` cannot swap one
  (it maps paths, never mu-plugins, and explicitly creates no FPM pool). So
  "TinyMCE boots and looks right" is verified in the served **markup** and in the
  emitted `mceInit`, not in a rendered browser. It needs Ian's eyes after the pull
  — which is exactly what he is waiting to do.
- **A brief FOUC is now possible and is unmeasured.** With `delay` the raw
  textarea was visible permanently; now it is visible only until `tinymce.init()`
  settles. If Ian sees a flash of tags on load, that is this, and the fix is a CSS
  hide on `.tmce-active .wp-editor-area` — not a revert.
- **Gate 47 not re-run** (deploy-coupled, above). **`run-all.sh` not run** — it
  exits early on main's gate-72 red (#175), so gates were run individually.
  `compose-richtext-gate.py` reports **CANNOT RUN (2)** on this branch *and
  identically on main* — pre-existing wp-cli `DISABLE_WP_CRON` noise, attributed
  by re-running it from the serving checkout rather than pattern-matched.

## Verdict

`compose-gate.py` (gate 35) **GREEN**. Paywall gate P1/P2/P3 **green**, P4 red by
deploy coupling only. `php -l` clean.
