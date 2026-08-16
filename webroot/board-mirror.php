<?php
declare(strict_types=1);
/**
 * board-mirror.php — Ian, 2026-08-16 night: "Is it possible to tie the wip
 * board directly to an md file using JS or something. Like have it be a
 * mirror?" — Yes. This page IS that: the ledger files rendered verbatim as
 * markdown, re-fetched every 5 seconds. No parsing into bands, no invented
 * structure, no way for a parser expectation to drift from the file — what
 * you see is byte-for-byte what the file says, seconds old at most.
 *
 * Reads the same copies the main board reads. Serves the RAW text via
 * ?raw=<name>; the shell renders it client-side (marked.js is vendored
 * inline-free? No — CSP self-contained: a ~40-line minimal renderer below,
 * headings/bold/lists/code only, which is all these files use).
 */
$repo = dirname(__DIR__);
$files = [
    'BACKLOG' => $repo . '/docs/BACKLOG.md',
    'DONE'    => $repo . '/docs/DONE.md',
];
if (isset($_GET['raw']) && isset($files[$_GET['raw']])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    readfile($files[$_GET['raw']]);
    exit;
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Board Mirror</title>
<style>
:root{--bg:#f6f3ee;--panel:#fffdf9;--ink:#1f1d1a;--soft:#4a463f;--line:#8a8478;--accent:#b9450b}
html[data-lguser-theme="dark"]{--bg:#15171a;--panel:#1b1e21;--ink:#e5e7e1;--soft:#cdd0ca;--line:#767c76;--accent:#e8a07a}
@media (prefers-color-scheme: dark){html:not([data-lguser-theme="light"]){--bg:#15171a;--panel:#1b1e21;--ink:#e5e7e1;--soft:#cdd0ca;--line:#767c76;--accent:#e8a07a}}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 ui-sans-serif,system-ui,sans-serif}
header{display:flex;gap:14px;align-items:baseline;padding:10px 16px;border-bottom:1px solid var(--line);position:sticky;top:0;background:var(--bg)}
header b{font-size:17px} header span{color:var(--soft);font-size:12px}
nav button{font:inherit;border:1px solid var(--line);background:var(--panel);color:var(--ink);border-radius:7px;padding:3px 12px;cursor:pointer;margin-right:6px}
nav button.on{border-color:var(--accent);color:var(--accent);font-weight:600}
main{max-width:1080px;margin:0 auto;padding:14px 16px 60px;overflow-x:auto}
main h1,main h2{border-bottom:1px solid var(--line);padding-bottom:4px}
main code{background:var(--panel);border:1px solid var(--line);border-radius:4px;padding:0 4px;font-size:.9em}
main li{margin:6px 0} main ul{padding-left:22px}
#stamp{color:var(--soft);font-size:12px}
</style></head><body>
<header><b>Board Mirror</b>
<nav><button id="b-BACKLOG" class="on">BACKLOG.md</button><button id="b-DONE">DONE.md</button></nav>
<span id="stamp">loading…</span></header>
<main id="out"></main>
<script>
(function(){
  var cur='BACKLOG', last='';
  function esc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
  function inline(s){return s
    .replace(/`([^`]+)`/g,function(_,c){return '<code>'+c+'</code>'})
    .replace(/\*\*([^*]+)\*\*/g,'<b>$1</b>')
    .replace(/(https?:\/\/[^\s)<]+)/g,'<a href="$1">$1</a>')}
  function render(md){
    var out=[], inList=false;
    md.split('\n').forEach(function(l){
      var m;
      if((m=l.match(/^(#{1,3}) (.*)/))){ if(inList){out.push('</ul>');inList=false}
        var n=m[1].length; out.push('<h'+n+'>'+inline(esc(m[2]))+'</h'+n+'>'); return }
      if((m=l.match(/^[-*] (.*)/))){ if(!inList){out.push('<ul>');inList=true}
        out.push('<li>'+inline(esc(m[1]))+'</li>'); return }
      if(inList){out.push('</ul>');inList=false}
      if(l.trim()==='') { out.push(''); return }
      out.push('<p>'+inline(esc(l))+'</p>')
    });
    if(inList)out.push('</ul>');
    return out.join('\n')
  }
  function tick(){
    fetch('?raw='+cur,{cache:'no-store'}).then(function(r){return r.text()}).then(function(t){
      if(t!==last){ last=t; document.getElementById('out').innerHTML=render(t) }
      document.getElementById('stamp').textContent=cur+'.md · live · '+new Date().toLocaleTimeString()
    }).catch(function(){ document.getElementById('stamp').textContent='fetch failed — retrying' })
  }
  ['BACKLOG','DONE'].forEach(function(n){
    document.getElementById('b-'+n).onclick=function(){
      cur=n; last='';
      document.querySelectorAll('nav button').forEach(function(b){b.className=''});
      this.className='on'; tick()
    }
  });
  tick(); setInterval(tick, 5000);
  document.addEventListener('visibilitychange',function(){ if(!document.hidden) tick() });
})();
</script></body></html>
