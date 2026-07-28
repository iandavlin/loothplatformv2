#!/bin/bash
# editor-rail-reachable-gate.sh — the profile editor's SECTION PICKER must be
# REACHABLE at every width (docs/CRAFT-STANDARD.md).
#
# Defect class (found 2026-06-15, Danny West / WP 206 / iPad portrait):
#   RESPONSIVE-DISPLAY-NONE-NO-FALLBACK
#   The editor's section nav was `display:none` below the breakpoint with no
#   toggle or replacement, so the means of editing your profile silently
#   vanished on iPad portrait, phones, and narrow/split windows.
#   "Logged in but no sidebar." Pure layout — identity was fine.
#
# REWRITTEN 2026-07-28 (profile-audit). This gate had rotted against a surface
# that moved underneath it, and was asserting on a page that no longer exists:
#   - it drove /profile/edit expecting the OLD standalone editor's #lg-rail /
#     #lg-rail-toggle / .tab[data-anchor]. /profile/edit now 302s to /u/<slug>,
#     where the editor is INLINE and the section picker is the caddy. None of
#     those three ids exist there, so it could only ever report "editor did not
#     render" — a RED that said nothing about the defect class.
#   - it encoded a 780px breakpoint. The live breakpoint is 1380px, so its
#     "desktop" case at 1024px was testing the WRONG SIDE of the line: 1024 is
#     below 1380 and still gets the drawer.
#   - it read the dev token from a conf file that no longer exists (see
#     lib/gate-token.sh — four gates shared that bug).
#
# What it asserts now, against the CURRENT surface:
#   768px  (below 1380) — caddy is off-canvas; at least one [data-caddy-open]
#                         opener is VISIBLE; clicking it makes the caddy's
#                         section bubbles hit-testable.
#   1440px (>= 1380)    — caddy is a static visible column AND the sub-1380
#                         openers do not leak onto it.
#
# This also guards Ian's 2026-07-28 option-A change (the picker's openers moved
# out of the privacy panel into a "Your layout" row + an end-of-list add card):
# if a future edit removes those openers without a replacement, this goes RED —
# which is the entire point of the defect class.
#
# CDP-based, so it can flake under load (RULES §5) — held OUT of run-all.sh;
# run standalone, and re-run once before believing a RED. Exit 0 = GREEN.
set -uo pipefail

WP="/var/www/dev"
APP="/srv/profile-app"; SUBJ=7      # a claimed member (owns an inline /u/ editor)

. "$(dirname "$0")/lib/gate-token.sh"
GATE=$(gate_token) || { echo "GATE-ERROR  $GATE_TOKEN_ERR"; exit 1; }

