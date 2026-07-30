#!/usr/bin/env python3
"""
§3.5 ⋯ menu — DARK theme + the account-emails-off note, done right.

The first attempt emulated prefers-color-scheme and "passed" while rendering LIGHT:
this site's dark signal is html[data-lguser-theme="dark"], and the popover's dark
rules are injected by /app-settings.js — which the harness was 404ing, so they were
never present. Both fixed; this asserts the ACTUAL painted colour, not a proxy.

Also probes whether the mobile reply-sheet (§2.4) is reachable now that root-level
overlays are served.
"""
import asyncio, json, urllib.request, sys, base64, subprocess
import websockets

CDP="http://127.0.0.1:9222"; HUB="http://127.0.0.1:8791/hub/"; SHOT="/tmp/tf-exercise/shots"
out=[]; fails=0; passes=0
def log(*a):
    s=" ".join(str(x) for x in a); out.append(s); print(s,flush=True)
def check(l,g,w):
    global fails,passes
    ok=g==w
    if ok: passes+=1
    else: fails+=1
    log(f"  {'PASS' if ok else 'FAIL'}  {l}"+("" if ok else f"   got={g!r} want={w!r}"))
def http(p): return json.load(urllib.request.urlopen(CDP+p))

class Page:
    def __init__(s,ws): s.ws=ws; s.n=0
    async def send(s,m,p=None):
        s.n+=1; i=s.n
        await s.ws.send(json.dumps({"id":i,"method":m,"params":p or {}}))
        while True:
            r=json.loads(await s.ws.recv())
            if r.get("id")==i:
                if "error" in r: raise RuntimeError(f"{m}: {r['error']}")
                return r.get("result",{})
    async def ev(s,e):
        r=await s.send("Runtime.evaluate",{"expression":e,"returnByValue":True,"awaitPromise":True})
        if r.get("exceptionDetails"):
            raise RuntimeError("JS: "+str(r["exceptionDetails"].get("text")))
        return r["result"].get("value")
    async def click(s,sel,nth=0):
        box=await s.ev(f"""(()=>{{const e=[...document.querySelectorAll({json.dumps(sel)})][{nth}];
            if(!e)return null;e.scrollIntoView({{block:'center'}});
            const r=e.getBoundingClientRect();return {{x:r.x+r.width/2,y:r.y+r.height/2}};}})()""")
        if not box: raise RuntimeError("no el "+sel)
        for t,b in (("mousePressed",1),("mouseReleased",0)):
            await s.send("Input.dispatchMouseEvent",{"type":t,"x":box["x"],"y":box["y"],
                                                    "button":"left","clickCount":1,"buttons":b})
    async def shot(s,path):
        r=await s.send("Page.captureScreenshot",{"format":"png"})
        open(path,"wb").write(base64.b64decode(r["data"]))

