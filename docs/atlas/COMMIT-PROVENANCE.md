# COMMIT PROVENANCE — what is actually tested in the undeployed window

**Question this answers:** live is 207 commits behind. Which of them has a human or a
gate actually exercised, so Ian can pull something he trusts?

**Audited 2026-07-30 by the commit-provenance lane.** Read-only. Live state verified
through `ssh live-ro` at time of writing.

---

## ONE SCREEN

Live = `c57b70f`. main = `9707fa6`. **207 commits** (182 non-merge + 25 merges).

| | count | what it means |
|---|---|---|
| Commits that reach live on a pull | **73** | change code symlinked into live's serving checkout |
| Commits that CANNOT reach live | 105 | docs, tools, gates, footer-mockups |
| Commits touching mu-plugins live doesn't load | 4 | the file isn't symlinked on live — inert until Ian links it |
| **Net file surface** | **97 files** | 60 added, 37 modified |

**The 73 that reach live, by evidence:**

| | | count |
|---|---|---|
| **A — VERIFIED** | defect reproduced first, then fixed and re-checked; or a gate proven red-before/green-after | **8** |
| **B — EXERCISED, NOT PROVEN** | ran on dev2 all day, gates green, but nothing checked *this* behaviour | **~35** |
| **C — UNTESTED** | no gate, no human, no evidence — incl. 4 that say so themselves | **~14** |
| **D — KNOWN BROKEN on live** | will not work, or is a new exposure | **16 + 2 defects** |

### Verdict: there is NO clean subset. Do not pull to main today.

I looked for a cut point that gets the content-destroying save fixes without the
unflagged digest work. **There isn't one.** The recap code enters the window at
**position 6 of 207**; the save fixes are at **61** and **131**. Any pull far enough
to fix the save bugs necessarily takes all the digest work with it.

### Three blockers, in priority order

1. **The weekly digest will send to the real member list, unflagged, on Mon 2026-08-03
   13:00 UTC.** Live has a scheduled `lg_wd_send_digest` cron (verified in live's
   `wp_options`). `templates/email.php` now emits `##lg_recap.section##`
   *unconditionally* into every issue, and `LG_WD_Recap_Source::init()` registers the
   per-recipient substitution at plugin load. There is **no feature flag** — this
   violates the standing "flag, defaulted OFF" rule. Email is unrecallable.
2. **Thread-follow is broken on live until two migrations run.** Re-verified today:
   `forums.topic_follow` is **MISSING**, and `notifications_type_check` still rejects
   `forum.followed_topic`. See `docs/runbooks/live-topic-follow-migration.md` — Ian's
   own ruling is migration FIRST, then deploy, in one window.
3. **NEW: 46 files land in an anon-reachable path on live** (`lg-weekly-digest/dev/`,
   0 files on live today → 46 at main). Confirmed executing, not just present — see
   §4. No gate covers it.

### Recommendation

Fix blocker 3 (small), run the migrations for blocker 2, and **neutralise blocker 1
before deploying** — either hold the Aug 3 send or put the recap behind a flag
defaulted OFF. Then the whole window can go in one window, which is what the save
fixes need anyway. The ~4-day gap to Aug 3 is the entire margin.

**Holding is not free.** See §5 — two content-destroying bugs are live right now.

---

## 1. What "reaches live" actually means

I did not trust a file-path heuristic. I resolved every path on live and checked
whether `lg-deploy` (a single `git pull --ff-only`) propagates it:

```
SYMLINK  /var/www/dev/wp-content/plugins/lg-weekly-digest -> loothplatformv2-clean/lg-weekly-digest
SYMLINK  /var/www/dev/wp-content/plugins/lg-layout-v2     -> loothplatformv2-clean/lg-layout-v2
SYMLINK  /srv/{bb-mirror,profile-app,lg-shared,archive-poc,events} -> loothplatformv2-clean/*
SYMLINK  /var/www/dev/{pwa,mobile-hub,hub-polish,events-mobile,app-settings}.js -> webroot/*
```

Everything live-active is symlinked into the serving checkout, so **a pull is
all-or-nothing** across all of it.

**Correction to the earlier hand-off:** the "44 member-facing commits" figure was an
undercount. It excluded most of `lg-weekly-digest`, which I confirmed is an **active
plugin on live** (in `active_plugins`, 25 `weekly_email` posts, 21 published). The
real figure is **73**. Treat the 44 as superseded.

Three mu-plugins in the window are **not symlinked on live** and therefore do not run
there: `lg-discussion-unsub.php`, `lg-discussion-group-gate.php`,
`lg-author-socials.php`. Only `lg-event-reminders.php` and `lg-weekly-email-bridge.php`
are live.

## 2. The 73, by feature group

| group | commits | headline |
|---|---|---|
| weekly-digest / recap | 30 | the unflagged send path — blocker 1 |
| thread-follow | 16 | code is good, live DB is not — blocker 2 |
| composer / edit | 3 | the content-destroying save fixes — the reason to deploy |
| other | 24 | nginx, archive-poc disclosure, hub polish, layout blocks |

## 3. Category A — the 8 genuinely verified

These lanes were unusually honest, and several state plainly what they could *not*
reproduce. That candour is why I trust them.

- `c097162` author-socials gate — *"tested by reintroducing each defect: it goes RED
  and returns GREEN."* Textbook: the gate reproduces before it passes.
