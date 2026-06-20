# R2-TOPOFF.md — pull missing dev media from the live R2 buckets

**Recurring workflow.** dev shows a blank/broken image (or any missing media) because dev's
R2 clone is missing a file that exists on live. The WP DB was cloned from live, so it
*references* files that were never copied into the dev clone. This is a **dev-data gap, not a
code bug.** "Top-off" = copy the missing file(s) from the live (read-only) bucket into the
dev clone bucket.

> Keeper note: R2 trips me up every time. Follow this literally; don't improvise. The #1
> mistake is concluding "no access" from a list-403 — scoped tokens 403 on list BY DESIGN.

## The buckets (the part I always get lost on)

| role | DEV bucket (read+write) | LIVE bucket (READ-ONLY) |
|------|------------------------|-------------------------|
| profile media (avatars/banners/gallery/resumes) | `loothgroup-2-0-profile-dev` | **`loothgroup2-0-profile-bucket`** |
| wp + forum uploads | `loothgroup-uploads-dev` | **`loothgroup2-0`** |

- The two LIVE buckets — **`loothgroup2-0-profile-bucket`** and **`loothgroup2-0`** — are
  **READ-ONLY from dev. Never write to them.** We only ever GET from live, PUT to the dev clone.
- Dev write token = rclone **`r2up`** (writes both dev buckets; works today).
- Live read token = rclone **`r2live`** (manifest cred `cred-live`). ⚠️ STATUS below.
- `loothgroup` (bare) = legacy/old bucket; the live system's uploads are in `loothgroup2-0`.
- Dev uploads are a FUSE mount: `/var/www/dev/wp-content/uploads` → `/mnt/loothgroup-uploads-dev`
  (the `loothgroup-uploads-dev` bucket). Writing to that bucket = it appears on dev.

## Creds — the part that keeps breaking

Live read = rclone remote **`r2live`** (cred `cred-live`, declared for the live buckets).

**STATUS 2026-06-20: `r2live` holds the WRONG token.** Its Access Key ID is
`d0b3676756eb67e37859780e7345c3bb`, and it returns a **bare `Forbidden: Forbidden` on EVERY
bucket** (incl. dev ones it should own) from dev2 → that's the IP-block/revoked signature
(see decode above), NOT a scope problem. So `d0b367` is an **old/revoked token**, not the
new `dev-read-only-live-buckets` token Ian configured (Object Read on `loothgroup2-0` +
`loothgroup2-0-profile-bucket`, IP-allow 50.19.198.38 + 54.146.118.131).

**To finish: get the NEW token's S3 credentials and put them in `r2live`.** The new token's
**Access Key ID + Secret Access Key** are shown in Cloudflare R2 → **Manage R2 API Tokens →
the token → "Use the following credentials for S3 clients"** (NOT the `cfat_` token value,
NOT a sha256 derivation). Then `rclone config update r2live access_key_id <AKID>
secret_access_key <SECRET>` and re-probe (expect `NoSuchKey`, not `Forbidden`).

**▶ FILL IN once a remote actually reads live (verified by the probe below):**
```
WORKING LIVE-READ REMOTE: __________      verified: __________ (date)
```

## Verify a token reads a bucket — NEVER trust list (it 403s by design)

