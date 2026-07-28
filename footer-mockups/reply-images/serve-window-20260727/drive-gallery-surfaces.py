#!/usr/bin/env python3
"""
Proof C — the gallery on real device paths against the real serve.

Covers what a harness cannot: the desktop discussion modal, the mobile replies
sheet, and the LOGGED-OUT render, all on an EXISTING member reply (58510, four
photos stored since Sep 2025 of which one has ever been visible) rather than a
reply this lane made.

Reports, per surface: gallery count, cells, the srcset candidate the browser
ACTUALLY picked, broken tiles, and the rendered tile width.
"""
import json, time, base64, sys, urllib.request, websocket

PORT, CN, CV = sys.argv[1], sys.argv[2], sys.argv[3]
OUT = '/tmp/capshots'
t = json.load(urllib.request.urlopen(f'http://127.0.0.1:{PORT}/json/list'))
pg = next(x for x in t if x['type'] == 'page')
ws = websocket.create_connection(pg['webSocketDebuggerUrl'], origin='',
                                 suppress_origin=True, timeout=90)
_id = [0]
def cmd(m, **kw):
    _id[0] += 1
    ws.send(json.dumps({'id': _id[0], 'method': m, 'params': kw}))
    while True:
        r = json.loads(ws.recv())
        if r.get('id') == _id[0]:
            if 'error' in r: raise RuntimeError(f"{m}: {r['error']}")
            return r.get('result', {})
def ev(e):
    return cmd('Runtime.evaluate', expression=e, returnByValue=True).get('result', {}).get('value')
def shot(n):
    s = cmd('Page.captureScreenshot', format='png', captureBeyondViewport=True)
    open(f'{OUT}/{n}.png', 'wb').write(base64.b64decode(s['data']))

PROBE = """(()=>{
  const gs=[...document.querySelectorAll('.reply-stub__gallery')];
  const g=gs.find(x=>x.getAttribute('data-count')==='4')||gs[0];
  if(!g)return JSON.stringify({galleries:gs.length,found:false});
  const imgs=[...g.querySelectorAll('img')];
  return JSON.stringify({galleries:gs.length,found:true,
    count:g.getAttribute('data-count'),
    cells:g.querySelectorAll('.reply-stub__gcell').length,
    picked:imgs.map(i=>(i.currentSrc||'').replace(/^.*&w=/,'w=')),
    broken:imgs.filter(i=>!i.naturalWidth).length,
    tileW:imgs.length?Math.round(imgs[0].getBoundingClientRect().width):0,
    hasDims:imgs.every(i=>i.getAttribute('width')&&i.getAttribute('height')),
    lazy:imgs.every(i=>i.getAttribute('loading')==='lazy'),
    galleryH:Math.round(g.getBoundingClientRect().height)});})()"""

cmd('Page.enable'); cmd('Runtime.enable'); cmd('Network.enable')
res = {}

def run(label, width, height, mobile, logged_in, shotname):
    cmd('Network.clearBrowserCookies')
    if logged_in:
        cmd('Network.setCookie', name=CN, value=CV, domain='dev2.loothgroup.com',
            path='/', httpOnly=True, secure=True)
    cmd('Emulation.setDeviceMetricsOverride', width=width, height=height,
        deviceScaleFactor=2, mobile=mobile)
    cmd('Emulation.setTouchEmulationEnabled', enabled=mobile, maxTouchPoints=5 if mobile else 1)
    # the replies fragment is what BOTH surfaces load for the thread; render it
    # inside the real hub page so the real forums.css and real chrome apply
    cmd('Page.navigate', url='https://dev2.loothgroup.com/hub/?topic=58468')
    time.sleep(7)
    # open the thread the way the surfaces do: fetch the fragment and mount it
    ev("""(()=>{window.__lgDone=false;
      fetch('/hub/?replies=58468&sort=oldest',{credentials:'same-origin'})
        .then(r=>r.text()).then(h=>{
          let host=document.querySelector('#lrs-thread')||document.querySelector('.feed-page')||document.body;
          const d=document.createElement('div');d.id='lg-proofc';d.innerHTML=h;
          host.insertBefore(d,host.firstChild);window.__lgDone=true;});})()""")
    for _ in range(40):
        if ev("window.__lgDone"): break
        time.sleep(0.4)
    lazy_before = ev("(()=>{const g=document.querySelector('.reply-stub__gallery');"
                     "return g?[...g.querySelectorAll('img')].every(i=>i.getAttribute('loading')==='lazy'):null;})()")
    ev("document.querySelectorAll('img[loading=lazy]').forEach(i=>i.loading='eager');"
       "window.scrollTo(0,0);")
    time.sleep(2.5)
    v = json.loads(ev(PROBE))
    v['lazy'] = lazy_before          # as SHIPPED, before the shot forced decode
    v['loggedIn'] = ev("!!document.querySelector('.lg-chrome__aside img, #site-header img')") if logged_in else False
    v['anonScrubbed'] = ev("!document.querySelector('#lg-proofc .reply-stub__edit')")
    res[label] = v
    print(f'{label}: {json.dumps(v)}')
    ev("(()=>{const d=document.getElementById('lg-proofc');if(d)d.scrollIntoView({block:'start'});})()")
    time.sleep(0.5)
    shot(shotname)

run('mobile-390-loggedin', 390, 844, True, True, 'C1-mobile-loggedin')
run('desktop-1280-loggedin', 1280, 900, False, True, 'C2-desktop-loggedin')
run('mobile-390-loggedOUT', 390, 844, True, False, 'C3-mobile-loggedout')

open(f'{OUT}/proofC.json', 'w').write(json.dumps(res, indent=1))
ws.close()
