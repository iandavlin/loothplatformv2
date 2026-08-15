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
tier_slug, tier_label
```

**There is no author on a post-backed item.** `normalize_post()` emits exactly
the ten fields above; `author_name` / `author_url` appear **only** on
`manual_items`. The front page's `.rcard` puts the author in `.rcard__meta` and
the mock's cards show it, so the block must take the author from
`discovery.content_item.author_name` (where it already is) or drop the line —
it will not arrive with the payload. Reading a field that is never set is the
kind of thing that renders as a quietly missing byline rather than an error.

Five properties of that payload matter more than the rest:

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

## 5. Tiers: a slug mismatch, and where the excerpt risk actually is

### 5.1 `tier_slug` is NOT the value the front page's classes expect

Measured on dev2 — the `tier` taxonomy's terms are **`looth-lite`, `looth-pro`,
`public`**, and a post with no term at all comes back as `''`. So
`build_payload_from_issue()` emits:

| payload `tier_slug` | what `discovery.content_item.tier` says | what `.rcard` / `.badge` expect |
|---|---|---|
| `looth-lite` | `lite` | `lite` |
| `looth-pro` | `pro` | `pro` |
| `public` or `''` | `public` | `public` |

**Map it explicitly.** Passing `tier_slug` straight into
`badge--<tier>` / `rcard--gated-<tier>` yields `badge--looth-lite`, which
matches no rule in archive.css — an unstyled badge and, worse, a **gated card
with no padlock**, because `.rcard--gated-lite` is what draws it. It fails
silently and looks like a card that simply is not gated. Verified on the dev2
issue: three of its five items come back `looth-lite`, two come back `''`, and
the discovery index calls those same five `lite,lite,lite,public,public`.

### 5.2 The excerpt risk is real, but it lives in ONE section

I had assumed the payload carries a full excerpt for every item regardless of
tier. **Measured, that is not what happens**, and the true picture is more
useful:

| section kind | `post_content` | excerpt in payload |
|---|---|---|
| `card` — videos, loothprints, articles | **empty** (0 bytes; layout-v2 posts keep their content in `_lg_layout_v2` meta, videos are embeds) | **none** — every item on the dev2 issue came back `strlen 0` |
| `forum` — topics | **real prose** (measured on live: 547 and 240 bytes) | **yes** |
| `manual_items` | n/a | yes — hand-typed |

So the rule stands, but pointed at the right place:

> **The `forum` section and `manual_items` are the only things in an issue that
> carry prose. Any gating must be applied to those, inside WordPress, before the
> payload leaves it.**

And note what this does NOT license: an "excerpts are always empty so gating is
moot" conclusion would be a `clean_excerpt()` change away from false. Strip at
source and send the mapped tier; do not rely on the store happening to be empty.

Helpfully, the front page's existing discussion path already does the right
thing for forum items — `archive_poc_run_discussions()` masks member-visibility
authors for anon and drops gated excerpts below the viewer's tier. Rendering the
issue's forum section through that existing path, rather than through a new one,
inherits the masking instead of re-implementing it.

For scale: of the live August issue's five videos the index calls **one `pro`,
three `lite`, one `public`** — four of five gated for exactly the visitor this
feature is aimed at.

---

## 6. The transport, and why it is not a direct database read

`archive-poc/web/index.php` **never loads WordPress** — it says so in its own
header ("No WP needed") and runs on its own FPM pool against a read-only
Postgres discovery index. Booting WP there would put a ~0.8s BuddyBoss bootstrap
on every anonymous front-page render, on a 2-core box.

The pattern to copy is `archive_poc_run_activity_strip()`, in the same file —
**but copy it knowing it has never actually run.** Measured 2026-08-15: the
`activity-strip` layout appears **zero** times in live's `archive-poc/config.json`
and is absent from dev2's rows, and there is **no `lg_actstrip_*` cache file on
either box**. It is a well-reasoned, well-documented, entirely unexercised code
path. Its design is still right and its docblock states real costs — the loopback
REST call measured **0.54s** on dev2 just now, which is why it must not sit on
the render path — but "this already works here" is not available as evidence.
**Whoever builds this owns proving the caching behaviour**, not inheriting it.

Two consequences for the cache file specifically:

- `PrivateTmp=no` on the FPM service (measured), so `sys_get_temp_dir()` is the
  **real, shared** `/tmp` — shared with every other pool and every lane on the
  box. Name the file so it cannot collide, and handle the case where it exists
  but is owned by another user and therefore unwritable: that fails silently
  into "serve nothing" or "serve forever-stale".
- A first-load miss fetches **synchronously**, so the very first anonymous
  render after a cache flush pays the 0.54s. Acceptable once; make sure the
  failure path bumps the mtime rather than retrying per render.

The shape to copy:

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
   not; **no gated excerpt in the served HTML** (§5.2, asserted on the bytes);
   **a `lite`/`pro` item renders WITH its padlock** — this is the assertion that
   catches the §5.1 slug mismatch, which otherwise fails silently as a gated
   card that merely looks ungated, and "the badge is present" would not catch it;
   no archived item; the block's contrast clears AA in light and dark at both
   widths — `tools/preview/weekly-front-shots.py` already does that last one and
   can be lifted.

Option-specific: **A** needs the row layout in `_render-main-row.php` plus the
`.wkiss` component already drawn and measured in
`footer-mockups/weekly-front/panel.html`. **B** needs no new component — it
frames `admin-ajax.php?action=lg_wd_email_preview` — but inherits the framing
Ian rejected on 30 July, cannot follow dark mode, and adds a second full page
load to the front page.

---

## 8. A live defect found while checking my own recommendation — and dev2 lied about it

I had written this section from dev2 alone, and dev2 gave the **opposite** answer
to live. Measured on both, as anon, on the box (never a plain public curl —
Cloudflare bot-challenges that into a 403 that reads as an outage):

| anon request | dev2 | **live** |
|---|---|---|
| `/looth-group-weekly/` — where the front page's one weekly button points | **302 → wp-login** | **200** |
| `/weekly-email-sign-up/` — the public sign-up page | **200** | **302 → wp-login** |
| `/weekly/` — the standalone issue page | 200, but the members gate shell, zero content | same: 200, `lg-wk__gate` ×6, zero content |

So my original §8 — "the front page's weekly button is a login wall" — is **true
on dev2 and false on live**, and the thing that is actually broken is the one I
had recommended as the safe CTA target.

### The live defect

> **`/weekly-email-sign-up/` — the page built so that someone WITHOUT a WP
> account can subscribe — bounces logged-out visitors to `wp-login.php`.**

It is `post_status = publish` on live (page 68595). The bounce carries
`bp-auth=1&action=bpnoaccess`, BuddyBoss's private-network refusal. The lever is
`wp_options.bp-enable-private-network-public-content`, a newline-separated
allow-list of publicly reachable URLs — **67 entries on live**. It contains
`https://loothgroup.com/looth-group-weekly/`, which is exactly why that one
answers 200. It does **not** contain `/weekly-email-sign-up/`.

That is the whole of it: **one missing line in a 67-line list**, and the entire
public sign-up funnel — Ian's 29 July ask, the four audience states, the
non-member list that ruling 6 is built around — is closed to the only audience it
was written for. Nobody without an account can reach it.

(`bp-enable-private-network` itself reads `0`, which does not obviously square
with the behaviour; BuddyBoss's naming for that toggle is inverted in places.
The correlation above is measured and unambiguous, so the allow-list is the
lever to pull — but pull it through the BuddyBoss admin UI rather than by
hand-editing the option, and confirm the toggle's sense there.)

**Whose job:** a live change, so **Ian's**. It needs no deploy and no code.

**What it means for this build:** the CTA in both options points at
`/weekly-email-sign-up/`. On live that link is currently a login wall, so **the
allow-list entry has to land before this feature is switched on**, or the block
ships a sign-up button that cannot be signed up through. On dev2 it already
works, which is precisely the shape of thing that gets verified green here and
fails there.
