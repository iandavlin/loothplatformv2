# #129 COMPOSER REDESIGN — write first, categorize last

Lane `129-composer-redesign`. Phase 1 (measure) and Phase 2 (mock) are done and
committed; this is the Phase-3 plan and the lane parks here. **No feature code has
been written.**

- Measurement: `handoffs/plans/129-composer-redesign-MEASUREMENT.md`, also posted as
  a comment on #129 (2026-08-19).
- Mock: `handoffs/plans/129-mock-composer-v2/index.html`, served at
  **https://dev2.loothgroup.com/mockups/composer-v2/** (behind the dev gate).

---

## 1. What I'd build

### 1.1 The Where step leaves the composer

Delete step 1 of the desktop wizard and the forum radiogroup from the mobile flat
form. New discussions land in a **default forum**, config-defined, so forums become
plumbing.

The radiogroup is the *source of truth the wizard reads*, not just a view: the
wizard moves the existing `input[name=forum_id]` radios into an accordion and
`ntmGetForum()` reads the checked one. So removing the step means the form still has
to POST a `forum_id` — it becomes a single hidden input carrying the default (or the
mapped forum), and `ntmGetForum()` reads that instead. That keeps the submit handler,
the edit-mode PUT and Buck's mobile reader of `#ntm-form` untouched, which is the
same discipline the wizard itself was built with.

**Edit mode must keep a way to change forum.** Today `ntmOpenForEdit()` jumps to step
2 precisely because a *new* post starts at Where and an *edit* should not. Once Where
is gone, an editing member has no forum control at all — and `api/v0/reply.php`
already enforces the postable list on the edit PUT, so the capability exists and only
the UI would be missing. Plan: keep a forum control on the **Review** row (the mock
draws it as an `Edit` affordance on the Forum row) rather than resurrect the step.

### 1.2 An optional topic-chip step at the end