read LIN LIV SN SV < <(sudo -n wp --path="$WP" --allow-root eval '
  $uid='"$SUBJ"'; $exp=time()+1800; $t=WP_Session_Tokens::get_instance($uid)->create($exp);
  echo LOGGED_IN_COOKIE." ".wp_generate_auth_cookie($uid,$exp,"logged_in",$t)." ".SECURE_AUTH_COOKIE." ".wp_generate_auth_cookie($uid,$exp,"secure_auth",$t);' 2>/dev/null)
LOOTH=$(sudo -n -u profile-app php "$APP/bin/mint-dev-token.php" "$SUBJ" 2>/dev/null | tail -1)
[ -n "${LIV:-}" ] && [ -n "${LOOTH:-}" ] || { echo "GATE-ERROR  could not mint owner session — gate CANNOT RUN (not a craft failure)"; exit 1; }

GATE="$GATE" LIN="$LIN" LIV="$LIV" SN="$SN" SV="$SV" LOOTH="$LOOTH" python3 - <<'PYEOF'
import asyncio, json, os, urllib.request, websockets, sys
C=[(os.environ['LIN'],os.environ['LIV']),(os.environ['SN'],os.environ['SV']),
   ('loothdev_auth',os.environ['GATE']),('looth_id',os.environ['LOOTH'])]
cookies=[{'domain':'dev.loothgroup.com','name':n,'value':v,'path':'/','secure':True,'httpOnly':True} for n,v in C]
# /profile/edit 302s to the owner's /u/<slug>, which IS the inline editor — so this
# exercises the real entry point as well as the picker.
URL='https://dev.loothgroup.com/profile/edit'

SHOWN = """function shown(el){if(!el)return false;var s=getComputedStyle(el),r=el.getBoundingClientRect();
  return s.display!=='none'&&s.visibility!=='hidden'&&r.width>0&&r.height>0;}"""

async def main():
  pages=json.load(urllib.request.urlopen('http://127.0.0.1:9222/json'))
  page=[p for p in pages if p['type']=='page'][0]
  fails=[]
  async with websockets.connect(page['webSocketDebuggerUrl'],max_size=None) as ws:
    i=0
    async def cmd(m,p=None):
      nonlocal i; i+=1; mid=i
      await ws.send(json.dumps({'id':mid,'method':m,'params':p or {}}))
      while True:
        r=json.loads(await ws.recv())
        if r.get('id')==mid: return r
    async def ev(e):
      r=await cmd('Runtime.evaluate',{'expression':e,'returnByValue':True,'awaitPromise':True})
      return r['result'].get('result',{}).get('value')
    await cmd('Network.enable'); await cmd('Page.enable'); await cmd('Network.clearBrowserCookies')
    for c in cookies: await cmd('Network.setCookie',c)

    # ---- 768px (iPad portrait): caddy off-canvas, reachable via an opener ----
    await cmd('Emulation.setDeviceMetricsOverride',{'width':768,'height':1024,'deviceScaleFactor':1,'mobile':True})
    await cmd('Page.navigate',{'url':URL}); await asyncio.sleep(3.0)
    st=await ev("""(function(){""" + SHOWN + """
      var caddy=document.getElementById('lg-caddy');
      var opens=[].slice.call(document.querySelectorAll('[data-caddy-open]'));
      var cr=caddy?caddy.getBoundingClientRect():null;
      return JSON.stringify({
        editor: !!caddy,
        openerShown: opens.some(shown),
        openerCount: opens.length,
        caddyOffscreen: !cr || cr.left>=innerWidth-20 || cr.right<=0
      });})()""")
    st=json.loads(st or '{}')
    if not st.get('editor'):
        fails.append("768: inline editor did not render (no #lg-caddy) — owner session/looth_id issue?")
    if not st.get('openerShown'):
        fails.append("768: NO visible [data-caddy-open] opener (found %s) — the section picker is "
                     "UNREACHABLE. This is the RESPONSIVE-DISPLAY-NONE-NO-FALLBACK class."
                     % st.get('openerCount'))
    if not st.get('caddyOffscreen'):
        fails.append("768: caddy is not off-canvas by default (drawer closed-state wrong)")

    # open via the FIRST visible opener, then hit-test a section bubble
    await ev("""(function(){""" + SHOWN + """
      var o=[].slice.call(document.querySelectorAll('[data-caddy-open]')).filter(shown)[0];
      o&&o.click();})()"""); await asyncio.sleep(0.6)
    reach=await ev("""(function(){var it=document.querySelector('.lg-caddy__item'); if(!it)return false;
      var r=it.getBoundingClientRect(); if(r.width<=0||r.left<0||r.left>innerWidth)return false;
      var hit=document.elementFromPoint(r.left+6,r.top+6);
      return !!(hit&&(hit===it||it.contains(hit)||hit.closest('.lg-caddy__item')));})()""")
    if not reach:
        fails.append("768: after opening the drawer, the section bubbles (.lg-caddy__item) are NOT hit-testable")

    # ---- 1440px (>=1380): caddy is the permanent column, openers must not leak ----
    await cmd('Emulation.setDeviceMetricsOverride',{'width':1440,'height':900,'deviceScaleFactor':1,'mobile':False})
    await cmd('Page.navigate',{'url':URL}); await asyncio.sleep(2.5)
    d=await ev("""(function(){""" + SHOWN + """
      var caddy=document.getElementById('lg-caddy');
      var opens=[].slice.call(document.querySelectorAll('[data-caddy-open]'));
      return JSON.stringify({caddyShown:shown(caddy), openersHidden:!opens.some(shown)});})()""")
    d=json.loads(d or '{}')
    if not d.get('caddyShown'):
        fails.append("1440: permanent caddy column is not visible (regressed the >=1380 layout)")
    if not d.get('openersHidden'):
        fails.append("1440: a sub-1380 opener leaks onto the wide layout (>=1380 must be unchanged)")

  if fails:
    print("==================== EDITOR-RAIL GATE RED ====================")
    for f in fails: print("  "+f)
    sys.exit(1)
  print("editor-rail-reachable-gate: 768 picker reachable via a visible opener; "
        "1440 permanent caddy, no opener leak")
  print("==================== EDITOR-RAIL GATE GREEN ====================")
asyncio.run(main())
PYEOF
