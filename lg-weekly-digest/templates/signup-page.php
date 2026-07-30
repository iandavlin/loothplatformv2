<?php
/**
 * The public weekly-email signup page.
 *
 * Rendered by [lg_weekly_signup] (LG_WD_Signup_Page). This is the BUILD of
 * dev/signup/mock-signup.html, which Ian ruled on in six parts on 2026-07-30;
 * each ruling is marked at the markup it governs so a later reader can tell a
 * decision from a preference.
 *
 * SCOPED UNDER .lgws. The page renders inside the theme, so every selector is
 * prefixed — an unscoped `section{padding:52px 0}` from the mock would have
 * restyled the whole site's sections on any page this shortcode appears on.
 *
 * @package LG_Weekly_Digest
 */

defined( 'ABSPATH' ) || exit;

$lgws_ajax    = LG_WD_Signup_Page::ajax_url();
$lgws_sample  = LG_WD_Signup_Page::sample_email_url();
$lgws_prefs   = LG_WD_Signup_Page::prefs_url();
?>
<div class="lgws">
<style>
.lgws{--gold:#ECB351;--dark:#2B2318;--mint:#87986A;--mint-pale:#D4E0B8;
      --paper:#FAF6EE;--ink:#2B2318;--dim:#6b6357;--line:#e3dbcd;--white:#fff;--mail:#e8e2d8;
      background:var(--paper);color:var(--ink);
      font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
      -webkit-font-smoothing:antialiased}
.lgws *{box-sizing:border-box}
.lgws .wrap{max-width:1080px;margin:0 auto;padding:0 20px}
.lgws .sec{padding:52px 0}

/* hero */
.lgws .hero{background:var(--dark);color:var(--paper);padding:54px 0 48px}
.lgws .hero .wrap{display:grid;grid-template-columns:1.15fr .85fr;gap:48px;align-items:center}
.lgws .eyebrow{display:inline-flex;align-items:center;gap:8px;font:700 11.5px/1 inherit;
      letter-spacing:.14em;text-transform:uppercase;color:var(--gold);margin:0 0 16px}
