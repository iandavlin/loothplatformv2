#!/usr/bin/env python3
"""
§3.5 ⋯ menu — tight re-run. The menu's ticks are asserted to MATCH THE STORE at every
step, with the store printed either side of each click, so a stale baseline can never
be mistaken for a defect (or hide one).
"""
import asyncio, json, urllib.request, sys, base64
import websockets

CDP="http://127.0.0.1:9222"; HUB="http://127.0.0.1:8791/hub/"
API="/bb-mirror-api/v0/follow"; SHOT="/tmp/tf-exercise/shots"
out=[]; fails=0; passes=0
def log(*a):
    s=" ".join(str(x) for x in a); out.append(s); print(s,flush=True)
def check(l,g,w):
    global fails,passes
    ok = g==w
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
            raise RuntimeError("JS: "+str(r["exceptionDetails"].get("text"))+" "+str(r.get("result",{}).get("description","")))
        return r["result"].get("value")
    async def click(s,sel,nth=0):
        box=await s.ev(f"""(()=>{{const e=[...document.querySelectorAll({json.dumps(sel)})][{nth}];
            if(!e)return null; e.scrollIntoView({{block:'center'}});
            const r=e.getBoundingClientRect(); return {{x:r.x+r.width/2,y:r.y+r.height/2}};}})()""")
        if not box: raise RuntimeError("no el "+sel)
        for t,b in (("mousePressed",1),("mouseReleased",0)):
            await s.send("Input.dispatchMouseEvent",{"type":t,"x":box["x"],"y":box["y"],
                                                    "button":"left","clickCount":1,"buttons":b})
    async def shot(s,path):
        r=await s.send("Page.captureScreenshot",{"format":"png"})
        open(path,"wb").write(base64.b64decode(r["data"]))

async def main():
    global fails
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

            async def load():
                await p.send("Page.navigate",{"url":HUB})
                for _ in range(60):
                    await asyncio.sleep(0.5)
                    if await p.ev("document.readyState==='complete'"): break
                await asyncio.sleep(2.0)

            async def store(t):
                return json.loads(await p.ev(f"""(async()=>{{
                    const r=await fetch('{API}?topics='+{json.dumps(t)},{{credentials:'same-origin'}});
                    const j=await r.json(); return JSON.stringify(j.state[{json.dumps(t)}]||null);}})()"""))
            async def setb(t,ch,on):
                return await p.ev(f"""(async()=>{{
                    const g=await (await fetch('{API}?topics='+{json.dumps(t)},{{credentials:'same-origin'}})).json();
                    const r=await fetch('{API}',{{method:'POST',credentials:'same-origin',
                      headers:{{'Content-Type':'application/json','X-WP-Nonce':g.nonce}},
                      body:JSON.stringify({{topic_id:+{json.dumps(t)},channel:{json.dumps(ch)},on:{str(on).lower()}}})}});
                    return r.status;}})()""")
            async def openpanel():
                await p.click("[data-lg-notif-link]"); await asyncio.sleep(2.2)
            async def menu():
                return json.loads(await p.ev("""(()=>{const m=document.querySelector('.lg-notif-menu');
                    if(!m)return JSON.stringify(null);
                    return JSON.stringify([...m.querySelectorAll('[data-follow-menu]')].map(i=>({
                      ch:i.getAttribute('data-follow-menu'), on:i.classList.contains('is-on'),
                      tick:(i.querySelector('.lg-notif-menu__tick')||{}).textContent.trim(),
                      label:i.textContent.replace(/\\s+/g,' ').replace('\\u2713','').trim()})));})()"""))

            await load(); await openpanel()
            topic = await p.ev("(document.querySelector('[data-notif-more]')||{}).getAttribute?.('data-notif-more')")
            log(f"\n=== ⋯ MENU on topic {topic} ===")

            # BASELINE: force both OFF through the endpoint and PROVE it before opening.
            log(f"  set notify off -> HTTP {await setb(topic,'notify',False)}")
            log(f"  set email  off -> HTTP {await setb(topic,'email',False)}")
            base = await store(topic)
            log(f"  STORE baseline: {base}")
            check("baseline really is OFF/OFF in the store", base, {"notify":False,"email":False})

            await p.click("[data-notif-more]"); await asyncio.sleep(2.0)
            m = await menu()
            log(f"  MENU: {json.dumps(m)}")
            check("menu mirrors the store — no ticks", [i["tick"] for i in m], ["",""])
            check("labels read as opt-IN when off", [i["label"] for i in m],
                  ["Notify me about new replies","Email me about new replies"])
            await p.shot(f"{SHOT}/E-dots-menu-both-off.png")

            log("\n  -- click 🔔 --")
            await p.click("[data-follow-menu='notify']"); await asyncio.sleep(2.2)
            st = await store(topic); log(f"  STORE: {st}")
            check("🔔 wrote through", st["notify"], True)
            check("✉ untouched by the 🔔 click", st["email"], False)

            await p.click("[data-notif-more]"); await asyncio.sleep(2.2)
            m = await menu(); log(f"  MENU reopened: {json.dumps(m)}")
            check("🔔 now ticked", m[0]["tick"], "✓")
            check("🔔 is-on", m[0]["on"], True)
            check("✉ still unticked", m[1]["tick"], "")
            check("🔔 label flips to opt-OUT", m[0]["label"], "Stop notifications")
            await p.shot(f"{SHOT}/F-dots-menu-notify-on.png")

            log("\n  -- click ✉ (independence, from this surface too) --")
            await p.click("[data-follow-menu='email']"); await asyncio.sleep(2.2)
            st = await store(topic); log(f"  STORE: {st}")
            check("both bits now on", st, {"notify":True,"email":True})

            await p.click("[data-notif-more]"); await asyncio.sleep(2.2)
            m = await menu(); log(f"  MENU reopened: {json.dumps(m)}")
            check("both ticked", [i["tick"] for i in m], ["✓","✓"])
            await p.shot(f"{SHOT}/G-dots-menu-both-on.png")

            log("\n  -- click 🔔 again: turn it OFF, ✉ must survive --")
            await p.click("[data-follow-menu='notify']"); await asyncio.sleep(2.2)
            st = await store(topic); log(f"  STORE: {st}")
            check("🔔 off, ✉ SURVIVES", st, {"notify":False,"email":True})

            log("\n=== second click on ⋯ closes the menu ===")
            await p.click("[data-notif-more]"); await asyncio.sleep(1.2)
            await p.click("[data-notif-more]"); await asyncio.sleep(1.2)
            check("menu closed on the second ⋯ click",
                  await p.ev("!document.querySelector('.lg-notif-menu')"), True)
            check("aria-expanded back to false",
                  await p.ev("document.querySelector('[data-notif-more]').getAttribute('aria-expanded')"), "false")
    finally:
        try:
            async with websockets.connect(http("/json/version")["webSocketDebuggerUrl"],max_size=None) as b:
                await Page(b).send("Target.closeTarget",{"targetId":tgt})
            log(f"\nclosed target {tgt[:12]}")
        except Exception as e: log(f"\nclose failed: {e}")
    log(f"\n=== {passes} passed, {fails} failed ===")
    open("/tmp/tf-exercise/dots-report2.txt","w").write("\n".join(out))
    sys.exit(1 if fails else 0)

asyncio.run(main())
