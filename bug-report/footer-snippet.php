<?php
/**
 * ── "Report a bug" sticky button + modal ───────────────────────────────────
 *
 * PASTE this block into /srv/lg-shared/site-footer.php INSIDE
 * lg_shared_render_site_footer(), immediately BEFORE the closing `<?php`
 * (i.e. after the </footer> on line ~72, before `} // end function`).
 *
 * It is gated LOGGED-IN-ONLY on $ctx['authenticated']. The footer function
 * currently only reads logo_url, so the caller must now ALSO pass
 * 'authenticated' (every consumer already builds it for the header — pass the
 * same value). If a consumer doesn't pass it, the button simply never renders
 * (fails closed → safe).
 *
 * Self-contained: inline CSS + JS, namespaced .lg-bugreport* — no build step,
 * no external asset. Posts same-origin to /wp-json/looth/v1/bug-report; the
 * gate + looth_id + WP cookies ride along automatically.
 */
$lg_br_auth = (bool) ( $ctx['authenticated'] ?? false );
if ( $lg_br_auth ):
?>
<!-- ── LG Report-a-bug (logged-in only) ───────────────────────────────── -->
<style>
.lg-bugreport-btn{position:fixed;right:16px;bottom:16px;z-index:9000;display:inline-flex;
  align-items:center;gap:6px;padding:8px 12px;border:1px solid #87986A;border-radius:999px;
  background:#1A1E12;color:#d4e0b8;font:600 12px/1 system-ui,-apple-system,"Segoe UI",sans-serif;
  cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.28);opacity:.82;transition:opacity .15s,transform .15s}
.lg-bugreport-btn:hover,.lg-bugreport-btn:focus-visible{opacity:1;transform:translateY(-1px);outline:none}
.lg-bugreport-btn svg{width:14px;height:14px;flex:0 0 auto}
@media (max-width:640px){.lg-bugreport-btn{right:12px;bottom:12px;padding:8px;border-radius:50%}
  .lg-bugreport-btn .lg-bugreport-btn__label{display:none}}
.lg-bugreport-modal[hidden]{display:none}
.lg-bugreport-modal{position:fixed;inset:0;z-index:9001;display:flex;align-items:center;
  justify-content:center;padding:16px}
.lg-bugreport-modal__backdrop{position:absolute;inset:0;background:rgba(10,12,7,.62)}
.lg-bugreport-modal__panel{position:relative;width:100%;max-width:440px;background:#1A1E12;
  color:#e7ecdc;border:1px solid #3a4230;border-radius:14px;padding:20px;
  box-shadow:0 18px 50px rgba(0,0,0,.5);font:14px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif}