.lgws .eyebrow::before{content:"";width:22px;height:2px;background:var(--gold)}
.lgws h1{font-size:clamp(28px,4.2vw,42px);line-height:1.12;margin:0 0 14px;letter-spacing:-.02em;color:var(--paper)}
.lgws .lede{font-size:17.5px;line-height:1.55;color:#e8e0d2;margin:0 0 22px;max-width:47ch}
.lgws .lede b{color:var(--gold)}
.lgws .cadence{display:flex;flex-wrap:wrap;gap:8px;margin:0}
.lgws .chip{display:inline-flex;align-items:center;gap:7px;background:rgba(236,179,81,.13);
      border:1px solid rgba(236,179,81,.4);color:var(--gold);border-radius:99px;
      padding:6px 13px;font:600 12.5px/1 inherit}

/* form card */
.lgws .card{background:var(--white);border-radius:12px;padding:26px 24px 22px;
      box-shadow:0 8px 28px rgba(0,0,0,.22);color:var(--ink)}
.lgws .card h2{font-size:19px;margin:0 0 6px;color:var(--ink)}
.lgws .card .sub{font-size:13.5px;color:var(--dim);margin:0 0 18px}
.lgws label.fl{display:block;font:600 13px/1 inherit;margin:0 0 6px}
.lgws input[type=email]{width:100%;font:16px/1.4 inherit;padding:12px 13px;color:var(--ink);
      border:1.5px solid var(--line);border-radius:7px;background:var(--white)}
.lgws input[type=email]:focus{outline:0;border-color:var(--gold);box-shadow:0 0 0 3px rgba(236,179,81,.25)}
.lgws .consent{display:flex;gap:10px;align-items:flex-start;margin:15px 0 0;
      font-size:12.5px;line-height:1.45;color:var(--dim)}
.lgws .consent input{margin:2px 0 0;flex:0 0 auto;width:16px;height:16px;accent-color:var(--mint)}
.lgws .btn{display:block;width:100%;margin:17px 0 0;font:700 16px/1 inherit;padding:14px;
      color:var(--dark);background:var(--gold);border:0;border-radius:7px;cursor:pointer}
.lgws .btn:hover{background:#e0a53f}
.lgws .btn[disabled]{opacity:.6;cursor:default}
/* The 7% who never confirm are why this sits IN the card, not in small print. */
.lgws .next{display:flex;gap:9px;align-items:flex-start;margin:15px 0 0;padding:11px 12px;
      background:var(--paper);border:1px solid var(--line);border-radius:7px;
      font-size:12.5px;line-height:1.45;color:var(--dim)}
.lgws .next b{color:var(--ink)}
.lgws .next .ico{flex:0 0 auto;font-size:15px;line-height:1.2}
/* honeypot: off-screen, not display:none — some bots skip hidden fields */
.lgws .hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}

/* the answer panel that replaces the form */
.lgws .said{border-radius:9px;padding:15px 16px;font-size:14px;line-height:1.5;margin:17px 0 0;
      background:var(--mint-pale);border:1px solid #b9c79a;color:#2f3d1c}
.lgws .said b{display:block;margin:0 0 4px;color:var(--dark);font-size:15px}
.lgws .said.is-bad{background:#fdf0e7;border-color:#e8c4ad;color:#7a4a2c}
.lgws .said a{color:var(--dark);font-weight:700}
.lgws .card.is-done .fl,.lgws .card.is-done .consent,
.lgws .card.is-done .btn,.lgws .card.is-done .next,
.lgws .card.is-done input[type=email]{display:none}

/* sections */
.lgws .shead{text-align:center;max-width:58ch;margin:0 auto 34px}
.lgws .shead h2{font-size:clamp(22px,3vw,30px);margin:0 0 10px;letter-spacing:-.01em;color:var(--ink)}
.lgws .shead p{font-size:15.5px;color:var(--dim);margin:0}
.lgws .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:18px}
.lgws .tile{background:var(--white);border:1px solid var(--line);border-radius:10px;padding:20px 18px}
.lgws .tile .ti{width:36px;height:36px;border-radius:8px;background:var(--mint-pale);
      display:flex;align-items:center;justify-content:center;font-size:18px;margin:0 0 12px}
.lgws .tile h3{font-size:15px;margin:0 0 6px;color:var(--ink)}
.lgws .tile p{font-size:13.5px;color:var(--dim);margin:0;line-height:1.5}

/* RULINGS 3+4 — the window */
.lgws .window{background:var(--mint-pale)}
.lgws .wsteps{display:grid;grid-template-columns:repeat(3,1fr);gap:0;max-width:860px;margin:0 auto;
      background:var(--white);border:1px solid #b9c79a;border-radius:12px;overflow:hidden}
.lgws .wstep{padding:22px 20px;border-right:1px solid #dfe7cd}
.lgws .wstep:last-child{border-right:0}
.lgws .wstep .n{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;
      border-radius:50%;background:var(--dark);color:var(--gold);font:700 13px/1 inherit;margin:0 0 11px}
.lgws .wstep h3{font-size:15.5px;margin:0 0 6px;color:var(--ink)}
.lgws .wstep p{font-size:13.5px;color:var(--dim);margin:0;line-height:1.5}
.lgws .wstep.is-shut .n{background:#8a8578;color:#fff}
.lgws .wnote{max-width:860px;margin:16px auto 0;text-align:center;font-size:14.5px;color:#41502a}
.lgws .wnote b{color:var(--dark)}

/* RULING 2 — the sample email is CONTAINED, not floating: the frame is the
   email's OWN 624px column and its OWN #e8e2d8 body colour, with no shadow, on
   the light page. Three mismatches (width, colour, elevation) were what made it
   read as detached. */
.lgws .mailsec{background:var(--white);border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
/* 992px is NOT a taste decision — it is the framed document's own width.
   templates/email.php:68 sets .email-container to max-width:960px, and :64 wraps it
   in .email-wrapper with padding:24px 16px, i.e. 32px horizontally. 960 + 32 = 992.
   Framing it at 624 (the email's CONTENT COLUMN, not its DOCUMENT) left 368px
   outside the box, so a reader had to pan sideways to finish a headline — which is
   exactly what Ian saw. Approved by him 2026-07-30 as variant B.
   max-width, never width: below 992 this collapses to the viewport and the email's
   own 768/480 breakpoints take over, which is what keeps it readable on a phone.
   dev/verify-preview-frame-fits.php holds both halves of that. */
.lgws .mail{max-width:992px;margin:0 auto;border:1px solid var(--line);border-radius:10px;
      overflow:hidden;background:var(--mail)}
.lgws .mailbar{display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--dark);
      color:var(--paper);font:600 12.5px/1 inherit}
.lgws .mailbar .dot{width:9px;height:9px;border-radius:50%;background:var(--gold);flex:0 0 auto}
.lgws .mailbar .from{margin-left:auto;font-weight:400;color:#b7ad9b;font-size:11.5px}
.lgws .mail iframe{display:block;width:100%;height:600px;border:0;background:var(--mail)}

/* RULING 6 — the member state */
.lgws .already{max-width:760px;margin:0 auto;background:var(--white);border:1px solid var(--line);
      border-left:4px solid var(--mint);border-radius:10px;padding:22px 24px}
.lgws .already h3{font-size:17px;margin:0 0 8px;color:var(--ink)}
.lgws .already p{font-size:14.5px;color:var(--dim);margin:0 0 10px}
.lgws .already a{color:var(--dark);font-weight:700;text-decoration:underline;
      text-decoration-color:var(--gold);text-decoration-thickness:2px;text-underline-offset:3px}

@media (max-width:880px){
  .lgws .hero{padding:40px 0 36px}
  .lgws .hero .wrap{grid-template-columns:1fr;gap:30px}
  .lgws .grid{grid-template-columns:repeat(2,1fr)}
  .lgws .wsteps{grid-template-columns:1fr}
  .lgws .wstep{border-right:0;border-bottom:1px solid #dfe7cd}
  .lgws .wstep:last-child{border-bottom:0}
  .lgws .mail iframe{height:520px}
}
@media (max-width:520px){ .lgws .grid{grid-template-columns:1fr} .lgws .sec{padding:38px 0} }
</style>

<div class="hero">
  <div class="wrap">
    <div>
      <p class="eyebrow">The Looth Group Weekly</p>
      <!-- RULING 1: people who BUILD AND REPAIR — not "guitar fans". -->
      <h1>For people who build and repair guitars.</h1>
      <p class="lede">One email a week for luthiers, repairers and benders of truss rods —
        with <b>this week's public articles and videos, while they're still public</b>.</p>
      <p class="cadence">
        <span class="chip">✉ Once a week, Mondays</span>
        <span class="chip">◔ About a 4-minute read</span>
        <span class="chip">↩ Unsubscribe in one click</span>
      </p>
    </div>

    <form class="card" id="lgws-form" novalidate>
      <h2>Get the weekly email</h2>
      <p class="sub">Free. No account needed.</p>

      <label class="fl" for="lgws-email">Your email address</label>
      <input id="lgws-email" type="email" name="email" placeholder="you@example.com"
             autocomplete="email" required>

      <!-- Honeypot. The endpoint swallows any submission that fills this in. -->
      <div class="hp" aria-hidden="true">
        <label for="lgws-website">Website</label>
        <input id="lgws-website" type="text" name="website" tabindex="-1" autocomplete="off">
      </div>

      <label class="consent">
        <input type="checkbox" name="gdpr-agreement" required>
        <span>I agree to receive the weekly email from The Looth Group and understand
          I can unsubscribe at any time.</span>
      </label>

      <button class="btn" type="submit">Send me the weekly</button>

      <p class="next">
        <span class="ico" aria-hidden="true">→</span>
        <span><b>One more step:</b> we'll email you a confirmation link. Click it and you're on
          the list — if you don't, the emails never start.</span>
      </p>

      <div class="said" id="lgws-said" role="status" aria-live="polite" hidden></div>

      <noscript>
        <div class="said is-bad"><b>This form needs JavaScript.</b>
          Turn it on and reload, or email us and we'll add you by hand.</div>
      </noscript>
    </form>
  </div>
</div>

<!-- RULINGS 3+4: the hook, and the whole reason to be on the list. -->
<section class="sec window">
  <div class="wrap">
    <div class="shead">
      <h2>Why it's worth being on the list</h2>
      <p>New articles and videos go up public. They don't stay that way.</p>
    </div>
    <div class="wsteps">
      <div class="wstep">
        <span class="n">1</span>
        <h3>It goes up public</h3>
        <p>A new repair writeup, teardown or video is published and anyone can read it — no
          account, no membership.</p>
      </div>
      <div class="wstep">
        <span class="n">2</span>
        <h3>Monday, we tell you</h3>
        <p>That week's public pieces are listed in the email, with links straight to them.
          This is the part you're signing up for.</p>
      </div>
      <div class="wstep is-shut">
        <span class="n">3</span>
        <h3>Later it goes members-only</h3>
        <p>It moves behind membership and stays there. If you didn't catch it while it was
          open, that's the door shut.</p>
      </div>
    </div>
    <p class="wnote"><b>So the email isn't a summary of things you've missed — it's the window
      while it's still open.</b> That's the whole reason it exists.</p>
  </div>
</section>

<section class="sec">
  <div class="wrap">
    <div class="shead">
      <h2>What's actually in it</h2>
      <p>Put together by hand each week. These are the sections it's built from.</p>
    </div>
    <div class="grid">
      <div class="tile">
        <div class="ti" aria-hidden="true">🔧</div>
        <h3>Repairs &amp; restorations</h3>
        <p>What came in, what was wrong with it, and how it was put right — with the photos.</p>
      </div>
      <div class="tile">
        <div class="ti" aria-hidden="true">🪵</div>
        <h3>Builds in progress</h3>
        <p>Bench work from people building instruments: jigs, timber, finishes, mistakes.</p>
      </div>
      <div class="tile">
        <div class="ti" aria-hidden="true">📅</div>
        <h3>Upcoming events</h3>
        <p>Shows, meetups and workshops worth the drive, with dates and places.</p>
      </div>
      <div class="tile">
        <div class="ti" aria-hidden="true">💬</div>
        <h3>From the forum</h3>
        <p>The problems people brought to the bench this week, and what actually fixed them.</p>
      </div>
    </div>
  </div>
</section>

<?php if ( $lgws_sample ) : /* No sent issue -> no section, rather than an empty frame. */ ?>
<section class="sec mailsec">
  <div class="wrap">
    <div class="shead">
      <h2>Here's a real one</h2>
      <p>Not an illustration — this is the most recent issue that actually went out, rendered
        by the same code that sends it.</p>
    </div>
    <div class="mail">
      <div class="mailbar">
        <span class="dot" aria-hidden="true"></span>
        <span>The Looth Group Weekly</span>
        <span class="from">from noreply@loothgroup.com</span>
      </div>
      <iframe src="<?php echo esc_url( $lgws_sample ); ?>"
              title="The most recent issue of the weekly email"
              loading="lazy" referrerpolicy="same-origin"></iframe>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- RULING 6: what a member sees, stated on the page and not only after they submit. -->
<section class="sec">
  <div class="wrap">
    <div class="already">
      <h3>Already a Looth Group member?</h3>
      <p>You already get this — it comes with your membership, and your copy also tells you
        what's waiting for you on the site: connection requests, replies to your discussions,
        and anyone who mentioned you.</p>
      <?php if ( $lgws_prefs ) : ?>
        <p><a href="<?php echo esc_url( $lgws_prefs ); ?>">Manage your email preferences →</a></p>
      <?php endif; ?>
      <p>If you sign up here anyway we'll tell you you're already on the list — we won't
        change anything, and we never add a member to the non-member list.</p>
    </div>
  </div>
</section>
</div>

<script>
(function () {
  var form = document.getElementById('lgws-form');
  if (!form) return;
  var said = document.getElementById('lgws-said');
  var btn  = form.querySelector('.btn');
  var card = form;

  // The endpoint OWNS the copy for the four audience states (Ian's ruling 6), so
  // it is not duplicated here — data.message is rendered as sent. Only the error
  // codes get page-side wording, because the endpoint returns codes for those.
  var ERRORS = {
    bad_email:      ['That address doesn’t look right', 'Check it over and try again.'],
    slow_down:      ['Too many tries from here', 'Give it an hour and have another go.'],
    bad_origin:     ['That didn’t come from this page', 'Reload and try again.'],
    crm_unavailable:['We can’t sign you up right now', 'Something on our side is down. Please try again later.'],
    crm_error:      ['Something went wrong at our end', 'Nothing was saved. Please try again in a few minutes.']
  };

  var HEADS = {
    pending:            'Almost there',
    already_signed_up:  'You’re already signed up',
    already_member:     'You’re already on the list',
    member_needs_prefs: 'You already have an account'
  };

  function show(head, body, bad, done) {
    said.innerHTML = '';
    var b = document.createElement('b'); b.textContent = head;
    said.appendChild(b);
    said.appendChild(document.createTextNode(body));
    said.classList.toggle('is-bad', !!bad);
    said.hidden = false;
    if (done) card.classList.add('is-done');
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var email   = form.querySelector('input[name=email]').value.trim();
    var consent = form.querySelector('input[name=gdpr-agreement]').checked;

    if (!email) { show('We need an email address', ' Pop it in above and try again.', true); return; }
    if (!consent) { show('One box left', ' Tick the consent box so we know it’s OK to email you.', true); return; }

    btn.disabled = true;
    btn.textContent = 'Signing you up…';

    var body = new FormData();
    body.append('action', 'lg_weekly_signup');
    body.append('email', email);
    body.append('website', form.querySelector('input[name=website]').value);

    fetch(<?php echo wp_json_encode( $lgws_ajax ); ?>, {
      method: 'POST', body: body, credentials: 'same-origin'
    })
    .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'crm_error' }; }); })
    .then(function (d) {
      btn.disabled = false;
      btn.textContent = 'Send me the weekly';
      if (d && d.ok) {
        // A bot that tripped the honeypot gets {ok:true} with no state — say the
        // normal thing rather than nothing, so the page never looks broken.
        var head = HEADS[d.state] || 'Thanks';
        show(head, ' ' + (d.message || 'You’re all set.'), false, true);
        return;
      }
      var err = (d && d.error) || 'crm_error';
      var pair = ERRORS[err] || ERRORS.crm_error;
      show(pair[0], ' ' + pair[1], true);
    })
    .catch(function () {
      btn.disabled = false;
      btn.textContent = 'Send me the weekly';
      show('That didn’t get through', ' Check your connection and try again.', true);
    });
  });
})();
</script>
