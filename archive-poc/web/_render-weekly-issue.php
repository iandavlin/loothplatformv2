<?php
/**
 * "This week's issue" — the latest weekly email, for LOGGED-OUT visitors.
 * Backlog item 8 (Ian 2026-07-30; ruled 2026-08-15 after the mock, Option A).
 *
 * Expects: $lg_wk (the payload from lg_weekly_front_payload()).
 * Emits nothing at all when there is nothing to say — the caller has already
 * checked the flag and the audience.
 *
 * ── WHY THE CARDS ARE THE FRONT PAGE'S OWN, AND WHY THAT WAS THE DECISION ───
 * The alternative Ian was shown was the rendered email in an iframe. It is one
 * ruling away and deliberately not built. The reasons this shape won are in the
 * mock (footer-mockups/weekly-front/): an email hardcodes its colours because
 * inboxes strip stylesheets, so a framed one cannot follow dark mode and sits
 * as a lit panel on a dark page; at 390px the frame shows a masthead and one
 * heading; and Ian had already rejected that framing on the sign-up page in
 * July ("This sucks"), where it remains switched off on live.
 *
 * ── TWO CARD TYPES, BECAUSE AN ISSUE GENUINELY HAS TWO ──────────────────────
 * `discussion` items are forum topics and render as .dcard; everything else
 * renders as .rcard. This is not cosmetic — forum topics are not in
 * discovery.content_item at all, so they are not "an rcard we happen to style
 * differently", they are a different thing from a different table that the
 * front page already draws this way.
 *
 * ── THE PADLOCK IS THE POINT (Ian's ruling, 2026-08-15) ─────────────────────
 * Member-only items show WITH their gate. A stranger seeing three locked cards
 * beside two open ones is the clearest argument for joining the page can make.
 * No prose leaks: LG_WD_Front_Feed strips the excerpt from every gated item
 * INSIDE WordPress, so there is nothing here to accidentally reveal.
 */

if ( empty( $lg_wk['sections'] ) ) {
	return;
}

/**
 * The date the issue was SENT, from the issue's own stored data — never the
 * render clock. The email builder dates itself with date_i18n('F j, Y') at
 * render time, which is correct on send day and wrong for every later
 * re-render; on dev2 that currently shows the July 13 issue as "Week of
 * August 15, 2026". This block re-renders an old issue by definition.
 */
$lg_wk_sent  = strtotime( (string) ( $lg_wk['sent_at'] ?? '' ) );
$lg_wk_when  = $lg_wk_sent ? date( 'l j F', $lg_wk_sent ) : '';
?>
    <section class="row row--weekly-issue" data-row-id="weekly-issue">
      <header class="row__head">
        <h2 class="row__title">This week&rsquo;s issue</h2>
      </header>

      <div class="wkiss">
        <div class="wkiss__mast">
          <span class="wkiss__wordmark">The Looth Group Weekly</span>
<?php if ( $lg_wk_when !== '' ): ?>
          <span class="wkiss__sent">Sent <?= h( $lg_wk_when ) ?></span>
<?php endif; ?>
        </div>

        <div class="wkiss__body">
          <p class="wkiss__lede">Every Monday we send one email with the week&rsquo;s new videos, articles and
            loothprints. <b>This is the one that just went out.</b></p>

<?php foreach ( $lg_wk['sections'] as $lg_wk_sec ):
        /* The section's own label, straight from the stored issue. Nothing is
           renamed or re-grouped here — if an editor called it "From The Forum",
           that is what the front page says. */
        $lg_wk_label = trim( (string) ( $lg_wk_sec['label'] ?? '' ) );
?>
<?php if ( $lg_wk_label !== '' ): ?>
          <div class="wkiss__sec"><?= h( $lg_wk_label ) ?></div>
<?php endif; ?>
          <div class="rail">