Recommended shape is the mock's **Option A**: rail becomes `Write → Photos → Topics →
Review`, where Topics is optional, visibly skippable, and encouragement-framed
("bonus points"). `canLeave(3)` always returns true; the primary button never
disables on that step. Mobile has no wizard (`buildNtmWizard()` returns null below
641px) so the strip is simply the last block of the flat form, above Post.

Chips are `shared_category` ("Content Topics") terms. Top-level chips first, then the
children of whatever was picked — the mock shows both rows.

**This is the first writer of `shared_category` onto a discussion.** The taxonomy is
registered for 8 object types and `topic` is not among them, so the mu-plugin must
call `register_taxonomy_for_object_type('shared_category', 'topic')` on `init` after
ACF registers it. It must NOT be done by editing the ACF definition — that lives in
`wp_posts` 21219 in the database, and a DB edit is not traceable to a commit.

### 1.3 taxo → forum mapping, committed and hand-reviewed

A committed PHP map, **keyed on forum ID**, seeded from the measurement. ID because 4
of the 37 postable forum slugs are duplicated and 2 titles are outright identical
across the Repair and New Builds trees — a slug- or name-keyed map is ambiguous for 8
of 37 forums.

Resolution order: most specific term wins (a child term beats its parent), then the
first mapped term in the picked set, then the default forum. A term with no mapping is
not an error — it just doesn't move the post.

Do **not** derive the map at runtime from name matching. Measured: matching gets 21 of
36 terms right but produces 2 confidently wrong pairs, and the highest non-exact score
in the whole run (0.909, `Electronics Repair` → `Electric Repair`) is on a wrong pair.
21 rows need no thought; 6 need a decision. The map is small enough to read.

### 1.4 `wp lg-recat` — the hand tool and the LLM's tool

See §3 for the exact shape.

### 1.5 Flag discipline

One flag, **defaulted OFF**, copying `LG_AUTHOR_SOCIALS_ALL_MEMBERS`
(`platform/mu-plugins/lg-author-socials.php`). Name: `LG_COMPOSER_CATEGORIZE_LAST`.

- OFF must be a **proven byte-identical no-op** — same served `forums.js`, same
  `_chrome.php` output, Where step present and required exactly as today.
- The **OFF state must be gated**, per the standing rule that the missing OFF
  assertion is the whole recurring failure class. The gate reads the flag and asserts
  per state (absent / OFF / ON) off the *served* asset, so flipping the default later
  needs no gate edit.
- The flag has to be readable from **both** `getenv()` and `$_SERVER` — a
  `fastcgi_param`-set preview flag lands in `$_SERVER` only, and an env-only read
  serves OFF on the very preview URL built for Ian to click.
- `wp lg-recat` runs under WP-CLI, which has **no FPM pool environment**. Its flag
  read must come from a tracked PHP file via `__DIR__`, not from `env[]`.

---

## 2. Files I expect to touch

Guessing wider rather than narrower, per LANE-RULES.

| file | why |
|---|---|
| `bb-mirror/web/forums.js` | remove step 1, add the Topics step, `ntmGetForum()` reads the hidden input, `ntmOpenForEdit()` no longer needs to skip a step, chip UI + submit payload |
| `bb-mirror/web/forums.css` | Topics-step styles (`.lgt*`, chips); retire the `.ntm-forumlist` / `.lgw-acc` accordion rules once nothing renders them |
| `bb-mirror/web/_chrome.php` | stop rendering the 37-row radiogroup; emit the hidden default `forum_id`; keep the postable query for the Review-row forum control |
| `bb-mirror/config.php` | the default forum ID + the flag reader (already the shared home for `LG_BB_MIRROR_NONPOSTABLE_FORUM_IDS`, which two pools have to agree about) |
| `platform/config/taxo-forum-map.php` **(new)** | the committed ID-keyed map |
| `platform/mu-plugins/lg-frontend-compose.php` | `register_taxonomy_for_object_type` for `topic`; shared term-list helper |
| `platform/mu-plugins/lg-recat-cli.php` **(new)** | the wp-cli command |
| `bb-mirror/api/v0/reply.php` | the topic-edit PUT already enforces the postable list; it must accept a forum arrived at via the map and keep refusing non-postable ones |
| `docs/FLAGS.md` | register the flag row, same commit |
| `tools/gates/craft-gate.py` + `tools/gates/run-all.sh` | new gate for the three flag states; number it **from main, after a rebase**, and add the row to `docs/CRAFT-STANDARD.md` |
| `handoffs/plans/129-*` | measurement, mock, this plan (already committed) |

Not touching: anything under the config directory that is symlinked to live services,
without saying so explicitly first. `platform/config/` is the documented place for a
tracked reader, which is why the map goes there rather than into an `env[]` flip —
dev2's pool files symlink into the serving checkout and an `env[]` flip dirties it.

---

## 3. The wp-cli command's exact shape

Two subcommands: one reads, one writes. The write verb is the single motion plan v2
asked for.

```
wp lg-recat <topic-id>... --terms=<slug[,slug…]>
                          [--forum=<id>] [--no-forum]
                          [--dry-run] [--porcelain] [--reason=<text>]

wp lg-recat-list [--uncategorized] [--forum=<id>] [--since=<YYYY-MM-DD>]
                 [--limit=<n>] [--format=<table|json|csv|ids>]
