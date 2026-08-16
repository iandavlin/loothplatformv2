#!/usr/bin/env python3
"""
Verify ruling 5's picker against the REAL deployed ACF markup, without a deploy.

The picker is client-side: it transforms markup ACF already renders. So the honest
pre-merge test is to load the LIVE compose page — which carries the genuine
taxonomy fields, 18 and 36 terms, hierarchy and all — and inject the EXACT js/css
that ships, sliced out of the mu-plugin rather than retyped.

THE ASSERTION THAT MATTERS IS THE LAST ONE. A picker that looks right and does not
move ACF's real inputs would post an empty taxonomy and lose the member's choice
silently — a page that looks perfect and does nothing. So this clicks an option and
then reads the underlying checkbox.
"""
import json, os, subprocess, sys, time
import websocket
CDP="http://127.0.0.1:9222"; REPO="/home/ubuntu/worktrees/frontend-compose"
# The js/css are sliced out of the SHIPPED mu-plugin at run time, so this can
# never drift from what actually ships.
SP=os.environ.get("LG_TAXO_SLICE_DIR", os.path.dirname(os.path.abspath(__file__)))
def sh(c): return subprocess.run(c,capture_output=True,text=True)
env=dict(l.partition("=")[::2] for l in sh(["bash",os.path.join(REPO,"tools","gates","gate-env.sh")]).stdout.splitlines())
def wp_cookies(login):
    r=sh(["sudo","-n","-u","looth-dev","wp","--path=/var/www/dev","eval",
      f"$u=get_user_by('login','{login}');$e=time()+3600;"
      "echo LOGGED_IN_COOKIE.'|'.wp_generate_auth_cookie($u->ID,$e,'logged_in').\"\\n\";"
      "echo SECURE_AUTH_COOKIE.'|'.wp_generate_auth_cookie($u->ID,$e,'secure_auth');"])
    return [l.split("|",1) for l in r.stdout.splitlines() if "|" in l and not l.startswith(("PHP ","Warning"))]
class Tab:
    def __init__(self):
        t=json.loads(subprocess.check_output(["curl","-s","-X","PUT",f"{CDP}/json/new?about:blank"]))
        self.id=t["id"]; self.ws=websocket.create_connection(t["webSocketDebuggerUrl"],suppress_origin=True,timeout=60); self.n=0
    def call(self,m,**p):
        self.n+=1; self.ws.send(json.dumps({"id":self.n,"method":m,"params":p}))
        while True:
            r=json.loads(self.ws.recv())
            if r.get("id")==self.n:
                if "error" in r: raise RuntimeError(f"{m}: {r['error']}")
                return r.get("result",{})
    def js(self,e,tries=8):
        for _ in range(tries):
            time.sleep(0.6)
            v=self.call("Runtime.evaluate",expression=e,returnByValue=True).get("result",{}).get("value")
            if v is not None and v != "": return v
        return None
    def close(self):
        try: self.ws.close()
        finally: sh(["curl","-s",f"{CDP}/json/close/{self.id}"])

dom=env["LG_GATE_DOMAIN"]; url=env["LG_GATE_HOST"]+"/compose/?type=loothprint"
cookies=[{"name":"loothdev_auth","value":env["LG_GATE_TOKEN"],"domain":"."+dom,"path":"/","secure":True}]
for n,v in wp_cookies("claude_admin"):
    cookies.append({"name":n,"value":v,"domain":dom,"path":"/","secure":True,"httpOnly":True,"sameSite":"Lax"})
