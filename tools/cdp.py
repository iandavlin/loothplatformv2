#!/usr/bin/env python3
"""Minimal CDP driver for the loothdev shared chromium.

Bridge endpoint: http://127.0.0.1:9222 (socat in container netns).
Usage examples (run directly or import):
    python3 cdp.py tabs
    python3 cdp.py open https://example.com
    python3 cdp.py goto https://example.com         # navigates current/front tab
    python3 cdp.py eval "document.title"            # JS in front tab
    python3 cdp.py fill 'input[name=foo]' 'value'   # set value + dispatch input event
    python3 cdp.py click 'button.submit'
    python3 cdp.py submit 'form.post-password-form'
"""
import json, subprocess, sys, urllib.request, websocket

CDP = "http://127.0.0.1:9222"

def _http(path):
    # Chromium's CDP HTTP endpoint rejects external Host headers, so route /json
    # discovery calls through the container instead of the docker-proxy bridge.
    out = subprocess.check_output(
        ["sudo", "docker", "exec", "chromium", "curl", "-s", f"{CDP}{path}"]
    )
    return json.loads(out)

def tabs():
    return [t for t in _http("/json") if t.get("type") == "page"]

def front_tab():
    ts = tabs()
    if not ts: raise RuntimeError("no tabs open")
    return ts[0]

def open_url(url):
    return json.loads(urllib.request.urlopen(f"{CDP}/json/new?{url}", data=b"").read())

class Session:
    def __init__(self, ws_url):
        self.ws = websocket.create_connection(ws_url, suppress_origin=True, timeout=30)
        self.msg_id = 0
        # Subscribe to Page events so native JS dialogs (confirm/alert) don't wedge us.
        # We auto-accept; tested actions like "Remove this card" rely on confirm()
        # returning truthy. Without this the renderer blocks forever and Closing
        # the tab kills the only window → chromium exits → container restart.
        self._silent_send("Page.enable")
    def _silent_send(self, method, params=None):
        self.msg_id += 1
        self.ws.send(json.dumps({"id": self.msg_id, "method": method, "params": params or {}}))
        while True:
            try: msg = json.loads(self.ws.recv())
            except Exception: return None
            if msg.get("id") == self.msg_id: return msg.get("result", {})
    def send(self, method, params=None):
        self.msg_id += 1
        self.ws.send(json.dumps({"id": self.msg_id, "method": method, "params": params or {}}))
        while True:
            msg = json.loads(self.ws.recv())
            # Handle native JS dialog events that would otherwise block the renderer.
            if msg.get("method") == "Page.javascriptDialogOpening":
                self._silent_send("Page.handleJavaScriptDialog", {"accept": True})
                continue
            if msg.get("id") == self.msg_id:
                if "error" in msg: raise RuntimeError(msg["error"])
                return msg.get("result", {})
    def close(self): self.ws.close()

def with_front(fn):
    s = Session(front_tab()["webSocketDebuggerUrl"])
    try: return fn(s)
    finally: s.close()

def goto(url):
    return with_front(lambda s: s.send("Page.navigate", {"url": url}))

def evaluate(expr):
    r = with_front(lambda s: s.send("Runtime.evaluate", {"expression": expr, "returnByValue": True}))
    return r.get("result", {}).get("value")

def fill(selector, value):
    js = f"""(()=>{{const el=document.querySelector({json.dumps(selector)});
        if(!el) return 'NOT_FOUND';
        el.focus(); el.value={json.dumps(value)};
        el.dispatchEvent(new Event('input',{{bubbles:true}}));
        el.dispatchEvent(new Event('change',{{bubbles:true}}));
        return 'OK';}})()"""
    return evaluate(js)

def click(selector):
    js = f"""(()=>{{const el=document.querySelector({json.dumps(selector)});
        if(!el) return 'NOT_FOUND'; el.click(); return 'OK';}})()"""
    return evaluate(js)

def submit(selector):
    js = f"""(()=>{{const f=document.querySelector({json.dumps(selector)});
        if(!f) return 'NOT_FOUND'; f.submit(); return 'OK';}})()"""
    return evaluate(js)

if __name__ == "__main__":
    cmd, *args = sys.argv[1:] or ["tabs"]
    out = {
        "tabs":   lambda: [{"id": t["id"], "title": t["title"], "url": t["url"]} for t in tabs()],
        "open":   lambda: open_url(args[0]),
        "goto":   lambda: goto(args[0]),
        "eval":   lambda: evaluate(args[0]),
        "fill":   lambda: fill(args[0], args[1]),
        "click":  lambda: click(args[0]),
        "submit": lambda: submit(args[0]),
    }[cmd]()
    print(json.dumps(out, indent=2, default=str))
