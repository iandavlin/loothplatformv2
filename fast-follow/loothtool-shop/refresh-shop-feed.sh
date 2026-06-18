#!/usr/bin/env bash
# refresh-shop-feed.sh — rebuild the Looth-app shop bubble feed from live Loothtool
# products. RUN THIS ON THE DEV BOX (loothdev) — Loothtool and Loothgroup live on the
# SAME server, so there is NO SSH hop: wp-cli reads the Loothtool install locally and
# the JSON + mirrored images are written straight into the Loothgroup docroot.
#
#   ssh loothdev 'bash /home/buck/temp/pwa-verify/refresh-shop-feed.sh'
#   (or drop it in cron on the box to keep the feed fresh)
#
# What it does:
#   1. wp eval-file against /var/www/dev.loothtool -> up to 60 newest published
#      products as JSON (id, name, price, image, url, vendor).
#   2. Mirrors each product thumbnail from the Loothtool uploads dir into the
#      Loothgroup docroot (/var/www/dev/shop-img/<id>-<file>) and rewrites the feed's
#      image URL to that SAME-ORIGIN path. (Loothtool's own /wp-content/uploads is
#      behind the loothtool gate -> 403 for app users; same-origin mirror fixes that.)
#   3. Writes /var/www/dev/shop-feed.json (backing up the prior copy).
#
# NOTE on click-through: product `url` still points at dev.loothtool.com/product/...,
# which is gated (403) until Loothtool goes public. Set PUBLIC_DOMAIN=loothtool.com to
# rewrite those links once the live site is up.

set -euo pipefail

LT_WP="${LT_WP:-/var/www/dev.loothtool}"          # Loothtool WP install (local on box)
LG_DOCROOT="${LG_DOCROOT:-/var/www/dev}"           # Loothgroup app docroot (local on box)
FEED="$LG_DOCROOT/shop-feed.json"
IMGDIR="$LG_DOCROOT/shop-img"
PUBLIC_DOMAIN="${PUBLIC_DOMAIN:-loothtool.com}"    # rewrite dev->live so app users can buy (Buck 2026-06-07; empty = leave dev URLs)

PHP="$(mktemp --suffix=.php)"
RAW="$(mktemp)"
OUT="$(mktemp)"
trap 'rm -f "$PHP" "$RAW" "$OUT"' EXIT

cat > "$PHP" <<'PHPEOF'
<?php
$out = array();
$q = new WP_Query([
  "post_type"      => "product",
  "post_status"    => "publish",
  "posts_per_page" => 60,
  "orderby"        => "date",
  "order"          => "DESC",
  "fields"         => "ids",
  "tax_query"      => [[
    "taxonomy" => "product_visibility",
    "field"    => "name",
    "terms"    => ["exclude-from-catalog"],
    "operator" => "NOT IN",
  ]],
]);
foreach ($q->posts as $id) {
  $p = wc_get_product($id);
  if (!$p) continue;
  $img = wp_get_attachment_image_url($p->get_image_id(), "woocommerce_thumbnail");
  $vid = (int) get_post_field("post_author", $id);
  $vendor = get_user_meta($vid, "dokan_store_name", true);
  if (!$vendor) $vendor = get_the_author_meta("display_name", $vid);
  // departed vendors (Buck 2026-06-11): no longer sell on Loothtool
  $excluded = ["j.b. jewitt co., inc", "guitar specialist, inc.", "j.b. jewitt", "guitar specialist"];
  foreach ($excluded as $ex) {
    if (stripos($vendor, $ex) !== false || stripos($ex, $vendor ?: "~none~") !== false) continue 2;
  }
  $out[] = [
    "id"     => (string) $id,
    "name"   => html_entity_decode($p->get_name(), ENT_QUOTES),
    "price"  => trim(html_entity_decode(wp_strip_all_tags($p->get_price_html()), ENT_QUOTES)),
    "image"  => $img ?: "",
    "url"    => get_permalink($id),
    "vendor" => $vendor,
  ];
}
echo wp_json_encode(array_values($out));
PHPEOF

