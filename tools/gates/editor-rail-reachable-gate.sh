#!/bin/bash
# editor-rail-reachable-gate.sh — the profile editor's section nav must be
# REACHABLE at every width (docs/CRAFT-STANDARD.md).
#
# Defect class (found 2026-06-15, Danny West / WP 206 / iPad portrait):
#   RESPONSIVE-DISPLAY-NONE-NO-FALLBACK
#   The editor's left rail (.rail — the section nav) was `display:none` below
#   780px with no toggle or replacement, so the sidebar to edit your profile
#   silently vanished on iPad portrait (768px), phones, and narrow/split windows.
#   "Logged in but no sidebar." Pure layout — identity was fine.
#
# This gate drives a real owner at 768px and asserts the section nav is
# reachable: a visible toggle reveals an off-canvas rail whose tabs become
# hit-testable. It also confirms the desktop (>780) rail is untouched.
#
# CDP-based, so it can flake under load (RULES §5) — held OUT of run-all.sh;
# run standalone, and re-run once before believing a RED. Exit 0 = GREEN.
set -uo pipefail

WP="/var/www/dev"; CONF="/etc/nginx/sites-available/dev.loothgroup.com.conf"
APP="/srv/profile-app"; SUBJ=7      # a claimed member (owns a /profile/edit editor)

GATE=$(grep -oP '(?<=set \$loothdev_token ")[^"]+' "$CONF" | head -1)
[ -n "${GATE:-}" ] || { echo "GATE-ERROR  cannot read dev gate token"; exit 1; }

read LIN LIV SN SV < <(sudo -u www-data wp --path="$WP" eval '
  $uid='"$SUBJ"'; $exp=time()+1800; $t=WP_Session_Tokens::get_instance($uid)->create($exp);
  echo LOGGED_IN_COOKIE." ".wp_generate_auth_cookie($uid,$exp,"logged_in",$t)." ".SECURE_AUTH_COOKIE." ".wp_generate_auth_cookie($uid,$exp,"secure_auth",$t);' 2>/dev/null)
LOOTH=$(sudo -u profile-app php "$APP/bin/mint-dev-token.php" "$SUBJ" 2>/dev/null | tail -1)
[ -n "${LIV:-}" ] && [ -n "${LOOTH:-}" ] || { echo "GATE-ERROR  could not mint owner session"; exit 1; }

GATE="$GATE" LIN="$LIN" LIV="$LIV" SN="$SN" SV="$SV" LOOTH="$LOOTH" python3 - <<'PYEOF'
import asyncio, json, os, urllib.request, websockets, sys
C=[(os.environ['LIN'],os.environ['LIV']),(os.environ['SN'],os.environ['SV']),
   ('loothdev_auth',os.environ['GATE']),('looth_id',os.environ['LOOTH'])]
cookies=[{'domain':'dev.loothgroup.com','name':n,'value':v,'path':'/','secure':True,'httpOnly':True} for n,v in C]
URL='https://dev.loothgroup.com/profile/edit'
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

    # ---- 768px (iPad portrait): nav hidden by default, reachable via toggle ----
    await cmd('Emulation.setDeviceMetricsOverride',{'width':768,'height':1024,'deviceScaleFactor':1,'mobile':True})
    await cmd('Page.navigate',{'url':URL}); await asyncio.sleep(3.0)
    st=await ev("""(function(){
      function shown(el){if(!el)return false;var s=getComputedStyle(el),r=el.getBoundingClientRect();
        return s.display!=='none'&&s.visibility!=='hidden'&&r.width>0&&r.height>0;}
      var rail=document.getElementById('lg-rail'),tog=document.getElementById('lg-rail-toggle');
      var rr=rail?rail.getBoundingClientRect():null;
      return JSON.stringify({editor:!!document.querySelector('.tab[data-anchor]'),
        toggleShown:shown(tog), railOffscreen: !rr|| rr.left<=-20 || rr.right<=0});
    })()""")
    st=json.loads(st or '{}')
    if not st.get('editor'): fails.append("768: editor did not render (no .tab) — owner session/looth_id issue?")
    if not st.get('toggleShown'): fails.append("768: .rail-toggle hamburger is NOT visible (no way to reach the section nav)")
    if not st.get('railOffscreen'): fails.append("768: rail is not off-canvas by default (drawer closed-state wrong)")
    # open + hit-test a tab
    await ev("var t=document.getElementById('lg-rail-toggle'); t&&t.click();"); await asyncio.sleep(0.5)
    reach=await ev("""(function(){var tab=document.querySelector('.tab[data-anchor]'); if(!tab)return false;
      var r=tab.getBoundingClientRect(); if(r.width<=0||r.left<0||r.left>innerWidth)return false;
      var hit=document.elementFromPoint(r.left+6,r.top+6); return !!(hit&&(hit===tab||tab.contains(hit)||hit.closest('.tab')));})()""")
    if not reach: fails.append("768: after opening the drawer, the section nav (.tab) is NOT hit-testable")

    # ---- 1024px (desktop): rail static & visible, no toggle (no regression) ----
    await cmd('Emulation.setDeviceMetricsOverride',{'width':1024,'height':900,'deviceScaleFactor':1,'mobile':False})
    await cmd('Page.navigate',{'url':URL}); await asyncio.sleep(2.5)
    d=await ev("""(function(){
      function shown(el){if(!el)return false;var s=getComputedStyle(el),r=el.getBoundingClientRect();
        return s.display!=='none'&&r.width>0&&r.height>0;}
      var rail=document.getElementById('lg-rail'),tog=document.getElementById('lg-rail-toggle');
      return JSON.stringify({railShown:shown(rail), toggleHidden:!shown(tog)});})()""")
    d=json.loads(d or '{}')
    if not d.get('railShown'): fails.append("1024: desktop rail is not visible (regressed the static column)")
    if not d.get('toggleHidden'): fails.append("1024: hamburger leaks onto desktop (>780 layout must be unchanged)")

  if fails:
    print("==================== EDITOR-RAIL GATE RED ====================")
    for f in fails: print("  "+f)
    sys.exit(1)
  print("editor-rail-reachable-gate: 768 nav reachable via toggle; 1024 static rail, no toggle leak")
  print("==================== EDITOR-RAIL GATE GREEN ====================")
asyncio.run(main())
PYEOF
