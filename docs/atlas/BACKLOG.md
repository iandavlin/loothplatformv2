# BACKLOG — work waiting for a free lane slot

The box runs ~5 lanes before RAM bites (3.8GB + 2GB swap; each lane ~400-500MB,
a headless engine another ~500-660MB). This file is the QUEUE for everything
that is ready to start but has nowhere to run. It is in the repo deliberately:
a queue that lives only in a keeper's context dies with that keeper, and we have
lost work that way.

**Rules.** Keeper owns this file. When a lane frees, take the top unblocked item.
Move finished items to DONE with the date. If an item is blocked, say *on what* —
"blocked" without a named blocker is how things sit for weeks.

---

## RUNNING (5/5 — full)

| Lane | Work | State |
|---|---|---|
| composer-p3 | mention mint fix | fix proven; owes fresh end-to-end (needs engine, RAM-blocked) |
| connections-backfill | 748 missing live connections | producing Ian's canary command (his 136 first) |
| reply-images | 6 images per reply | merge-ready; owes 422-under-real-request + composer-guard-under-real-finger |
| slug-backfill | craft gate repoint + slug dry-run | gate work; slug run blocked on live TSVs |
| weekly-recap | weekly digest recap + dedup | BUILT to Ian's four 07-28 rulings (fixed window, to-do list, empty=no email, two registers); suite 4/4; blocked only on a real send, which is Ian's |

## QUEUED — ready, no slot

1. **The 241 sweep** — those members were invisible to *everything* keyed on
   `wp_user_bridge` at cutover, not just connections. Inventory which other
   tables/features skipped them. *Assigned to connections-backfill in parallel;
   promote to its own lane if it grows.*
2. **`/vscode` tab badge** — inject a script into code-server via nginx
   `sub_filter` so the decision count lands on the editor tab itself. Needs
   MutationObserver (code-server rewrites its own title) and
   `proxy_set_header Accept-Encoding ""`. **Blocked on: a free serve window.**
3. **`looth-auth-issue.php` hand-rolled validator** — the last one not routed
   through `lg_dest_capture`. No backslash check, no auth-path check. Flagged by
   the login-destination lane as follow-on.
4. **BuddyBoss Pro SSO on live** — `redirect_to_last_location` bypasses the
   `login_redirect` filter entirely. Dormant on dev2, **unverified on live**.
   Needs a live check now that live-ro exists.
5. **Lane report publishing rule** — slug-backfill wrote two HTML reports
   straight into `/var/www/dev` as real files. Decide: docroot real-file (no repo
   twin, defensible) vs `webroot/` deployed by pull. **Needs an Ian ruling.**
6. **`/etc/looth/live-wp-keys.php` is `root:looth-dev 0640`** — this is the real
   cause of the long-standing "www-data wp-cli fatal", and it blocks lanes from
   minting WP cookies without root. Decide the correct group posture.

7. ~~**Events leave the events page too soon**~~ — Ian, 2026-07-28.
   **DIAGNOSED + FIXED** on branch `events-expiring-early`. **Root cause: the
   timezone/UTC boundary** — of the three candidates listed here it is that one,
   and it is *not* an end-date problem (there is no end-date field in the data
   model at all: `event` posts carry only `events_start_date_and_time_` + a free-text
   `time_of_event`).
   `gmdate('Ymd')` produced a **UTC** calendar day and compared it against a date an
   editor typed in **site-local** time, so every event fell out of `upcoming` at
   **20:00 America/New_York** (19:00 in winter) — 4 hours before its own day ended.
   **Live proof:** event **72327** "Frank Brothers process and shop tour", start
   `20260727` at **8:00 pm**, was already absent from the rendered `/events/` page at
   22:14 local — it vanished at 20:00, *the exact minute it began*.
   Same bug in **three** places (`events/lib/events-query.php`,
   `lg-events-shortcode.php`, `lg-patreon-stripe-poller/src/Wp/UpcomingEvents.php`),
   while `lg-weekly-digest/includes/class-lg-wd-query.php` already had it right with
   `current_time('Ymd')`. Three occurrences ⇒ gated: `tools/gates/event-date-tz-gate.sh`,
   now GATE 6/6 (proven RED on the pre-fix tree, GREEN after).
8. **Everything belongs in the monorepo** — Ian, 2026-07-28: "everything should
   be in mono repo, that's why all the symlink". Standing principle, not a task:
   if a served file is not in the repo and traceable to a commit, it does not
   exist. Audit for anything still living outside it. Known offenders: the two
   slug report HTMLs written straight into `/var/www/dev`, and the keeper badge
   page under `~/projects/footer-mockups/`.

## PROCESS NOTES

- **Push with `git push origin main:main`, never `HEAD:main`.** On 2026-07-27
  keeper was still on a temporary integration branch when it pushed a docs
  commit with `HEAD:main`, which silently merged two feature branches into main
  hours before Ian approved them. Nothing reached the serve or live, but the
  explicit refspec would have failed loudly instead of quietly succeeding.
- **Restore with `git checkout HEAD -- <path>`, never the bare form.** The bare
  form restores from the INDEX; on a shared clone it installs another process's
  staged work and exits 0. Measured 2026-07-27.

9. **Mobile needs the desktop search-select for forum posting** — Ian,
   2026-07-28: "I would like to replicate the search select for forum posting
   that is on dt on the mobile." The searchable forum/destination picker that
   desktop shows when composing a forum post does not exist on mobile; mobile
   gets a lesser control (confirm which — a plain select, or no chooser at all).
   Bring the desktop one across.
   *Recorded as Ian said it plus keeper's reading of it — CONFIRM the actual
   desktop control and the actual mobile control before building. Composer v2
   phase 3 unified the composer across surfaces, so this may now be a matter of
   pointing mobile at the existing component rather than writing a second one.
   Ask Ian for a screenshot if the surface is ambiguous — he decides from
   pictures.*

## BLOCKED — named blocker

- **slug-backfill live dry-run** — blocked on the two live TSV exports. *May now
  be unblocked: live-ro exists as of 2026-07-27.*
- **composer-v2-p3 + reply-images-count merge** — blocked on composer-p3's fresh
  end-to-end, then Ian's word.
- **login-destination merge** — needs its new mu-plugin symlink created in the
  *same window* as the pull, or `/activity/` goes dark.

## DONE

- 2026-07-27 — live-ro restored on dev2 (ProxyJump via dev1, read-only key only).
- 2026-07-27 — stranded `threadfollow-spec` commit rescued off dev1 and pushed.
- 2026-07-27 — three serve windows granted and closed, all restores verified.
