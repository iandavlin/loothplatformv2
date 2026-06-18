# Session handoff — lg-layout-v2 (2026-05-23)

> ⚠️ **SNAPSHOT — verify every open/queued to-do against `git log` before working it (flagged 2026-06-15).** Items marked open/TODO/next here may already be shipped — a lane re-did a done task off a stale handoff. Source of truth = `git log` + `tools/gates/run-all.sh`, not these bullets.

Picks up from the prior 2026-05-23 handoff (now `SESSION-HANDOFF.2026-05-23-am.md` if needed). This session covered: structured callout block (Phase A/B/C), transcript block, paywall + gate-CTA, post-tier taxonomy auto-gate, render-time YT-link scrub, BB @mention strip, image crop (aspect + focal-point) with FE picker, post-footer share row, and a full VG-reprint workflow shipped on the '57 Strat / Dan Erlewine article.

## TL;DR — what's live

- **Plugin 0.1.54** on dev. Live needs the same push (script in this doc).
- **Post 69408** on dev (`Battle-Scarred: A '57 Strat Goes Under the Knife`) — full reprint demo: post-header, credit callout (note variant), 1:1 cropped hero with focal point, intro wysiwyg, 12 numbered step images (some columns-paired), Parts & tools callout, VG-promo 2-column ad-card, post-footer with share row + author card + related grid. **Awaiting live push.**
- **Post 69406 / 70990** (AGBC video) — already on live as `looth-lite`, embed gated to CTA, transcript stripped of same-video YT anchors for non-members.
- **Skill `write-article-v2`** updated with: VG-reprint variant, image_text canonical prop, image crop (aspect/focal_x/focal_y), tag pack rule, per-person callouts, in-body link lifting, tier mapping (patreon-level 1 → looth-lite).

## Conversion-agent workflow (the pattern this session validated)

For a new article/video conversion, the user pastes a source path (PDF for articles, legacy-source JSON for videos) and asks for it. The flow:

1. **Read the source.** PDF → `pdftotext -layout`. WP legacy → just `json.load`.
2. **Read each image in the pack** (if image-heavy). Sub-agent vision works on `.webp`/`.jpg`/`.png` — describes subject, supports alt-text, focal-point guesses, pairing decisions.
3. **Web-search for enrichment links.** Guests, brands, tools, podcasts. Add 2-3 enriching rows per callout from canonical sources.
4. **Build the layout JSON** per skill. Always bracket with `post-header` + `post-footer`. Lift in-body links into callouts. Strip same-video YT anchors if gated.
5. **Validate** — `wp lg-layout-v2 validate <file>` — fix fatal errors before import.
6. **Create / update the dev post** — `wp post create` for new, `wp post meta update` or `wp lg-layout-v2 import` for existing.
7. **Set tier** (if gated): `wp post term set <id> tier looth-lite` (or `-pro`, etc).
8. **Set author** — find via `wp user list --search="Display Name"`.
9. **Tag pack** — 5-10 tags from transcript/prose body (NOT just intro). Dedupe with `wp term list post_tag --orderby=count`.
10. **Verify on dev** — curl with the dev cookie, grep for expected counts.
11. **Stage for live** — copy layout + image pack to `/var/www/dev/.well-known/` for the live curl.

## Spawning the sub-agent

Either invoke the skill directly in conversation:

```
/write-article-v2 — convert /home/ubuntu/projects/<source>
```

Or have me spawn a general-purpose sub-agent with a prompt that says "load the write-article-v2 skill, apply it to `<source>`, return a tight report." Both work; the sub-agent path keeps the conversion's tool noise out of the main context.

## Dial-in on dev (the runbook for the conversion agent)

When the user pastes a source path, follow this exact procedure on dev before staging anything for live.

### 1. Bootstrap

```bash
# Confirm we're on the dev box (per the global CLAUDE.md sanity check)
curl -s ifconfig.me   # → 50.19.198.38 means we're dev. act locally, do NOT ssh.
whoami                # → ubuntu (sudo available)
```

### 2. Inspect the source

