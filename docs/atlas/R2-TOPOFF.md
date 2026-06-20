# R2-TOPOFF.md — pull missing dev media from the live R2 buckets

**Recurring workflow.** dev shows a blank/broken image (or any missing media) because dev's
R2 clone is missing a file that exists on live. The WP DB was cloned from live, so it
*references* files that were never copied into the dev clone. This is a **dev-data gap, not a
code bug.** "Top-off" = copy the missing file(s) from the live (read-only) bucket into the
dev clone, **writing through the FUSE mount** so it shows up immediately.

> ✅ SOLVED + verified 2026-06-20. Follow this literally — R2 trips me up every time.

## Keys ↔ buckets (the map I kept getting lost on)

| rclone remote | Access Key ID | reads/writes | buckets |
|---------------|---------------|--------------|---------|
| **`r2up`** | `6389a93d9c5b44af9543b96fbb588e34` | read **+ write** | DEV: `loothgroup-uploads-dev`, `loothgroup-2-0-profile-dev` |
| **`r2live`** | `c94a811562024caec2a41a030e10a260` | **read-only** | LIVE: `loothgroup2-0`, `loothgroup2-0-profile-bucket` (account-wide read) |

- `r2live`'s creds are **derived from the `cfat_` token** `/etc/looth/cf-api-token` (see
  "The credential" below) — Access Key ID = that token's ID, Secret = SHA-256 of its value.
- **The two LIVE buckets are READ-ONLY from dev. Never write them.** GET from live, write the
  dev clone. (`loothgroup` bare = legacy; live uploads = `loothgroup2-0`.)
- Dev uploads are a FUSE mount: `/var/www/dev/wp-content/uploads` → `/mnt/loothgroup-uploads-dev`
  (bucket `loothgroup-uploads-dev`), mounted `--vfs-cache-mode full --dir-cache-time 12h`.

## The credential — `cfat_` token → S3 (my instincts were WRONG here)

The `cfat_…` Cloudflare API token **CAN** read R2 objects over S3. (The r2-wiring skill's
"cfat is not S3" is too strong — for an R2-permissioned token you DERIVE S3 creds from it.)
Per Cloudflare R2 docs:
- **S3 Access Key ID = the token's ID.**
- **S3 Secret Access Key = SHA-256 hex of the token VALUE.** (verified: `printf '%s' "$TOK"
  | sha256sum`, no trailing newline.)

Get the token ID via the **account-level** verify (the `/user/...` one returns
`code 1000 Invalid` for account-owned tokens):
```bash
ACCT=2b34fc01f7fc32230a76c1490ac64b13                 # = the R2 endpoint subdomain
TOK=$(sudo cat /etc/looth/cf-api-token)
curl -s -H "Authorization: Bearer $TOK" \
  "https://api.cloudflare.com/client/v4/accounts/$ACCT/tokens/verify"   # -> result.id = Access Key ID
SECRET=$(printf '%s' "$TOK" | sha256sum | awk '{print $1}')             # = Secret Access Key
```
Wire it into `r2live` (run as **ubuntu**, not sudo — root has no rclone config):
```bash
rclone config update r2live access_key_id <result.id> secret_access_key "$SECRET"
# probe: expect NoSuchKey (NOT Forbidden):
rclone cat "r2live:loothgroup2-0/_zzprobe_$RANDOM" 2>&1
```
The token must be IP-allowed for dev2's egress IP `54.146.118.131` (a bare `Forbidden` on
every bucket = IP-block/revoked, see decode below).

## The top-off procedure (run on dev2, as ubuntu)

1. **Find the missing file's upload path** (from a blank featured image + the post ID):
   ```bash
   cd /var/www/dev && sudo -u looth-dev wp db query "SELECT af.meta_value
     FROM wp_postmeta ti JOIN wp_postmeta af
       ON af.post_id=ti.meta_value AND af.meta_key='_wp_attached_file'
     WHERE ti.post_id=<POST_ID> AND ti.meta_key='_thumbnail_id'"   # -> 2026/06/<file>
   ```
   (General rule: any `/wp-content/uploads/<path>` that 404s → `<path>` is the object key.)
2. **Confirm missing on dev, present on live:**
   ```bash
   sudo test -f /var/www/dev/wp-content/uploads/<path> && echo dev-has || echo dev-MISSING
   rclone cat "r2live:loothgroup2-0/<path>" | wc -c        # >0 = live has it
   ```
3. **Copy live → WRITE THROUGH THE MOUNT** (this is the key step — see the FUSE note):
   ```bash
   rclone copyto "r2live:loothgroup2-0/<path>" "/mnt/loothgroup-uploads-dev/<path>"
   ```
   ⚠️ Do NOT copy to `r2up:loothgroup-uploads-dev/<path>` (the bucket directly) — the bytes
   land in the bucket but the mount's 12h dir-cache hides them (no rc port to forget). Writing
   to the `/mnt/...` PATH goes through the writable VFS → appears + serves immediately.
   (Profile media: `r2live:loothgroup2-0-profile-bucket/<path>` → `/mnt/...profile mount`.)
4. **Verify it serves:**
   ```bash
   curl -sk -o /dev/null -w '%{http_code}\n' -H 'Host: dev2.loothgroup.com' \
     https://127.0.0.1/wp-content/uploads/<path>            # expect 200
   ```

## Bulk top-off (many files / a whole prefix)
```bash
rclone copy "r2live:loothgroup2-0/2026/06/" "/mnt/loothgroup-uploads-dev/2026/06/" \
  --ignore-existing --transfers 8
```
`--ignore-existing` pulls ONLY what's missing. **Never `rclone sync`** (it deletes); always
`copy --ignore-existing`. Through the mount path so the VFS surfaces them.

## Decode the EXACT S3 error (don't lump them)
- `404` / `NoSuchKey` / `object not found` → token CAN read this bucket (object just absent). ✅
- `AccessDenied` → key valid + IP-allowed, but lacks permission/scope on this bucket/op.
- **bare `Forbidden: Forbidden`** (no S3 code) on EVERY bucket → IP-block or revoked token.
  Fix = add the box egress IP to the token allowlist, or the token is dead.
- `SignatureDoesNotMatch` → the **secret** is wrong (Access Key ID may be fine).
- `401 Unauthorized` → the Access Key ID itself is unknown/dead.
- Scoped tokens **403 on LIST by design** — never conclude "no access" from a list-403; probe
  an object with `rclone cat <remote>:<bucket>/_zzprobe_$RANDOM`.

## Traps
- `no_check_bucket=true` required on the rclone remote (else writes 403).
- The `cfat_` token's Secret = SHA-256 of the token VALUE (no trailing newline); the token ID
  (= Access Key ID) comes from the **account** verify endpoint, not the user one.
- Writing to the bucket out-of-band does NOT refresh the FUSE mount (12h dir-cache, no rc) —
  write through `/mnt/...`.

*See also: `r2-wiring` skill, SYSTEM-MAP §11 (R2 buckets).*
