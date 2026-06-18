<?php
/**
 * blocks/contact-form/render.php
 *
 * @var array $args  { heading?: string, blurb?: string, action?: string, _depth: int }
 * @var array $ctx   { sponsor, editor_mode, ... }
 *
 * Sponsor lead-gen form. POSTs name/email/message to the sponsor-contact
 * endpoint, which looks up the recipient by the hidden `slug` (it never trusts
 * a client-supplied email as the recipient) and mails sponsor.email.
 *
 * Degradation: no sponsor.email → no recipient → suppress the form entirely
 * (a contact form that can't deliver is worse than none). When email IS
 * present, a 'or email us directly' mailto: fallback is always rendered so the
 * lead path survives even if the endpoint/JS is unavailable.
 *
 * The <lg-edit> marker is emitted by the Renderer wrapper — do NOT emit it here.
 */

use LG\LayoutV2\Renderer;

$sponsor = is_array($ctx['sponsor'] ?? null) ? $ctx['sponsor'] : null;
$depth   = (int) ($args['_depth'] ?? 1);
$ind     = Renderer::indent($depth);
$editorMode = !empty($ctx['editor_mode']);

$email = $sponsor !== null ? trim((string) ($sponsor['email'] ?? '')) : '';
$slug  = $sponsor !== null ? trim((string) ($sponsor['slug'] ?? '')) : '';

/* No deliverable address → no form. */
if ($email === '') {
    if ($editorMode) echo $ind . '<!-- lg-contact-form: sponsor record has no email; form suppressed -->';
    return;
}

$name    = $sponsor !== null ? (trim((string) ($sponsor['display_name'] ?? '')) ?: trim((string) ($sponsor['name'] ?? ''))) : '';
$heading = trim((string) ($args['heading'] ?? '')) ?: 'Get in touch';
$blurb   = trim((string) ($args['blurb'] ?? ''));
if ($blurb === '' && $name !== '') $blurb = "Send a message to {$name} and they'll get back to you.";
$action  = trim((string) ($args['action'] ?? '')) ?: '/archive-api/v0/sponsor-contact';

$headingEdit = $editorMode ? ' data-lg-edit-prop="heading"' : '';
$blurbEdit   = $editorMode ? ' data-lg-edit-prop="blurb"' : '';

ob_start();
?>
<?= $ind ?><section class="lg-contact-form">
<?= $ind ?>  <div class="lg-contact-form__inner">
<?= $ind ?>    <h2 class="lg-contact-form__title"<?= $headingEdit ?>><?= Renderer::text($heading) ?></h2>
<?php if ($blurb !== '' || $editorMode): ?>
<?= $ind ?>    <p class="lg-contact-form__blurb"<?= $blurbEdit ?>><?= Renderer::text($blurb) ?></p>
<?php endif; ?>
<?= $ind ?>    <form class="lg-contact-form__form" method="post" action="<?= Renderer::attr($action) ?>" data-lg-contact-form>
<?= $ind ?>      <input type="hidden" name="slug" value="<?= Renderer::attr($slug) ?>" />
<?= $ind ?>      <div class="lg-contact-form__row">
<?= $ind ?>        <label class="lg-contact-form__field">
<?= $ind ?>          <span>Name</span>
<?= $ind ?>          <input type="text" name="name" autocomplete="name" required />
<?= $ind ?>        </label>
<?= $ind ?>        <label class="lg-contact-form__field">
<?= $ind ?>          <span>Email</span>
<?= $ind ?>          <input type="email" name="email" autocomplete="email" required />
<?= $ind ?>        </label>
<?= $ind ?>      </div>
<?= $ind ?>      <label class="lg-contact-form__field">
<?= $ind ?>        <span>Message</span>
<?= $ind ?>        <textarea name="message" rows="5" required></textarea>
<?= $ind ?>      </label>
<?= $ind ?>      <div class="lg-contact-form__actions">
<?= $ind ?>        <button type="submit" class="lg-contact-form__submit">Send message</button>
<?= $ind ?>        <a class="lg-contact-form__mailto" href="mailto:<?= Renderer::attr($email) ?>">or email us directly</a>
<?= $ind ?>      </div>
<?= $ind ?>      <p class="lg-contact-form__status" data-lg-contact-status role="status" aria-live="polite" hidden></p>
<?= $ind ?>    </form>
<?= $ind ?>  </div>
<?= $ind ?></section>
<?php
echo ob_get_clean();
