# Backlog 30 — RULED DELETION manifest (four videos)

**Ian's ruling, relayed by keeper 2026-08-15: "Delete all four."**
Executed on **dev2 only**. Live is a separate database and bucket and its writes
are Ian's — the commands for live are in §5, unrun.

## 1. What was deleted

| attachment | path | bytes | md5 | uploaded | by |
|---|---|---|---|---|---|
| 6110 | `2023/09/nut-making.mp4` | 154321041 | `be8b7db9fecbb8e038ff8a62dff66089` | 2023-09-21 15:36:46 | Ian Davlin The Looth Group |
| 57953 | `2025/08/3D-Clamp-Feet-1.mp4` | 138886147 | `bd43a242d16b9d2e8208bc31582bcf49` | 2025-08-24 13:03:46 | Doug Proper Guitar Specialist |
| 57931 | `2025/08/3D-Clamp-Feet.mp4` | 138886147 | `bd43a242d16b9d2e8208bc31582bcf49` | 2025-08-24 12:46:46 | Doug Proper Guitar Specialist |
| 6145 | `2023/09/Loothsaber-Chisel.mp4` | 115526886 | `a37c3ce9b443a91a24d8f6370ac39415` | 2023-09-22 15:02:56 | Ian Davlin The Looth Group |

**Total freed on dev2: 547,620,221 bytes — 522.3 MB (0.51 GB).**

Each was a single object with no generated size variants (WordPress does not
derive thumbnails for video), so four objects were removed, not four families.

**Two of these were byte-identical duplicates.** `3D-Clamp-Feet.mp4` and
`3D-Clamp-Feet-1.mp4` share md5 `bd43a242d16b9d2e8208bc31582bcf49` and the
same 138,886,147 bytes, uploaded 17 minutes apart. That is 132 MB of the total.

Two of the four were **member** uploads (Doug Proper), not staff content. Ian
ruled on them explicitly; recording it because a manifest is the place where
whose-content-was-this stays visible.

## 2. Pre-deletion verification

Every file was re-checked **at delete time**, individually, against every source
the audit knows about. All returned zero:

`wp_posts` content/excerpt · non-attachment `wp_postmeta` · `wp_options` ·
`wp_bp_activity` · `wp_bp_media` (by `attachment_id`) · `wp_bp_document`
(by `attachment_id`) · `_thumbnail_id` · `discovery.article_blobs` ·
`discovery.content_item`

## 3. Post-deletion verification

- All four bucket objects: **gone** (`rclone lsl` returns 0 for each).
- All four attachment rows **and** their `postmeta`: **gone**.
- Attachment corpus: 7,397 → **7,393**; parentless 4,879 → **4,875**.
- dev2 still serving: `/` 200, `/hub/` 200, `/wp-admin/` 302 (its normal
  redirect to login).

## 4. What was NOT touched

The **430 MB long tail** — the other 1,121 files classified dead — is untouched
by ruling. It stays for a separate decision. Nothing in the *referenced* or
*uncertain* classes was touched at all.

## 5. LIVE — not executed, Ian's to run

Live carries the same four rows, same IDs, also parentless (verified read-only).
Deleting on dev2 frees dev2's bucket only; the live 522 MB is still there.

```
# on live, as the deploy user:
wp --path=<live docroot> post delete 6110 57931 57953 6145 --force

# and remove the objects from the LIVE bucket (separate from dev2's):
rclone deletefile r2live:<live-uploads-bucket>/2023/09/nut-making.mp4
rclone deletefile r2live:<live-uploads-bucket>/2025/08/3D-Clamp-Feet.mp4
rclone deletefile r2live:<live-uploads-bucket>/2025/08/3D-Clamp-Feet-1.mp4
rclone deletefile r2live:<live-uploads-bucket>/2023/09/Loothsaber-Chisel.mp4
```

The live bucket name is deliberately left as a placeholder: I have not verified
which remote maps to it, and `lg-secrets-helper` is the authoritative
cred↔bucket map for that. **Confirm the four are still unreferenced on live
before running** — this audit's reference scan was run against dev2's data.
