# Coordinator report — dev2 profile-app provisioning sweep (cut top-off list)

**Lane:** profile-app. **Date:** 2026-06-14. **Coordinator:** dev2-build chat (via Ian).
**TL;DR:** dev2's profile-app **DATA** is essentially complete (the PG dump carried it —
socials/connections/sponsors/messages/location/business all match dev). The real gaps
were **MEDIA FILES** (`/srv/profile-app-media` not bundled in Phase 1) and **two
host/slug DATA touch-ups**. All fixes are idempotent scripts on dev's `/.well-known/`;
Ian pastes on dev2. Each gap below is also a **cut-day top-off step**.

## ✅ EXECUTED ON DEV2 (Ian, 2026-06-14) — ALL GREEN
- **Media files:** pulled (sha OK, 696 files); serving-chain sample → **200** ⇒
  dev2's flat nginx ALREADY has the `/profile-media/` blocks — **no nginx deploy needed** (gap #2 closed).
- **Avatar host-fix:** relativized 1039 → 0 remaining. Post-fix samples — profile-media,
  patreon_avatar, AND wp-upload avatars all resolve **200** ⇒ the files (incl. patreon) ARE
  in R2 `loothgroup2-0` ⇒ **no null-to-initials needed** (gap #3b moot for now).
- **Slug-fix:** 0 bridged empty-slug users on dev2 (the 3 `slug_empty` are unbridged ghosts —
  no `/u/` access, benign). dev's 2 were a dev-only artifact.
- **Identity bridge:** 112 done earlier, GATE GREEN.
- **Coverage re-audit:** every count matches the dev baseline; `avatar dev.loothgroup` now 0.
- **dev2 profile-app is fully provisioned.** Media bundle deleted from dev `/.well-known/`
  post-pull (disk). The 6 scripts remain published for **cut-day reuse on the real prod box**
  (re-bundle media with one `tar` on cut day).

## Run order on dev2 (`ubuntu@…`, gate token in scripts)
```bash
curl -s https://dev.loothgroup.com/.well-known/dev2-coverage.sh        | bash   # 1. audit (compare to dev baseline, printed inline)
curl -s https://dev.loothgroup.com/.well-known/dev2-media-pull.sh      | bash   # 2. FILES: pull 17M media + verify serving chain
curl -s https://dev.loothgroup.com/.well-known/dev2-avatar-host-fix.sh | bash   # 3. DATA: relativize 1039 dev-host avatar URLs
curl -s https://dev.loothgroup.com/.well-known/dev2-slug-fix.sh        | bash   # 4. DATA: 2 empty-slug bridged users
# (dev2-bridge-fix.sh already run earlier — identity GREEN)
curl -s https://dev.loothgroup.com/.well-known/dev2-coverage.sh        | bash   # 5. re-audit, expect parity
```

## Gap list + classification

| # | Gap | Class | Close it | Verify |
|---|-----|-------|----------|--------|
| 1 | `/srv/profile-app-media` empty/partial on dev2 (Phase 1 bundled CODE, not media) — 502 `/profile-media/` avatars + 2 banners + 13 gallery + 1 resume all 404 | **FILES** | `dev2-media-pull.sh` (17M tgz, sha-checked, extract→chown profile-app→644) | sample `/profile-media/...` → **200** |
| 2 | nginx `/profile-media/` → `/profile-media-auth` → `/profile-media-internal` blocks may be absent (dev2 nginx is FLAT, not the git symlink) | **CONFIG** (cross-lane) | media-pull sample returns **404** ⇒ blocks missing ⇒ **flag for nginx deploy** | media-pull prints the verdict |
| 3 | 1039 `avatar_url` = absolute `https://dev.loothgroup.com/wp-content/uploads/...` (951 patreon_avatar + 88 other). PG dump carried the dev host; WP search-replace doesn't touch PG → 404 on dev2/prod | **DATA** | `dev2-avatar-host-fix.sh` relativizes (strip scheme+host) → host-agnostic, resolves vs R2 | sample patreon + non-patreon → 200/404 |
| 3b | The 951 are **patreon_avatar** (Ian 6/13: unused, decommission later). If they 404 after relativize (not in R2 `loothgroup2-0`), cut-correct fallback = null-to-initials | **DECISION (Ian)** | optional `UPDATE users SET avatar_url=NULL WHERE avatar_url LIKE '%patreon_avatar%'` | — |
| 4 | 2 bridged users with empty `slug` (their `/u/<slug>` + "My Profile" break) | **DATA** | `dev2-slug-fix.sh` (slug ← WP nicename) | empty-slug bridged → 0 |
| 5 | 112 real members missing `_looth_uuid` (Patreon, email-less → email-keyed backfill skipped) | **DATA** | `dev2-bridge-fix.sh` — **DONE, GATE GREEN** | missing_uuid = 0 ✓ |
| 6 | 274 gravatar.com avatars | external | needs dev2 outbound HTTPS to gravatar.com | n/a (external) |
| 7 | socials(218/125u), connections(10380), sponsor(5), practices(4), location(700 latlng/706 text), business(1517), profiles(668), threads(440), messages(2104) | **NO GAP** | PG dump carried them — coverage.sh confirms parity, **do not re-run** those backfills | coverage.sh diff |

## dev baseline (coverage.sh prints this inline for comparison)
```
users 1907 | avatar: pm=502 gravatar=274 devhost=1039 none=92
banner 2 | resume 1 | slug_empty 3(2 bridged) | latlng 700 | loc_text 706 | business 1517
socials rows=218 users=125 | connections 10380 | sponsor 5 | practices 4
profiles 668 | threads 440 | messages 2104
```

## Notes
- Media bundle `profile-app-media.tgz` (12M, sha `6e22908b…`) is on dev `/.well-known/` —
  **delete after Ian confirms the dev2 pull** (dev disk at 85%).
- All scripts idempotent + re-runnable; `WP_CACHE_KEY_SALT` wp-cli noise filtered out.
- Open cross-lane: gap #2 (nginx blocks on dev2) if the media sample 404s after pull.
</content>
