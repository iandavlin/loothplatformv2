# WEEKLY-DIGEST-RECAP

> **Status:** BUILT, verified on dev2, **awaiting Ian's answers to §4** and a keeper window for
> the one unexercised leg (§7). Nothing is deployed; no campaign left draft; nothing was sent.
> **Lane:** weekly-digest-recap (branch `weekly-digest-recap`). **Box:** dev2. **Date:** 2026-07-27.
> Cross-refs: NOTIFICATIONS-AUDIT.md (**stale in one important way — see §2**),
> THREAD-FOLLOW-SPEC.md §2.6b (**overlaps this lane — see §6**), OPERATOR.md, SYSTEM-MAP.md.
>
> **Preview:** `https://dev2.loothgroup.com/mockups/wd-recap/` (dev gate; claimed browser).

---

## 0. What this is

The dynamic per-member section of the weekly digest — a personal recap of what happened to
*that member* this week, inside the digest they already get.

Ian's ruling (2026-07-25), which is as much about what we do NOT send:

- **No daily or per-event notification email, ever.** Real-time is the bell only.
- The email channel is **this one section**, in the weekly digest.
- **Counts and senders with deep links — never content.** "3 replies on your thread" and who,
  not the reply text. This is a privacy ruling, not a style one.
- BuddyBoss subscription emails stay permanently off. Nothing here is fed from them.

And (2026-07-27): **"your week" = UNREAD, last 7 days.** Not a replay of what they already read
in the bell or cleared off the DM badge, and never resurfacing old news.

---

## 1. Shape

```
  profile_app (PG)                  loopback, shared secret            WP / looth-dev
  ┌───────────────────┐             ┌──────────────────────┐          ┌────────────────────────┐
  │ notifications     │  Recap::    │ POST /profile-api/   │  curl    │ LG_WD_Recap_Source     │
  │  (unread, ≤7d)    │─forWpIds()─▶│   v0/internal/recap  │◀─────────│  ├ smart code callback │
  │ message_recipients│             │  {wp_user_ids[],days}│          │  ├ hydrate titles      │
  │  (unread_count)   │             └──────────────────────┘          │  │   (get_the_title)   │
  └───────────────────┘                                               │  └ LG_WD_Recap::render │
                                                                      └───────────┬────────────┘
                                                                                  │
                       FluentCRM campaign (ONE body, design_template: raw_html)   │
                       …<p>intro</p> ##lg_recap.section## <curated sections>… ◀───┘
                                    ▲ substituted PER RECIPIENT at send
```

| Piece | File | Role |
|---|---|---|
| Query | `profile-app/src/Recap.php` | unread + window, batched by wp id. Owns the "your week" definition. |
| Endpoint | `profile-app/api/v0/internal-recap.php` | loopback + shared secret, read-only. Read twin of `internal-notify.php`. |
| Route | `platform/nginx/strangler-profile-app.conf` | `^/profile-api/v0/internal/recap/?$` — **written, not yet live (§7)** |
| Fetch + seam | `lg-weekly-digest/includes/class-lg-wd-recap-source.php` | smart code registration, loopback client, title hydration |
| Render | `lg-weekly-digest/includes/class-lg-wd-recap.php` | rows → HTML. Enforces "never content" and "empty means absent". |
| Token | `lg-weekly-digest/templates/email.php` | emits `##lg_recap.section##` after the intro rule |
| Mode plumbing | `class-lg-wd-email-builder.php`, `class-lg-wd-sender.php` | resolve/strip the token on every non-campaign path |

---

## 2. What the notification store does and does not know

**Read this before designing anything that reads the bell. NOTIFICATIONS-AUDIT.md is a month
stale in exactly the way that matters, and the next person will otherwise assume less.**

That audit (2026-06-27) records `profile_app.notifications` as knowing about **connection
request/accept only** — its `message` type built but never written, Hub replies/mentions/reactions
notifying nobody (audit rows 6 and 7). A recap built on that description would say "you have 1
connection request" and nothing else.

**Since 2026-07-12 that is no longer true.** The notifications lane shipped `lg-shared/notify-bridge.php`
and `Notifications::pushHubEvent()`, and the store now also carries:

| Type | Written by | Carries |
|---|---|---|
| `forum.reply_to_topic` | notify-bridge, from bb-mirror's reply path | actor, `actor_count`, topic id, deep link |
| `forum.reply_to_reply` | ditto | + `anchor_id` = the reply |
| `forum.mention` | ditto (`lg_notify_find_mentions`) | + `anchor_id` |
| `reaction.on_post` | notify-bridge, from archive-poc's `card-react.php` | `target_kind` ∈ topic/reply/card |