echo "[1/3] Exporting products from $LT_WP …"
( cd "$LT_WP" && wp eval-file "$PHP" --skip-themes ) > "$RAW"
jq empty "$RAW"   # abort if not valid JSON
echo "      got $(jq length "$RAW") products."

echo "[2/3] Mirroring + WebP-encoding thumbnails into $IMGDIR (same-origin) …"
mkdir -p "$IMGDIR"
HAVE_CWEBP=0; command -v cwebp >/dev/null 2>&1 && HAVE_CWEBP=1
jq -c '.[]' "$RAW" | while read -r row; do
  id=$(echo "$row"  | jq -r '.id')
  img=$(echo "$row" | jq -r '.image')
  if [ -n "$img" ] && [ "$img" != "null" ]; then
    path=${img#*://*/}                 # wp-content/uploads/…
    local="$LT_WP/$path"
    base=$(basename "$path")
    stem="${base%.*}"
    if [ -f "$local" ]; then
      if [ "$HAVE_CWEBP" = 1 ] && cwebp -quiet -q 80 -m 6 "$local" -o "$IMGDIR/${id}-${stem}.webp" 2>/dev/null; then
        chmod 644 "$IMGDIR/${id}-${stem}.webp"
        echo "$row" | jq -c --arg u "/shop-img/${id}-${stem}.webp" '.image=$u'
      else
        cp -f "$local" "$IMGDIR/${id}-${base}"; chmod 644 "$IMGDIR/${id}-${base}"
        echo "$row" | jq -c --arg u "/shop-img/${id}-${base}" '.image=$u'
      fi
    else
      echo "$row" | jq -c '.image=""'
    fi
  else
    echo "$row"
  fi
done | jq -s '.' > "$OUT"

# Prune orphaned images: delete anything in $IMGDIR not referenced by the new feed.
echo "      pruning orphaned thumbnails …"
KEEP="$(mktemp)"; jq -r '.[].image | select(. != "" and . != null) | sub("^/shop-img/";"")' "$OUT" | sort -u > "$KEEP"
for f in "$IMGDIR"/*; do
  [ -e "$f" ] || continue
  # brand assets (vendor logos + the Loothtool site logo) are mirrored by
  # mirror-vendor-logos.py, not the feed — never prune them (2026-06-11:
  # the prune ate loothtool-logo.png and broke the /shop/ header).
  case "$(basename "$f")" in vendor-*|loothtool-logo*) continue;; esac
  grep -qxF "$(basename "$f")" "$KEEP" || rm -f "$f"
done
rm -f "$KEEP"
echo "      $IMGDIR now $(du -sh "$IMGDIR" | cut -f1), $(ls -1 "$IMGDIR" | wc -l) files."

if [ -n "$PUBLIC_DOMAIN" ]; then
  sed -i "s#https://dev\.loothtool\.com#https://$PUBLIC_DOMAIN#g" "$OUT"

  # Drop any product whose public URL doesn't resolve 200 — e.g. a dev-only product
  # that isn't on the live store would otherwise become a 404 link for shoppers.
  # GET + browser UA + follow redirects (the store 403s bare HEAD / non-browser hits).
  echo "      validating live product URLs on $PUBLIC_DOMAIN …"
  UA="Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1"
  VALID="$(mktemp)"
  jq -c '.[]' "$OUT" | while read -r row; do
    url=$(echo "$row" | jq -r '.url')
    code=$(curl -s -o /dev/null -w '%{http_code}' -A "$UA" -L --max-time 20 "$url" || echo 000)
    if [ "$code" = "200" ]; then echo "$row"; else echo "      DROP $code $url" >&2; fi
  done | jq -s '.' > "$VALID"
  mv "$VALID" "$OUT"
  echo "      $(jq length "$OUT") products resolve live."
fi

echo "[3/3] Publishing feed -> $FEED"
[ -f "$FEED" ] && cp -f "$FEED" "$FEED.bak"
cp -f "$OUT" "$FEED"
chmod 644 "$FEED"
echo "DONE — shop bubble now serves $(jq length "$FEED") live listings."

# vendor/store logos (Buck 2026-06-10): mirror Dokan store avatars + write shop-vendors.json
python3 /home/buck/bin/mirror-vendor-logos.py || true
