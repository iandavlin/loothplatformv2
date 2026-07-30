#!/usr/bin/env python3
"""
§2.4 the MOBILE SHEET header (.lrs-notify / .lrs-email in #looth-rep-sheet).

Reachable only now that the harness reproduces nginx's sub_filter injection of
/pwa.js, which is what loads hub-polish.js — the file that owns the sheet. The sheet
is built ON DEMAND when a discussion is opened at <=640px, so this drives that.
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
        if r.get("exceptionDetails"): raise RuntimeError("JS: "+str(r["exceptionDetails"].get("text")))
        return r["result"].get("value")
    async def tap(s,sel,nth=0):
        box=await s.ev(f"""(()=>{{const e=[...document.querySelectorAll({json.dumps(sel)})][{nth}];
            if(!e)return null;e.scrollIntoView({{block:'center'}});
            const r=e.getBoundingClientRect();return {{x:r.x+r.width/2,y:r.y+r.height/2}};}})()""")
        if not box: return False
        await s.send("Input.dispatchTouchEvent",{"type":"touchStart","touchPoints":[{"x":box["x"],"y":box["y"]}]})
        await s.send("Input.dispatchTouchEvent",{"type":"touchEnd","touchPoints":[]})
        return True
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
            await p.send("Emulation.setDeviceMetricsOverride",
                         {"width":390,"height":844,"deviceScaleFactor":3,"mobile":True})
            await p.send("Emulation.setTouchEmulationEnabled",{"enabled":True,"maxTouchPoints":5})
            await p.send("Emulation.setUserAgentOverride",{"userAgent":
                "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 "
                "(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1"})
            jwt=open("/tmp/tf-exercise/jwt.txt").read().strip()
            cks=[l.strip() for l in open("/tmp/tf-exercise/cookies.txt") if l.strip()]
            ck=[{"name":l.split("=",1)[0],"value":l.split("=",1)[1],"domain":"127.0.0.1","path":"/"} for l in cks]
            ck.append({"name":"looth_id","value":jwt,"domain":"127.0.0.1","path":"/"})
            await p.send("Network.clearBrowserCookies"); await p.send("Network.setCookies",{"cookies":ck})

            await p.send("Page.navigate",{"url":HUB})
            for _ in range(60):
                await asyncio.sleep(0.5)
                if await p.ev("document.readyState==='complete'"): break
            await asyncio.sleep(4.0)   # pwa.js injects overlays async

            env=json.loads(await p.ev("""(()=>JSON.stringify({
                pwa:[...document.querySelectorAll('script[src*="pwa.js"]')].length,
                hubPolish:[...document.querySelectorAll('script[src*="hub-polish"]')].length,
                sheet:!!document.getElementById('looth-rep-sheet'),
                width:window.innerWidth}))()"""))
            log(f"  env: {json.dumps(env)}")
            check("pwa.js present (nginx sub_filter reproduced)", env["pwa"], 1)
            check("hub-polish.js injected by pwa.js", env["hubPolish"], 1)
            check("mobile viewport", env["width"], 390)

            # Open a discussion the way a phone user does.
            topic = await p.ev("""(()=>{for(const b of document.querySelectorAll('[data-follow="notify"]')){
                const r=b.getBoundingClientRect(); if(r.width>0&&r.height>0) return b.dataset.topicId;} return null;})()""")
            log(f"  opening topic {topic}")
            # The exported opener is exactly what §2.4's deep-link router calls
            # (hub-polish.js:3714). Tapping the replies pill goes through the same
            # function; we call it directly so the test does not depend on which
            # pill happens to be wired on this card.
            opened = await p.ev(f'''(() => {{
                const btn = [...document.querySelectorAll('[data-follow="notify"]')]
                  .find(b => b.dataset.topicId === {json.dumps(topic)});
                const card = btn && (btn.closest('.feed-card') || btn.closest('article'));
                if (!card || typeof window.lgOpenTopicMobile !== 'function') return false;
                window.lgOpenTopicMobile(card, {{toReplies: true}});
                return true; }})()''')
            log(f"  lgOpenTopicMobile called: {opened}")
            await asyncio.sleep(5.0)

            sheet=json.loads(await p.ev("""(()=>{const s=document.getElementById('looth-rep-sheet');
                if(!s)return JSON.stringify({exists:false});
                const r=s.getBoundingClientRect();
                const rd=q=>{const b=s.querySelector(q); if(!b)return null;
                  const br=b.getBoundingClientRect();
                  return {pressed:b.getAttribute('aria-pressed'),topic:b.getAttribute('data-topic-id'),
                          w:Math.round(br.width),h:Math.round(br.height),vis:br.width>0&&br.height>0};};
                return JSON.stringify({exists:true,open:r.height>0,
                  notify:rd('.lrs-notify'),email:rd('.lrs-email'),
                  head:[...(s.querySelector('.lrs-hd')||{children:[]}).children].map(e=>(e.className||e.tagName).toString().split(' ')[0])});})()"""))
            log("  sheet: " + json.dumps(sheet))
            if not sheet["exists"]:
                log("  NOT EXERCISED — the sheet was not created by this interaction")
                globals()['fails'] = fails + 1
            else:
                check("sheet is open", sheet["open"], True)
                if sheet["notify"] and sheet["email"]:
                    check("§2.4 .lrs-notify present and visible", sheet["notify"]["vis"], True)
                    check("§2.4 .lrs-email present and visible", sheet["email"]["vis"], True)
                    check("order reads title -> state -> dismiss",
                          sheet["head"][:4], ["lrs-t","lrs-notify","lrs-email","lrs-x"])
                    hit=json.loads(await p.ev("""(()=>{const b=document.querySelector('#looth-rep-sheet .lrs-notify');
                        const cs=getComputedStyle(b,'::after');
                        return JSON.stringify({w:cs.width,h:cs.height});})()"""))
                    log(f"  hit area (::after): {json.dumps(hit)}")
                    check("effective touch target >=44px (§2.4)", hit["w"], "44px")
                    t = sheet["notify"]["topic"]
                    before=json.loads(await p.ev(f"""(async()=>{{const r=await fetch('{API}?topics='+{json.dumps(t)},
                        {{credentials:'same-origin'}});const j=await r.json();
                        return JSON.stringify(j.state[{json.dumps(t)}]||null);}})()"""))
                    log(f"  server-before {before}")
                    await p.shot(f"{SHOT}/L-mobile-sheet-header.png")
                    await p.tap(".lrs-notify")
                    await asyncio.sleep(2.5)
                    after=json.loads(await p.ev(f"""(async()=>{{const r=await fetch('{API}?topics='+{json.dumps(t)},
                        {{credentials:'same-origin'}});const j=await r.json();
                        return JSON.stringify(j.state[{json.dumps(t)}]||null);}})()"""))
                    log(f"  server-after  {after}")
                    check("sheet 🔔 tap wrote through to the store", after["notify"], not before["notify"])
                    await p.shot(f"{SHOT}/M-mobile-sheet-notify.png")
                else:
                    log("  sheet exists but carries no .lrs-notify/.lrs-email")
                    globals()['fails'] = fails + 1
    finally:
        try:
            async with websockets.connect(http("/json/version")["webSocketDebuggerUrl"],max_size=None) as b:
                await Page(b).send("Target.closeTarget",{"targetId":tgt})
            log(f"\nclosed target {tgt[:12]}")
        except Exception as e: log(f"\nclose failed: {e}")
    log(f"\n=== {passes} passed, {fails} failed ===")
    open("/tmp/tf-exercise/sheet-report.txt","w").write("\n".join(out))
    sys.exit(1 if fails else 0)

asyncio.run(main())
