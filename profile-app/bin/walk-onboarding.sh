#!/usr/bin/env bash
# walk-onboarding.sh — scripted CDP cold-walk for a fresh profile-app user.
#
# Slice-end ritual: runs an end-to-end onboarding flow, screenshots each
# step, and exits non-zero on the first divergence from spec. Output and
# screenshots go into a timestamped directory under
# /var/www/dev/mockups/walks/<ts>/.
#
# Requires:
#   - chrome-dev.service (CDP on 127.0.0.1:9222)
#   - wp-cli on the dev WP install
#   - the cookie-gate token in /etc/nginx/sites-available/dev.loothgroup.com.conf
#
# Usage:
#   bin/walk-onboarding.sh            # creates a fresh user, runs the walk
#   bin/walk-onboarding.sh --keep     # don't delete the user at end
#
set -euo pipefail

KEEP_USER=0
[[ "${1:-}" == "--keep" ]] && KEEP_USER=1

TS=$(date -u +%Y%m%dT%H%M%SZ)
OUT_DIR="/var/www/dev/mockups/walks/${TS}"
sudo mkdir -p "$OUT_DIR"
sudo chown ubuntu:ubuntu "$OUT_DIR"
echo "walk-onboarding: $TS"
echo "  out: $OUT_DIR"

step() { echo; echo "── $* ──"; }
die()  { echo "FAIL: $*" >&2; exit 1; }

# ── 1. fresh WP user ─────────────────────────────────────────────────────
step "creating fresh WP user"
RAND=$(date +%s)$RANDOM
LOGIN="coldwalk-${RAND}"
EMAIL="${LOGIN}@looth.test"
WP_ID=$(sudo -u www-data wp --path=/var/www/dev user create "$LOGIN" "$EMAIL" \
    --role=subscriber --first_name=Cold --last_name=Walk --porcelain)
[[ -n "$WP_ID" ]] || die "user create returned no ID"
echo "  wp_user_id=$WP_ID email=$EMAIL"

trap 'cleanup' EXIT
cleanup() {
    if [[ $KEEP_USER -eq 0 ]]; then
        echo "(cleaning up $LOGIN)"
        sudo -u www-data wp --path=/var/www/dev user delete "$WP_ID" --yes >/dev/null 2>&1 || true
        sudo -u profile-app psql -d profile_app -c "
          DELETE FROM wp_user_bridge WHERE wp_user_id=$WP_ID;
          DELETE FROM users WHERE primary_email='${EMAIL}';
        " >/dev/null 2>&1 || true
    fi
}

# ── 2. webhook fired? ────────────────────────────────────────────────────
step "waiting for webhook → users row"
PA_ID=""
for i in 1 2 3 4 5 6 7 8 9 10; do
    PA_ID=$(sudo -u profile-app psql -At -d profile_app -c \
        "SELECT user_id FROM wp_user_bridge WHERE wp_user_id=$WP_ID")
    [[ -n "$PA_ID" ]] && break
    sleep 1
done
[[ -n "$PA_ID" ]] || die "no users row after 10s — webhook didn't fire"
echo "  pa_user_id=$PA_ID"

SLUG=$(sudo -u profile-app psql -At -d profile_app -c "SELECT COALESCE(slug, id::text) FROM users WHERE id=$PA_ID")
echo "  slug=$SLUG"

# ── 3. mint JWT via /wp-json/looth/auth/issue ────────────────────────────
step "minting JWT for new user"
LOOTHDEV_TOK=$(sudo grep '\$loothdev_token' /etc/nginx/sites-available/dev.loothgroup.com.conf | head -1 | awk -F'"' '{print $2}')

