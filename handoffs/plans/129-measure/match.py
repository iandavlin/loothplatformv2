import re, sys, difflib, json
S=sys.argv[1]
def rows(p,n):
    out=[]
    for ln in open(p,encoding='utf-8'):
        ln=ln.rstrip('\n')
        if not ln.strip(): continue
        f=ln.split('\t'); f+=['']*(n-len(f)); out.append(f[:n])
    return out
forums=[{'id':int(a),'slug':b,'title':c,'parent':d,'d365':int(e or 0)} for a,b,c,d,e in rows(S+'/forums.tsv',5)]
terms =[{'id':int(a),'slug':b,'name':c,'parent':d,'uses':int(e or 0)} for a,b,c,d,e in rows(S+'/terms.tsv',5)]
SIDE={'repair','builds','build','restoration'}   # words that only say WHICH SIDE of the tree
NOISE={'and','the','of','instruments','instrument','machine','database'}
def norm(s):
    s=s.lower().replace('&',' and ').replace('/',' ')
    s=(s.replace('organisation','organization').replace('orginization','organization')
        .replace('buisness','business'))
    return ' '.join(re.sub(r'[^a-z0-9 ]',' ',s).split())
def stem(t): return t[:-1] if len(t)>3 and t.endswith('s') else t
def toks(s,drop=NOISE):    return {stem(t) for t in norm(s).split() if t not in drop}
def core(s):               return toks(s, NOISE|SIDE)
def jac(a,b):              return len(a&b)/len(a|b) if (a|b) else 0.0
def pagree(t,f):
    if not t['parent'] or not f['parent']: return (not t['parent']) and (not f['parent'])
    return core(t['parent'])==core(f['parent'])
clean=[]; hand=[]; noforum=[]; used=set()
for t in terms:
    cands=[]
    for f in forums:
        nt,nf=norm(t['name']),norm(f['title'])
        pa=pagree(t,f); j=jac(toks(t['name']),toks(f['title'])); r=difflib.SequenceMatcher(None,nt,nf).ratio()
        if   nt==nf:                          k,sc='exact-name',1.00
        elif t['slug']==f['slug']:            k,sc='exact-slug',0.99
        elif core(t['name'])==core(f['title']) and core(t['name']): k,sc='core-token',0.95
        elif j>=0.50:                         k,sc='token-overlap',round(0.60+0.30*j,3)
        elif r>=0.62:                         k,sc='char-fuzzy',round(r,3)
        else: continue
        cands.append((sc,pa,k,f,j,r))
    cands.sort(key=lambda c:(c[1],c[0]),reverse=True)   # parent agreement FIRST
    if not cands: noforum.append(t); continue
    sc,pa,k,f,j,r=cands[0]
    tie=[c for c in cands if c[0]==sc and c[1]==pa]
    rec={'t':t,'f':f,'k':k,'sc':sc,'pa':pa,'j':j,'r':r,'tie':[c[3] for c in tie[1:]]}
    (clean if (pa and k in('exact-name','exact-slug','core-token','token-overlap')) else hand).append(rec)
    used.add(f['id'])
def ln(r):
    t,f=r['t'],r['f']
    warn=''
    if not r['pa']: warn+=' ⚠PARENT-MISMATCH'
    if r['tie']:    warn+=' ⚠TIE-with:'+','.join(str(x['id']) for x in r['tie'])
    return (f"  {t['name']:<52s} [{(t['parent'] or 'TOP'):<34s}] {t['uses']:>4d} uses\n"
            f"     -> #{f['id']:<6d} {f['title']:<46s} [{(f['parent'] or 'TOP'):<34s}] {r['k']} {r['sc']}{warn}")
print(f"shared_category terms: {len(terms)}    postable leaf forums: {len(forums)}\n")
print(f"=== A. CLEAN AUTO-DERIVABLE PAIRS — {len(clean)}/{len(terms)} terms ===")
for r in sorted(clean,key=lambda r:-r['t']['uses']): print(ln(r))
print(f"\n=== B. NEEDS A HAND-WRITTEN PAIR — {len(hand)} ===")
for r in sorted(hand,key=lambda r:-r['t']['uses']): print(ln(r))
print(f"\n=== C. TERMS WITH NO FORUM AT ALL — {len(noforum)} ===")
for t in sorted(noforum,key=lambda t:-t['uses']):
    print(f"  {t['name']:<52s} [{(t['parent'] or 'TOP'):<34s}] {t['uses']:>4d} uses")
fo=[f for f in forums if f['id'] not in used]
print(f"\n=== D. POSTABLE FORUMS NO TERM REACHES — {len(fo)} ===")
for f in sorted(fo,key=lambda f:-f['d365']):
    print(f"  #{f['id']:<6d} {f['title']:<46s} [{(f['parent'] or 'TOP'):<34s}] {f['d365']:>3d} new topics/365d")
cov=sum(t['uses'] for t in terms); cc=sum(r['t']['uses'] for r in clean)
hh=sum(r['t']['uses'] for r in hand); nn=sum(t['uses'] for t in noforum)
print(f"\n=== E. WEIGHTED BY ACTUAL TERM USE (total {cov} assignments) ===")
print(f"  clean pairs      {cc:>5d}  {100*cc/cov:5.1f}%")
print(f"  hand-written     {hh:>5d}  {100*hh/cov:5.1f}%")
print(f"  no forum exists  {nn:>5d}  {100*nn/cov:5.1f}%")
json.dump({'clean':[[r['t']['slug'],r['f']['id'],r['k']] for r in clean],
           'hand':[[r['t']['slug'],r['f']['id'],r['k'],r['sc'],r['pa']] for r in hand],
           'noforum':[t['slug'] for t in noforum],
           'unreached':[f['id'] for f in fo]},open(S+'/match2.json','w'),indent=1)