- **PDF**: `pdftotext -layout "<path>" /tmp/article.txt && head -200 /tmp/article.txt`
- **WP legacy bundle**: `python3 -c "import json; d=json.load(open('<path>')); print(d['post_title'], d['post_type']); [print(' ', k) for k in d['meta'].keys() if not k.startswith('_')]"`
- **Image pack** (if separate): `ls <dir>` then `Read` each image file via the agent's vision so alt text + focal-point + pairing decisions come from actual content, not guesses.

### 3. Upload images to dev

```bash
# Slugify filenames first (URL-safe, ordered by figure number):
cd <image-source-dir>
# Then upload:
for f in *.webp *.jpg *.jpeg *.png; do
  sudo -u www-data wp --path=/var/www/dev media import "$f" --porcelain 2>&1 | tail -1
done
# Capture the IDs in order — they map to article figure numbers.
```

### 4. Build the layout JSON

Per the `write-article-v2` skill. Save to `/home/ubuntu/projects/lg-layout-v2/storage/layouts/<slug>.json`.

### 5. Create the dev post + import

```bash
PID=$(sudo -u www-data wp --path=/var/www/dev post create \
  --post_type=post-imgcap \
  --post_status=draft \
  --post_title="<Title>" \
  --post_author=<USER_ID> \
  --porcelain)
echo "dev post id: $PID"

# Featured image (use the hero attachment ID)
sudo -u www-data wp --path=/var/www/dev post meta update $PID _thumbnail_id <HERO_ID>

# Validate + import the layout
sudo -u www-data wp --path=/var/www/dev lg-layout-v2 validate /home/ubuntu/projects/lg-layout-v2/storage/layouts/<slug>.json
sudo -u www-data wp --path=/var/www/dev lg-layout-v2 import \
  --post-id=$PID --file=/home/ubuntu/projects/lg-layout-v2/storage/layouts/<slug>.json

# Tier (if gated) and tags
# sudo -u www-data wp --path=/var/www/dev post term set $PID tier looth-lite
sudo -u www-data wp --path=/var/www/dev post term set $PID post_tag "tag1" "tag2" ... # 5-10 tags

# Publish for preview
sudo -u www-data wp --path=/var/www/dev post update $PID --post_status=publish
sudo -u www-data wp --path=/var/www/dev option update lg_layout_v2_cache_epoch $(date +%s)
```

### 6. Verify on dev (curl with the dev cookie)

```bash
TOK=$(grep loothdev_token /etc/nginx/sites-available/dev.loothgroup.com.conf | head -1 | awk -F'"' '{print $2}')
URL=$(sudo -u www-data wp --path=/var/www/dev eval "echo get_permalink($PID);" | tail -1)
curl -sk -H "Cookie: loothdev_auth=$TOK" "$URL" -o /tmp/check.html -w "HTTP %{http_code}  %{size_download}b\n"

# Expected counts (article reprint):
grep -c 'lg-post-header\b' /tmp/check.html         # 1
grep -c 'lg-callout--note' /tmp/check.html         # 1 (credit) for reprints
grep -c 'lg-image\b' /tmp/check.html               # however many figures
grep -c 'lg-callout--data' /tmp/check.html         # 1+ (parts/references)
grep -c 'lg-post-footer\b' /tmp/check.html         # 1
grep -c 'lg-post-footer__share' /tmp/check.html    # 1
```

If anything's off, fix the layout JSON, re-import, bump cache_epoch, re-curl. Iterate until clean.

### 7. Hand the user a review URL + summary

```
preview:  $URL
blocks emitted:  <list>
tag pack:        <slugs>
judgment calls:  <anything ambiguous>
```

**Wait for user sign-off before staging for live.**

## Curl to live (the runbook for the deploy)

Live is at `loothgroup.com` (different box, host `ip-172-31-45-223`). This box can't ssh out — the user runs commands on live themselves. Our job is to stage everything on dev's `.well-known/` and hand them a single bash block to paste.

### 1. Stage on dev's .well-known/