- `c001a85` thread-follow long-press — *"PROVEN in a real browser — red before /
  green after."*
- `ec90ffc` the wizard's SAVE — *"25/25 through the real UI"*, found by driving the
  real Save and **reading the database**, not the screen.
- `d68786d` Ian's three phone defects — all three reproduced on dev2 at 390px in real
  dark, then fixed.
- `765dbc3` desktop feed-card action row — reproduced on the real page (17 of 18 cards).
- `d3ecf98` menu + mobile sheet — now reproduces the injection; verified at 44px.
- `028767a` shorty-react — gate verified to redden pre-fix, but explicitly **not yet
  reproduced through the real button**. Partial.
- `04a8598` desktop topic page — verified in the engine, but explicitly **could not
  reproduce** Ian's flash-then-vanish report. Partial.

**A green gate alone never earned category A here.** 41 of the 73 mention a gate; only
these 8 showed the defect first.

## 4. Category D — known broken / new exposure

**4a. Thread-follow (16 commits).** Code is category A/B quality; the *live database*
is the problem. Both migrations re-verified unrun today. Deploying without them ships
a feature that cannot work.

**4b. NEW web-reachable dev harness — 46 files.** `lg-weekly-digest/dev/` does not
exist on live (0 files at `c57b70f`); main adds 46, inside a plugin directory that is
served over HTTP. Probed on the dev2 serve:

```
403  /wp-content/plugins/lg-weekly-digest/dev/                        (autoindex off — good)
500  /wp-content/plugins/lg-weekly-digest/dev/verify-signup-audience.php   (it EXECUTED)
200  /wp-content/plugins/lg-weekly-digest/dev/frames-index.html            (served)
```

Three scripts have no `ABSPATH` / CLI guard. One of them **discloses internal server
paths to an unauthenticated request**:

```
GET .../dev/verify-missed-exclusions.php
→ UNDER TEST: \Looth\ProfileApp\Recap from
  /home/ubuntu/loothplatformv2-clean/profile-app/src/Recap.php
```

On dev2 this sits behind the cookie gate. **Live has no such gate.** Severity is
information disclosure, not RCE — `build-inbox-test.php` is properly guarded and sends
nothing, and I found no member names or emails in the served HTML frames. But this is
the *same recurring class* as the `/archive-api/v0/*.php` leak: the `infra-sec` gate
checks `/v2/*.php` and is blind one directory over. Cheapest fix is to not ship
`dev/` at all.

## 5. What NOT deploying costs — this is the other side

Holding is **not** the safe default. Two content-destroying bugs are live right now:

- **`ec90ffc` — editing a post silently destroys content.** Bullet lists come back
  *numbered* (Quill's internal DOM was serialised straight into `post_content`), and
  **an inline image is deleted by editing at all** — a legacy body's genuine inline
  image is stripped on open and again on save, so it just vanishes. The lane measured
  **50 of dev2's 1,311 discussions** carry such an image. Every member who edits one
  loses it permanently, and the screen looks fine while it happens.
- **`83db275`** — *"stop destroying the body"*, same class, in the composer.

Every day this sits undeployed is another day of silent, unrecoverable member data
loss. That is the case *for* moving quickly on the three blockers.

## 6. The structural blind spot Ian should know about

**The one thing that most needs verification is the one thing dev2 cannot verify.**
dev2 runs mailpit (confirmed active): it swallows outbound mail and returns success.
The weekly-digest lane wrote this down itself —

> *"dev2's mail containment would swallow it into mailpit and return true, which reads
> as a successful send and is not one."*

So no amount of dev2 exercise can prove the recap substitution works against a real
FluentCRM campaign send. The 30 recap commits are structurally capped at category B/C
for the behaviour that matters. That is exactly why blocker 1 needs a flag rather than
more testing.

## 7. The two merges with empty bodies — reconstructed

- **`20631ab` events-expiring-early** — fixed *"events leave too soon"*: a UTC "today"
  was judging a site-local date. Shipped with `event-date-tz-gate.sh` (now GATE 6).
  **Its content is already on live** — it reached `c57b70f` via `3f62e31`, and
  `events/` is byte-identical between live and main. The merge commit is in the window
  but is a **deploy no-op**.
- **`14fe0a5` gate-harness-portable** — repaired the gate harness after it died on its
  own drift. Adds `tools/gates/gate-env.sh` (one resolver for host + token, no hostname
  written in the file) and rewires craft / infra-sec / looth-auth / editor-rail plus
  `visibility-matrix.php`. Gate infrastructure only; nothing member-facing.

## 8. Attribution — honest limits

106 of 182 non-merge commits are authored by the generic box user `Ubuntu`, so
**per-commit authorship is UNATTRIBUTED** for those. I classified by evidence *in the
commit body and in the repo*, not by author. Named lanes: weekly-digest-recap (55),
profile-guide (8), Ian Davlin (6), gate-harness (3), thread-follow (2), patreon-return
(1). Signing is fixed going forward in `tools/lanes/spin-lane.sh`.

I did **not** independently re-verify all 73 commits to category-A standard — that is
weeks of work. Categories B and C are marked `~` and are an evidence-distribution
estimate, not a certification. A/D are individually checked and are the ones the
recommendation rests on.