read LOGGED_IN_NAME LOGGED_IN_VAL AUTH_NAME AUTH_VAL < <(sudo -u www-data wp --path=/var/www/dev eval "
\$exp = time() + 86400;
\$secure = is_ssl();
echo LOGGED_IN_COOKIE . ' ' . wp_generate_auth_cookie($WP_ID, \$exp, 'logged_in') . ' ';
echo (\$secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE) . ' ' . wp_generate_auth_cookie($WP_ID, \$exp, \$secure ? 'secure_auth' : 'auth') . PHP_EOL;
") || true
[[ -n "$LOGGED_IN_VAL" ]] || die "wp_generate_auth_cookie returned empty"

curl -sk -o /dev/null -D /tmp/cw-issue-headers.txt \
    -H "Cookie: loothdev_auth=$LOOTHDEV_TOK; ${LOGGED_IN_NAME}=${LOGGED_IN_VAL}; ${AUTH_NAME}=${AUTH_VAL}" \
    "https://dev.loothgroup.com/wp-json/looth/auth/issue?return=/profile/edit"
LOOTH_ID=$(grep -oP 'looth_id=[^;]+' /tmp/cw-issue-headers.txt | head -1 | sed 's/^looth_id=//')

echo "  looth_id=${LOOTH_ID:0:24}..."
[[ -n "$LOOTH_ID" ]] || die "no looth_id cookie minted"

# ── 3b. claim the profile ────────────────────────────────────────────────
# Without this, /profile/edit renders the claim interstitial instead of the
# editor (fresh users have no profiles row until they claim).
step "claiming profile (via=direct)"
CLAIM_RESP=$(curl -sk -X POST \
    -H "Cookie: loothdev_auth=$LOOTHDEV_TOK; looth_id=$LOOTH_ID" \
    -H "Content-Type: application/json" \
    -d '{"via":"direct"}' \
    "https://dev.loothgroup.com/profile-api/v0/me/claim")
echo "  claim: $CLAIM_RESP"
echo "$CLAIM_RESP" | grep -qE '"claimed":(true|false)' || die "claim endpoint did not respond as expected"

# ── 4. CDP: load /profile/edit ───────────────────────────────────────────
step "CDP → /profile/edit"
PAGE_ID=$(curl -s http://127.0.0.1:9222/json | python3 -c "
import json, sys
pages = json.load(sys.stdin)
print([p['id'] for p in pages if p['type']=='page'][0])
")
echo "  page=$PAGE_ID"

# Set cookies + navigate via CDP
python3 - <<EOF
import asyncio, json, urllib.request, websockets, base64
pages = json.load(urllib.request.urlopen('http://127.0.0.1:9222/json'))
page  = [p for p in pages if p['id']=='$PAGE_ID'][0]
cookies = [
  {'domain':'dev.loothgroup.com','name':'loothdev_auth','value':'$LOOTHDEV_TOK','path':'/','secure':True,'httpOnly':True},
  {'domain':'dev.loothgroup.com','name':'${LOGGED_IN_NAME}','value':'${LOGGED_IN_VAL}','path':'/','secure':True,'httpOnly':True},
  {'domain':'dev.loothgroup.com','name':'${AUTH_NAME}','value':'${AUTH_VAL}','path':'/','secure':True,'httpOnly':True},
  {'domain':'dev.loothgroup.com','name':'looth_id','value':'${LOOTH_ID}','path':'/','secure':True,'httpOnly':True},
]
async def go():
  async with websockets.connect(page['webSocketDebuggerUrl'], max_size=None) as ws:
    await ws.send(json.dumps({'id':1,'method':'Network.clearBrowserCookies'})); await ws.recv()
    for i,c in enumerate(cookies, 2):
      await ws.send(json.dumps({'id':i,'method':'Network.setCookie','params':c})); await ws.recv()
    await ws.send(json.dumps({'id':99,'method':'Page.navigate','params':{'url':'https://dev.loothgroup.com/profile/edit'}})); await ws.recv()
    await asyncio.sleep(2.5)
    await ws.send(json.dumps({'id':100,'method':'Page.captureScreenshot','params':{'format':'png'}}))
    r = json.loads(await ws.recv())
    open('$OUT_DIR/01-edit-loaded.png','wb').write(base64.b64decode(r['result']['data']))
    # Editor sanity: the loc-vis radio is editor-only. If it's missing, we
    # got an interstitial — die loudly rather than silently pass through.
    await ws.send(json.dumps({'id':101,'method':'Runtime.evaluate','params':{'expression':"!!document.querySelector('input[name=\"loc-vis\"]')",'returnByValue':True}}))
    res = json.loads(await ws.recv())
    has_radio = res.get('result',{}).get('result',{}).get('value') is True
    print('  editor_dom_present:', has_radio)
    if not has_radio:
        # Capture page title + URL for the post-mortem
        await ws.send(json.dumps({'id':102,'method':'Runtime.evaluate','params':{'expression':"document.title + ' | ' + location.href",'returnByValue':True}}))
        title = json.loads(await ws.recv()).get('result',{}).get('result',{}).get('value','')
        open('$OUT_DIR/01-edit-FAIL.txt','w').write('editor DOM missing after navigate; page: ' + str(title))
        raise SystemExit(2)
asyncio.run(go())
EOF
ls -la "$OUT_DIR/01-edit-loaded.png" >/dev/null || die "screenshot 1 missing"
[[ -f "$OUT_DIR/01-edit-FAIL.txt" ]] && die "editor DOM not present after /profile/edit load (see $OUT_DIR/01-edit-FAIL.txt)"

# ── 5. Type into location picker, pick row, screenshot ───────────────────
step "location picker — type + pick"
python3 - <<EOF
import asyncio, json, urllib.request, websockets, base64
pages = json.load(urllib.request.urlopen('http://127.0.0.1:9222/json'))
page  = [p for p in pages if p['id']=='$PAGE_ID'][0]
async def go():
  async with websockets.connect(page['webSocketDebuggerUrl'], max_size=None) as ws:
    async def call(m,p=None):
      await ws.send(json.dumps({'id':1,'method':m,'params':p or {}})); return json.loads(await ws.recv())
    # Open the location modal
    await call('Runtime.evaluate', {'expression':"document.querySelector('[data-modal=\"location\"]')?.click() || document.getElementById('disp-loc').click()"})
    await asyncio.sleep(0.5)
    # Type "Portland, OR" by setting value + dispatching input
    await call('Runtime.evaluate', {'expression':"(()=>{const el=document.getElementById('f-loc');el.focus();el.value='Portland, Oregon';el.dispatchEvent(new Event('input',{bubbles:true}));})()"})
    await asyncio.sleep(1.2)
    await call('Page.captureScreenshot',{'format':'png'})
    # Screenshot
    res = await call('Page.captureScreenshot',{'format':'png'})
    open('$OUT_DIR/02-picker-open.png','wb').write(base64.b64decode(res['result']['data']))
    # Pick first row if any
    pick = await call('Runtime.evaluate',{'expression':"(()=>{const i=document.querySelector('#loc-picker .ta-item');if(!i)return null;const ev=new MouseEvent('mousedown',{bubbles:true,cancelable:true});i.dispatchEvent(ev);return i.textContent.slice(0,60);})()",'returnByValue':True})
    print('  picked:', pick.get('result',{}).get('result',{}).get('value'))
    await asyncio.sleep(1.0)
    res = await call('Page.captureScreenshot',{'format':'png'})
    open('$OUT_DIR/03-after-pick.png','wb').write(base64.b64decode(res['result']['data']))
asyncio.run(go())
EOF

# ── 6. Toggle visibility, screenshot ─────────────────────────────────────
step "toggle location visibility → private"
python3 - <<EOF
import asyncio, json, urllib.request, websockets, base64
pages = json.load(urllib.request.urlopen('http://127.0.0.1:9222/json'))
page  = [p for p in pages if p['id']=='$PAGE_ID'][0]
async def go():
  async with websockets.connect(page['webSocketDebuggerUrl'], max_size=None) as ws:
    async def call(m,p=None):
      await ws.send(json.dumps({'id':1,'method':m,'params':p or {}})); return json.loads(await ws.recv())
    await call('Runtime.evaluate',{'expression':"(()=>{const r=document.querySelector('input[name=\"loc-vis\"][value=\"private\"]');r.checked=true;r.dispatchEvent(new Event('change',{bubbles:true}));})()"})
    await asyncio.sleep(0.8)
    r = await call('Page.captureScreenshot',{'format':'png'})
    open('$OUT_DIR/04-vis-private.png','wb').write(base64.b64decode(r['result']['data']))
asyncio.run(go())
EOF

# Confirm DB sees the visibility
DB_VIS=$(sudo -u profile-app psql -At -d profile_app -c "SELECT location_visibility FROM users WHERE id=$PA_ID")
[[ "$DB_VIS" == "private" ]] || die "visibility didn't save (db=$DB_VIS)"

# ── 7. /u/<slug> — anon vs self ──────────────────────────────────────────
step "/u/$SLUG anon → location should be omitted"
ANON_HTML=$(curl -sk -H "Cookie: loothdev_auth=$LOOTHDEV_TOK" "https://dev.loothgroup.com/u/$SLUG")
echo "$ANON_HTML" > "$OUT_DIR/05-u-anon.html"
if echo "$ANON_HTML" | grep -q 'class="loc"'; then
    die "anon /u/$SLUG leaked location element"
fi

# ── 8. /directory/members — confirm visible ──────────────────────────────
step "/directory/members — new user should appear"
LIST=$(curl -sk -H "Cookie: loothdev_auth=$LOOTHDEV_TOK; looth_id=$LOOTH_ID" \
    "https://dev.loothgroup.com/profile-api/v0/directory/members?page_size=200")
echo "$LIST" > "$OUT_DIR/06-directory.json"
COUNT=$(echo "$LIST" | python3 -c "import sys,json;d=json.load(sys.stdin);print(sum(1 for i in d.get('items',[]) if i.get('slug')=='$SLUG'))")
[[ "$COUNT" -ge 1 ]] || die "new user not in directory (page_size=200)"

# ── 9. page_size cap test ────────────────────────────────────────────────
step "page_size cap test"
CAPPED=$(curl -sk -H "Cookie: loothdev_auth=$LOOTHDEV_TOK; looth_id=$LOOTH_ID" \
    "https://dev.loothgroup.com/profile-api/v0/directory/members?page_size=500" \
    | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('page_size'))")
[[ "$CAPPED" == "200" ]] || die "page_size cap broken (got $CAPPED, want 200)"

# ── 9b. business_name patch + wp_users mirror (slice 3) ─────────────────
step "PATCH /me/name with business_name + display_name"
COOKIE="loothdev_auth=$LOOTHDEV_TOK; looth_id=$LOOTH_ID"
BIZ_RESP=$(curl -sk -X PATCH -H "Cookie: $COOKIE" -H "Content-Type: application/json" \
    -d '{"display_name":"Cold Walk","business_name":"Walk Test Repairs"}' \
    "https://dev.loothgroup.com/profile-api/v0/me/name")
echo "  $BIZ_RESP"
echo "$BIZ_RESP" | grep -q '"business_name":"Walk Test Repairs"' || die "business_name not echoed"

DB_BIZ=$(sudo -u profile-app psql -At -d profile_app -c "SELECT business_name FROM users WHERE id=$PA_ID")
[[ "$DB_BIZ" == "Walk Test Repairs" ]] || die "business_name didn't save (db=$DB_BIZ)"

WP_DN=$(sudo -u www-data wp --path=/var/www/dev user get $WP_ID --field=display_name)
[[ "$WP_DN" == "Cold Walk" ]] || die "wp_users.display_name not mirrored (wp=$WP_DN)"

step "/u/$SLUG shows business name on header"
U_HTML_BIZ=$(curl -sk -H "Cookie: loothdev_auth=$LOOTHDEV_TOK" "https://dev.loothgroup.com/u/$SLUG")
echo "$U_HTML_BIZ" | grep -q 'class="biz"' || die "business_name not rendered on public /u page"
echo "$U_HTML_BIZ" | grep -q 'Walk Test Repairs' || die "business_name string not on /u page"

# ── 9c. /whoami smoke + self-purge (slice 3.5) ──────────────────────────
step "/profile-api/v0/whoami returns authed shape with correct identity"
WHOAMI=$(curl -sk -H "Cookie: $COOKIE" "https://dev.loothgroup.com/profile-api/v0/whoami")
echo "$WHOAMI" | python3 -c "
import sys, json
d = json.load(sys.stdin)
need = ['authenticated','user_uuid','wp_user_id','display_name','tier','capabilities','cache']
miss = [k for k in need if k not in d]
if miss: print('MISSING:', miss); sys.exit(1)
if d['authenticated'] is not True: print('not authenticated'); sys.exit(1)
if d['wp_user_id'] != $WP_ID: print(f'wp_user_id mismatch: {d[\"wp_user_id\"]} vs $WP_ID'); sys.exit(1)
if d['display_name'] != 'Cold Walk': print(f'display_name stale: {d[\"display_name\"]}'); sys.exit(1)
print('  whoami shape ok: tier=%s prov=%s caps=%s' % (d['tier'], d.get('provenance','?'), list(d['capabilities'].keys())))
" || die "whoami payload invalid"

step "/whoami self-purge: PATCH name → next /whoami reflects new value"
curl -sk -X PATCH -H "Cookie: $COOKIE" -H "Content-Type: application/json" \
    -d '{"display_name":"Cold Walk Renamed"}' "https://dev.loothgroup.com/profile-api/v0/me/name" >/dev/null
DN_NOW=$(curl -sk -H "Cookie: $COOKIE" "https://dev.loothgroup.com/profile-api/v0/whoami" \
    | python3 -c "import sys,json;print(json.load(sys.stdin).get('display_name'))")
[[ "$DN_NOW" == "Cold Walk Renamed" ]] || die "self-purge failed: whoami still says '$DN_NOW' after rename"

# WP shim returns same shape
step "/wp-json/looth/v1/whoami shim returns identical shape"
SHIM=$(curl -sk -H "Cookie: $COOKIE" "https://dev.loothgroup.com/wp-json/looth/v1/whoami")
SHIM_DN=$(echo "$SHIM" | python3 -c "import sys,json;print(json.load(sys.stdin).get('display_name'))")
[[ "$SHIM_DN" == "Cold Walk Renamed" ]] || die "WP shim display_name mismatch: '$SHIM_DN'"

# Batch users endpoint
step "/profile-api/v0/users?uuids=<self> returns one item"
SELF_UUID=$(sudo -u profile-app psql -At -d profile_app -c "SELECT uuid FROM users WHERE id=$PA_ID")
BATCH_COUNT=$(curl -sk -H "Cookie: $COOKIE" "https://dev.loothgroup.com/profile-api/v0/users?uuids=$SELF_UUID" \
    | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('count'))")
[[ "$BATCH_COUNT" == "1" ]] || die "batch users wrong count: $BATCH_COUNT"

# Internal purge auth
step "/profile-api/v0/internal/purge-whoami requires X-LG-Internal-Auth"
NO_AUTH=$(curl -sk -o /dev/null -w '%{http_code}' -X POST -H "Host: dev.loothgroup.com" \
    -H "Content-Type: application/json" -d "{\"wp_user_id\":$WP_ID}" \
    "https://127.0.0.1/profile-api/v0/internal/purge-whoami")
[[ "$NO_AUTH" == "403" ]] || die "internal purge no-auth returned $NO_AUTH (want 403)"
SECRET=$(sudo cat /etc/lg-internal-secret)
WITH_AUTH=$(curl -sk -o /dev/null -w '%{http_code}' -X POST -H "Host: dev.loothgroup.com" \
    -H "X-LG-Internal-Auth: $SECRET" -H "Content-Type: application/json" \
    -d "{\"wp_user_id\":$WP_ID}" "https://127.0.0.1/profile-api/v0/internal/purge-whoami")
[[ "$WITH_AUTH" == "204" ]] || die "internal purge with-auth returned $WITH_AUTH (want 204)"

# ── 10. practices cold-walk (slice 3) ────────────────────────────────────
step "create practice via /me/practices"
COOKIE="loothdev_auth=$LOOTHDEV_TOK; looth_id=$LOOTH_ID"
PR_NAME="Bench Test Guitars ${RAND}"
CREATE_RESP=$(curl -sk -X POST -H "Cookie: $COOKIE" -H "Content-Type: application/json" \
    -d "{\"name\":\"$PR_NAME\",\"tagline\":\"slice-3 walk\",\"location_text\":\"Brooklyn, NY\",\"location_visibility\":\"public\"}" \
    "https://dev.loothgroup.com/profile-api/v0/me/practices")
echo "  create: $CREATE_RESP"
PR_UUID=$(echo "$CREATE_RESP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('uuid',''))")
PR_SLUG=$(echo "$CREATE_RESP" | python3 -c "import sys,json;print(json.load(sys.stdin).get('slug',''))")
[[ -n "$PR_UUID" ]] || die "practice create returned no uuid"
[[ -n "$PR_SLUG" ]] || die "practice create returned no slug"

step "/p/$PR_SLUG renders publicly with staff roster"
PR_HTML=$(curl -sk -H "Cookie: loothdev_auth=$LOOTHDEV_TOK" "https://dev.loothgroup.com/p/$PR_SLUG")
echo "$PR_HTML" > "$OUT_DIR/07-p-anon.html"
echo "$PR_HTML" | grep -q "$PR_NAME"          || die "/p/$PR_SLUG missing practice name"
echo "$PR_HTML" | grep -q 'id="staff"'        || die "/p/$PR_SLUG missing staff roster card"
echo "$PR_HTML" | grep -q "/u/$SLUG"          || die "/p/$PR_SLUG missing creator in staff roster"

step "/u/$SLUG shows practice in Practices card"
U_HTML=$(curl -sk -H "Cookie: loothdev_auth=$LOOTHDEV_TOK" "https://dev.loothgroup.com/u/$SLUG")
echo "$U_HTML" > "$OUT_DIR/08-u-with-practice.html"
echo "$U_HTML" | grep -q 'id="practices"'    || die "/u/$SLUG missing Practices section"
echo "$U_HTML" | grep -q "/p/$PR_SLUG"        || die "/u/$SLUG missing practice link"

step "update practice tagline"
UPDATE_RESP=$(curl -sk -X PATCH -H "Cookie: $COOKIE" -H "Content-Type: application/json" \
    -d '{"tagline":"updated tagline"}' \
    "https://dev.loothgroup.com/profile-api/v0/me/practices/$PR_UUID")
echo "  update: $UPDATE_RESP"
PR_HTML2=$(curl -sk -H "Cookie: loothdev_auth=$LOOTHDEV_TOK" "https://dev.loothgroup.com/p/$PR_SLUG")
echo "$PR_HTML2" | grep -q 'updated tagline'  || die "tagline update didn't render"

step "leave practice"
LEAVE_RESP=$(curl -sk -X DELETE -H "Cookie: $COOKIE" "https://dev.loothgroup.com/profile-api/v0/me/practices/$PR_UUID")
echo "  leave: $LEAVE_RESP"

step "/u/$SLUG no longer lists the practice"
U_HTML2=$(curl -sk -H "Cookie: loothdev_auth=$LOOTHDEV_TOK" "https://dev.loothgroup.com/u/$SLUG")
echo "$U_HTML2" > "$OUT_DIR/09-u-after-leave.html"
if echo "$U_HTML2" | grep -q "/p/$PR_SLUG"; then
    die "/u/$SLUG still references practice after leave"
fi

step "/p/$PR_SLUG persists as orphan with empty staff roster"
PR_HTML3=$(curl -sk -H "Cookie: loothdev_auth=$LOOTHDEV_TOK" "https://dev.loothgroup.com/p/$PR_SLUG")
echo "$PR_HTML3" | grep -q "$PR_NAME"         || die "/p/$PR_SLUG disappeared after leave"
if echo "$PR_HTML3" | grep -q "/u/$SLUG"; then
    die "/p/$PR_SLUG still lists creator in roster after leave"
fi

# Cleanup the test practice — orphans are fine for now but the walk should
# not leave litter every run.
sudo -u profile-app psql -d profile_app -c "DELETE FROM practices WHERE uuid='$PR_UUID'" >/dev/null

step "all checks passed"
ls -la "$OUT_DIR"
echo
echo "screenshots + transcript: $OUT_DIR"
echo "wp_user_id=$WP_ID pa_id=$PA_ID slug=$SLUG"