Scoped R2 tokens ALWAYS 403 on listing (`lsd`, `lsf` on a bucket root, `ListObjectsV2`).
That is NOT "no access." Probe a **HeadObject on a nonexistent key** and read the error:
```bash
rclone cat <remote>:<bucket>/_zzprobe_nonexistent_$RANDOM 2>&1
```
**Decode the EXACT S3 error (don't lump them — they mean different things):**
- `404` / `NoSuchKey` / `object not found` → token CAN read this bucket (object just absent). ✅
- `AccessDenied` → key is valid + IP-allowed, but the token lacks permission/scope on this
  bucket/op. Fix = widen the token's bucket scope or permission.
- **bare `Forbidden: Forbidden`** (no S3 error code) on EVERY bucket, even ones it owns →
  **IP-block or revoked/deleted token.** Cloudflare denies *before* checking scope. Fix =
  add this box's egress IP to the token's allowlist, OR the token is dead → use a live one.
- `SignatureDoesNotMatch` → the **secret** in rclone is wrong (Access Key ID may be fine).
- `401 Unauthorized` → the Access Key ID itself is unknown/dead.

## Wiring a (new) R2 token into rclone — the part my instincts get wrong

**R2 S3 access = an Access Key ID + a Secret Access Key. These are NOT the same as the
`cfat_…` CF API token, and you CANNOT derive them from it (sha256 of the token is a myth that
won't authenticate).** When you create an R2 token in the dashboard, Cloudflare shows, under
**"Use the following credentials for S3 clients"**, an **Access Key ID** + **Secret Access
Key** — copy BOTH. Then:
```bash
rclone config update r2live access_key_id <NEW_AKID> secret_access_key <NEW_SECRET>
# verify with the object probe above; expect NoSuchKey on a random key (not Forbidden)
```
The `cfat_` token at `/etc/looth/cf-api-token` is management-only (it lists *buckets* via
`api.cloudflare.com`) — it can NEVER read object bytes. Don't reach for it here.

## The top-off procedure (run on dev2)

1. **Find the missing file's upload path.** From a blank featured image, given the post ID:
   ```bash
   cd /var/www/dev && sudo -u looth-dev wp db query "SELECT af.meta_value
     FROM wp_postmeta ti JOIN wp_postmeta af
       ON af.post_id=ti.meta_value AND af.meta_key='_wp_attached_file'
     WHERE ti.post_id=<POST_ID> AND ti.meta_key='_thumbnail_id'"
   # -> e.g. 2026/06/Bashkin-resaw-H-1.webp
   ```
   (General rule: any `/wp-content/uploads/<path>` that 404s → `<path>` is the object key.)
2. **Confirm missing on dev, present on live:**
   ```bash
   sudo test -f /var/www/dev/wp-content/uploads/<path> && echo dev-has || echo dev-MISSING
   rclone cat <LIVE-READ-REMOTE>:loothgroup2-0/<path> | wc -c      # >0 = live has it
   ```
3. **Copy live → dev clone (the actual top-off):**
   ```bash
   rclone copyto <LIVE-READ-REMOTE>:loothgroup2-0/<path> r2up:loothgroup-uploads-dev/<path>
   ```
   (Profile media instead: `loothgroup2-0-profile-bucket` → `loothgroup-2-0-profile-dev`.)
4. **Verify it serves:**
   ```bash
   curl -sk -o /dev/null -w '%{http_code}\n' -H 'Host: dev2.loothgroup.com' \
     https://127.0.0.1/wp-content/uploads/<path>      # expect 200
   ```

## Bulk top-off (many files / a whole prefix)
```bash
rclone copy <LIVE-READ-REMOTE>:loothgroup2-0/2026/06/ \
            r2up:loothgroup-uploads-dev/2026/06/ --ignore-existing --transfers 8
```
`--ignore-existing` pulls ONLY what's missing (the real top-off). **Never `rclone sync`** (it
deletes); always `copy --ignore-existing`. Profile-media prefix analogously between the
profile buckets.

## Traps (from the `r2-wiring` skill)
- Scoped tokens 403 on list — probe an object, don't conclude "no access."
- `no_check_bucket=true` is required on the rclone remote (else writes 403).
- The CF API token ("tight butterfly", `/etc/looth/cf-api-token`) is **management only** — it
  lists buckets but CANNOT read object bytes. Useless for top-off.
- R2 tokens are IP-locked; a 403 from dev may just mean dev's IP isn't allowlisted.

*See also: `r2-wiring` skill (R2 setup/debug), SYSTEM-MAP §11 (R2 buckets).*
