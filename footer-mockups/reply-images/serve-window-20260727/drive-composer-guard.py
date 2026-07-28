#!/usr/bin/env python3
"""
Drive the REAL composer v2 on the REAL serve at a real mobile viewport.

Real touch taps (Input.dispatchTouchEvent) for the UI, real file-input change
events (DOM.setFileInputFiles) for the photos, real uploads to the real BB media
endpoint. The OS file picker itself cannot be driven; everything downstream of it
is the genuine code path.
"""
import json, time, base64, sys, urllib.request, websocket

PORT = sys.argv[1]
COOKIE_NAME = sys.argv[2]
COOKIE_VAL = sys.argv[3]
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

def ev(expr):
    r = cmd('Runtime.evaluate', expression=expr, returnByValue=True, awaitPromise=False)
    if 'exceptionDetails' in r:
        raise RuntimeError(f"JS: {r['exceptionDetails'].get('text')} :: {expr[:120]}")
    return r.get('result', {}).get('value')

def shot(name):
    s = cmd('Page.captureScreenshot', format='png', captureBeyondViewport=True)
    open(f'{OUT}/{name}.png', 'wb').write(base64.b64decode(s['data']))

def tap(sel):
    """A real trusted touch on the element's centre.

    scrollIntoView first: a rect outside the 390x844 viewport still yields
    coordinates, and dispatchTouchEvent happily delivers the touch to whatever is
    actually at those coordinates — so an off-screen target silently taps
    something else and the wait times out with no clue why.
    """
    ev(f"""(()=>{{const e=document.querySelector({sel!r});
        if(e)e.scrollIntoView({{block:'center'}});}})()""")
    time.sleep(0.6)
    box = ev(f"""(()=>{{const e=document.querySelector({sel!r});if(!e)return null;
        const r=e.getBoundingClientRect();
        const cx=Math.round(r.left+r.width/2), cy=Math.round(r.top+r.height/2);
        const hit=document.elementFromPoint(cx,cy);
        return JSON.stringify({{x:cx,y:cy,w:Math.round(r.width),h:Math.round(r.height),
          inView: cy>0&&cy<innerHeight&&cx>0&&cx<innerWidth,
          hit: hit?(hit.tagName+'.'+(hit.className||'').toString().slice(0,40)):null}});}})()""")
    if not box: raise RuntimeError(f'no element for tap: {sel}')
    _b = json.loads(box)
    print(f"    tap {sel} -> {_b}")
    b = json.loads(box)
    tp = [{'x': b['x'], 'y': b['y'], 'radiusX': 12, 'radiusY': 12, 'force': 1}]
    cmd('Input.dispatchTouchEvent', type='touchStart', touchPoints=tp)
    cmd('Input.dispatchTouchEvent', type='touchEnd', touchPoints=[])
    return b

def wait(expr, secs=25, label=''):
    end = time.time() + secs
    while time.time() < end:
        if ev(expr): return True
        time.sleep(0.35)
    raise RuntimeError(f'timeout waiting: {label or expr[:90]}')

def state():
    return json.loads(ev("""(()=>{const sh=document.getElementById('looth-comp-sheet');
      if(!sh)return JSON.stringify({open:false});
      const c=sh.querySelector('#lgc-pcount'),b=sh.querySelector('#lgc-photo'),
            e=sh.querySelector('.lgc-uperr, .lgc-err, [role=alert]');
      const chips=sh.querySelectorAll('#lgc-strip .lgc-pv');
      return JSON.stringify({
        open: sh.classList.contains('is-open'),
        chips: chips.length,
        uploading: sh.querySelectorAll('#lgc-strip .lgc-pv--up').length,
        counter: c && !c.hidden ? c.textContent : null,
        counterFull: c ? c.getAttribute('data-full') : null,
        counterW: c ? Math.round(c.getBoundingClientRect().width) : 0,
        counterScrollW: c ? c.scrollWidth : 0,
        photoDisabled: b ? !!b.disabled : null,
        err: e ? e.textContent.trim() : null });})()"""))

cmd('Page.enable'); cmd('Runtime.enable'); cmd('DOM.enable'); cmd('Network.enable')
cmd('Network.setCookie', name=COOKIE_NAME, value=COOKIE_VAL,
    domain='dev2.loothgroup.com', path='/', httpOnly=True, secure=True)
cmd('Emulation.setDeviceMetricsOverride', width=390, height=844,
    deviceScaleFactor=2, mobile=True)
cmd('Emulation.setTouchEmulationEnabled', enabled=True, maxTouchPoints=5)
cmd('Emulation.setEmitTouchEventsForMouse', enabled=True, configuration='mobile')

