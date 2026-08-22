<?php

declare(strict_types=1);

namespace LGMS;

use LGMS\Membership\Health;

/**
 * HealthPanel — the Health tab. Issue #192.
 *
 * IT IS ITS OWN CLASS FOR THE REASON TesterUnlockPanel IS (#190): so a gate can
 * render it without loading Admin.php. That file reaches StripeLifecycle,
 * StripePrice, Invites, CompExpiry and more, and its neighbouring test file has
 * died at exit 255 with NO FAIL LINE three separate times because the door
 * gained a dependency nobody added to a require list. A panel that can only be
 * exercised by loading all of that is a panel that will eventually stop being
 * exercised.
 *
 * ⚠️ IT RENDERS ONLY. Every decision, every measurement and every verdict lives
 * in Membership\Health. This class chooses colours and words, which is why it
 * can be pointed at a deliberately broken fixture and asserted on cheaply.
 *
 * ⚠️ IT CANNOT PRINT A SECRET EVEN BY MISTAKE, and that is a property of the
 * data rather than care taken here: Health reduces every secret-shaped value to
 * present / length / sha256 at the moment it parses the file, and hands this
 * class no value, no fingerprint and no prefix. Gate 91 asserts that by
 * rendering with real-looking secrets in the fixture and requiring that none of
 * them, and no sha256 of them, appears anywhere in the markup.
 *
 * ⚠️ NO BUTTON ON THIS SCREEN CHANGES ANYTHING. Ian's ruling on #192: server
 * file settings are READ-ONLY WITH A COPY BUTTON. Payment keys behind a web
 * form is a risk class he did not choose, and a dash that can rewrite the
 * billing app's environment is a much larger surface than a dash that can
 * describe it.
 */
