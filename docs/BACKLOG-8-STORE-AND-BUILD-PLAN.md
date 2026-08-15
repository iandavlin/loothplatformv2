# Backlog 8 — where the weekly email is stored, and the build that follows

**Lane:** `guitardle-fairness` (branch `front-weekly-email`). Written 2026-08-15,
between publishing the mock and Ian's ruling on it. **Nothing here is built.**

The charter's instruction was *"the weekly content comes from where the digest
already stores it — find that store, never re-render from scratch."* This is that
step, done to the point where the build can start the moment Ian picks an option.
Everything below was measured on **dev2 and on live**, not read off the code.

---

## 1. The one-line answer

> **The store is `LG_WD_Query::build_payload_from_issue()`, fed by
> `LG_WD_Issue::get_data( <latest sent issue id> )`.**

That is the same call the email itself goes through. It already resolves posts,
trims excerpts, folds in hand-typed items, builds hub-correct forum URLs and
attaches each item's membership tier. **Nothing about "what was in the week"
needs deriving again** — the front page's job is only to *fetch* and *draw*.

---

## 2. The raw store

| | |
|---|---|
| Post type | `weekly_email` (`LG_WD_Issue::POST_TYPE`) |
| Where the content lives | postmeta `_lg_wd_issue_data` (`LG_WD_Issue::META_KEY`) — **PHP-serialized**, so a LIKE-for-JSON query measures nothing |
| "Which is the latest?" | the newest issue whose `status` is `sent`. `LG_WD_Signup_Page::latest_sent_issue_id()` already does exactly this over the newest 20 and is the only implementation — but it is **`private`**, so the new endpoint cannot call it. **Promote it, do not copy it**: two "which issue is current" implementations that can disagree is precisely the drift this area keeps having. It arguably belongs on `LG_WD_Issue` beside `get_all_issues()` |
| Latest sent, live | **72626, Weekly Digest — August 10, 2026** (`sent_at` 2026-08-10 09:33:28, `campaign_id` 417) |
| Latest sent, dev2 | 72147, July 13 — **33 days stale.** dev2's July 30 issue is still a draft. Any dev2 verification of this feature is verifying month-old content; that is a data gap, not a defect |

Shape of `_lg_wd_issue_data`:

```
date_from, date_to, status, sent_at, campaign_id
sections[] = {
  key, label, slug, is_header, template,
  post_ids[]        # WP post ids, in display order
  manual_items[]    # hand-typed, NOT backed by a post
  html_content      # only when template == 'html-block'
  html_header
}
```

### The live August 10 issue, section by section — the real thing

| # | template | label | post_ids | resolves? |
|---|---|---|---|---|
| 1 | `header` | Upcoming Events | — | label only |
| 2 | `date-forward` | *(none)* | `72616` | **NO — see §4.2** |
| 3 | `header` | New To The Website | — | label only |
| 4 | `card` | Videos | `72618, 72612, 72603, 72517, 72513` | **5/5 yes** |
| 5 | `forum` | From The Forum | `72559, 72509` | yes, but see §4.1 |
| 6 | `card` | Loothcuts | *(empty)* | skipped |

---

## 3. What the resolver hands back

`build_payload_from_issue()` returns, per section,
`{section:{key,label,template}, items:[…]}`, and per item:

```
id, title, url, excerpt, thumb_url, date, post_type, type_label,
tier_slug, tier_label, author_name, author_url
```

Four properties of that payload matter more than the rest:

1. **`url` is already hub-correct for forum content.** `LG_WD_Query::hub_url()`
   turns a bbPress topic into `/hub/?topic=<forum>/<topic>` instead of the legacy
   BuddyBoss permalink, whose route to the Hub is a fragile
   nginx→bb-mirror→301 chain. A front-page block that built its own topic URLs
   would reintroduce that.
2. **`tier_slug` is present on every item.** This is what makes an anon-safe
   render possible at all — see §5.
3. **`manual_items` arrive folded in with `post_type: 'external'`.** They have no
   post behind them, so they cannot be looked up in the discovery index. Any
   render path that assumes "item ⇒ row in `content_item`" drops them silently.
4. **`html-block` sections arrive as one synthetic item** carrying raw
   `html_content`. Same warning.

---

## 4. Three things that would have broken a naive build

### 4.1 The `forum` section's ids are WP post ids — but the front page reads a different store

Forum topics **are** WP posts (`post_type='topic'`), so the resolver handles them.
But `72559`/`72509` are **not in `discovery.content_item`** — measured on live.
They live in `forums.topic`, which the front page reads through its own
`archive_poc_run_discussions()` and renders as `.dcard`, not `.rcard`.

So an issue's items span **two different front-page card types from two different
tables**. Taking the payload from the resolver (rather than re-looking-up ids
against `content_item`) sidesteps this entirely — which is the argument for the
endpoint in §6 over a direct index join.

### 4.2 The latest live issue contains an item that no longer exists publicly

`72616` — *Acoustic Guitar Builders Club: Brace Yourself* — is
**`post_status = 'archived'`**. `normalize_posts_by_ids()` asks for
`['publish','closed','open','archived']`, so **the resolver returns it**, and a
block that replayed the issue verbatim would publish a card pointing at archived
content, on the front page, to strangers.

**Decision: the front-page block filters `archived`.** Correct for the email (an
archive of what was sent) and wrong for a shop window (a claim about what is
there now).

### 4.3 The events section is structurally un-replayable

