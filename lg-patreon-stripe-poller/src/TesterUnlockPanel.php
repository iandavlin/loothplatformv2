<?php

declare(strict_types=1);

namespace LGMS;

/**
 * TesterUnlockPanel — the Testers tab's unlock-link panel. Issue #190.
 *
 * IT IS ITS OWN CLASS FOR ONE REASON: so a gate can render it without loading
 * Admin.php. That file reaches StripeLifecycle, StripePrice, Invites,
 * CompExpiry and more, and its neighbour test file has now died at exit 255
 * with NO FAIL LINE three separate times because the door gained a dependency
 * nobody added to a require list. A panel that can only be exercised by loading
 * all of that is a panel that will eventually stop being exercised.
 *
 * So the dependencies here are exactly two — TesterUnlock and CohortAllowlist —
 * plus WordPress's escaping and nonce helpers. Gate 90 renders it in every
 * state with those stubbed, and asserts what is on the page.
 */
final class TesterUnlockPanel
{
    /**
     * THE TESTER LINK PANEL (#190) — the half of "who can get in" that had no
     * screen at all.
     *
     * Ian, 2026-08-21: "Can we put the token link in there with the whitlist ?"
     * It sits directly above the test-group list because the two are the same
     * question asked twice: the list admits people who are SIGNED IN and on it,
     * this admits ONE anonymous browser. #181 has no admin bypass, so Ian has
     * to be on the list below to buy, and carrying the link above does not
     * change that — it changes what he can SEE.
     *
     * Everything it prints is a measurement. Where it cannot measure something
     * it says "unknown" rather than guessing, because a dash that quietly
     * reports the safe-looking answer sends whoever reads it to fix the wrong
     * thing.
     */
    public static function render(): void
    {
        $u      = TesterUnlock::describe();
        $header = TesterUnlock::headerJoinState();
        $ok     = isset( $_GET['lgms_tester_ok'] ) ? rawurldecode( (string) $_GET['lgms_tester_ok'] ) : '';

        $chip = static function ( string $label, bool $good, bool $neutral = false ): string {
            $bg = $neutral ? '#f0f0f1;color:#666' : ( $good ? '#dcfce7;color:#15803d' : '#fef3c7;color:#92400e' );
            return '<span class="lgms-chip" style="background:' . $bg . ';">' . esc_html( $label ) . '</span>';
        };
        ?>
        <h2 style="margin-top:0;">The tester link</h2>

        <?php if ( $ok !== '' ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $ok ); ?></p></div>
        <?php endif; ?>

        <p class="description" style="max-width:760px;">
            One URL marks <strong>one browser</strong>. For that browser only, the site's
            Join button points at the Stripe join page instead of Patreon, and the join
            page lets it in. Everybody else sees exactly what they see today. It is a
            <strong>page-visibility</strong> key and nothing more — it does not let anyone
            buy, and it grants no membership.
        </p>

        <p>
            <?php
            echo $chip( 'unlock ' . ( $u['armed'] ? 'ARMED' : 'OFF' ), $u['armed'] );
            echo $chip( 'header: ' . $header, $header === 'allowlist' || $header === 'on', $header === 'unknown' );
            echo $chip( 'in the test group: ' . count( CohortAllowlist::ids() ), true, true );
            ?>
            <style>.lgms-chip { display:inline-block; padding:.15em .55em; border-radius:3px; font-size:.85em; font-weight:600; margin-right:.4em; }</style>
        </p>

        <?php
        /* THE PAIRING, STATED WHERE IT MATTERS (#170's ruling). The unlock only
           WIDENS the header's 'allowlist' state. In 'off' it is inert — not
           broken, inert — and an armed link with an 'off' header is the exact
           shape of "wired perfectly and lands nowhere" that #165 and #170 were
           both bitten by. Reported rather than blocked: arming in the wrong
           order is fine, it just does nothing until the header moves. */
        if ( $u['armed'] && $header === 'off' ) : ?>
            <div class="notice notice-warning"><p>
                <strong>The link is armed, but the header is <code>off</code>, so it currently does nothing.</strong>
                In <code>off</code> the Join button goes to Patreon for everyone, by ruling —
                the unlock only widens the <code>allowlist</code> state. Move the header to
                <code>allowlist</code> (<code>platform/config/header-join-stripe.local.php</code>) and
                this link starts working with no change here.
            </p></div>
        <?php elseif ( $header === 'unknown' ) : ?>
            <div class="notice notice-warning"><p>
                <strong>Could not read the header's join state on this box</strong> — the shared
                site header did not load. The link below may be correct; what it does on the
                front end cannot be confirmed from here.
            </p></div>
        <?php endif; ?>

        <?php if ( ! $u['writable'] ) : ?>
            <div class="notice notice-error"><p>
                <strong>This box cannot store a tester link yet.</strong> WordPress cannot write
                <code><?php echo esc_html( $u['state_path'] ); ?></code>. It needs the one-time
                root step:<br>
                <code>install -d -o looth-dev -g looth-dev -m 755 <?php echo esc_html( dirname( $u['state_path'] ) ); ?></code><br>
                The buttons below are shown but will refuse rather than half-work.
            </p></div>
        <?php endif; ?>

        <?php if ( $u['mode'] === 'dash' ) : ?>
            <p style="margin-bottom:.35em;"><strong>The live link — send this to a tester:</strong></p>
            <?php /* Wide enough for the WHOLE URL at this font — host + /lgjoin/
                     + a 48-hex token is ~92 characters, and a visibly clipped
                     link undermines the one thing this panel is for. Copy and
                     click-to-select both take the full value either way. */ ?>
            <p style="display:flex;gap:.5em;align-items:center;max-width:900px;">
                <input type="text" id="lgms-tester-url" readonly
                       style="flex:1;font-family:monospace;font-size:12.5px;"
                       value="<?php echo esc_attr( $u['url'] ); ?>"
                       onclick="this.select()">
                <button type="button" class="button" id="lgms-tester-copy">Copy</button>
            </p>
            <p class="description" style="max-width:760px;">
                It does not expire and it is not tied to an email address — a forwarded copy
                works too, which is accepted: looking at the join page is not signing up for
                anything. The controls are Rotate and Turn off, and both are instant.
                <?php if ( $u['rotated'] !== '' ) : ?>
                    <br>Minted <?php echo esc_html( $u['rotated'] ); ?> (UTC).
                <?php endif; ?>
            </p>

        <?php elseif ( $u['mode'] === 'foreign' ) : ?>
            <div class="notice notice-warning"><p>
                <strong>This box is armed, but not by this dash — so the working link cannot be
                shown here.</strong> Something else is arming it: a hand-placed
                <code>platform/config/tester-unlock.local.php</code>, or an environment override.
                Only a hash of the token is stored there, and a hash cannot be turned back into
                a link. <strong>Rotate below to mint a fresh one and take ownership</strong> — that
                is the only way to get a link on screen, and it stops the existing one working.
            </p></div>

        <?php elseif ( $u['mode'] === 'stale' ) : ?>
            <div class="notice notice-warning"><p>
                <strong>A token is stored here but this box does not read as armed</strong> — so
                the link would not work if you sent it. Either turn it off to tidy up, or rotate
                to arm the box on a fresh link.
            </p></div>

        <?php else : ?>
            <p><strong>There is no tester link on this box.</strong> Nothing is wrong — an
            anonymous visitor gets Patreon, which is the shipped behaviour. Create one when
            you want to hand a tester the Stripe join page.</p>
        <?php endif; ?>

        <p style="margin-top:1em;">
            <?php
            /* ROTATE. Ian ruled: one deliberate click, with the consequence
               stated BEFORE it happens. So the sentence is on the page next to
               the button, the button names what it does, and the confirm only
               appears when there is actually something to break — creating the
               FIRST link breaks nothing and asking "are you sure?" there would
               train the reflex that dismisses the one that matters. */
            $breaks  = ( $u['mode'] === 'dash' || $u['mode'] === 'foreign' );
            $label   = $breaks ? 'Rotate — mint a new link' : 'Create the tester link';
            $confirm = 'Rotate the tester link?\n\nEvery link already sent stops working immediately, including any a tester is still using. This cannot be undone — the old token is gone.';
            ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5em;">
                <?php wp_nonce_field( 'lgms_tester_rotate' ); ?>
                <input type="hidden" name="action" value="lgms_tester_rotate">
                <button type="submit" class="button<?php echo $breaks ? '' : ' button-primary'; ?>"
                        <?php if ( $breaks ) : ?>onclick="return confirm(<?php echo esc_attr( wp_json_encode( $confirm ) ); ?>);"<?php endif; ?>>
                    <?php echo esc_html( $label ); ?>
                </button>
            </form>

            <?php if ( $u['armed'] || $u['token'] !== '' ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                    <?php wp_nonce_field( 'lgms_tester_clear' ); ?>
                    <input type="hidden" name="action" value="lgms_tester_clear">
                    <button type="submit" class="button"
                            onclick="return confirm(<?php echo esc_attr( wp_json_encode( 'Turn the tester link off?\n\nEvery link already sent stops working immediately.' ) ); ?>);">
                        Turn it off
                    </button>
                </form>
            <?php endif; ?>
        </p>

        <?php if ( $breaks ) : ?>
            <p class="description" style="max-width:760px;color:#92400e;">
                <strong>Rotating stops every link already sent.</strong> If a tester is mid-way
                through, they will need the new one.
            </p>
        <?php endif; ?>

        <script>
        (function () {
            var b = document.getElementById('lgms-tester-copy'),
                i = document.getElementById('lgms-tester-url');
            if (!b || !i) { return; }
            b.addEventListener('click', function () {
                var done = function () { b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy'; }, 1500); };
                /* navigator.clipboard is unavailable on insecure origins and in
                   some embedded admin views; select()+execCommand still works
                   there, and the input is readonly-but-selectable either way, so
                   the link is never trapped on screen. */
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(i.value).then(done, function () { i.select(); document.execCommand('copy'); done(); });
                } else {
                    i.select(); document.execCommand('copy'); done();
                }
            });
        })();
        </script>

        <hr style="margin:2em 0;">
        <?php
    }
}