final class HealthPanel
{
    public static function render(): void
    {
        $h      = Health::describe();
        $checks = $h['checks'];
        $shared = $h['shared_secret'];
        /* #201: the shared-secret section is folded into the headline rather
           than sitting beside it. A tab whose chip says everything is healthy
           while its first card says DIFFER is the "a blank cell reads like
           health" failure wearing a different hat — and this is the one channel
           whose failure makes every other answer on the screen meaningless. */
        $worst  = Health::worst( array_merge( $checks, [ $shared ] ) );
        $env    = $h['app_env'];
        ?>
        <style>
            .lgms-h-chip { display:inline-block; padding:.15em .6em; border-radius:3px; font-size:.85em; font-weight:600; }
            .lgms-h-ok      { background:#dcfce7; color:#15803d; }
            .lgms-h-warn    { background:#fef3c7; color:#92400e; }
            .lgms-h-fail    { background:#fee2e2; color:#b91c1c; }
            .lgms-h-unknown { background:#e0e7ff; color:#3730a3; }
            .lgms-h-neutral { background:#f0f0f1; color:#555; }
            .lgms-h-card { background:#fff; border:1px solid #c3c4c7; border-left-width:4px; padding:1em 1.2em; margin:0 0 1em; max-width:900px; }
            .lgms-h-card.s-ok      { border-left-color:#15803d; }
            .lgms-h-card.s-warn    { border-left-color:#d97706; }
            .lgms-h-card.s-fail    { border-left-color:#b91c1c; }
            .lgms-h-card.s-unknown { border-left-color:#4338ca; }
            .lgms-h-card h3 { margin:0 0 .35em; font-size:1.05em; }
            .lgms-h-sum { margin:.2em 0 .8em; font-size:1em; }
            .lgms-h-tbl { width:100%; border-collapse:collapse; font-size:.92em; }
            .lgms-h-tbl td { padding:.28em .5em .28em 0; vertical-align:top; border-bottom:1px solid #f0f0f1; }
            .lgms-h-tbl td:first-child { width:42%; color:#50575e; }
            .lgms-h-note { margin:.8em 0 0; font-size:.88em; color:#646970; }
        </style>

        <h2 style="margin-top:0;">Health</h2>

        <p class="description" style="max-width:860px;">
            The questions that, on 2026-08-21, each cost about an hour to answer by hand —
            and none of which announced itself. Every failure that day was a <strong>silence</strong>:
            nothing went red, nothing logged an error, and the site kept serving pages.
            <strong>Nothing on this screen changes anything.</strong>
        </p>

        <p>
            <?php
            printf(
                '<span class="lgms-h-chip %s">%s</span>',
                esc_attr( self::cls( $worst ) ),
                esc_html( self::headline( $worst ) )
            );
            ?>
        </p>

        <?php if ( $env['state'] !== 'ok' ) : ?>
            <?php
            /* THE HONEST DEGRADATION, AND THE WHOLE POINT OF THE LANE. Half the
               questions here compare a WordPress value against the billing
               app's settings file. When that file cannot be read, the panel
               must say which of the four states it is in — each needs a
               different fix — rather than rendering a reassuring blank.
               ⚠️ dev2 and live are NOT the same shape: dev2's /srv path is a
               symlink with a world-readable .env, live's is a real directory
               owned by www-data at mode 0640. */
            $words = match ( $env['state'] ) {
                'missing'    => 'There is no billing-app settings file at that path.',
                'unreadable' => 'The billing-app settings file exists, but WordPress may not read it.',
                'empty'      => 'The billing-app settings file was read and parsed to nothing at all.',
                default      => 'The billing-app settings file could not be used.',
            };
            ?>
            <div class="notice notice-warning" style="max-width:900px;">
                <p>
                    <strong><?php echo esc_html( $words ); ?></strong><br>
                    Path: <code><?php echo esc_html( $env['path'] ); ?></code>
                    <?php if ( $env['reason'] !== '' ) : ?>
                        — <?php echo esc_html( $env['reason'] ); ?>
                    <?php endif; ?>
                </p>
                <p>
                    Everything below that compares the two halves is reported as
                    <strong>cannot see it from here</strong> rather than guessed. That is deliberate:
                    a number nobody can trust is worse than a sentence saying we cannot see it.
                    <?php if ( $env['state'] === 'unreadable' ) : ?>
                        This is a permissions job for root, not a settings change —
                        the file is owned by the billing app's user and WordPress runs as its own.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <?php
        /* FIRST CARD ON THE TAB, and #201's whole subject. It authenticates the
           billing app's calls into WordPress, so when it is wrong every other
           answer below is answering about a channel that cannot carry anything.
           It is ABSENT ON LIVE today. */
        SharedSecretPanel::render( $shared );
        ?>

        <?php foreach ( $checks as $c ) : ?>
            <div class="lgms-h-card s-<?php echo esc_attr( $c['status'] ); ?>">
                <h3>
                    <?php echo esc_html( $c['title'] ); ?>
                    <span class="lgms-h-chip <?php echo esc_attr( self::cls( $c['status'] ) ); ?>" style="margin-left:.4em;">
                        <?php echo esc_html( self::word( $c['status'] ) ); ?>
                    </span>
                </h3>
                <p class="lgms-h-sum"><?php echo esc_html( $c['summary'] ); ?></p>
                <table class="lgms-h-tbl">
                    <?php foreach ( $c['lines'] as $l ) : ?>
                        <tr>
                            <td><?php echo esc_html( $l['label'] ); ?></td>
                            <td>
                                <span class="lgms-h-chip <?php echo esc_attr( self::cls( $l['status'] ) ); ?>">
                                    <?php echo esc_html( $l['value'] ); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php if ( $c['note'] !== '' ) : ?>
                    <p class="lgms-h-note"><?php echo wp_kses_post( self::emphasise( $c['note'] ) ); ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php
        /* READ-ONLY WITH A COPY BUTTON — Ian's ruling. The one thing worth
           copying off this screen is the path to the file WordPress cannot
           edit, so that whoever fixes it is fixing the right file on the right
           box. No value from inside it is ever offered. */
        ?>
        <div class="lgms-h-card s-unknown" style="border-left-color:#8c8f94;">
            <h3>The billing app's settings file</h3>
            <p class="lgms-h-sum">
                Read-only from here, deliberately. Payment keys behind a web form is a risk
                that was not chosen — this screen reads that file and never writes it.
            </p>
            <p style="display:flex;gap:.5em;align-items:center;max-width:640px;">
                <input type="text" id="lgms-health-envpath" readonly
                       style="flex:1;font-family:monospace;font-size:12.5px;"
                       value="<?php echo esc_attr( $env['path'] ); ?>"
                       onclick="this.select()">
                <button type="button" class="button" id="lgms-health-copy">Copy path</button>
            </p>
            <p class="lgms-h-note">
                The <strong>shared secret</strong> is set on the command line — both halves; the section
                at the top of this tab carries the two lines.
                The cohort is edited on the <strong>Testers</strong> tab.
            </p>
        </div>

        <script>
        (function () {
            var b = document.getElementById('lgms-health-copy');
            var f = document.getElementById('lgms-health-envpath');
            if (!b || !f) { return; }
            b.addEventListener('click', function () {
                f.select();
                var done = function () { b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy path'; }, 1400); };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(f.value).then(done, function () { try { document.execCommand('copy'); done(); } catch (e) {} });
                } else {
                    try { document.execCommand('copy'); done(); } catch (e) {}
                }
            });
        }());
        </script>
        <?php
    }

    private static function cls( string $status ): string
    {
        return 'lgms-h-' . ( in_array( $status, [ 'ok', 'warn', 'fail', 'unknown' ], true ) ? $status : 'neutral' );
    }

    /** The word in the chip. `unknown` says what it means, not what it is called. */
    private static function word( string $status ): string
    {
        return match ( $status ) {
            'ok'      => 'OK',
            'warn'    => 'NEEDS A LOOK',
            'fail'    => 'BROKEN',
            'unknown' => 'CANNOT SEE',
            default   => '',
        };
    }

    private static function headline( string $worst ): string
    {
        return match ( $worst ) {
            'ok'      => 'Every answer on this screen is healthy',
            'warn'    => 'Healthy, with something worth a look',
            'unknown' => 'Something cannot be seen from here',
            default   => 'Something is broken',
        };
    }

    /** Markdown-lite for the notes: **bold** and `code`, nothing else. */
    private static function emphasise( string $s ): string
    {
        $s = esc_html( $s );
        $s = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s );
        return (string) preg_replace( '/`(.+?)`/', '<code>$1</code>', $s );
    }
}