```bash
# Each new article gets its own pack dir under .well-known/
sudo mkdir -p /var/www/dev/.well-known/<slug>-pack

# Copy the image files (originals only, not size variants)
for id in <id1> <id2> ...; do
  src=$(sudo -u www-data wp --path=/var/www/dev eval "echo get_attached_file($id);" 2>&1 | tail -1)
  fname=$(basename "$src")
  sudo cp "$src" "/var/www/dev/.well-known/<slug>-pack/$fname"
done

# Copy the layout JSON
sudo cp /home/ubuntu/projects/lg-layout-v2/storage/layouts/<slug>.json \
        /var/www/dev/.well-known/<slug>-pack/layout-dev-ids.json

# Build a remap manifest (filename → dev attachment ID)
python3 -c '
import json, subprocess
ids = [<id1>, <id2>, ...]
m = {}
for i in ids:
    r = subprocess.run(["sudo","-u","www-data","wp","--path=/var/www/dev","eval",
                        f"echo basename(get_attached_file({i}));"],
                       capture_output=True, text=True)
    m[r.stdout.strip().split("\n")[-1]] = i
print(json.dumps(m, indent=2))' | sudo tee /var/www/dev/.well-known/<slug>-pack/remap-manifest.json

sudo chown -R www-data:www-data /var/www/dev/.well-known/<slug>-pack
```

### 2. Hand the user this block to run on LIVE

