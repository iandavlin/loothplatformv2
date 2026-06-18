# Batch 06 — social source recon, BOTH sources (read-only, on LIVE)

> Run on live. Read-only. Two sources feed profile-app's social consolidation
> (see `docs/plan-social-consolidation.md`): xprofile socials (primary) +
> ACF author socials (fallback). Dev can't show the xprofile source (stripped),
> and we don't know if the ACF author fields are populated on live — both need
> live recon. WP_PATH=/var/www/html, run as looth-live.

```bash
WP_PATH=/var/www/html

# 56. All xprofile fields — find the social one(s). Look for type='socialnetworks'
#     or names like "Social", "Instagram", "Website", "Find me online", etc.
sudo -u looth-live wp --path=$WP_PATH db query \
  "SELECT id, name, type, parent_id FROM wp_bp_xprofile_field ORDER BY id" --skip-column-names

# 57. If there's a socialnetworks-type field, its child options define the
#     platform set. Show children of any social field (replace <ID> after #56,
#     or this dumps all field children at once):
sudo -u looth-live wp --path=$WP_PATH db query \
  "SELECT id, parent_id, name, type FROM wp_bp_xprofile_field WHERE parent_id <> 0 ORDER BY parent_id, id" --skip-column-names

# 58. Sample stored VALUES for the social field(s) — this reveals the
#     serialization format (single serialized blob of platform=>url pairs,
#     vs. one url per field). Replace <FIELD_ID> with the social field id(s)
#     from #56; run once per social field if there are several.
sudo -u looth-live wp --path=$WP_PATH db query \
  "SELECT user_id, field_id, value FROM wp_bp_xprofile_data WHERE field_id IN (<FIELD_ID>) AND value <> '' LIMIT 8" --skip-column-names

# 59. Size it: how many users have non-empty social data?
sudo -u looth-live wp --path=$WP_PATH db query \
  "SELECT field_id, COUNT(*) FROM wp_bp_xprofile_data WHERE field_id IN (<FIELD_ID>) AND value <> '' GROUP BY field_id" --skip-column-names
```

# --- ACF author socials (the FALLBACK source) ---

# 60. Are the ACF author-social fields populated on live, and for how many?
#     (Confirmed on dev: author_website/instagram/facebook/youtube/linktree,
#     132 users. Need to know if live has them + counts.)
sudo -u looth-live wp --path=$WP_PATH db query \
  "SELECT meta_key, COUNT(*) c FROM wp_usermeta
   WHERE meta_key IN ('author_website','author_instagram','author_facebook','author_youtube','author_linktree')
     AND meta_value <> '' GROUP BY meta_key ORDER BY c DESC" --skip-column-names

# 61. Sample a few live author_* values (format / dirtiness check)
sudo -u looth-live wp --path=$WP_PATH db query \
  "SELECT user_id, meta_key, meta_value FROM wp_usermeta
   WHERE meta_key IN ('author_website','author_instagram','author_facebook','author_youtube','author_linktree')
     AND meta_value <> '' LIMIT 8" --skip-column-names

# --- xprofile ADDRESS field (for slice-4 location_address backfill) ---
# profile-app: BB xprofile field 96 carries address-precision text. Confirm the
# field ID/name/type on LIVE (may differ from dev) + sample + count. This feeds
# the new users.location_address column, populated at slice-4 alongside the
# existing location_city/region snapshot (so users land in the new model on
# cutover day — no post-cutover back-pass).

# 62. Confirm field 96 is the address field on live + its type
sudo -u looth-live wp --path=$WP_PATH db query \
  "SELECT id, name, type FROM wp_bp_xprofile_field WHERE id = 96" --skip-column-names

# 63. Sample + count populated address values
sudo -u looth-live wp --path=$WP_PATH db query \
  "SELECT user_id, value FROM wp_bp_xprofile_data WHERE field_id = 96 AND value <> '' LIMIT 6" --skip-column-names
sudo -u looth-live wp --path=$WP_PATH db query \
  "SELECT COUNT(*) FROM wp_bp_xprofile_data WHERE field_id = 96 AND value <> ''" --skip-column-names
```

After paste-back, profile-app gets both sources characterized:
- **xprofile** (#56–59): social field id(s) + type, platform set, value format, count
- **ACF author** (#60–61): presence on live + per-platform counts + value format

From that it builds the consolidation (xprofile primary + ACF author fallback +
non-clobbering precedence) per `docs/plan-social-consolidation.md`.

No writes. Same read-only discipline as BATCH-04.
