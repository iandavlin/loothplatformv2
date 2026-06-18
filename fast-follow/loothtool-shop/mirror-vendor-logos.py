# Mirror Loothtool vendor/store logos + the site logo into the Loothgroup docroot
# and write /var/www/dev/shop-vendors.json:
#   { "<store_name>": { "logo": "/shop-img/vendor-<slug>.<ext>",
#                       "url":  "https://loothtool.com/store/<slug>/" } }
# (v1 wrote plain strings — consumers handle both shapes.)
# Source: the public Dokan stores API on loothtool.com (gravatar = store logo,
# shop_url = the vendor's own store page). Also mirrors the homepage logo to
# /shop-img/loothtool-logo.png (the refresh script's prune step once ate it).
import json, os, re, urllib.request

import shutil

API = 'https://loothtool.com/wp-json/dokan/v1/stores?per_page=50'
# Buck's official wordmark (2026-06-11) — lives in the Loothtool theme on this
# same box; fall back to the old square logo URL only if the file vanishes.
SITE_LOGO_LOCAL = '/var/www/dev.loothtool/wp-content/themes/hello-elementor-child/assets/loothtool-logo-tight.png'
SITE_LOGO_FALLBACK = 'https://loothtool.com/wp-content/uploads/2025/02/cropped-logo-300x300.png'
IMGDIR = '/var/www/dev/shop-img'
OUT = '/var/www/dev/shop-vendors.json'
UA = {'User-Agent': 'Mozilla/5.0 (LoothApp shop-feed mirror)'}

def grab(url, dest):
    r = urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=30)
    with open(dest, 'wb') as f:
        f.write(r.read())

os.makedirs(IMGDIR, exist_ok=True)

# site logo (used by the /shop/ header + the desktop modal)
try:
    dest = os.path.join(IMGDIR, 'loothtool-logo.png')
    if os.path.exists(SITE_LOGO_LOCAL):
        shutil.copyfile(SITE_LOGO_LOCAL, dest)
        print('ok site logo (theme wordmark)')
    else:
        grab(SITE_LOGO_FALLBACK, dest)
        print('ok site logo (fallback url)')
except Exception as e:
    print('skip site logo', e)

# coordinator 2026-06-13: cap the served site logo to ~320px wide regardless of
# source size (theme source has no intrinsic resize guard; site renders ~159px,
# so 320px=2x keeps the craft image-gate green and self-heals if the source grows).
try:
    from PIL import Image as _PILImage
    _lp = os.path.join(IMGDIR, 'loothtool-logo.png')
    _im = _PILImage.open(_lp)
    if _im.width > 360:
        _im.resize((320, round(_im.height*320/_im.width)), _PILImage.LANCZOS).save(_lp, optimize=True)
        print('ok site logo capped to 320w')
except Exception as _e:
    print('skip logo resize', _e)

req = urllib.request.Request(API, headers=UA)
stores = json.load(urllib.request.urlopen(req, timeout=30))
# departed vendors (Buck 2026-06-11): no longer sell on Loothtool
EXCLUDE = ('j.b. jewitt', 'guitar specialist')
out = {}
for s in stores:
    name = (s.get('store_name') or '').strip()
    g = s.get('gravatar') or ''
    shop_url = (s.get('shop_url') or '').strip()
    if not name or name in out:
        continue
    if any(x in name.lower() for x in EXCLUDE):
        print('excluded', name)
        continue
    entry = {}
    if shop_url:
        entry['url'] = shop_url
    if g and 'mystery-person' not in g:
        ext = os.path.splitext(g.split('?')[0])[1].lower()
        if ext not in ('.png', '.jpg', '.jpeg', '.webp', '.gif'):
            ext = '.png'
        slug = re.sub(r'[^a-z0-9]+', '-', name.lower()).strip('-')
        dest = os.path.join(IMGDIR, 'vendor-' + slug + ext)
        try:
            grab(g, dest)
            entry['logo'] = '/shop-img/vendor-' + slug + ext
        except Exception as e:
            print('skip logo', name, e)
    if entry:
        out[name] = entry
        print('ok', name, '->', entry)
with open(OUT, 'w') as f:
    json.dump(out, f, indent=1)
print('vendors:', len(out))
