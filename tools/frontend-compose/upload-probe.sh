#!/bin/bash
# upload-probe.sh — can an ordinary member actually PUT A PHOTO IN, through the
# uploader this form gives them?
#
# WHY, AND WHAT THIS DOES NOT COVER. The handoff listed "not tested with a real
# photo upload" as an open gap. roundtrip-probe.sh proves that attachment IDs
# posted into the gallery field are stored and read by the page-builder — but it
# hands ACF IDs that already existed. It never proved a member can CREATE one.
#
# That matters because the gallery is a REQUIRED field. If the upload leg refuses
# the member, the form cannot be completed at all, and every other proof we have
# would still be green: the route serves, the gate admits, the save path writes.
# A required field nobody can fill is a working form that cannot be used.
#
# The gallery does NOT post through our route. It uploads over AJAX and hands the
# field an attachment ID, so this drives that endpoint directly, as the member.
#
# TWO THINGS THAT MADE THE FIRST VERSION LIE, both worth keeping written down:
#
#   1. IT IS NOT WordPress's async-upload.php. This box runs Big File Uploads,
#      which replaces the uploader with a CHUNKER on admin-ajax.php
#      (action=bfu_chunker). The endpoint and the nonce both have to be read out
#      of the rendered page's _wpPluploadSettings rather than assumed from core.
#   2. NO Referer HEADER => "The link you followed has expired". check_admin_referer()
#      validates the referring host as well as the nonce, and curl sends neither by
#      default. That failure is indistinguishable from "the member is not allowed
#      to upload" — which is exactly the wrong conclusion, and the one this probe
#      reached before the header was added.
#
# A related red herring, recorded so nobody re-chases it: /wp-admin/profile.php
# 302s for an ordinary member and 200s for an admin. Members ARE redirected out of
# wp-admin PAGES. That is not the uploader's problem — admin-ajax.php answers 200
# for both, which is the only wp-admin surface the picker needs.
#
# STILL NOT COVERED, and named rather than implied: tapping a camera roll on a
# physical phone. That is a device affordance, not a code path — this proves the
# server accepts what the picker sends, not that the picker opens.
set -uo pipefail
cd "$(dirname "$0")/../.."

WP="sudo -n wp --allow-root --path=/var/www/dev"
LOGIN="${1:-bangers}"
nowarn() { grep -v '^PHP Warning:\|^PHP Deprecated:\|^Warning:'; }
source tools/gates/gate-env.sh || exit 2

echo "=== 0. the member, and the capability the form requires of them ==="
$WP eval '
  $u = get_user_by("login","'"$LOGIN"'");
  if (!$u) { echo "  CANNOT RUN: no such user\n"; exit(2); }
  printf("  %s (#%d) roles=%s  upload_files=%d  edit_posts=%d\n",
    $u->user_login, $u->ID, implode(",", $u->roles),
    user_can($u->ID,"upload_files"), user_can($u->ID,"edit_posts"));
' 2>/dev/null | nowarn

COOKIE="$($WP eval '
  $u = get_user_by("login","'"$LOGIN"'"); $e = time()+600;
  echo LOGGED_IN_COOKIE."=".wp_generate_auth_cookie($u->ID,$e,"logged_in").";"
     . SECURE_AUTH_COOKIE."=".wp_generate_auth_cookie($u->ID,$e,"secure_auth");' 2>/dev/null | nowarn)"
JAR="loothdev_auth=$LG_GATE_TOKEN; $COOKIE"
FORM="$LG_GATE_HOST/compose/?type=loothprint"

# The nonce is user + session-token + tick bound, so it is taken from a FRESH
# render of the very page whose uploader we are testing — not minted in CLI,
# where the session token is empty and the nonce will not verify.
curl -s -H "Cookie: $JAR" $LG_GATE_RESOLVE "$FORM" > /tmp/lgfc-form.html
read -r UPURL UPACTION NONCE <<<"$(python3 - <<'PY'
import re, json
h = open('/tmp/lgfc-form.html').read()
m = re.search(r'_wpPluploadSettings\s*=\s*(\{.*?\});', h, re.S)
if not m:
    print("NONE NONE NONE"); raise SystemExit
