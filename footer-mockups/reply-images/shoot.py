#!/usr/bin/env python3
"""
Screenshot previs frames through a REAL browser, to prove the frames render —
real /hub/forums.css, real /img.php photos — rather than trusting the HTML.

Also probes each frame for the numbers that decide the design: how tall the
gallery is, how tall the whole stub is, and whether any tile failed to load.

Usage (dev2 is a 3.8GB box at a 4-lane cap — ONE chrome, launch+drive+kill in
a single shell call, never a fleet):

  /opt/lg-chrome/chrome-linux64/chrome --headless=new --no-sandbox \
    --remote-debugging-port=9333 --remote-allow-origins='*' \
    --host-resolver-rules="MAP dev2.loothgroup.com 127.0.0.1" &
  python3 shoot.py 9333 shots  a09--phone:390:844  page5--desk:1100:900
  pkill -f 'remote-debugging-port=9333'

--host-resolver-rules pins the host to loopback, which the dev gate authorizes
(geo $loothdev_src_local) — no cookie needed and no trip through the CF edge.
"""
import json, os, sys, time, base64
import websocket
import urllib.request

PORT = sys.argv[1] if len(sys.argv) > 1 else '9333'
OUT = sys.argv[2] if len(sys.argv) > 2 else 'shots'
JOBS = [j.split(':') for j in sys.argv[3:]]
BASE = 'https://dev2.loothgroup.com/mockups/reply-images/'

targets = json.load(urllib.request.urlopen(f'http://127.0.0.1:{PORT}/json/list'))
page = next(t for t in targets if t['type'] == 'page')
ws = websocket.create_connection(page['webSocketDebuggerUrl'],
                                 origin='', suppress_origin=True, timeout=45)
_id = [0]


def cmd(method, **params):
    _id[0] += 1
    ws.send(json.dumps({'id': _id[0], 'method': method, 'params': params}))
    while True:
        m = json.loads(ws.recv())
        if m.get('id') == _id[0]:
            return m.get('result', {})


PROBE = r"""(()=>{
  const stub=document.querySelector('.reply-stub');
  const g=document.querySelector('.reply-stub__gallery');
  const imgs=[...document.querySelectorAll('.reply-stub__body img')];
  return {
    cssLoaded: !!getComputedStyle(stub).borderBottomWidth &&
               getComputedStyle(document.body).fontFamily.length>0,
    stubClassStyled: getComputedStyle(stub).paddingTop,
    gallery: g ? g.getAttribute('data-count')+(g.hasAttribute('data-more')?'+'+g.getAttribute('data-more'):'') : 'single-img',
    tiles: imgs.length,
    broken: imgs.filter(i=>!i.naturalWidth).length,
    galleryH: g ? Math.round(g.getBoundingClientRect().height) : null,
    stubH: Math.round(stub.getBoundingClientRect().height),
    docH: document.documentElement.scrollHeight,
    tileW: imgs.length ? Math.round(imgs[0].getBoundingClientRect().width) : 0,
    picked: imgs.length ? (imgs[0].currentSrc||'').replace(/^.*w=/,'w=') : ''
  };})()"""

cmd('Page.enable')
os.makedirs(OUT, exist_ok=True)
rows = []
for name, w, h in JOBS:
    cmd('Emulation.setDeviceMetricsOverride', width=int(w), height=int(h),
        deviceScaleFactor=2, mobile=int(w) <= 480)
    cmd('Page.navigate', url=BASE + name + '.html')
    time.sleep(2.0)
    # force the lazy tiles in so the shot shows the whole reply, then settle
    cmd('Runtime.evaluate', expression=(
        "document.querySelectorAll('img[loading=lazy]').forEach(i=>i.loading='eager');"
        "window.scrollTo(0,99999);window.scrollTo(0,0);"))
    time.sleep(1.6)
    r = cmd('Runtime.evaluate', expression=PROBE, returnByValue=True)
    val = r.get('result', {}).get('value', {})
    val['frame'] = name
    rows.append(val)
    print(json.dumps(val))
    shot = cmd('Page.captureScreenshot', format='png', captureBeyondViewport=True)
    open(os.path.join(OUT, name + '.png'), 'wb').write(base64.b64decode(shot['data']))

json.dump(rows, open(os.path.join(OUT, 'probe.json'), 'w'), indent=1)
ws.close()
print(f'\n{len(rows)} frames shot -> {OUT}')