Three properties of those rows are what made this lane cheap:

1. **`target_url` is already the canonical deep link.** The bridge stamps
   `/hub/?topic=<forum>%2F<topic>[&reply=<id>]` — the query-param modal form, not a legacy
   `/groups/` permalink — at the moment the event fires. **We reuse it. We did not build a
   second link system**, and we did not need a query layer over `forums.*` / `discovery.*` to
   reconstruct links.
2. **`actor_count` is already coalesced.** `pushHubEvent`'s upsert merges a second actor on the
   same target into one row and increments the count, scoped to UNREAD rows. "Ian Davlin and 1
   other" is read straight off the row; the recap does no batching of its own.
3. **`is_read` is real and maintained** by the bell UI, which is what makes "unread" a
   meaningful filter rather than a synonym for "recent".

**What it still does NOT know:**

- **DMs.** profile-app deliberately does not ring the bell for a new message ("no double-notify"),
  and the `message` notification type remains dead code. Unread DMs are therefore read separately
  from `message_recipients.unread_count`, and merged in by the renderer. This is a permanent
  split, not a gap to be closed by this lane.
- **Anything before 2026-07-12.** The bridge does not backfill. The first digests will be thinner
  than steady state, and on dev2 specifically there is barely any material at all (§5).
- **Whether a thread is public.** Rows carry no visibility flag; see §4 Q1.

**What WordPress cannot do:** read any of it. The WP pool runs as `looth-dev`, which holds **zero**
grants on the `profile_app` database (`select count(*) from information_schema.role_table_grants
where grantee='looth-dev'` → 0). That is precisely why notify-bridge POSTs over loopback instead
of writing PG, and it is why the read side is an endpoint and not a SELECT. Granting `looth-dev`
SELECT was considered and rejected: one lane should not punch a hole in the store-ownership
boundary the bridge deliberately maintains, to save a loopback call on a weekly cron.

---

## 3. The per-user seam (Ian's decision 1) — surveyed, then proven

The brief said: survey FluentCRM's smart-code API on the box first; if it cannot do this with a
per-subscriber callback, **stop and report** rather than inventing a second sender. It can.

The digest sends as ONE campaign with `design_template: raw_html` and a single `email_body`
(`LG_WD_Sender_FluentCRM::send`), so per-user content cannot be baked at compose time. Three
findings, in the order they had to be checked:

1. **A per-subscriber callback exists.** `FluentCrmApi('extender')->addSmartCode($key, $title,
   $codes, $callback)` (`Api/Classes/Extender.php:84`) registers
   `fluent_crm/smartcode_group_callback_<key>`, which `ShortcodeParser` dispatches to for any
   unknown group (`Parser/ShortcodeParser.php:160`) with `($code, $valueKey, $default, $subscriber)`.
2. **It runs per recipient.** `CampaignEmail::getEmailBody()` applies
   `fluent_crm/parse_campaign_email_text` to every CampaignEmail row
   (`Models/CampaignEmail.php:277`), and that filter is `Parser::parse($text, $subscriber)`
   (`Hooks/filters.php:27`).
3. **`raw_html` bypasses the per-campaign body cache — and this was the real risk.** For block
   templates, `getParsedEmailBody()` memoises ONE parsed body per campaign in a static
   (`CampaignEmail.php:405-415`), which would have baked one member's recap into everyone's
   email. For `raw_html` the code takes `$this->campaign->email_body` fresh per row
   (`:259-261`) and never consults the static. **The digest sends raw_html, so we are on the
   safe side of that branch — but anyone who later "improves" the digest onto a block template
   silently breaks per-user rendering.** That trap is recorded in the class docblock.

**Known limit, cosmetic:** FluentCRM caches the click-tracking URL map per campaign when the body
has no conditionals (`getCampaignUrls`, `:455-459`), so per-recipient recap links are **not
click-tracked** — the map is built from whichever recipient rendered first. The links themselves
are correct and work for everyone; only the click metric is missing. Not worth defeating the
cache for.

**Token discipline.** `##lg_recap.section##` reaches FluentCRM only on the campaign path.
`LG_WD_Email_Builder::build()` takes a mode (`token` | `render` | `strip`) and **defaults to
`strip`**, so a forgotten mode fails safe instead of putting literal `##lg_recap.section##` in
front of a member. Preview renders the previewing admin's own recap; a test send renders the test
recipient's if that address is a member; the wp_mail fallback resolves for its single recipient.