log = {}
cmd('Page.navigate', url='https://dev2.loothgroup.com/hub/')
time.sleep(8)
log['loggedIn'] = ev("!!document.querySelector('.lg-chrome__aside img, #site-header img')")
# [data-frm-open] is the DESKTOP affordance and measures 0x0 at 390px — the
# mobile reply entry point is .lg-fb-reply on the teaser stub (inventory E4/E6).
wait("document.querySelectorAll('.lg-fb-reply').length>0", 25, 'mobile reply CTA')
log['targetTopic'] = ev("(()=>{const b=document.querySelector('.lg-fb-reply');"
                        "const c=b.closest('.feed-card');return c?c.getAttribute('data-topic-id'):null;})()")
shot('B0-feed')

# open the composer the way a member does: tap the card's Reply
tap('.lg-fb-reply')
try:
    wait("(()=>{const s=document.getElementById('looth-comp-sheet');return s&&s.classList.contains('is-open');})()",
         20, 'composer open')
except RuntimeError:
    print('  composer did not open; what appeared:',
          ev("""JSON.stringify({compSheet:!!document.getElementById('looth-comp-sheet'),
            compClass:(document.getElementById('looth-comp-sheet')||{className:''}).className,
            repSheet:!!document.getElementById('looth-rep-sheet'),
            repClass:(document.getElementById('looth-rep-sheet')||{className:''}).className,
            frmModal:!!document.getElementById('frm-modal')||!!document.querySelector('.frm-modal'),
            dmodal:!!document.getElementById('lg-dmodal'),
            openSheets:[...document.querySelectorAll('.is-open')].map(e=>e.id||e.className).slice(0,8)})"""))
    raise
log['opened_by'] = 'real touch on .lg-fb-reply (mobile reply CTA)'
shot('B1-composer-open')

# type something so Post is enabled for a text+photos reply
ev("""(()=>{const q=document.querySelector('#looth-comp-sheet .ql-editor');
  if(q){q.innerHTML='<p>six photo cap proof</p>';q.dispatchEvent(new Event('input',{bubbles:true}));}})()""")

# attach photos one at a time through the REAL file input
steps = []
node = cmd('DOM.getDocument')['root']['nodeId']
for i in range(1, 8):
    fid = cmd('DOM.querySelector', nodeId=node, selector='#looth-comp-sheet #lgc-file')['nodeId']
    before = state()
    try:
        cmd('DOM.setFileInputFiles', files=[f'{OUT}/p{i}.jpg'], nodeId=fid)
    except RuntimeError as e:
        steps.append({'n': i, 'setFilesError': str(e)[:120], 'after': state()})
        continue
    # wait for the strip to settle (no tile still uploading)
    try:
        wait("(()=>{const s=document.getElementById('looth-comp-sheet');"
             "return s && s.querySelectorAll('#lgc-strip .lgc-pv--up').length===0;})()", 45,
             f'upload {i} settle')
    except RuntimeError:
        pass
    time.sleep(0.6)
    st = state()
    steps.append({'n': i, 'chipsBefore': before['chips'], 'after': st})
    print(f"  photo {i}: chips={st['chips']} counter={st['counter']!r} "
          f"full={st['counterFull']} photoDisabled={st['photoDisabled']} err={st['err']!r}")
    if i == 3: shot('B2-three-of-six')
    if i == 6: shot('B3-six-of-six')
    if i == 7: shot('B4-seventh-refused')

log['steps'] = steps
log['final'] = state()

# post it — real touch on Post
posted = None
if log['final']['chips'] >= 6:
    ev("window.__lgPosted=null;(function(){const of=window.fetch;window.fetch=function(u,o){"
       "const p=of.apply(this,arguments);if(String(u).indexOf('/bb-mirror-api/v0/reply')>-1&&o&&o.method==='POST'){"
       "p.then(r=>r.clone().json()).then(j=>{window.__lgPosted=j;}).catch(()=>{});}return p;};})()")
    tap('#looth-comp-sheet #lgc-post')
    try:
        wait("!!window.__lgPosted", 45, 'post response')
        posted = ev("JSON.stringify(window.__lgPosted)")
    except RuntimeError:
        posted = None
log['posted'] = posted
print('POSTED:', (posted or '')[:200])

# let the sheet reload and look at the rendered gallery
time.sleep(4)
shot('B5-after-post')
log['galleryInSheet'] = json.loads(ev("""(()=>{const g=document.querySelectorAll('#looth-rep-sheet .reply-stub__gallery');
  const last=g[g.length-1];
  return JSON.stringify({galleries:g.length,
    lastCount:last?last.getAttribute('data-count'):null,
    lastCells:last?last.querySelectorAll('.reply-stub__gcell').length:0,
    lastPicked:last?[...last.querySelectorAll('img')].map(i=>(i.currentSrc||'').replace(/^.*w=/,'w=')):[],
    broken:last?[...last.querySelectorAll('img')].filter(i=>!i.naturalWidth).length:null});})()"""))

open(f'{OUT}/proofB.json', 'w').write(json.dumps(log, indent=1))
print(json.dumps({k: v for k, v in log.items() if k != 'steps'}, indent=1))
ws.close()