d = json.loads(m.group(1))["defaults"]
mp = d.get("multipart_params", {})
print(d.get("url", "NONE"), mp.get("action", "NONE"), mp.get("_wpnonce", "NONE"))
PY
)"
echo "  uploader: $UPACTION -> $UPURL"
[ "$NONCE" != "NONE" ] && [ -n "$NONCE" ] || { echo "  CANNOT RUN: no uploader settings on the form (is the flag on?)"; exit 2; }

# A real PNG, built here so the probe carries no fixture and cannot drift.
IMG=$(mktemp /tmp/lgfc-upload-XXXX.png)
printf '\x89PNG\r\n\x1a\n' > "$IMG"
python3 - "$IMG" <<'PY'
import struct, sys, zlib
p = sys.argv[1]
def chunk(t, d):
    c = t + d
    return struct.pack(">I", len(d)) + c + struct.pack(">I", zlib.crc32(c) & 0xffffffff)
w = h = 64
raw = b"".join(b"\x00" + bytes([40, 60, 30]) * w for _ in range(h))
png = (b"\x89PNG\r\n\x1a\n"
       + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0))
       + chunk(b"IDAT", zlib.compress(raw))
       + chunk(b"IEND", b""))
open(p, "wb").write(png)
PY
echo "  built a real 64x64 PNG: $(stat -c%s "$IMG") bytes"

BEFORE=$($WP eval 'global $wpdb; echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=\"attachment\"");' 2>/dev/null | nowarn)

echo
echo "=== 1. upload it the way the media modal does ==="
RESP=$(curl -s -H "Cookie: $JAR" \
  -H "Referer: $FORM" -H "X-Requested-With: XMLHttpRequest" $LG_GATE_RESOLVE \
  -F "action=$UPACTION" -F "_wpnonce=$NONCE" \
  -F "name=lgfc-upload-probe.png" -F "chunk=0" -F "chunks=1" \
  -F "async-upload=@$IMG;type=image/png;filename=lgfc-upload-probe.png" \
  "$UPURL")
rm -f "$IMG"
OK=$(printf '%s' "$RESP" | python3 -c 'import sys,json;d=json.load(sys.stdin);print("1" if d.get("success") else "0")' 2>/dev/null || echo 0)
ATT=$(printf '%s' "$RESP" | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d.get("data",{}).get("id",0))' 2>/dev/null || echo 0)
echo "  success=$OK  attachment=$ATT"
[ "$OK" = "1" ] || { echo "  RESPONSE: $(printf '%s' "$RESP" | head -c 300)"; }

echo
echo "=== 2. is it a real attachment, owned by the member, with a resized set? ==="
$WP eval '
  $id = (int)"'"$ATT"'";
  if (!$id) { echo "  no attachment created — the member CANNOT complete the required photo field\n"; exit(1); }
  $a = get_post($id);
  printf("  #%d type=%s mime=%s author=%d (%s)\n", $a->ID, $a->post_type, $a->post_mime_type,
    $a->post_author, get_userdata($a->post_author)->user_login);
  $m = wp_get_attachment_metadata($id);
  printf("  %dx%d, generated sizes: %s\n", $m["width"] ?? 0, $m["height"] ?? 0,
    implode(",", array_keys($m["sizes"] ?? [])) ?: "none (a 64px source is below every threshold)");
' 2>&1 | nowarn

echo
echo "=== 3. clean up, and prove it is gone ==="
$WP eval '
  $id = (int)"'"$ATT"'";
  if ($id) { wp_delete_attachment($id, true); }
  echo "  attachment surviving: " . (get_post($id) ? "YES — CLEAN UP BY HAND" : "none") . "\n";
' 2>/dev/null | nowarn
AFTER=$($WP eval 'global $wpdb; echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=\"attachment\"");' 2>/dev/null | nowarn)
echo "  attachment rows before=$BEFORE after=$AFTER (must match)"
