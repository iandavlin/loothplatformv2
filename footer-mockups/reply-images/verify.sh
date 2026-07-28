#!/usr/bin/env bash
# Verify the reply-image cap end to end WITHOUT a serve window: the real
# renderer, the real query, real rows out of the live mirror.
#
#   sudo -u postgres bash verify.sh          (needs mirror read access)
#
# Asserts, in order:
#   1. a single-image reply renders BYTE-IDENTICALLY to the pre-change code
#   2. 2..6 images render a gallery with the right cell count
#   3. every gallery tile carries srcset + sizes + intrinsic width/height
#   4. `sizes` tracks the SPAN rule (half-width for 2/4, thirds otherwise) —
#      the over-fetch bug this gallery exists to avoid
#   5. a legacy over-cap reply shows the cap and a +N, never a silent drop
#   6. the cap is ONE constant, shared by renderer and endpoint
set -uo pipefail
cd "$(dirname "$0")"
H="php render-harness.php"
pass=0; fail=0
ok(){ printf '  \033[32mPASS\033[0m %s\n' "$1"; pass=$((pass+1)); }
no(){ printf '  \033[31mFAIL\033[0m %s\n' "$1"; fail=$((fail+1)); }
chk(){ [ "$2" = "$3" ] && ok "$1 ($3)" || no "$1 — expected [$3], got [$2]"; }

