#!/usr/bin/env python3
"""Open topic 72212's replies for real and measure EVERY gallery cell:
declared `sizes` vs the candidate the browser PICKED vs the width it RENDERS.

A markup assertion passed the whole time while desktop pulled w=800 for a 229px
tile (e183136). So assert currentSrc and real clientWidth, per cell."""
import json, time, sys, urllib.request, websocket

PORT, CN, CV = sys.argv[1], sys.argv[2], sys.argv[3]
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
    r = cmd('Runtime.evaluate', expression=e, returnByValue=True)
    if 'exceptionDetails' in r: raise RuntimeError(r['exceptionDetails'].get('text'))
    return r.get('result', {}).get('value')
def tap(sel):
    ev(f"(()=>{{const e=document.querySelector({sel!r});if(e)e.scrollIntoView({{block:'center'}});}})()")
    time.sleep(0.6)
    b = ev(f"""(()=>{{const e=document.querySelector({sel!r});if(!e)return null;
      const r=e.getBoundingClientRect();const cx=Math.round(r.left+r.width/2),cy=Math.round(r.top+r.height/2);
      const hit=document.elementFromPoint(cx,cy);
      return JSON.stringify({{x:cx,y:cy,w:Math.round(r.width),h:Math.round(r.height),
        hit:hit?(hit.tagName+'.'+(hit.className||'')).slice(0,50):null}});}})()""")
    if not b: raise RuntimeError('no element ' + sel)
    d = json.loads(b); print(f"    tap {sel} -> {d}")
    tp = [{'x': d['x'], 'y': d['y'], 'radiusX': 12, 'radiusY': 12, 'force': 1}]
    cmd('Input.dispatchTouchEvent', type='touchStart', touchPoints=tp)
    cmd('Input.dispatchTouchEvent', type='touchEnd', touchPoints=[])

MEASURE = """(()=>{
  const gs=[...document.querySelectorAll('.reply-stub__gallery')];
  return JSON.stringify(gs.map(g=>{
    const host=g.closest('[data-reply-id]');
    return {count:g.getAttribute('data-count'), more:g.getAttribute('data-more'),
      reply: host?host.getAttribute('data-reply-id'):null,
      cells:[...g.querySelectorAll('img')].map(i=>({
        sizes:i.getAttribute('sizes'),
        picked:(i.currentSrc||'').replace(/^.*[?&]w=/,'').replace(/&.*$/,''),
        css:Math.round(i.getBoundingClientRect().width),
        dev:Math.round(i.getBoundingClientRect().width*devicePixelRatio),
        nat:i.naturalWidth, lazy:i.getAttribute('loading'),
        hasW:i.hasAttribute('width'), hasH:i.hasAttribute('height')}))};
  }));})()"""

results = {}
for label, w, h, dpr, mobile in [('mobile-390-dpr2', 390, 844, 2, True),
                                 ('desktop-1280-dpr1', 1280, 900, 1, False)]:
    cmd('Emulation.setDeviceMetricsOverride', width=w, height=h,
        deviceScaleFactor=dpr, mobile=mobile)
    cmd('Emulation.setTouchEmulationEnabled', enabled=True, maxTouchPoints=5)
    # A warm cache can hand back a LARGER cached candidate and make correct
    # `sizes` look like an over-fetch. Measure cold.
    cmd('Network.setCacheDisabled', cacheDisabled=True)
    cmd('Network.clearBrowserCache')
    cmd('Page.navigate', url='https://dev2.loothgroup.com/hub/')
    time.sleep(9)
    print(f"\n===== {label} =====")
    try:
        tap('[data-topic-id="72212"] .lg-act-replies')
    except Exception as e:
        print('   tap failed:', e)
    for _ in range(40):
        if ev("document.querySelectorAll('.reply-stub__gallery').length>0"): break
        time.sleep(0.5)
    out = json.loads(ev(MEASURE) or '[]')
    results[label] = out
    if not out: print("   NO GALLERIES FOUND")
    for g in out:
        print(f"  reply={g['reply']} data-count={g['count']} more={g['more']}")
        for c in g['cells']:
            pk = c['picked']
            verdict = 'OK'
            if pk.isdigit() and c['dev']:
                if int(pk) > c['dev'] * 1.5:  verdict = '*** OVER-FETCH ***'
                elif int(pk) < c['dev']:      verdict = 'under (blurry)'
            print(f"    sizes={c['sizes']!r}")
            print(f"      picked=w{pk} rendered={c['css']}css/{c['dev']}dev nat={c['nat']} "
                  f"lazy={c['lazy']} w/h={c['hasW']}/{c['hasH']} -> {verdict}")
json.dump(results, open('/tmp/claude-1000/-home-ubuntu-worktrees-reply-images-count/'
                        'b1612745-d576-42be-9768-5a19991f4207/scratchpad/proofC2.json', 'w'), indent=1)
ws.close()