---

## 4. Open questions for Ian — drawn, not argued

All three are rendered against the same real data at
`https://dev2.loothgroup.com/mockups/wd-recap/` (frames A–D). Recommendation first, one
alternative each.

**Q1. May discussion titles appear?**
**A (recommended)** — name the discussion: *2 new replies on your discussion — "Suggest an
alternative to concave fret file" — Doug Proper and 1 other.*
**B** — counts and senders only, no title.
*Why A:* the title is what makes the row worth the tap, and a public forum title is already
public — it is on the Hub, in search, and in the digest's own "From the Forum" section. It is not
message content, which is what the ruling forbids. **The catch:** a private-forum or tier-gated
title is *not* public and the recap has no idea which is which (§2). If A, titles get gated on
`forums.forum.visibility = 'public'` and fall back to B's wording otherwise — a small addition to
`Recap.php`, not a redesign. **This is the same question THREAD-FOLLOW-SPEC.md §6 Q3 asks; one
answer settles both lanes.**

**Q2. Per-row deep links, or one "Open the Hub" button?**
**A (recommended)** — every row links to its own target.
**C** — rows inert, one gold "Open the Hub →" button.
*Why A:* the brief asked for deep links and the links already exist (§2). **Conflict to be aware
of:** THREAD-FOLLOW-SPEC.md §2.6b proposes the opposite — one Hub link, "keeps the email inert."
Two lanes are pointed in different directions; A follows the brief given to this one.

**Q3. Top or bottom?**
**A (recommended)** — directly under the intro rule, above the curated content.
**D** — below the curated content, above the sign-off.
*Why A:* it is the only part of the email about *them*, and it is three or four lines.

**Decided without asking** (say if either is wrong): reactions are **in**, one row per thing
reacted to; and the section **caps at 8 rows** with a "N more updates waiting for you" tail
rather than running long or truncating silently.

---

## 5. Deep links, and the one that does not exist

- **Hub thread** — `/hub/?topic=<forum>%2F<topic>[&reply=<id>]`, taken verbatim from the row's
  `target_url`. Canonical query-param modal form; not `/hub/<forum>/<topic>/`, not a legacy
  `/groups/` or `/members/` permalink.
- **Card / post** — the row's stored path (e.g. `/post-type-videos/<slug>/`).
- **Profile** — `/u/<slug>`, from `users.slug` (PG is the authority; not `user_nicename`).
- Every link is absolutised at render (`home_url()`) and UTM-tagged through
  `LG_WD_Email_Builder::add_utm()`, so recap clicks are attributable like every other digest link.

**There is no URL that opens a DM thread.** The messages surface is a modal opened from the header
button (`data-lg-msg-link`, `site-header.php:478`); `social-modals.js` reads no query param and no
hash — verified, not assumed. So a DM row points at the **sender's profile** (`/u/<slug>`), which
is a real page one tap from "Message"; a group thread has no single profile to point at and
renders unlinked rather than arbitrarily picking a peer.

**The fix, for whoever wants it:** a `?dm=<thread-uuid>` trigger in `social-modals.js` that calls
the existing `openThread()`. The pattern is already established on the box — `bottom-nav.js`
handles `#search` (:854) and `?compose=1` (:1353). It is a Hub change, not a digest change, so
this lane flagged it rather than doing it. When it lands, `rows_from_dms()` swaps one line.

---

## 6. Relationship to the thread-follow lane

THREAD-FOLLOW-SPEC.md (`threadfollow-spec` @c7b8099) §2.6b designs a digest recap too. The lanes
must not ship two of them. How they line up:

| | thread-follow §2.6b | this lane |
|---|---|---|
| Scope | replies in threads you **follow** | **everything unread**: replies, mentions, reactions, DMs, connections |
| Source | `forums.forum_subscription` ⋈ replies, via a new `bb-mirror-api/v0/follow-recap` | `profile_app.notifications` + `message_recipients`, via `internal-recap` |
| Links | one "Open the Hub" | per-row deep links (§4 Q2) |
| Titles | open question (§6 Q3) | same open question (§4 Q1) |
| Per-user mechanism | "**Feasibility = dev2 verify**" | **answered: it works** (§3) |

