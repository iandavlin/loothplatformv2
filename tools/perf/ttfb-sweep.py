import subprocess, statistics
GATE=open('gate.txt').read().strip()
WP=''.join(f"{l.split(chr(9))[0]}={l.split(chr(9))[1].strip()}; " for l in open('wpcookies.tsv') if l.strip())
JWT=open('jwt.txt').read().strip()
ANON=f"loothdev_auth={GATE}"; LOGGED=f"loothdev_auth={GATE}; {WP}looth_id={JWT}"
N=9
def t(url,ck,follow=False):
    vals=[];code=sz=None
    for _ in range(N):
        cmd=["curl","-s","-o","/dev/null","-w","%{time_starttransfer} %{http_code} %{size_download} %{num_redirects} %{time_total}","-H",f"Cookie: {ck}",url]
        if follow: cmd.insert(1,"-L")
        o=subprocess.run(cmd,capture_output=True,text=True).stdout.split()
        if len(o)>=2: vals.append(float(o[0]));code=o[1];sz=o[2];tot=o[4]
    vals.sort()
    return statistics.median(vals)*1000,min(vals)*1000,max(vals)*1000,code,sz
def show(label,url,both=True,follow=False):
    am,ai,ax,ac,asz=t(url,ANON,follow)
    if both:
        lm,li,lx,lc,lsz=t(url,LOGGED,follow)
        print("%-30s anon %5.0f[%4.0f-%4.0f]%s | login %5.0f[%4.0f-%4.0f]%s %sB"%(label,am,ai,ax,ac,lm,li,lx,lc,lsz))
    else:
        print("%-30s %5.0f ms [%4.0f-%4.0f] %s %sB"%(label,am,ai,ax,ac,asz))
print("=== HTML pages, server TTFB (median/min/max of %d) ==="%N)
show("/archive/","https://dev.loothgroup.com/archive/")
show("/archive-poc/ (hub)","https://dev.loothgroup.com/archive-poc/")
show("CPT video standalone","https://dev.loothgroup.com/post-type-videos/3d-club-live-3d-scanning-demo-with-jerry-lynn-and-luke-heaton/")
show("CPT imgcap standalone","https://dev.loothgroup.com/post-imgcap/f5-l-mandolin-binding-repair/")
show("/u/gerryhayes (profile-app)","https://dev.loothgroup.com/u/gerryhayes")
show("/lgjoin/","https://dev.loothgroup.com/lgjoin/")
show("/membership-guide/","https://dev.loothgroup.com/membership-guide/")
print("\n=== backend APIs (logged-in) ===")
show("search?limit=24 (cards)","https://dev.loothgroup.com/archive-api/v0/search?limit=24")
show("rows-more (discover row)","https://dev.loothgroup.com/archive-api/v0/rows-more?row_id=latest&offset=0",both=False)
def one(l,u,ck):
    m,i,x,c,s=t(u,ck);print("%-30s %5.0f ms [%4.0f-%4.0f] %s"%(l,m,i,x,c))
one("profile-api whoami (fast)","https://dev.loothgroup.com/profile-api/v0/whoami",LOGGED)
one("looth/v1 whoami (OLD shim)","https://dev.loothgroup.com/wp-json/looth/v1/whoami",LOGGED)