<?php   foreach ( $lg_wk_sec['items'] as $it ):
          $lg_wk_kind  = (string) ( $it['kind'] ?? 'article' );
          $lg_wk_tier  = (string) ( $it['tier'] ?? 'public' );
          $lg_wk_gated = ! empty( $it['gated'] );
          $lg_wk_url   = (string) ( $it['url'] ?? '#' );
          $lg_wk_thumb = (string) ( $it['thumb_url'] ?? '' );

          if ( $lg_wk_kind === 'discussion' ):
            /* Forum topic — the front page's discussion card. */
            $lg_wk_author  = (string) ( $it['author'] ?? '' );
            $lg_wk_initial = $lg_wk_author !== '' ? mb_strtoupper( mb_substr( $lg_wk_author, 0, 1 ) ) : '?';
?>
            <a class="dcard" href="<?= h( $lg_wk_url ) ?>">
              <div class="dcard__head">
                <span class="dcard__avatar dcard__avatar--ph" aria-hidden="true"><?= h( $lg_wk_initial ) ?></span>
                <span class="dcard__author"><?= h( $lg_wk_author ) ?></span>
              </div>
              <h3 class="dcard__title"><?= h( (string) $it['title'] ) ?></h3>
<?php       if ( ! $lg_wk_gated && ! empty( $it['excerpt'] ) ): ?>
              <p class="dcard__excerpt"><?= h( (string) $it['excerpt'] ) ?></p>
<?php       endif; ?>
            </a>
<?php     else: ?>
            <a class="rcard rcard--<?= h( $lg_wk_kind ) ?><?= $lg_wk_gated ? ' rcard--gated rcard--gated-' . h( $lg_wk_tier ) : '' ?>" href="<?= h( $lg_wk_url ) ?>">
              <div class="rcard__img-wrap">
<?php       /* Uploads through the resizer with a 1x/2x pair and explicit
               dimensions — the craft-gate rule, and fp_img/fp_img_srcset are
               already loaded by _render-main-row.php on every front-page path. */ ?>
                <img class="rcard__img" src="<?= h( function_exists( 'fp_img' ) ? fp_img( $lg_wk_thumb, 480 ) : $lg_wk_thumb ) ?>"<?= function_exists( 'fp_img_srcset' ) ? fp_img_srcset( $lg_wk_thumb, 240, '(max-width: 640px) 45vw, 240px' ) : '' ?> alt="" loading="lazy" width="480" height="320" onerror="this.onerror=null;this.src='<?= h( LG_FALLBACK_IMG ) ?>'">
<?php       if ( $lg_wk_gated ): ?>
                <span class="rcard__gate" aria-label="<?= h( ucfirst( $lg_wk_tier ) ) ?> member content" title="<?= h( ucfirst( $lg_wk_tier ) ) ?> members only">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </span>
<?php       endif; ?>
              </div>
              <div class="rcard__body">
                <h3 class="rcard__title"><?= h( (string) $it['title'] ) ?></h3>
                <div class="rcard__meta">
<?php       if ( ! empty( $it['author'] ) ): ?><span class="author"><?= h( (string) $it['author'] ) ?></span>
<?php       endif; ?>
<?php       if ( ! empty( $it['date'] ) ): ?><span><?= h( (string) $it['date'] ) ?></span><?php endif; ?>
                </div>
                <div class="rcard__foot">
                  <span class="badge badge--<?= h( $lg_wk_tier ) ?>"><?= h( ucfirst( $lg_wk_tier ) ) ?></span>
                </div>
              </div>
            </a>
<?php     endif; ?>
<?php   endforeach; ?>
          </div>
<?php endforeach; ?>
        </div>

        <div class="wkiss__foot">
          <p class="wkiss__pitch"><b>One email a week. No account needed.</b>
            <small>Free, and you can unsubscribe in one click.</small></p>
          <a class="wkiss__cta" href="/weekly-email-sign-up/">Get it every Monday &rarr;</a>
        </div>
      </div>
    </section>
