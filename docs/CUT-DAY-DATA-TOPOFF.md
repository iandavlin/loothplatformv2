# Cut-day data top-off (Ian 2026-06-11: "cutting close to complete with a backfill top off")

Dev already carries near-complete backfills. On cut day, re-run the same
idempotent scripts against live as a TOP-OFF, with the fixes below folded in
FIRST. This doc is the keep-list for that pass; Buck's full avatar audit
numbers + repro queries are archived on his side (msg 2026-06-11 17:02).

## 1. Avatar chain (Buck audit 6/11 — the "missing profile icons" root cause)

One root in three places: nothing reads usermeta `patreon-avatar-url`, although
1,632 users have a REAL Patreon picture on disk
(`/uploads/20xx/xx/patreon_avatar_<id>.jpg`, files verified present). The only
code with the right chain is `MembershipGuide::getElderAvatar` — reuse it.

Canonical chain everywhere: **BB upload → patreon-avatar-url → gravatar?d=mp →
brand asset**, always https. Never gravatar's default `d=` pointing at the
dev-gated bp image (gravatar can't fetch it → broken icon).

- **forums.person (Hub feed)**: 504 rows; 201 share the BB default avatar
  (`avatars/0/674d94a777132-bpthumb.jpg`) because `backfill.php` +
  `bb_mirror_person_for` use bare `get_avatar_url()`; 497/504 URLs are plain
  http:// = mixed content on https (THIS is the visibly-broken case).
  Pass: https-coerce all + re-resolve the 201 default rows via the chain.
- **profile-app users**: 294 of 667 avatar rows are gravatar URLs with the
  gated-bp fallback while a real Patreon pic sits on disk.
  Pass: re-run `bin/backfill-avatars.php` with the patreon step added.
- **Provision.php**: sets NO avatar on first login → every not-yet-provisioned
  legacy user (~1,143) lands blank. Fix: add the chain to `Provision::ensure`
  (this is the re-rot guard — without it the backfills decay).

Dev already ran the 496-row BB backfill (`tools/backfill-bb-avatars.sh`);
re-run at cut after the chain fixes land.

## 2. Legacy DM migration (`profile-app/bin/migrate-social-from-bb.php`)

Ran `--commit` on dev 6/11 (threads +434, messages +2091, recipients +723).
Fold these post-pass fixes INTO the script before the prod top-off:

- **Strip HTML on import**: BB bodies are stored HTML but social-modals.js
  renders `esc(body)` → members saw literal `<p>` tags. Dev fix was applied as
  a post-pass (2,010 rows + 35 amp-entities, pattern kept at
  `/tmp/strip-msg-html.sql` — NB /tmp wipes on reboot, the pattern is trivial:
  strip tags + decode entities on rows with `bp_message_id` provenance).
- **Unescape magic quotes**: 710 rows carried backslash-escapes
  (`Can\'t` style).
- **Unread badges (Ian ruled 6/11): zero anything older than 2 months.** Done
  on dev (36 rows zeroed, 15 recent kept). At the prod top-off, run after the
  import:
  `UPDATE message_recipients r SET unread_count=0 FROM message_threads t
   WHERE t.id=r.thread_id AND r.unread_count>0
   AND t.last_message_at < now() - interval '2 months';`
- `--seed-notifications` was NOT passed on dev; decide for prod.

## 3. Connections + the rest

- `tools/` BB friendships → connections backfill (3e4178c): re-run at cut
  (idempotent, skip_exists verified on dev's 10,377).
- Unbridged-user skips (62 DM, similar elsewhere) self-heal as users provision;
  one final re-run post-cut catches stragglers.

## 4. Standing at-cut items (cross-ref)

- F6 secret rotation + profile-api rate-limit (docs/at-cut security memory).
- profile-app renderLocation 2-decimal patch must ship at cut.
- Collapse strangler file ownership to www-data; postgres peer→password DSNs.
- members-geo: per-user privacy decision gates each pin (Ian 6/11 ruling) —
  endpoint must be live before the front-page Bento map tile un-gates.