# a real one-image reply
ONE=$(psql -d looth -tAc "SELECT r.id FROM forums.reply r
  WHERE r.status='publish'
    AND (SELECT count(*) FROM forums.attachment a
          WHERE a.parent_kind='reply' AND a.parent_id=r.id)=1
  ORDER BY r.id LIMIT 1")

echo "1. single image is untouched (reply $ONE)"
NEW=$($H stubs "$ONE")
# Render the SAME reply through the PRE-CHANGE renderer, pulled straight out of
# git, and feed it the shape it expected (a single reply_image_url string).
# Plain /tmp with explicit modes, NOT mktemp -d: this script is normally invoked
# under `sudo -u postgres` (peer auth is how the mirror is read), so anything it
# writes has to stay readable by whichever user php ends up running as. A 0700
# mktemp dir here fails as an empty baseline, which reads as "the render changed"
# — a false RED that costs more than the two chmods.
OLDDIR=/tmp/lg-reply-verify.$$
mkdir -p "$OLDDIR"; chmod 755 "$OLDDIR"; trap 'rm -rf "$OLDDIR"' EXIT
# safe.directory: the repo is owned by the lane user but this runs as postgres,
# and git's dubious-ownership refusal writes an EMPTY baseline — which then reads
# as "the single-image render changed". Fail loudly instead if the file is empty.
git -C ../.. -c safe.directory='*' show "HEAD:bb-mirror/web/forums/_reply-render.php" \
  > "$OLDDIR/_reply-render.php"
[ -s "$OLDDIR/_reply-render.php" ] || { no "could not extract the pre-change renderer from git"; }
cat > "$OLDDIR/old.php" <<'PHP'
<?php
function lg_bb_mirror_can_post(): bool { return true; }
function lg_bb_mirror_can_moderate(): bool { return false; }
require getenv('CFG');
require getenv('OLDR');
$db = bb_mirror_db();
$st = $db->prepare("SELECT r.id AS reply_id, COALESCE(r.author_name,'Anonymous') AS author_name,
    r.author_id, p.slug AS author_slug, p.avatar_url, LEFT(r.content_text,200) AS excerpt,
    r.created_at, (SELECT url FROM forums.attachment WHERE parent_kind='reply'
      AND parent_id=r.id ORDER BY id ASC LIMIT 1) AS reply_image_url
   FROM forums.reply r LEFT JOIN forums.person p ON p.id=r.author_id WHERE r.id=?");
$st->execute([(int)getenv('RID')]);
$r = $st->fetch(); $r['_visibility_masked'] = true;
bb_mirror_render_reply_stub($r, false, false, true, null); echo "\n";
PHP
chmod 644 "$OLDDIR"/*.php
OLD=$(CFG="$PWD/../../bb-mirror/config.php" OLDR="$OLDDIR/_reply-render.php" RID="$ONE" \
      php "$OLDDIR/old.php" 2>"$OLDDIR/err")
[ -s "$OLDDIR/err" ] && { echo "  baseline stderr:"; head -3 "$OLDDIR/err" | sed 's/^/    /'; }
if [ "$NEW" = "$OLD" ]; then ok "single-image stub byte-identical to pre-change render"
else no "single-image stub CHANGED"; diff <(echo "$OLD") <(echo "$NEW") | head -6; fi

echo "2. counts 2..6 render a gallery of the right size"
for n in 2 3 4 5 6; do
  html=$($H synth $n)
  chk "count=$n cells"   "$(grep -o 'reply-stub__gcell' <<<"$html" | wc -l)" "$n"
  chk "count=$n attr"    "$(grep -o 'data-count=\"[0-9]*\"' <<<"$html" | head -1)" "data-count=\"$n\""
done
echo "  (1 image is deliberately NOT a gallery)"
chk "count=1 has no gallery" "$($H synth 1 | grep -c 'reply-stub__gallery')" "0"

echo "3. every tile carries the full image contract"
html=$($H synth 6)
chk "srcset on all 6"  "$(grep -o 'srcset=' <<<"$html" | wc -l)" "6"
chk "sizes on all 6"   "$(grep -o 'sizes=' <<<"$html" | wc -l)" "6"
chk "width on all 6"   "$(grep -o 'width=\"[0-9]' <<<"$html" | wc -l)" "6"
chk "height on all 6"  "$(grep -o 'height=\"[0-9]' <<<"$html" | wc -l)" "6"
chk "lazy on all 6"    "$(grep -o 'loading=\"lazy\"' <<<"$html" | wc -l)" "6"
chk "3 candidates each" "$(grep -o '240w' <<<"$html" | wc -l)" "6"

echo "4. sizes tracks the span rule, not the count"
# grep -c counts LINES and the renderer emits one line — grep -o | wc -l or this
# whole section silently reports 1 and passes nothing.
#
# The DESKTOP px in `sizes` is DERIVED from forums.css here, never hardcoded.
# Hardcoding is what rotted this section once already: e183136 corrected the px
# from 360/240 to 228/151 (the tiles are bounded by .reply-stub__gallery's own
# max-width, not the modal's 724px column) and left these assertions asserting
# the pre-fix strings — 5 false REDs on correct code. This over-fetch class has
# now been found twice (flat 33vw in previs, then 360px on the serve), so per
# docs/CRAFT-STANDARD.md it gets a gate that tracks the CSS instead of a literal.
CSS=../../bb-mirror/web/forums.css
gblock=$(awk '/^\.reply-stub__gallery \{/{f=1} f{print} f&&/\}/{exit}' "$CSS")
COLS=$(grep -o 'repeat([0-9]*' <<<"$gblock" | tr -d 'repeat(')
GAP=$(grep -o 'gap: *[0-9]*px' <<<"$gblock" | grep -o '[0-9]*')
MAXW=$(grep -o 'max-width: *[0-9]*px' <<<"$gblock" | grep -o '[0-9]*')
SPAN_THIRD=$(grep -A3 '^\.reply-stub__gcell {' "$CSS" | grep -o 'span [0-9]*' | grep -o '[0-9]*')
SPAN_HALF=$(grep -oE 'data-count="4"\] \.reply-stub__gcell \{ grid-column: span [0-9]+' "$CSS" \
            | grep -oE 'span [0-9]+' | grep -oE '[0-9]+')
# a silently-empty parse computes a nonsense width and fails every check below
# with a shell syntax error instead of a readable RED — catch it here
for v in COLS GAP MAXW SPAN_HALF SPAN_THIRD; do
  [ -n "${!v}" ] || { no "could not parse $v out of forums.css"; }
done
# rendered tile width = its share of the track, plus the gaps it spans across
tilepx(){ echo $(( (MAXW - (COLS-1)*GAP) * $1 / COLS + ($1-1)*GAP )); }
HALFPX=$(tilepx "$SPAN_HALF"); THIRDPX=$(tilepx "$SPAN_THIRD")
echo "  (from CSS: ${MAXW}px / ${COLS} cols / ${GAP}px gap -> half=${HALFPX}px third=${THIRDPX}px)"
for n in 2 4; do
  chk "count=$n declares half-width" \
    "$($H synth $n | grep -o "sizes=\"(max-width:640px) 47vw, ${HALFPX}px\"" | wc -l)" "$n"
done
for n in 3 5 6; do
  chk "count=$n declares thirds" \
    "$($H synth $n | grep -o "sizes=\"(max-width:640px) 30vw, ${THIRDPX}px\"" | wc -l)" "$n"
done
# and the inverse: no layout may declare the OTHER rule
chk "count=3 never declares half-width" \
  "$($H synth 3 | grep -o '47vw' | wc -l)" "0"
chk "count=4 never declares thirds" \
  "$($H synth 4 | grep -o '30vw' | wc -l)" "0"
# (The two `declares` checks above ARE the over-fetch gate: they compare what the
# renderer emits against what the CSS renders, so a tile that declares more width
# than it can occupy — the 800w-for-a-160px-tile defect — fails them outright.)
#
# KNOWN, DELIBERATE IMPRECISION — count=5 is the one mixed-span layout: cells 1-3
# are thirds and cells 4-5 are halves (forums.css), but the renderer emits ONE
# `sizes` per gallery, so cells 4-5 declare ${THIRDPX}px while rendering
# ${HALFPX}px. That under-declares, which cannot over-fetch. It is still adequate
# at both densities because the next candidate up covers it: DPR1 needs
# ${HALFPX}px and gets 240w; DPR2 needs $((HALFPX*2))px and gets 480w. Asserted so
# that if the candidate ladder or the max-width changes, this stops being true
# loudly instead of silently shipping a blurry tile.
c5_dpr1=$(( THIRDPX <= 240 && HALFPX <= 240 ? 1 : 0 ))
c5_dpr2=$(( THIRDPX*2 <= 480 && HALFPX*2 <= 480 ? 1 : 0 ))
chk "count=5 mixed span still covered at DPR1" "$c5_dpr1" "1"
chk "count=5 mixed span still covered at DPR2" "$c5_dpr2" "1"

echo "5. a legacy over-cap reply shows the cap and a +N"
over=$($H over 6 11)
chk "over-cap cells"     "$(grep -o 'reply-stub__gcell' <<<"$over" | wc -l)" "6"
chk "over-cap data-more" "$(grep -o 'data-more=\"5\"' <<<"$over" | wc -l)" "1"
chk "over-cap +N badge"  "$(grep -o '>+5<' <<<"$over" | wc -l)" "1"

echo "6. one constant, shared"
chk "config defines it" "$(grep -c "define('LG_REPLY_IMG_MAX', 6)" ../../bb-mirror/config.php)" "1"
chk "no second literal cap in reply.php" \
  "$(grep -cE '(> *6|>= *6)' ../../bb-mirror/api/v0/reply.php)" "0"
chk "renderer slices by the constant" \
  "$(grep -c 'array_slice($imgs, 0, LG_REPLY_IMG_MAX)' ../../bb-mirror/web/forums/_reply-render.php)" "1"
chk "query limits by the constant" \
  "$(grep -c 'LIMIT \" . LG_REPLY_IMG_MAX' ../../bb-mirror/web/forums/_topic-replies.php)" "1"
chk "auth ships it to the client" \
  "$(grep -c "'reply_image_max' => LG_REPLY_IMG_MAX" ../../bb-mirror/api/v0/auth.php)" "1"

echo "7. the caps sit BEFORE the writes they guard"
# A cap after the insert is not a cap. Line-number ordering is crude but it is the
# property that matters and it survives refactors that a text match would miss.
R=../../bb-mirror/api/v0/reply.php
put_cap=$(grep -n 'keep_count + count($add_atts) > LG_REPLY_IMG_MAX' $R | cut -d: -f1)
# the FIRST wp_update_post in the file belongs to the TOPIC edit branch, which is
# deliberately uncapped — anchor on the reply-edit branch and take its own write
put_branch=$(grep -n 'PUT — edit content' $R | cut -d: -f1)
put_write=$(awk -v s="$put_branch" 'NR>s && /wp_update_post\(/ {print NR; exit}' $R)
post_cap=$(grep -n 'count($media) > LG_REPLY_IMG_MAX' $R | cut -d: -f1)
post_write=$(grep -n 'rest_do_request($req)' $R | head -1 | cut -d: -f1)
[ -n "$put_cap" ]  && [ "$put_cap"  -lt "$put_write"  ] \
  && ok "PUT cap ($put_cap) precedes wp_update_post ($put_write)" \
  || no "PUT cap does not precede the write"
[ -n "$post_cap" ] && [ "$post_cap" -lt "$post_write" ] \
  && ok "POST cap ($post_cap) precedes rest_do_request ($post_write)" \
  || no "POST cap does not precede the write"
# the PUT cap must count the RESULTING set, not just the additions
chk "PUT cap counts kept + added" \
  "$(grep -c 'keep_count = \$has_keep ? count(\$keep_ids) : count(reply_media_list' $R)" "1"

echo
echo "  $pass passed, $fail failed"
exit $(( fail > 0 ))
