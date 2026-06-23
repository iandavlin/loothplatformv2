(function(){
  function show(){
    if(document.getElementById('lg-maint-modal'))return;
    var o=document.createElement('div');o.id='lg-maint-modal';
    o.style.cssText='position:fixed;inset:0;z-index:2147483646;background:rgba(0,0,0,.62);display:flex;align-items:center;justify-content:center;padding:1.5rem;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif';
    o.innerHTML='<div role="dialog" aria-modal="true" style="max-width:30rem;background:#15171a;color:#ececec;border:1px solid #2a2d31;border-radius:16px;padding:1.7rem 1.9rem;text-align:center;box-shadow:0 16px 50px rgba(0,0,0,.55)">'+
      '<h2 style="margin:0 0 .55rem;font-size:1.32rem">We’ll be right back</h2>'+
      '<p style="margin:0 0 1.2rem;color:#bcbcbc;line-height:1.6">We’re making a few updates to the site. Posting, profile changes, and sign-ups are paused for just a few minutes — feel free to keep browsing. Thanks for hanging in there!</p>'+
      '<button id="lg-maint-x" style="background:#87986a;color:#11130f;border:0;border-radius:9px;padding:.62rem 1.5rem;font-weight:600;font-size:.95rem;cursor:pointer">Got it</button></div>';
    document.body.appendChild(o);
    o.querySelector('#lg-maint-x').onclick=function(){o.remove();};
  }
  function boot(){ show(); }
  if(document.body)boot();else document.addEventListener('DOMContentLoaded',boot);
  var of=window.fetch; if(of){window.fetch=function(){return of.apply(this,arguments).then(function(r){try{if(r&&r.status===503)show();}catch(e){}return r;});};}
})();
