import json, time, sys, urllib.request, websocket
PORT, CN, CV = sys.argv[1], sys.argv[2], sys.argv[3]
t=json.load(urllib.request.urlopen(f'http://127.0.0.1:{PORT}/json/list'))
pg=next(x for x in t if x['type']=='page')
ws=websocket.create_connection(pg['webSocketDebuggerUrl'],origin='',suppress_origin=True,timeout=90)
_id=[0]
def cmd(m,**kw):
    _id[0]+=1; ws.send(json.dumps({'id':_id[0],'method':m,'params':kw}))
    while True:
        r=json.loads(ws.recv())
        if r.get('id')==_id[0]:
            if 'error' in r: raise RuntimeError(r['error'])
            return r.get('result',{})
def ev(e):
    r=cmd('Runtime.evaluate',expression=e,returnByValue=True)
    if 'exceptionDetails' in r: raise RuntimeError(r['exceptionDetails'].get('text'))
    return r.get('result',{}).get('value')
def tap(sel):
    ev(f"(()=>{{const e=document.querySelector({sel!r});if(e)e.scrollIntoView({{block:'center'}});}})()")
    time.sleep(0.6)
    b=ev(f"""(()=>{{const e=document.querySelector({sel!r});if(!e)return null;const r=e.getBoundingClientRect();
      return JSON.stringify({{x:Math.round(r.left+r.width/2),y:Math.round(r.top+r.height/2),w:Math.round(r.width)}});}})()""")
    d=json.loads(b); 
    if d['w']==0: raise RuntimeError(f'{sel} is 0-width')
    cmd('Input.dispatchTouchEvent',type='touchStart',touchPoints=[{'x':d['x'],'y':d['y'],'radiusX':12,'radiusY':12,'force':1}])
    cmd('Input.dispatchTouchEvent',type='touchEnd',touchPoints=[])
cmd('Page.enable'); cmd('Runtime.enable'); cmd('Network.enable')
cmd('Network.setCookie',name=CN,value=CV,domain='dev2.loothgroup.com',path='/',httpOnly=True,secure=True)
cmd('Emulation.setDeviceMetricsOverride',width=390,height=844,deviceScaleFactor=2,mobile=True)
cmd('Emulation.setTouchEmulationEnabled',enabled=True,maxTouchPoints=5)
cmd('Network.setCacheDisabled',cacheDisabled=True); cmd('Network.clearBrowserCache')
cmd('Page.navigate',url='https://dev2.loothgroup.com/hub/'); time.sleep(9)
tap('[data-topic-id="72212"] .lg-act-replies')
for _ in range(40):
    if ev("document.querySelectorAll('.reply-stub__gallery').length>0"): break
    time.sleep(0.5)
print("SOURCE-IMAGE COMPARISON: cover vs the 2-up gallery cells")
print(ev("""(()=>{
  const q=u=>{try{return new URL(u).searchParams.get('src')||new URL(u).searchParams.get('f')||u;}catch(e){return u;}};
  const cover=document.querySelector('[data-topic-id="72212"] .feed-card__cover-img')
            || document.querySelector('.feed-card__cover-img');
  const g=[...document.querySelectorAll('.reply-stub__gallery')].find(x=>x.getAttribute('data-count')==='2');
  const cells=g?[...g.querySelectorAll('img')]:[];
  const d=i=>({picked:(i.currentSrc||'').replace(/^.*[?&]w=/,'').replace(/&.*$/,''),
               source:q(i.currentSrc||i.src), css:Math.round(i.getBoundingClientRect().width),
               loading:i.getAttribute('loading')});
  return JSON.stringify({cover:cover?d(cover):null,
                         cell1:cells[0]?d(cells[0]):null,
                         cell2:cells[1]?d(cells[1]):null,
                         coverEqCell1: cover&&cells[0] ? q(cover.currentSrc)===q(cells[0].currentSrc) : null,
                         coverEqCell2: cover&&cells[1] ? q(cover.currentSrc)===q(cells[1].currentSrc) : null},null,1);})()"""))
ws.close()
