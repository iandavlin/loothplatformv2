# HANDOFF — `frontend-compose`, the draft-first LAST MILE

**Written 2026-08-15.** Assume zero context. This supersedes the compose half of
`handoffs/2026-08-14-frontend-compose-handoff.md`; that file is still the record
for the four layout-v2 blocks and the modal/dark rounds.

| | |
|---|---|
| Branch | `compose-loothprint-modal` |
| Flag | `platform/config/frontend-compose.php`, `'enabled' => false` — untouched |
| Merge | **not merge-ready until Ian has looked at the running thing** |
| Preview | `https://dev2.loothgroup.com/preview/frontend-compose/` |

---

## THE CHARTER I WAS GIVEN WAS STALE — READ THIS FIRST

My charter said one wiring fault remained: *"in real FPM requests the compose
form still binds new_post instead of the hidden draft"*, and told me to
root-cause it with a marker emitted into rendered HTML.

**That fault does not exist, and had already been fixed before I arrived.** The
predecessor's commit `2a855e4` diagnosed it correctly — FPM was serving stale
bytecode out of opcache, and the box reboot cleared it. The charter was written
against the state before that commit.

Measured, in rendered HTML from a real logged-in FPM request, which is the
diagnostic the charter asked for:

```
GET /preview/frontend-compose/compose/?type=loothprint     200, 188,766 bytes
  name="_acf_post_id" value="73209"
  occurrences of 'new_post' in the response body: 0
post 73209: loothprint / auto-draft / author 1912 / _lg_fc_draft set
```

**Lesson for the next seat, and it is the third time this lane has paid it:** a
charter is a snapshot. Verify its premise against the running box before
spending a day on it. Mine would have had me hunting a bug that a commit in my
own branch history had already closed.

---

## WHAT I DID

### 1. A real defect, found because a gate went red and would not reproduce

`lg_fc_sync_reaper_schedule()` disarmed the reaper with
`wp_unschedule_event($next, ...)`. That removes **one timestamped occurrence**,
and `wp_next_scheduled()` returns only the earliest — so whenever the cron array
held two entries for the hook, every flag-off load disarmed one and **left the
other armed**. An armed `lg_fc_reap_drafts_event` force-deletes auto-drafts
marked `_lg_fc_draft` *and their attachments*, daily, from WP-cron, for a feature
nobody can reach. That is precisely the failure assertion 7 was written to catch.

Fixed with `wp_clear_scheduled_hook()`, which takes every entry for the hook.

**Why it nearly got written off as flake, and the lesson:** a `wp eval` heals on
`init` before its own code runs, so it reports "nothing scheduled" no matter what
was there. The first run saw the survivor; the second run had already healed it.
**To measure cron state you must use `wp db query`, which never loads WordPress
and therefore cannot heal what it is measuring.**

Also recorded: **one anonymous request to the lane preview URL arms this event
site-wide.** The preview's `fastcgi_param` turns the flag on for that request,
`init` schedules, and the row outlives it. It self-heals on the next flag-off WP
load, so it is not a leak — but a gate run racing a preview click is reading
state the preview just wrote.

### 2. Gate 46 grew an assertion that plants the state instead of waiting for it

Assertion 7 is passive: it can only see what happened to be there. New **7b**
plants two cron entries, runs one ordinary WP load, and reads the raw option —
so the heal is exercised deterministically every run. Old code leaves 1 of 2 and
7b is red; new code leaves 0.

Also: the gate collected `notes` and never printed them. A swallowed note reads
exactly like a passed assertion. Now printed.

### 3. The whole loop, driven in a real browser at both widths

`tools/frontend-compose/draft-first-loop.py`. Gate 46 asserts this contract from
PHP — it calls `lg_fc_working_draft()` and inserts the attachment itself. That is
the right shape for a gate and it is **not evidence about what a member touches**,
which is why the phone keeps beating a green suite here.

Two phases, `--phase media` and `--phase modal`, each run at desktop 1280 and
phone 390 with touch emulation. **GREEN on both, on the running box:**

| what | desktop 1280 | phone 390 |
|---|---|---|
| form binds a numeric draft, never `new_post` | 73223 | 73225 |
| picker scoped `uploadedTo` = that draft | ✅ | ✅ |
| a REAL upload through the picker lands parented | ✅ | ✅ |
| picker lists ONLY this post's media | 1 listed / 1 owned | 1 listed / 1 owned |
| abandon → site-wide unattached unchanged | 4879 → 4879 | 4879 → 4879 |
| modal: injected form binds a draft | 73229 | 73229 (reused) |
| modal: injected photo picker OPENS and is scoped | ✅ | ✅ |
| swap, never show — no "both modals open" | ✅ | ✅ |
| discussion composer after the round trip | 37 forum options, takes input | 37 forum options, takes input |

The modal numbers matter more than they look. The Loothprint form is fetched
furniture-free and **injected** into the hub's shell — no iframe, Ian's ruling —
and the hub is a different app with no WordPress, so the injection drags jQuery,
acf.js, select2 and media-views in behind it. The recorded failure class here is
*relocated markup arrives without its behaviour*, which counting fields cannot
see. So the check taps the hardest control to carry over — the photo picker —
and confirms it opens **and** is scoped to the same draft; and it proves the
discussion composer still WORKS afterwards (its forum picker still holds its 37
options, a text field still takes input) rather than merely still being on screen,
because a second jQuery landing over a live one re-binds silently.

---

## THE THREE MEASUREMENT BUGS THIS COST, ALL OF WHICH ACCUSED HEALTHY CODE

Every one of these produced a confident, specific, wrong claim about the feature.
They are the reason this took a day rather than an hour, and they are all the
same shape: **the probe was wrong and said the product was.**

1. **Waiting on `wp.media`'s library reports success EARLY.** The picker inserts
   an attachment model when the upload *starts*. The run said *"the upload did
   not land parented — children unchanged at 0"* about an upload that landed a
   second later. Now it polls the database, which cannot be true early.
   **Assert the store, not the pixel.**

2. **Frames come back SIGNED OUT at random** — the branded 404. This is worse
   than a wrong screenshot: the upload POST fails with nothing on screen, which
   reads exactly like *"the feature does not parent uploads"*. Cookies are now
   re-asserted per navigation *and* again immediately before the upload, with a
   retry, the way `shots.py` already had to.

3. **`.lgfc__card` is the one element that never exists inside the modal.** The
   injector does `bodyEl.innerHTML = card.innerHTML`, so waiting for the card
   reported *"the fetch or the injection failed"* at both widths against a modal
   that was working perfectly — 12 acf-fields, a bound draft and a live picker
   were in `#lpm-body` the whole time. **Assert something the injection actually
   produces.**

A fourth, smaller: `Emulation.setTouchEmulationEnabled` rejects
`maxTouchPoints=0`, so disabling touch for the desktop pass killed the run before
a single assertion. **A check that cannot start looks nothing like a check that
fails** — and it exits 0 unless you look.

The general rule this branch keeps re-learning: **make the failure message say
what was actually on screen.** The signed-out cause was found only because the
"never rendered" finding was changed to print the page it saw. Before that it was
the same sentence for a 404, a 403, a dropped session and a slow page.

---

## WHAT IS NOT DONE

- **Ian has not seen it.** The flag stays OFF until he has.
- The unattached site-wide count moved 4879 → 4875 between runs from something
  outside this lane. It does not affect the within-run delta assertions, but a
  concurrent deleter of unattached rows could in principle flake gate 46's
  assertion 4. Not chased.