async def main():
    bws=http("/json/version")["webSocketDebuggerUrl"]
    async with websockets.connect(bws,max_size=None) as b:
        tgt=(await Page(b).send("Target.createTarget",{"url":"about:blank"}))["targetId"]
    log(f"created target {tgt[:12]}")
    pw=next(t["webSocketDebuggerUrl"] for t in http("/json") if t["id"]==tgt)
    try:
        async with websockets.connect(pw,max_size=None) as ws:
            p=Page(ws)
            await p.send("Page.enable"); await p.send("Runtime.enable")
            await p.send("Emulation.setDeviceMetricsOverride",{"width":1280,"height":950,"deviceScaleFactor":1,"mobile":False})
            jwt=open("/tmp/tf-exercise/jwt.txt").read().strip()
            cks=[l.strip() for l in open("/tmp/tf-exercise/cookies.txt") if l.strip()]
            ck=[{"name":l.split("=",1)[0],"value":l.split("=",1)[1],"domain":"127.0.0.1","path":"/"} for l in cks]
            ck.append({"name":"looth_id","value":jwt,"domain":"127.0.0.1","path":"/"})
            await p.send("Network.clearBrowserCookies"); await p.send("Network.setCookies",{"cookies":ck})

            async def load(dark=False):
                await p.send("Page.navigate",{"url":HUB})
                for _ in range(60):
                    await asyncio.sleep(0.5)
                    if await p.ev("document.readyState==='complete'"): break
                await asyncio.sleep(2.0)
                if dark:
                    # THE documented signal (site-header.php:128), normally set by app-settings.js
                    await p.ev("document.documentElement.setAttribute('data-lguser-theme','dark'); 1")
                    await asyncio.sleep(0.6)
            async def openmenu():
                await p.click("[data-lg-notif-link]"); await asyncio.sleep(2.2)
                await p.click("[data-notif-more]"); await asyncio.sleep(2.2)

            log("\n=== app-settings.js must actually be loaded, or dark is untestable ===")
            await load()
            check("/app-settings.js loaded (200, not 404)",
                  await p.ev("""(async()=>{const r=await fetch('/app-settings.js');return r.status})()"""), 200)
            check("its dark rules reached the document",
                  await p.ev("""[...document.styleSheets].some(s=>{try{return [...s.cssRules].some(r=>/lg-notif-menu/.test(r.cssText))}catch(e){return false}})"""), True)

            log("\n=== LIGHT ===")
            await openmenu()
            light=json.loads(await p.ev("""(()=>{const m=document.querySelector('.lg-notif-menu');
                if(!m)return JSON.stringify(null);const cs=getComputedStyle(m);
                const it=m.querySelector('[data-follow-menu]');
                return JSON.stringify({bg:cs.backgroundColor,item:getComputedStyle(it).color});})()"""))
            log(f"  {json.dumps(light)}")
            check("light menu is the cream card", light["bg"], "rgb(255, 255, 255)")
            await p.shot(f"{SHOT}/I-dots-light.png")

            log("\n=== DARK (html[data-lguser-theme=dark]) ===")
            await load(dark=True)
            await openmenu()
            dark=json.loads(await p.ev("""(()=>{const m=document.querySelector('.lg-notif-menu');
                if(!m)return JSON.stringify(null);const cs=getComputedStyle(m);
                const it=m.querySelector('[data-follow-menu]');
                const dots=document.querySelector('.lg-notif__more');
                return JSON.stringify({bg:cs.backgroundColor,border:cs.borderTopColor,
                  item:getComputedStyle(it).color, dots:getComputedStyle(dots).color});})()"""))
            log(f"  {json.dumps(dark)}")
            check("menu background is the DARK card #1c1f22", dark["bg"], "rgb(28, 31, 34)")
            check("menu text is the dark ink #e5e7e1", dark["item"], "rgb(229, 231, 225)")
            check("the ⋯ itself goes sage-on-dark #9cb37d", dark["dots"], "rgb(156, 179, 125)")
            check("dark differs from light", dark["bg"] != light["bg"], True)
            await p.shot(f"{SHOT}/J-dots-dark.png")

            log("\n=== ACCOUNT EMAILS OFF, in dark ===")
            subprocess.run(["sudo","-u","looth-dev","wp","--path=/var/www/dev","user","meta","update",
                            "1912","bb_forums_subscribed_reply","no"],capture_output=True)
            await load(dark=True); await openmenu()
            mu=json.loads(await p.ev("""(()=>{const m=document.querySelector('.lg-notif-menu');
                if(!m)return JSON.stringify(null);
                const e=m.querySelector("[data-follow-menu='email']");
                return JSON.stringify({muted:e.classList.contains('is-muted'),
                  opacity:getComputedStyle(e).opacity,
                  notes:[...m.querySelectorAll('.lg-notif-menu__note')].map(n=>n.textContent.trim())});})()"""))
            log(f"  {json.dumps(mu)}")
            check("✉ is-muted", mu["muted"], True)
            check("muted is visually dimmed", float(mu["opacity"]) < 1.0, True)
            check("says the account has discussion emails off (§8.1.3a)",
                  any("turned off" in n.lower() for n in mu["notes"]), True)
            await p.shot(f"{SHOT}/K-dots-dark-emails-off.png")
            subprocess.run(["sudo","-u","looth-dev","wp","--path=/var/www/dev","user","meta","delete",
                            "1912","bb_forums_subscribed_reply"],capture_output=True)

            log("\n=== §2.4 probe: is the mobile reply-sheet reachable on this page? ===")
            sheet=json.loads(await p.ev("""(()=>JSON.stringify({
                hubPolishTag: [...document.querySelectorAll('script[src*="hub-polish"]')].length,
                sheetEl: !!document.getElementById('looth-rep-sheet'),
                lrsNotify: document.querySelectorAll('.lrs-notify').length}))()"""))
            log(f"  {json.dumps(sheet)}")
            log("  → hub-polish.js is not loaded by the Hub, so #looth-rep-sheet never exists here."
                if not sheet["sheetEl"] else "  → sheet present, exercisable")
    finally:
        try:
            async with websockets.connect(http("/json/version")["webSocketDebuggerUrl"],max_size=None) as b:
                await Page(b).send("Target.closeTarget",{"targetId":tgt})
            log(f"\nclosed target {tgt[:12]}")
        except Exception as e: log(f"\nclose failed: {e}")
    log(f"\n=== {passes} passed, {fails} failed ===")
    open("/tmp/tf-exercise/dark-report.txt","w").write("\n".join(out))
    sys.exit(1 if fails else 0)

asyncio.run(main())
