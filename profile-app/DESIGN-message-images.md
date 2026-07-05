> **REBUILD NOTE (2026-07-05).** The original `message-images` branch (@eaa972e, off main
> @305640a on the old dev2) was LOST unpushed in the box rebuild. This branch is a faithful
> reconstruction from the dev1 transcript (session 1e890f0c), re-based onto current main.
> The "dev2 serve topology" notes below describe the OLD box and are stale — the new dev2
> serves /srv/profile-app and /srv/lg-shared as symlinks into ~/loothplatformv2-clean.

# Lane: message-images — image attachments in DMs, on a dedicated R2 bucket

Status: DESIGN (explore-first). Built nothing live yet. Branch `message-images` off main @0a5f34c.

## What exists today (explored, not assumed)

**Storage (postgres `profile_app`):**
- `message_threads(id, uuid [gen_random_uuid, opaque], subject, created_at, last_message_at, bp_thread_id)`
- `message_recipients(thread_id, user_uuid, unread_count, is_deleted, last_read_at)`
- `messages(id, thread_id, sender_uuid, body text NOT NULL, created_at, bp_message_id)`

**API (profile-app/api/v0):** `me-messages.php` (list + new DM), `me-thread.php` (one thread + reply).
Backend `src/Messaging.php`. Message wire shape: `{id, sender_uuid, body, created_at}`.
Send is JSON `{body}` / `{to_uuid, body}`. Connections-only gate (`Connections::canMessage`) on new DMs;
every write asserts `isRecipient`. Auth = `looth_id` JWT cookie → `Auth::currentUser()` → uuid.

**Existing profile-media → R2 path (the pattern to mirror):**
- `src/R2.php` — lean SigV4 S3 client over curl (no aws-sdk). Config from `/etc/looth/profile-r2`
  (640 root:profile-app) or pool env: endpoint/bucket/prefix/key/secret. `R2::put/get/delete/exists`.
  Hardwired to the `LG_PROFILE_R2_*` (profile) bucket.
- Upload endpoints `me-avatar.php` / `me-gallery.php`: multipart, `getimagesize` type check
  (jpeg/png/webp), 5MB cap, `ImageOptimize` (Imagick → WebP q82, EXIF auto-orient, cap dim),
  `R2::put` then `R2::exists` verify, store URL on the row. Rate-gated `profile_app_rate_gate('upload:'+uuid,30,300)`.
- Serve `web/media.php` via **auth-subrequest + X-Accel-Redirect** (the key pattern):
  nginx `/profile-media/<class>/<uuid>/<file>` → internal `/profile-media-auth` (media.php) →
  `Visibility::fileVisible()` decides → on allow X-Accel to `/profile-media-internal/` alias
  (or stream R2 bytes); **denials answer 404** so a gated file can't be probed. Optional `?w=` resize,
  cached LOCALLY, served via the same X-Accel.

**R2 account / creds (lg-secrets-helper):** account endpoint
`https://2b34fc01f7fc32230a76c1490ac64b13.r2.cloudflarestorage.com`. Profile dev bucket
`loothgroup-2-0-profile-dev` / live `loothgroup2-0-profile-bucket`, cred `cred-profile` (the dev R/W token).

**dev2 serve topology:** entrypoints served from `/srv/profile-app` (→ `~/loothplatformv2-serve/profile-app`);
`src/` classes load from APP_ROOT `~/projects/profile-app` (config.php dev2 branch). FPM pool user `profile-app`.
Deploy-to-test = copy changed files into the serve clone + `~/projects` (NEVER pull/reset serve clone).

## THE PRIVACY CALL (needs sign-off)

DMs are private. Profile avatars/banners are world-public; message images must NOT be. **Recommended:
auth-checked proxy** (mirror media.php), NOT public URLs and NOT bare signed URLs:

- Served URL: `/message-media/<thread-uuid>/<file>`. The thread-uuid is the access anchor — random,
  opaque (`gen_random_uuid`), not enumerable.