`discovery.content_item` carries **4 events on live, every one of them in the
future** (Aug 18 → Sep 9); on **dev2 it carries zero events at all**. The index
holds upcoming events, not past ones — correctly. So a week after the send, an
issue's `date-forward` section points at events that have happened.

**Decision: the front-page block skips the events section.** The front page
already has its own live "Upcoming events" row for both audiences; replaying a
week-old events list beside it would be both stale and duplicated.

---

## 5. The leak rule, and it is the one thing that must not be got wrong

The payload carries a **full excerpt for every item regardless of tier**, because
the email goes to people whose entitlement is decided at the click, not at the
render. The front page's anon path does the opposite: `.rcard` gates on
`content_item.tier` and drops the excerpt below the viewer's tier.

> **Excerpts for gated items must be stripped inside WordPress, before the
> payload leaves it — never hidden by the front page.**

An endpoint that emits gated excerpts and trusts the caller to hide them is one
CSS mistake away from a leak, and the mock's own measurements showed the front
page ships markup the gate cannot see inside. Strip at source; send `tier_slug`
so the block can draw the padlock.

The live August issue makes this concrete: of its five videos, **one is `pro`,
three are `lite`, one is `public`** — so four of five are gated for the visitor
this feature is aimed at.

---

## 6. The transport, and why it is not a direct database read

`archive-poc/web/index.php` **never loads WordPress** — it says so in its own
header ("No WP needed") and runs on its own FPM pool against a read-only
Postgres discovery index. Booting WP there would put a ~0.8s BuddyBoss bootstrap
on every anonymous front-page render, on a 2-core box.

The pattern already exists in the same file and is the one to copy —
`archive_poc_run_activity_strip()`:

- loopback `curl` to `https://127.0.0.1/wp-json/looth/v1/<route>` with an explicit
  `Host:` header
- cached to a file in `sys_get_temp_dir()`
- **stale-while-revalidate**: serve the cache instantly; if stale, `touch` the
  file *first* (so concurrent requests don't all queue — exactly one refreshes),
  then refresh after `fastcgi_finish_request()` so the WP call is off the
  visitor's critical path
- a failed refresh bumps the mtime and keeps serving the stale copy rather than
  hammering WP

**What has to be built on the WP side: one new `nopriv` route.** Measured: the
plugin registers **zero** REST routes and exactly **one** anon-reachable
endpoint — `lg_wd_email_preview` — which returns a rendered HTML email document,
not data. Every other handler is `wp_ajax_` admin-only. The new route wraps the
existing resolver, applies §4.2, §4.3 and §5, and returns JSON.

Register it as `wp_ajax_` + `wp_ajax_nopriv_` rather than as a REST route, for
the reason `LG_WD_Signup_Page::init()` already documents at length:
`admin-ajax.php` is routed by WordPress unconditionally, so it cannot be
intercepted by the strangler that owns `/` and needs no rewrite and no new
symlink. Two bugs in two days came from tying that preview to a routable page.

---

## 7. The build, once Ian has picked

Shared by both options:

1. **Flag** — `platform/config/weekly-front.php`, read by `@include`, exactly as
   `platform/config/featured-members.php` is read at `index.php:794`. **Not**
   `getenv()`: an FPM `env[]` is invisible to WP cron, and a `fastcgi_param`
   lands in `$_SERVER` only. Default OFF, and the OFF state must be a proven
   byte-identical no-op **with its own gate assertion** — that missing assertion
   is the failure class the house rules call out.
2. **Row, not a branch** — `rows.json` gains one row with `audience: "public"`.
   `index.php:233` already filters rows by audience, so "logged-out only" needs
   no new conditional. Add the layout to `$static_layouts` only if it should sit
   in the sidebar; it should not.
3. **The endpoint** (§6), with the archived filter, the events skip, and the
   excerpt strip.
4. **Fix the date bug on the way** —
   `class-lg-wd-email-builder.php:40` sets `$week_str = date_i18n('F j, Y')`,
   i.e. the render clock. Harmless on send day, wrong for every later re-render:
   dev2 currently serves the July 13 issue under *"Week of August 15, 2026"*,
   with the preview flag ON for Ian. Pass the issue's own `sent_at` in. Lines
   160 and 249 have the same shape.
5. **Red-first gate** — number **from keeper**, never minted here. Assertions:
   flag absent / OFF / ON as three separate states off the served page (so
   flipping the default needs no gate edit); anon sees the block, a member does
   not; **no gated excerpt in the served HTML** (the leak rule, asserted on the
   bytes); no archived item; the block's contrast clears AA in light and dark at
   both widths — `tools/preview/weekly-front-shots.py` already does that last
   one and can be lifted.

Option-specific: **A** needs the row layout in `_render-main-row.php` plus the
`.wkiss` component already drawn and measured in
`footer-mockups/weekly-front/panel.html`. **B** needs no new component — it
frames `admin-ajax.php?action=lg_wd_email_preview` — but inherits the framing
Ian rejected on 30 July, cannot follow dark mode, and adds a second full page
load to the front page.

---

## 8. Also worth fixing, found while measuring

The front page's only weekly-email affordance for a logged-out visitor is one
secondary text button reading **"Weekly email"**, inside the welcome video's
copy. It points at `/looth-group-weekly/`, which **302s a logged-out visitor to
`wp-login.php` (`action=bpnoaccess`)**. The standalone issue page `/weekly/` is
members-only in PHP and shows anon a sign-in card with no content (verified: 6
gate elements, zero issue content).

The public sign-up page **`/weekly-email-sign-up/` is open to anyone and answers
200**. Repointing that button is a config change and fixes a login wall on the
front page regardless of which option Ian picks.
