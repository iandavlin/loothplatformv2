# Do the eight legacy "Add Post" forms actually submit?

**Status: STILL NOT SETTLED.** Recorded here so the next attempt starts from what
is established rather than from the top.

## Why it matters

Eight of the nine legacy `/add-…/` pages serve a live ACF form to an ordinary
member (measured; see `docs/FRONTEND-COMPOSE-SCOPE.md`, second correction). If
those forms *work*, any member can already publish a video, an article or a
sponsor post — which changes how the compose feature's gate should be described.
It changes nothing about the compose feature itself, which is why this has stayed
off the critical path.

## Established

* **The submit channel.** `frontend-admin-pro` registers
  `wp_ajax_frontend_admin/form_submit` **and** `wp_ajax_nopriv_...` →
  `Submit::check_submit_form()`
  (`main/frontend/forms/classes/submit.php:864-865`). Logged-out is registered
  too; whether the handler then refuses is a separate question.
* **The guard order** in `check_submit_form()`: `_acf_form` present →
  `validate_form()` → `feadmin_verify_nonce($form['id'] . '_form')` →
  `submit_form()`. The honeypot `acff[_validate_email]` rejects as "Spam Detected".
* **The nonce field** is `_acf_nonce` (`main/helpers.php:751`), single-use — it is
  reset after a successful verify.
* **The submit control is NOT a submit input.** It is
  `<button type="button">Add Post</button>`; its JS drives the AJAX. The form also
  contains "Add Media", "Visual" and "Text" buttons — `querySelector('[type=submit]')`
  returns **Add Media**, which is what I clicked the first time and learned nothing.

## Tried, and what each attempt actually proved

1. **Plain page POST of the form's hidden fields** → HTTP 200, nothing created,
   title not echoed. Proves nothing: the form does not submit that way. A null
   result from the wrong channel is not a closed door.
2. **Direct AJAX POST with the page's own `_acf_nonce`** → reaches the handler and
   returns its own `"Authentication Error"`. So the channel and action name are
   right; the nonce action string is not `<form_id>_form` as the source reads.
   Seven candidates tried (`7270_elementor_78c15b7_form`, `7270_form`,
   `acf-form_form`, `acf-form`, `acf_form`, `post_form`, bare id) — none verify,
   including a nonce minted server-side for `acf-form_form`. A synthetic
   `wp_generate_auth_cookie` session may simply not reproduce whatever the page's
   nonce is bound to.
3. **Real browser, real button.** Filled the required title, hit-tested and clicked
   the actual **Add Post** button as an ordinary member. Result: **no post created,
   and no error text found** in `.acf-error-message`, `.acf-notice` or
   `[class*=error]`.

## The most likely remaining explanation, and the next step

The video form has several **REQUIRED repeater subfields**
(`field_67252b0c38b33`, `field_664b27df74000` and their `url` siblings). Attempt 3
filled only the title, so the form's own validation may have refused silently — or
surfaced an error somewhere my selectors did not look.

**Next step:** fill every required field (including the repeater rows) and watch
the network for the `frontend_admin/form_submit` request and its JSON response,
rather than inferring from the DOM. That answers it in one run.

## Safety note

Every attempt was made as an ordinary member against dev2 and created nothing;
video post counts were 369 before and after each. No cleanup was needed because
nothing was ever created.