- nginx `/message-media/` → internal `/message-media-auth` → `web/message-media.php`:
  1. viewer = `Auth::currentUser()`; null → 404
  2. resolve thread by uuid; not found → 404
  3. `Messaging::isRecipient(viewerUuid, threadId)` false → **404** (never 403 — don't reveal existence)
  4. allow → `Cache-Control: private, no-store`, X-Accel local OR stream from the messages R2 bucket.
- Bucket is **private** (no r2.dev / no custom domain) — bytes only ever reach a browser through the
  authenticated proxy. Per-request re-check ties access to the live session.

Rejected **signed URLs**: bearer-capability (anyone with the URL inside its TTL reads it; leaks via
history/referer), needs presign plumbing in the lean client, and doesn't re-check participation.

## Design

**1. Dedicated bucket (Ian's requirement).**
- dev `loothgroup-2-0-messages-dev`, live `loothgroup2-0-messages-bucket` (mirror profile convention).
- **New scoped token** (Object Read & Write), scoped to the messages bucket ONLY — do not reuse
  cred-profile (isolates blast radius: a message-bucket token can't touch profile media).
- Config `/etc/looth/messages-r2` (640 root:profile-app). Register in lg-secrets-helper as `cred-messages`.
- New class `src/MessageR2.php` (or parameterize R2.php) reading `LG_MSG_R2_*` / `/etc/looth/messages-r2`.

**2. Schema.** Add nullable columns to `messages` (one image per message bubble, text optional —
matches chat UX, keeps the hot row thin, send stays atomic):
`media_url text, media_mime text, media_w int, media_h int`. (Alt: `message_media` table for multi-image —
heavier; defer.) Relax the empty-body reject when media present.

**3. Upload endpoint** `me-message-image.php`, multipart `image` + optional `body` + (`to_uuid` new DM |
thread-uuid reply). Reuses CSRF guard, rate gate, getimagesize, ImageOptimize (cap 1600 / WebP q82),
Connections gate. Store `R2::put('<thread-uuid>/<rand>.webp')` + verify, then insert message with
`media_url = /message-media/<thread-uuid>/<rand>.webp`. Routes:
`/me/messages/image` and `/me/messages/<uuid>/image`.

**4. Serve** `web/message-media.php` as above.

**5. Frontend (lg-shared/social-modals.js + site-header.php — the REAL lg-shared, not lg-shell twin):**
attach button + hidden file input + drag-drop + preview thumb in `#lg-msg-compose`; send multipart when
an image is staged; render `<img>` (media_url) in thread bubbles with body as optional caption; thread-list
preview shows "📷 Photo" for image-only last message.

**Proposed limits:** 5 MB; JPEG/PNG/WebP (HEIC rejected — IM build can't; phones send JPEG on web upload);
1 image/message (v1); reuse 30/5min upload rate gate.

## What I need from Ian (infra = your hands; I can't create the bucket — cf-api-token is Read-only)
1. Create dev bucket `loothgroup-2-0-messages-dev` — **private** (no public r2.dev domain, no custom domain).
2. Create an R2 API token: **Object Read & Write**, scoped to that bucket only. Hand me the **S3 Access Key
   ID + Secret Access Key** (the AK/SK pair — NOT the `cfat_…` management token).
3. Confirm same account endpoint (`2b34fc01…`) — assumed.
I wire it into `/etc/looth/messages-r2` + lg-secrets-helper, then build + verify on dev2. Live bucket/token = a
separate at-deploy step (your hands too).

## Open decisions
- [D1] Bucket names as above? 
- [D2] New dedicated token (recommended) vs reuse cred-profile?
- [D3] Auth-proxy serve (recommended) vs signed URLs?
- [D4] Limits: 5MB / jpeg-png-webp / 1-per-message — OK?
- [D5] Schema: columns on `messages` (recommended) vs `message_media` table?