Token: `qShCjBdCVXLie7wcQddsprkYj4SuaXu7UJeYAHHG` (the loothdev_auth cookie, lets live curl through dev's gate).

```bash
TOK='qShCjBdCVXLie7wcQddsprkYj4SuaXu7UJeYAHHG'
WP_DIR='/var/www/html'
SLUG='<slug>'

# Pull the pack
mkdir -p /tmp/$SLUG-pack && cd /tmp/$SLUG-pack
for f in <list-each-webp> layout-dev-ids.json remap-manifest.json; do
  curl -sk -H "Cookie: loothdev_auth=$TOK" -o "$f" \
    "https://dev.loothgroup.com/.well-known/$SLUG-pack/$f"
done

# Import images, build live-id map
sudo cp /tmp/$SLUG-pack/*.webp /tmp/
> /tmp/$SLUG-pack/id-remap.json
echo '{' >> /tmp/$SLUG-pack/id-remap.json
first=1
while IFS= read -r line; do
  fname=$(echo "$line" | grep -oE '"[^"]+\.webp"' | tr -d '"')
  devid=$(echo "$line" | grep -oE '[0-9]+' | tail -1)
  [ -z "$fname" ] && continue
  liveid=$(sudo -u looth-live wp --path=$WP_DIR media import "/tmp/$fname" --porcelain | tail -1)
  echo "  $fname  dev=$devid → live=$liveid"
  [ $first -eq 0 ] && echo ',' >> /tmp/$SLUG-pack/id-remap.json
  printf '  "%s": %s' "$devid" "$liveid" >> /tmp/$SLUG-pack/id-remap.json
  first=0
done < <(grep -E '"\S+\.webp"' /tmp/$SLUG-pack/remap-manifest.json)
echo $'\n}' >> /tmp/$SLUG-pack/id-remap.json

# Rewrite layout JSON with live IDs
python3 <<'PY'
import json
remap = {int(k): int(v) for k, v in json.load(open('/tmp/'+'<slug>'+'-pack/id-remap.json')).items()}
layout = json.load(open('/tmp/'+'<slug>'+'-pack/layout-dev-ids.json'))
def walk(n):
    if isinstance(n, dict):
        if n.get('type') == 'image' and n.get('image_id') in remap: n['image_id'] = remap[n['image_id']]
        if n.get('type') == 'post-header' and n.get('featured_image_id') in remap: n['featured_image_id'] = remap[n['featured_image_id']]
        for v in n.values(): walk(v)
    elif isinstance(n, list):
        for v in n: walk(v)
walk(layout)
json.dump(layout, open('/tmp/'+'<slug>'+'-pack/layout-live-ids.json', 'w'), indent=2)
PY

# Look up author + create + import + tag
DAN=$(sudo -u looth-live wp --path=$WP_DIR user list --search="Dan Erlewine" --field=ID | head -1)
LIVE_PID=$(sudo -u looth-live wp --path=$WP_DIR post create \
  --post_type=post-imgcap --post_status=publish \
  --post_title="<Title>" --post_author=$DAN --porcelain)
HERO=$(python3 -c "import json; print(json.load(open('/tmp/<slug>-pack/id-remap.json'))['<HERO_DEV_ID>'])")
sudo -u looth-live wp --path=$WP_DIR post meta update $LIVE_PID _thumbnail_id $HERO
sudo -u looth-live wp --path=$WP_DIR lg-layout-v2 import \
  --post-id=$LIVE_PID --file=/tmp/$SLUG-pack/layout-live-ids.json
sudo -u looth-live wp --path=$WP_DIR post term set $LIVE_PID post_tag "tag1" "tag2" ...
sudo -u looth-live wp --path=$WP_DIR option update lg_layout_v2_cache_epoch $(date +%s)
echo "live URL: $(sudo -u looth-live wp --path=$WP_DIR post get $LIVE_PID --field=guid)"

# Cleanup
sudo rm /tmp/*.webp
```

### 3. Verify on live

```bash
# Cloudflare in front — may take a minute. Bypass cache if needed via CF dashboard.
curl -s "https://loothgroup.com/post-imgcap/<live-slug>/" \
  | grep -oE 'lg-(post-header|image|callout--[a-z]+|post-footer|post-footer__share)' \
  | sort | uniq -c
```

### Plugin upgrades, if needed

If the conversion uses features only in a newer plugin version (e.g. image-crop needs 0.1.51+, share-row needs 0.1.53+), include the plugin upgrade step BEFORE the post create:

```bash
curl -sk -H "Cookie: loothdev_auth=$TOK" -o /tmp/lg-layout-v2-<ver>.zip \
  https://dev.loothgroup.com/.well-known/lg-layout-v2-<ver>.zip
file /tmp/lg-layout-v2-<ver>.zip   # MUST be "Zip archive data" — STOP if HTML.
cd /tmp && rm -rf lg-layout-v2 && unzip -q lg-layout-v2-<ver>.zip
sudo rm -rf $WP_DIR/wp-content/plugins/lg-layout-v2.bak
sudo mv $WP_DIR/wp-content/plugins/lg-layout-v2 $WP_DIR/wp-content/plugins/lg-layout-v2.bak
sudo mv /tmp/lg-layout-v2 $WP_DIR/wp-content/plugins/lg-layout-v2
sudo chown -R looth-live:looth-live $WP_DIR/wp-content/plugins/lg-layout-v2
sudo -u looth-live wp --path=$WP_DIR eval 'LG\LayoutV2\WpAssets::regenerate_bundle();'
sudo -u looth-live wp --path=$WP_DIR option update lg_layout_v2_cache_epoch $(date +%s)
sudo -u looth-live wp --path=$WP_DIR plugin get lg-layout-v2 --field=version   # confirm
# Drop the backup after verifying:
sudo rm -rf $WP_DIR/wp-content/plugins/lg-layout-v2.bak
```

## Known bug — focal-point picker drag (0.1.51 → 0.1.56)

**Symptom:** Authors can see the orange focal dot on aspect-cropped images in editor mode. Dragging visually moves the cursor but no `POST /wp-json/lg-layout-v2/v1/blocks/update` fires — confirmed in browser Network tab (Fetch/XHR filter, cache disabled). After page reload, the dot snaps back to its saved position.

**What's confirmed working:**
- The REST update endpoint accepts `focal_x` / `focal_y` props (manifest allows them, all sanitization passes). Verified via `fetch()` from the browser console — returns `{ok: true}` and the image visibly recrops.
- Render path applies `object-position` correctly when focal_x/y change in meta.
- 0.1.56 deployed: document-level pointermove/pointerup listeners (better than the original setPointerCapture), Edit Image button moved to top-right corner so it doesn't overlap with focal dot.

**What's still broken:** the dot's pointerdown never fires. Probable cause is a stacking-context or hit-target issue inside `.lg-edit-img-overlay` — needs browser-DevTools-driven repro to nail. Either:
- Something else captures pointerdown before the dot does (an inner figure element, the image itself with `data-lg-lightbox` on a global handler, the BB theme's overlay layer, ...)
- The dot is being clipped by an `overflow:hidden` ancestor and is invisible to hit-testing despite being visually present.

**Workaround until fixed:** focal points can be set via the browser console with:
```js
fetch(LG_FE_EDITOR.rest_root + 'blocks/update', {
  method:'POST', credentials:'include',
  headers:{'Content-Type':'application/json','X-WP-Nonce':LG_FE_EDITOR.nonce},
  body: JSON.stringify({ post_id: LG_FE_EDITOR.post_id, path:[<idx>], props:{ focal_x: 50, focal_y: 30 }})
}).then(r=>r.json()).then(console.log)
```
Where `path` is the block's address in the layout (the lg-edit marker on each block emits it as `data-lg-block-path`).

OR set via the metabox / JSON paste in wp-admin — those use the same prop names and bypass the FE picker entirely.

**Next-session debug plan:** spawn a `general-purpose` agent with browser-CDP access (chromium container is running on 127.0.0.1:9222, mapped from the docker container). Have it: load the edit page as admin, screenshot the dot's rendered position, inspect what element actually receives pointerdown via `document.elementFromPoint(x, y)` at the dot's center, then fix the offending stacking-context rule.

## What's still pending

- **Strat article (post 69408 on dev) push to live.** Plugin 0.1.54 + new post + image pack remap. Dan Erlewine = user 8 on dev (`patreon_40755240`); look up the live equivalent before push.
- **Next article queued: `/home/ubuntu/projects/VG 1124 Dan's Guitar Rx Doubleneck Jerry.pdf`** — another Dan Erlewine "Dan's Guitar Rx" reprint from Vintage Guitar (November 2024 issue, the "Doubleneck Jerry" piece). Use the VG-reprint variant in the write-article-v2 skill. Same author user (Dan, user 8 on dev / look up on live), same tier policy (probably public again, but ask), same magazine-promo callout. Image pack: needs upload — extract from PDF first, or get from user.
- **FA Pro pull on live** (from the prior handoff). After it's gone, remove `/var/www/html/wp-content/mu-plugins/lg-fatal-catcher.php`.
- **lg-legacy-import cleanups** (still): make_clickable after wpautop, yt-core span flatten, link-text shortener.
- **Dash settings panel for the gate-CTA copy** (currently editable via `wp option update lg_layout_v2_gate_cta`).
- **317 videos backlog** — videos agent is dialed in. Articles agent dialed in via this session. Ready to batch.

## Key code surfaces touched

- `blocks/paywall/` — new (manifest + render + shell + README)
- `blocks/transcript/` — new
- `blocks/callout/` — items repeater (array_of_objects), 6 variants, gate-aware
- `blocks/image/` — image_text canonical, number badge, aspect + focal crop, has-aspect class for column override exemption
- `blocks/post-header/` — text-pop shadow + denser scrim
- `blocks/post-footer/` — share row (X / FB / email / copy-link)
- `src/Renderer.php` — top-level paywall cut, per-block gate→CTA, render-time YT-link scrub
- `src/GateCta.php` — CTA card with embed / download / paywall variants
- `src/Icons.php` — single source of truth for SVG glyphs
- `src/WpRenderer.php` — post_tier into ctx, BB @mention strip
- `src/Plugin.php` — taxonomy term-change cache invalidation hook
- `assets/lg-fe-editor.js` — aspect dropdown + focal-point drag picker, items modal
- `assets/lg-front.js` — lightbox prefers data-lg-fullsize-src; share-row copy-link button
- `src/MetaBox.php` — repeater UI for array_of_objects, format:html sanitization

## Skill files updated

- `.claude/skills/write-article-v2/SKILL.md` — main authoring contract (now article + video shapes both covered)
- `.claude/skills/lg-layout-v2/SKILL.md` — dev-side patterns (cascade, native details, gate-CTA architecture)
- `docs/LAYOUT-JSON.md` — auto-generated, current
- `docs/MANIFEST.md` — array_of_objects prop type + format:"html" documented

## Plugin versions shipped this session

0.1.42 → 0.1.54. Cumulative: structured callout, transcript, paywall, gate-CTA, post-tier auto-gate, render-time scrub, BB @mention strip, cache hook on term changes, image crop + focal + FE picker, lightbox fullsize swap, share row, post-header text-pop.

Carry the torch.