**The unifying fact:** thread-follow's proposed `forum.followed_topic` is just another
`Notifications::pushHubEvent()` type. The moment it is written, **this recap picks it up for free**
— no second query, no second endpoint, no second section. The recommendation is that thread-follow
drops its own `follow-recap` endpoint and lets the bell be the one spine, which is also what
NOTIFICATIONS-AUDIT.md §4.3 argues for.

**Preferences.** This section is part of the weekly digest, so the **digest toggle governs it** and
this lane invents nothing. The account page's Weekly Digest switch (bf9e3a1) is FluentCRM **list
membership** (list 3, via `lg_weekly_member_state` / `lg_weekly_member_toggle`), and the campaign
resolves its recipients from that list — so a member who turns the digest off never renders a
recap, automatically. Note for the record: the brief referred to thread-follow §6 proposing
"account = master switch per KIND of email"; the spec at c7b8099 does not contain that — §7
explicitly defers the prefs matrix as future work ("audit §4.2's prefs matrix remains future
work"). So there is no competing model to collide with today, and no new settings row was added.

---

## 7. What was verified, and the one leg that was not

Run on dev2, read-only, nothing sent, no campaign created.

| Check | How | Result |
|---|---|---|
| Smart code substitutes per subscriber | `Parser::parse()` on 3 real subscribers with a stub callback | 3 distinct bodies |
| `Recap::forWpIds` against real PG | run as the `profile-app` role, 6 wp ids incl. an unbridged one | matches `psql` ground truth; unbridged correctly absent |
| PG `text[]` parsing | 8 literals incl. embedded comma, `\"`, `\\`, NULL | 8/8 |
| Endpoint, real FPM pool | `cgi-fcgi` → `/run/php/php8.3-fpm-profile-app.sock` | 200 + correct JSON |
| Endpoint negative paths | bad secret / no ids / 501 ids / bad JSON | `forbidden`, `missing_wp_user_ids`, `too_many_ids`, `invalid_json` |
| Window is real | same call at `days:7` vs `days:365` | 7d returns empty for members whose activity is older |
| **Per-recipient, end to end** | ONE campaign body → `Parser::parse()` for **5 real subscribers** | **5 of 5 distinct**; token left in none |
| **Empty means absent** | wp:1170 (nothing this window) | no section; body **byte-identical** to the no-recap body |
| Never content | rendered sections vs. the verbatim stored text of the replies members were notified about | no stored text, no prose markup, in any section |
| The artifact, looked at | real email HTML rendered in headless Chrome at 390 / 720 | correct at both widths |

**NOT verified — the one leg:** `curl → nginx → FPM` for `/profile-api/v0/internal/recap`. The
location block is written in `platform/nginx/strangler-profile-app.conf` but is **not live on
dev2**: that file is symlinked from the serving checkout, so activating it touches the shared
serve and needs a keeper window. The endpoint PHP itself was exercised through the real pool via
`cgi-fcgi` (auth, validation, query, JSON all real); only nginx's routing of the path is
unexercised, and that block is a copy of the `/notify` block already in production use.

**Also not done, deliberately:** no test email was sent. Sending on dev2 means defeating three
locks, the third of which (`FLUENTMAIL_SIMULATE_EMAILS`) produces a convincing false positive —
`wp_mail` returns true and the log says `status=sent` while nothing leaves the box. The
substitution proof above does not need mail: it exercises the same `Parser::parse()` the mailer
calls. A real inbox test should happen in the same window as the nginx leg, to Ian's address only.

---

## 8. Deploy notes

1. `lg-weekly-digest` on dev2 is a **symlink to the serving checkout**
   (`/var/www/dev/wp-content/plugins/lg-weekly-digest → ~/loothplatformv2-clean/lg-weekly-digest`),
   so the plugin deploys with a normal pull. It was a real-directory copy at one point — check
   `ls -la` before assuming, on any box.
2. `platform/nginx/strangler-profile-app.conf` is symlinked from the serving checkout too, so the
   new location arrives with the pull, but needs `nginx -t && systemctl reload nginx`.
3. `profile-app/src/Recap.php` is loaded from `config.php`'s explicit `require_once` list (there
   is no autoloader) — the line is added; a new class without it 500s.
4. After repointing anything under `/srv/*`, `systemctl reload php8.3-fpm` — opcache and the
   realpath cache pin the old target.
5. The digest is still **double-off** (`enabled=false`, `cron_mode=draft_and_notify`) and the
   campaign path leaves campaigns in `draft`. Turning the digest on is a separate, deliberate act.
