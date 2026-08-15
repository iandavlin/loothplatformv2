"""Photograph the post-save panel (Ian's addition 2) WITHOUT ever writing.

window.fetch is replaced before any page script runs, so every save call resolves
locally and nothing reaches the profile-api. The panel is built by the page's own
JS from its own source, so this is the real thing, not a mock-up of it."""
import base64, json, sys, time, urllib.request, websocket
CDP="http://127.0.0.1:9222"
def http(p, method="GET"):
    return json.load(urllib.request.urlopen(urllib.request.Request(CDP+p, method=method), timeout=10))

STUB = """
window.__calls = [];
window.fetch = function(u, o){
  window.__calls.push((o&&o.method||'GET') + ' ' + u);
  return Promise.resolve({ ok:true, status:200, json:function(){
    return Promise.resolve({ ok:true, slug:'marty2' });   // as if the handle deduped
  }});
};
"""
tab = http("/json/new?about:blank", method="PUT")
try:
    ws = websocket.create_connection(tab["webSocketDebuggerUrl"], timeout=30, suppress_origin=True)
    i=[0]
    def send(m,p=None):
        i[0]+=1; ws.send(json.dumps({"id":i[0],"method":m,"params":p or {}}))
        while True:
            r=json.loads(ws.recv())
            if r.get("id")==i[0]: return r
    send("Page.enable"); send("Runtime.enable")
    send("Emulation.setDeviceMetricsOverride",{"width":1280,"height":1000,"deviceScaleFactor":1,"mobile":False})
    send("Page.addScriptToEvaluateOnNewDocument", {"source": STUB})   # BEFORE page scripts
    send("Page.navigate",{"url":"https://dev2.loothgroup.com/footer-mockups/_ps-tmp/live.html"})
    time.sleep(4)
    # fill a town so the location-sensitive branch of the offer is exercised
    send("Runtime.evaluate",{"expression":
        "document.getElementById('ps-city').value='Milwaukee, Wisconsin';"
        "document.getElementById('ps-save').click(); 'clicked'","returnByValue":True})
    time.sleep(2.5)
    r=send("Runtime.evaluate",{"expression":"document.body.innerText.slice(0,1200)","returnByValue":True})
    body=r.get("result",{}).get("result",{}).get("value","") or ""
    c=send("Runtime.evaluate",{"expression":"JSON.stringify(window.__calls)","returnByValue":True})
    calls=c.get("result",{}).get("result",{}).get("value","[]")
    print("stubbed calls the page would have made:", calls)
    if "Open the full profile editor" not in body:
        print("LIVENESS FAIL — the done panel did not render. body:", body[:200]); sys.exit(1)
    m=send("Page.captureScreenshot",{"format":"png","captureBeyondViewport":True})
    raw=base64.b64decode(m["result"]["data"]); open(sys.argv[1]+"/shot-done.png","wb").write(raw)
    print(f"ok shot-done.png {len(raw)//1024}KB — panel rendered")
    h=send("Runtime.evaluate",{"expression":"document.querySelector('#lg-ps-done').outerHTML","returnByValue":True})
    open(sys.argv[1]+"/done-panel.html","w").write(h.get("result",{}).get("result",{}).get("value","") or "")
finally:
    try: ws.close()
    except Exception: pass
    try: http("/json/close/"+tab["id"])
    except Exception: pass