.lg-bugreport-modal__title{margin:0 0 4px;font-size:17px;font-weight:700;color:#d4e0b8}
.lg-bugreport-modal__sub{margin:0 0 12px;font-size:12px;color:#9aa888}
.lg-bugreport-modal__panel textarea{width:100%;min-height:120px;resize:vertical;box-sizing:border-box;
  background:#11140c;color:#e7ecdc;border:1px solid #3a4230;border-radius:8px;padding:10px;
  font:inherit}
.lg-bugreport-modal__panel textarea:focus{outline:none;border-color:#87986A;box-shadow:0 0 0 2px rgba(135,152,106,.35)}
.lg-bugreport-modal__row{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}
.lg-bugreport-modal__btn{padding:9px 16px;border-radius:8px;border:1px solid transparent;
  font:600 13px/1 inherit;cursor:pointer}
.lg-bugreport-modal__btn--primary{background:#87986A;color:#15170e}
.lg-bugreport-modal__btn--primary:hover{background:#97a87a}
.lg-bugreport-modal__btn--primary:disabled{opacity:.5;cursor:default}
.lg-bugreport-modal__btn--ghost{background:transparent;border-color:#3a4230;color:#cdd6bd}
.lg-bugreport-modal__btn--ghost:hover{border-color:#87986A;color:#d4e0b8}
.lg-bugreport-modal__close{position:absolute;top:12px;right:12px;background:none;border:none;
  color:#9aa888;cursor:pointer;padding:4px;line-height:0}
.lg-bugreport-modal__close:hover{color:#d4e0b8}
.lg-bugreport-modal__err{margin:8px 0 0;font-size:12px;color:#e0a08a;min-height:1em}
.lg-bugreport-modal__done{text-align:center;padding:14px 4px}
.lg-bugreport-modal__done svg{width:42px;height:42px;color:#87986A;margin-bottom:8px}
</style>

<button class="lg-bugreport-btn" type="button" data-lg-bugreport-open aria-haspopup="dialog" aria-label="Report a bug">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <rect x="8" y="6" width="8" height="14" rx="4"/><path d="M12 6V4M5 9H3m18 0h-2M5 14H3m18 0h-2M6 5l1.5 1.5M18 5l-1.5 1.5"/>
  </svg>
  <span class="lg-bugreport-btn__label">Report a bug</span>
</button>

<div class="lg-bugreport-modal" id="lg-bugreport-modal" hidden role="dialog" aria-modal="true" aria-labelledby="lg-bugreport-title">
  <div class="lg-bugreport-modal__backdrop" data-lg-bugreport-close></div>
  <div class="lg-bugreport-modal__panel">
    <button class="lg-bugreport-modal__close" type="button" aria-label="Close" data-lg-bugreport-close>
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>

    <div data-lg-bugreport-form>
      <h2 class="lg-bugreport-modal__title" id="lg-bugreport-title">Report a bug</h2>
      <p class="lg-bugreport-modal__sub">Tell us what went wrong. We'll capture the page you're on automatically.</p>
      <textarea data-lg-bugreport-msg placeholder="What went wrong? What were you trying to do?" aria-label="What went wrong" required></textarea>
      <p class="lg-bugreport-modal__err" data-lg-bugreport-err aria-live="polite"></p>
      <div class="lg-bugreport-modal__row">
        <button class="lg-bugreport-modal__btn lg-bugreport-modal__btn--ghost" type="button" data-lg-bugreport-close>Cancel</button>
        <button class="lg-bugreport-modal__btn lg-bugreport-modal__btn--primary" type="button" data-lg-bugreport-send>Send report</button>
      </div>
    </div>

    <div class="lg-bugreport-modal__done" data-lg-bugreport-done hidden>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <p style="margin:0;font-weight:600;color:#d4e0b8">Thanks — your report was sent.</p>
    </div>
  </div>
</div>

<script>
(function(){
  var openBtn = document.querySelector('[data-lg-bugreport-open]');
  var modal   = document.getElementById('lg-bugreport-modal');
  if (!openBtn || !modal) return;
  var formWrap = modal.querySelector('[data-lg-bugreport-form]');
  var doneWrap = modal.querySelector('[data-lg-bugreport-done]');
  var msg      = modal.querySelector('[data-lg-bugreport-msg]');
  var sendBtn  = modal.querySelector('[data-lg-bugreport-send]');
  var errEl    = modal.querySelector('[data-lg-bugreport-err]');
  var lastFocus = null;

  function open(){
    lastFocus = document.activeElement;
    formWrap.hidden = false; doneWrap.hidden = true;
    errEl.textContent = ''; msg.value = '';
    modal.hidden = false;
    setTimeout(function(){ msg.focus(); }, 30);
  }
  function close(){
    modal.hidden = true;
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  openBtn.addEventListener('click', open);
  modal.querySelectorAll('[data-lg-bugreport-close]').forEach(function(el){
    el.addEventListener('click', close);
  });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && !modal.hidden) close();
  });

  sendBtn.addEventListener('click', function(){
    var text = (msg.value || '').trim();
    if (!text){ errEl.textContent = 'Please describe what went wrong.'; msg.focus(); return; }
    errEl.textContent = '';
    sendBtn.disabled = true; sendBtn.textContent = 'Sending…';

    var headers = { 'Content-Type': 'application/json' };
    // Best-effort WP REST nonce when on a WP-native page (cross-app pages
    // won't have it; the endpoint authenticates on the looth_id cookie).
    if (window.wpApiSettings && window.wpApiSettings.nonce) {
      headers['X-WP-Nonce'] = window.wpApiSettings.nonce;
    }

    fetch('/wp-json/looth/v1/bug-report', {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
      body: JSON.stringify({ message: text, page_url: window.location.href })
    })
    .then(function(r){ return r.json().then(function(j){ return { ok: r.ok, j: j }; }); })
    .then(function(res){
      if (res.ok && res.j && res.j.ok){
        formWrap.hidden = true; doneWrap.hidden = false;
        setTimeout(close, 1800);
      } else {
        errEl.textContent = (res.j && res.j.message) ? res.j.message : 'Could not send. Please try again.';
      }
    })
    .catch(function(){ errEl.textContent = 'Network error. Please try again.'; })
    .then(function(){ sendBtn.disabled = false; sendBtn.textContent = 'Send report'; });
  });
})();
</script>
<!-- ── /LG Report-a-bug ───────────────────────────────────────────────── -->
<?php endif; /* $lg_br_auth */ ?>