```

**`wp lg-recat`** — assigns Content Topics terms to one or more discussions and
re-homes each to the mapped forum, in one motion.

| flag | meaning |
|---|---|
| `<topic-id>...` | one or more `topic` post IDs. Any non-topic or missing ID aborts the whole run before writing anything. |
| `--terms=` | `shared_category` **slugs**, comma-separated. Every slug is validated up front; one unknown slug aborts the whole run. Slugs not IDs, because that is what an LLM can read off the term list and what a human can type. |
| `--forum=` | override the mapped forum. Refused if the ID is not in the postable set — the same `LG_BB_MIRROR_NONPOSTABLE_FORUM_IDS`-aware list the picker and the edit PUT use, so all three agree. |
| `--no-forum` | assign terms only, leave the topic where it is. For tagging without moving. |
| `--dry-run` | print the plan — per topic: current forum → target forum, terms added, replies that would move — and write nothing. |
| `--porcelain` | machine output, one line per topic, for the batch/LLM caller. |
| `--reason=` | free text recorded in the audit meta, so a later reader knows whether a row was Ian's hand call or a supervised LLM suggestion. |

Exit codes: `0` all applied, `1` nothing applied (validation refused), `2` partial —
never silent. Every run prints a count; a no-op prints "0 changed" rather than nothing.

**What one topic actually needs, and the trap that shapes it.** A bbPress topic stores
its forum in **two** places — `post_parent` *and* `_bbp_forum_id` meta — and so does
every one of its replies (5,128 of 5,130 replies carry `_bbp_forum_id`; the mirror's
`reply` table has its own `forum_id` column). So the command must:

1. validate everything, then
2. `wp_set_object_terms($topic, $terms, 'shared_category', true)`,
3. move the topic: `post_parent` + `_bbp_forum_id`, via bbPress's own helpers so its
   counters stay right,
4. move **every reply** of that topic: `_bbp_forum_id`,
5. **bump `post_modified_gmt`** on the topic and on each moved reply, and
6. dispatch the mirror sync explicitly for each.

Steps 5 and 6 are not belt-and-braces. It is recorded that a change which does not
bump `post_modified_gmt` never reaches the forum mirror, **confirmed specifically for
the replies of a topic moved between forums** — which is exactly this operation. A
version of this command that skips them would look completely successful in MySQL and
leave the Hub showing the old forum indefinitely. Verification must therefore query
the **Postgres** `forums.topic` / `forums.reply` rows after a run, not the MySQL rows
it just wrote.

**`wp lg-recat-list --uncategorized`** is the LLM's read side: discussions with no
`shared_category` term. Today that is *every* discussion (measured: 1,406 term
assignments exist and zero are on a `topic`), so the first batch is the whole corpus
and `--limit` / `--since` are how it gets worked in supervised slices rather than one
1,318-row swing. Output carries id, title, current forum and a body excerpt — enough
for a suggestion, nothing more.

---

## 4. What I noticed but am not fixing

1. **Two rulings block Phase 4** and are posted on #129. Both change what the mock's
   "Lands in" line says, not how the step looks, so the mock stands either way:
   (a) `#3837 General` is a child of *Repair and Restoration*, not a site-wide forum,
   so defaulting untagged discussions there files them in the repair tree;
   (b) `New Builds` (145 uses) and `Tools, Spaces, Robots and Widgets` (97 uses) have
   no generic child to land on, while Repair has General and Business has General
   Business. Together **25.5% of all term use**.
2. **The issue body's evidence line is off by one rank.** "General is the #1
   destination for new topics" — it is #2 (40 new topics/365d) behind Acoustic Repair
   (87). Recorded in the measurement; does not disturb the ruling.
3. **6 mapping rows still need a human decision** (§1.3). Not inventing them: the
   whole measured point is that guessing here is what goes wrong.
4. **`Quick Questions` (#3876) holds 181 topics and is frozen** — newest topic
   2025-08-05, nonpostable by product decision. Not in scope, but any "busiest forum"
   claim that counts all-time totals will keep tripping over it.
5. **`scanners` is lowercase** where every sibling term is Title Case, and
   `shared_category` carries the typo slug `general-buisness` on the forum side and
   `shop-orginization` on the term side. Cosmetic; a normalising map hides them, and
   renaming a term is a content decision, not a lane's.
6. **14 postable forums are unreachable by any chip** — sponsor forums, Market Place,
   local chapters, Suggestion Box, the two "Share Your … Content" forums, Neck Reset
   Database. Correct by design (the map is one-directional and the picker is not the
   only door), listed so it is not later read as a gap.
7. **Two forum titles are identical across trees** (*Amps, Pickups, and Pedals*,
   *Folk, Bluegrass, Irish, Old Time Instruments*) and a chip resolving to one of them
   will read ambiguously in the "Lands in" line unless it is rendered with its parent.
   The mock shows the leaf only; worth a parent prefix in the real thing.
8. **The two-Generals naming collision and the Middle-Tennessee duplicate** (#58440
   publish / #58442 private, same title) are out of scope by ruling — folded into
   #127's rework.
9. **`shared_category` is `public` and `publicly_queryable` with rewrite slug
   `content_categories`.** Putting discussions into it therefore makes them appear on
   whatever that archive renders, for the first time. Worth checking before the flag
   flips ON; anon reachability differs dev2 vs live and has given opposite answers on
   two URLs before.
10. **`tools/lanes/comment-issue.sh` does not exist.** The charter names it for
    posting the measurement. I used the GitHub API directly with the same
    `LG_GITHUB_ISSUES_TOKEN` from `/etc/looth/env` that `approve-issue.sh` uses, to
    the same repo. Worth writing the helper so the next lane does not re-derive it.