CSS=open(SP+"/taxo.css").read(); JS=open(SP+"/taxo.js").read()
fails=[]
for width,theme in ((1280,"light"),(390,"dark")):
    tab=Tab()
    try:
        tab.call("Page.enable");tab.call("Network.enable");tab.call("Network.clearBrowserCookies")
        tab.call("Network.setCookies",cookies=cookies)
        tab.call("Emulation.setDeviceMetricsOverride",width=width,height=1500,deviceScaleFactor=1,
                 mobile=width<641,screenWidth=width,screenHeight=1500)
        tab.call("Page.navigate",url=url);time.sleep(2.5)
        tab.call("Runtime.evaluate",expression=(
            f"localStorage.setItem('lg-set-theme','{theme}');"
            f"document.documentElement.setAttribute('data-lguser-theme','{theme}');"))
        tab.call("Page.navigate",url=url);time.sleep(3.5)
        live=tab.js("JSON.stringify({form:!!document.querySelector('.lgfc__card'),"
                    "taxo:document.querySelectorAll('.lgfc .acf-field-taxonomy').length})")
        d=json.loads(live)
        print(f"── {width}px {theme}: form={d['form']} acf taxonomy fields={d['taxo']}")
        if not d["form"] or d["taxo"] < 2:
            print("   CANNOT TRUST — the page did not give us the real fields"); fails.append("liveness"); continue
        tab.call("Runtime.evaluate",expression=
          "(()=>{const s=document.createElement('style');s.textContent="+json.dumps(CSS)+";document.head.appendChild(s);return 1})()")
        tab.call("Runtime.evaluate",expression=JS)
        time.sleep(1)
        dbg=json.loads(tab.js("""(()=>{
          const u=document.querySelector('.lgfc .acf-field-taxonomy .acf-checkbox-list');
          if(!u) return JSON.stringify({err:'no list'});
          const c=getComputedStyle(u), r=u.getBoundingClientRect();
          return JSON.stringify({cls:u.className, h:Math.round(r.height), w:Math.round(r.width),
            pos:c.position, clip:c.clipPath||c.clip, disp:c.display, ov:c.overflow,
            maxh:c.maxHeight, height:c.height});})()"""))
        print("   debug source list:", dbg)
        res=json.loads(tab.js("""(()=>{
          const trigs=[...document.querySelectorAll('.lgfc-taxo__trig')];
          const srcs=[...document.querySelectorAll('.lgfc .acf-field-taxonomy .acf-checkbox-list')];
          return JSON.stringify({
            trigs:trigs.length,
            srcHidden:srcs.length>0 && srcs.every(u=>u.getBoundingClientRect().height<=2),
            closedRow:trigs.length?trigs[0].textContent.replace(/\\s+/g,' ').trim().slice(0,50):'',
            sheetsHidden:[...document.querySelectorAll('.lgfc-taxo__sheet')].every(x=>x.hidden)
          });})()"""))
        for label,ok,detail in (
            ("two sheet triggers built", res["trigs"]==2, f"n={res['trigs']}"),
            ("old scrolling list hidden", res["srcHidden"], ""),
            ("closed row states something", bool(res["closedRow"]), res["closedRow"][:36]),
            ("sheets start closed", res["sheetsHidden"], ""),
        ):
            print(f"   {'ok  ' if ok else 'RED '} {label} {detail}")
            if not ok: fails.append(f"{width}{theme}: {label}")
        # ── the one that matters: does a tap move ACF's REAL input? ──
        act=json.loads(tab.js("""(()=>{
          const f=document.querySelector('.lgfc .acf-field-taxonomy');
          const trig=f.querySelector('.lgfc-taxo__trig'); trig.click();
          const sheet=f.querySelector('.lgfc-taxo__sheet');
          const opt=sheet.querySelector('.lgfc-taxo__opt');
          const before=[...f.querySelectorAll('input[type=checkbox],input[type=radio]')].filter(i=>i.checked).length;
          const label=opt?opt.textContent.trim():'';
          if(opt) opt.click();
          const after=[...f.querySelectorAll('input[type=checkbox],input[type=radio]')].filter(i=>i.checked).length;
          const checkedNow=[...f.querySelectorAll('input[type=checkbox],input[type=radio]')]
            .filter(i=>i.checked).map(i=>{const li=i.closest('li');return li?li.textContent.trim():i.value;});
          // search must narrow the list
          const s=sheet.querySelector('.lgfc-taxo__search input');
          const all=sheet.querySelectorAll('.lgfc-taxo__opt').length;
          s.value=label.slice(0,4); s.dispatchEvent(new Event('input',{bubbles:true}));
          const narrowed=sheet.querySelectorAll('.lgfc-taxo__opt').length;
          return JSON.stringify({before,after,label,checkedNow,all,narrowed,
            rowNow:trig.textContent.replace(/\\s+/g,' ').trim().slice(0,60)});})()"""))
        realmove = act["after"] > act["before"] and act["label"] and any(act["label"] in c for c in act["checkedNow"])
        for label,ok,detail in (
            ("a tap CHECKS ACF's real input", realmove, f"{act['before']}->{act['after']} {act['checkedNow']}"),
            ("closed row now names the pick", act["label"][:18] in act["rowNow"], act["rowNow"][:44]),
            ("search narrows the list", act["narrowed"] < act["all"] and act["narrowed"] > 0, f"{act['all']}->{act['narrowed']}"),
        ):
            print(f"   {'ok  ' if ok else 'RED '} {label} {detail}")
            if not ok: fails.append(f"{width}{theme}: {label}")
    finally: tab.close()
print()
if fails:
    print(f"RED — {len(fails)}: " + "; ".join(fails)); sys.exit(1)
print("GREEN — the picker builds, hides the old box, states the answer, drives ACF's real inputs, and searches.")
