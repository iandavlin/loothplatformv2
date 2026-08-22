<?php

declare(strict_types=1);

namespace LGMS;

use LGMS\Membership\Health;
use Throwable;

/**
 * SharedSecretPanel — the shared secret's status, and the Refresh button.
 * Issue #201.
 *
 * Ian reshaped the issue himself, 2026-08-22, verbatim: *"Should just be a
 * refresh button or something with a status check."* — which superseded a
 * paste-in field the first draft had proposed. This is that: a status check and
 * a button, and deliberately no way to type a value.
 *
 * ⚠️ WHY THIS SECRET GETS A SECTION OF ITS OWN. It is what authenticates the
 * billing app's server-to-server calls into WordPress, so when it is wrong
 * every other answer on the Health tab is answering about a channel that
 * cannot carry anything. It is ABSENT ON LIVE today, and #181's checkout guard
 * is fail-open by design — a route that cannot answer produces UNKNOWN, and
 * UNKNOWN waves every checkout through. The guard reads as armed on the dash
 * and refuses nobody. That silence is what this section exists to end.
 *
 * ---------------------------------------------------------------------------
 * FOUR DECISIONS THAT LOOK LIKE DETAIL AND ARE NOT
 * ---------------------------------------------------------------------------
 *
 * ⚠️ (1) ONE RENDERER SERVES BOTH THE PAGE LOAD AND THE REFRESH.
 * `renderBody()` is called by `render()` on a normal page load and by
 * `handleStatus()` over AJAX, and the refresh ships that same server-rendered
 * markup rather than JSON a script re-renders. Two renderers would be two
 * places a secret could leak and two things to keep gated; one is asserted
 * once and covers both paths. Gate 98 §E asserts it through PHP's tokenizer.
 *
 * ⚠️ (2) A REFRESH THAT CAN RETURN A CACHED ANSWER IS A LIE.
 * The whole promise of the button is "this is true right now". Measured on dev2
 * 2026-08-22: the box runs a persistent object cache
 * (`wp-content/object-cache.php`, 105,926 bytes) and `lgms_shared_secret` is an
 * autoloaded option, so it is served out of the `alloptions` blob. WordPress's
 * own `update_option` does invalidate that blob — so a round trip is PROBABLY
 * fresh, and "probably" is precisely the word this panel exists to remove. See
 * `refreshRead()`.
 *
 * ⚠️ (3) THE ERROR PATH IS WHERE A SECRET ESCAPES, NOT THE HAPPY PATH.
 * A `Throwable` from a file read or a PDO handle can carry a value in its
 * message — a DSN, a config line, an argument. So the handler answers with a
 * FIXED sentence and never `$e->getMessage()`. Gate 98 §C3 proves it by
 * throwing an exception whose message CONTAINS the secret and requiring that
 * none of it reaches the response.
 *
 * ⚠️ (4) NO INPUT. NOT ONE, ANYWHERE IN THIS SECTION.
 * Ian's shape, and gate 98 §D asserts a count of ZERO `<input>` elements rather
 * than the absence of some particular name — an assertion that cannot be
 * argued with, and cannot be fudged by renaming a field. Setting the secret is
 * a command-line act on both halves, the section says so on screen, and #201
 * retired the Settings tab's field (which set only the WordPress half AND
 * printed the live value into that page's HTML source) in the same commit.
 *
 * ⚠️ IT IS ITS OWN CLASS FOR THE REASON HealthPanel, TesterUnlockPanel AND
 * ProductsPanel ARE (#190, #192, #194): so a gate can drive it — and its
 * handler — without loading `Admin.php`, whose neighbouring test files have
 * died at exit 255 with NO FAIL LINE three separate times because that door
 * gained a dependency nobody added to a require list.
 *
 * @see \LGMS\Membership\Health::sharedSecret()  every decision; this prints
 */
final class SharedSecretPanel
{
    /** The AJAX action, and the nonce that guards it. */
    public const ACTION = 'lgms_shared_secret_status';
    public const NONCE  = 'lgms_shared_secret_status';

    /**
     * The one sentence an error path may say.
     *
     * ⚠️ FIXED, AND NEVER `$e->getMessage()`. See decision (3) above: an
     * exception raised while reading a settings file or opening a database
     * handle can carry a value in its message, and an error path is the one
     * place nobody is looking when a secret escapes.
     */
    public const ERROR_SENTENCE = 'The check could not be run. Nothing was changed. Reload the page and try again.';

    /** What a caller without the capability is told. */
    public const DENIED_SENTENCE = 'You do not have permission to run this check.';

    public static function boot(): void
    {
        /* NO `nopriv` TWIN, deliberately: an unauthenticated caller gets
           admin-ajax's own 0 and never reaches this class at all. */
        add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'handleStatus' ] );
    }

    // =========================================================================
    // The read
    // =========================================================================

    /**
     * Re-ask, for real.
     *
     * `Health::reset()` drops the memoised parse of the billing app's settings
     * file, and the two cache deletes drop WordPress's cached copy of the
     * option. Both are needed and neither is superstition:
     *
     *   - the option is AUTOLOADED, so it is served out of the `alloptions`
     *     blob rather than fetched by name — deleting only the single key would
     *     leave the blob answering;
     *   - the person pressing this button has, in the case it exists for, just
     *     run `wp option update` in another process on a box with a persistent
     *     object cache.
     *
     * This writes nothing. Dropping a cache entry costs the next request one
     * query and is the price of the word "refresh" meaning what it says.
     */
    public static function refreshRead(): array
    {
        Health::reset();
        wp_cache_delete( 'lgms_shared_secret', 'options' );
        wp_cache_delete( 'alloptions', 'options' );

        return Health::sharedSecret();
    }

    // =========================================================================
    // The AJAX door
    // =========================================================================

    /**
     * BOTH LOCKS, NEVER ONE. The capability AND the nonce.
     *
     * The capability is what stops a subscriber; the nonce is what stops a page
     * on another origin driving an admin's browser into pressing it. Neither is
     * decoration here — this reads a secret's fingerprint out of two stores.
     */
    public static function handleStatus(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => self::DENIED_SENTENCE ], 403 );
            return;
        }

        check_ajax_referer( self::NONCE );

        $level = ob_get_level();
        try {
            ob_start();
            self::renderBody( self::refreshRead() );
            $html = (string) ob_get_clean();
        } catch ( Throwable $e ) {
            while ( ob_get_level() > $level ) {
                ob_end_clean();
            }
            /* The message is DISCARDED, not shortened and not logged to the
               screen. See ERROR_SENTENCE. */
            wp_send_json_error( [ 'message' => self::ERROR_SENTENCE ], 500 );
            return;
        }

        wp_send_json_success( [ 'html' => $html ] );
    }

    // =========================================================================
    // The screen
    // =========================================================================

    /**
     * The whole section: styles, shell, body, button, script.
     *
     * @param array|null $status the already-taken reading (HealthPanel passes
     *                           the one from `Health::describe()` so the whole
     *                           screen carries a single timestamp); null takes
     *                           its own.
     */
    public static function render( ?array $status = null ): void
    {
        $nonce = wp_create_nonce( self::NONCE );
        ?>
        <style>
            .lgms-ss { background:#fff; border:1px solid #c3c4c7; border-left-width:4px; padding:1em 1.2em; margin:0 0 1.4em; max-width:900px; }
            .lgms-ss.s-ok      { border-left-color:#15803d; }
            .lgms-ss.s-warn    { border-left-color:#d97706; }
            .lgms-ss.s-fail    { border-left-color:#b91c1c; }
            .lgms-ss.s-unknown { border-left-color:#4338ca; }
            .lgms-ss h3 { margin:0 0 .35em; font-size:1.05em; }
            .lgms-ss-head { display:flex; align-items:center; gap:.6em; flex-wrap:wrap; margin:0 0 .6em; }
            .lgms-ss-sum { margin:.2em 0 .9em; }
            .lgms-ss-tbl { width:100%; border-collapse:collapse; font-size:.92em; }
            .lgms-ss-tbl td { padding:.3em .5em .3em 0; vertical-align:top; border-bottom:1px solid #f0f0f1; }
            .lgms-ss-tbl td:first-child { width:30%; color:#50575e; }
            .lgms-ss-stamp { color:#646970; font-size:.86em; }
            .lgms-ss-note { margin:.9em 0 0; font-size:.88em; color:#646970; }
            .lgms-ss-cmd { display:block; background:#f6f7f7; border:1px solid #dcdcde; padding:.5em .7em; margin:.35em 0 .1em; font-family:Menlo,Consolas,monospace; font-size:12.5px; overflow-x:auto; white-space:pre; }
            .lgms-ss-cmdrow { display:flex; align-items:flex-start; gap:.5em; }
            .lgms-ss-cmdrow > .lgms-ss-cmd { flex:1; min-width:0; }
            /* ⚠️ THE CHIP PALETTE IS REPEATED HERE ON PURPOSE. It is HealthPanel's,
               and sharing the class names is deliberate — one visual language on one
               tab. But depending on a SIBLING to emit the rules makes this section
               render as unstyled grey text anywhere else, which is exactly what the
               first picture built for Ian showed: every verdict, MATCH and BROKEN
               alike, in identical plain text. Identical declarations, so the two
               copies cannot disagree; the section is now self-contained. */
            .lgms-ss .lgms-h-chip { display:inline-block; padding:.15em .6em; border-radius:3px; font-size:.85em; font-weight:600; }
            .lgms-ss .lgms-h-ok      { background:#dcfce7; color:#15803d; }
            .lgms-ss .lgms-h-warn    { background:#fef3c7; color:#92400e; }
            .lgms-ss .lgms-h-fail    { background:#fee2e2; color:#b91c1c; }
            .lgms-ss .lgms-h-unknown { background:#e0e7ff; color:#3730a3; }
            .lgms-ss .lgms-h-neutral { background:#f0f0f1; color:#555; }
        </style>

        <div id="lgms-ss-root">
            <?php self::renderBody( $status ); ?>
        </div>

        <script>
        (function () {
            var root = document.getElementById('lgms-ss-root');
            if (!root) { return; }
            root.addEventListener('click', function (ev) {
                var btn = ev.target.closest ? ev.target.closest('[data-lgms-ss]') : null;
                if (!btn || !root.contains(btn)) { return; }
                ev.preventDefault();
                var what = btn.getAttribute('data-lgms-ss');

                if (what === 'copy') {
                    var pre = document.getElementById(btn.getAttribute('data-target'));
                    if (!pre) { return; }
                    var text = pre.textContent, label = btn.textContent;
                    var done = function () { btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = label; }, 1400); };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(done, function () {});
                    }
                    return;
                }

                if (what !== 'refresh') { return; }
                btn.disabled = true;
                var was = btn.textContent;
                btn.textContent = 'Checking…';
                var body = new URLSearchParams();
                body.append('action', <?php echo wp_json_encode( self::ACTION ); ?>);
                body.append('_ajax_nonce', <?php echo wp_json_encode( $nonce ); ?>);
                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString()
                }).then(function (r) { return r.json(); }).then(function (j) {
                    if (j && j.success && j.data && typeof j.data.html === 'string') {
                        root.innerHTML = j.data.html;
                        return;
                    }
                    /* Whatever came back, the reader is told the check did not
                       run — never the raw response. */
                    throw new Error('refused');
                }).catch(function () {
                    btn.disabled = false;
                    btn.textContent = was;
                    var n = root.querySelector('.lgms-ss-err');
                    if (n) { n.hidden = false; }
                });
            });
        }());
        </script>
        <?php
    }

    /**
     * THE ONE RENDERER — page load and refresh both land here. See decision (1).
     *
     * It cannot print a secret even carelessly, and that is a property of what
     * it is handed rather than care taken here: `Health::secretPair()` reduces
     * both halves to present/absent, a length and a verdict before either value
     * leaves that class, so there is no value, no fingerprint and no prefix in
     * this scope to leak.
     */
    public static function renderBody( ?array $status = null ): void
    {
        $s = $status ?? Health::sharedSecret();

        $envPath = (string) ( $s['env']['path'] ?? '' );
        $wpPath  = defined( 'ABSPATH' ) ? rtrim( (string) ABSPATH, '/' ) : '';
        $wpCmd   = 'wp' . ( $wpPath !== '' ? ' --path=' . $wpPath : '' )
                 . " option update lgms_shared_secret '<the-new-value>'";

        /* ⚠️ BUILT AS A STRING, NOT AS TWO LINES OF INLINE HTML. PHP swallows one
           newline directly after `?>`, so a closing tag at the end of the first
           line silently joins the two — the first picture showed
           `.env# set the LGMS_SHARED_SECRET= line ...` running off the edge as a
           single line.

           ⚠️ AND IT DOES NOT TELL ANYONE TO RESTART ANYTHING. Checked rather than
           assumed: `LGSB\App::create()` calls `Dotenv::createImmutable(...)->load()`
           on EVERY request, so the billing app re-reads this file as it stands. A
           reload instruction here would be a real action taken for no reason. */
        $appCmd  = 'sudoedit ' . $envPath . "\n"
                 . '# set LGMS_SHARED_SECRET= to the SAME value. The app re-reads this'
                 . " file on every request — nothing to restart.";
        ?>
        <div class="lgms-ss s-<?php echo esc_attr( (string) $s['status'] ); ?>">
            <div class="lgms-ss-head">
                <h3 style="margin:0;">Shared secret</h3>
                <span class="lgms-h-chip <?php echo esc_attr( self::cls( (string) $s['status'] ) ); ?>">
                    <?php echo esc_html( (string) $s['headline'] ); ?>
                </span>
                <span style="flex:1 1 auto;"></span>
                <button type="button" class="button" data-lgms-ss="refresh">Refresh</button>
                <span class="lgms-ss-stamp">Checked at <?php echo esc_html( (string) $s['checked_at'] ); ?></span>
            </div>

            <p class="lgms-ss-sum"><?php echo esc_html( (string) $s['summary'] ); ?></p>

            <div class="notice notice-error lgms-ss-err" hidden style="margin:0 0 .9em;">
                <p><?php echo esc_html( self::ERROR_SENTENCE ); ?></p>
            </div>

            <table class="lgms-ss-tbl">
                <?php foreach ( (array) $s['lines'] as $l ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $l['label'] ); ?></td>
                        <td>
                            <span class="lgms-h-chip <?php echo esc_attr( self::cls( (string) $l['status'] ) ); ?>">
                                <?php echo esc_html( (string) $l['value'] ); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <?php
            /* THE FOUR ENV STATES ARE NAMED, NEVER COLLAPSED. "There is no file"
               and "this user may not read the file" send whoever reads this to
               different boxes and different fixes; #192's panel learned it and
               this section inherits it rather than re-deciding it. */
            if ( ( $s['env']['state'] ?? 'ok' ) !== 'ok' ) : ?>
                <p class="lgms-ss-note">
                    The billing app's half could not be read from
                    <code><?php echo esc_html( $envPath ); ?></code><?php
                    if ( ( $s['env']['reason'] ?? '' ) !== '' ) :
                        ?> — <?php echo esc_html( (string) $s['env']['reason'] );
                    endif; ?>.
                    <?php if ( ( $s['env']['state'] ?? '' ) === 'unreadable' ) : ?>
                        That is a permissions job for root, not a settings change: the file belongs to
                        the billing app's user and WordPress runs as its own.
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php
            /* ⚠️ SAYING IT IS THE POINT, not politeness. #201: setting this is a
               command-line act on BOTH halves and there is no field for it
               anywhere in this dash any more. Without this block the screen
               reports a problem and leaves the reader hunting a control that
               was deliberately removed. */
            ?>
            <p class="lgms-ss-note" style="color:#3c434a;">
                <strong>Setting this is a command-line act, on purpose — there is no field for it here or
                on any other tab.</strong>
                The billing app's half lives in a server file that the web user cannot write, so a form on
                this screen could only ever move <em>one</em> of the two halves — which is how a pair ends up
                reading <em>DIFFER</em> with nobody having meant it. Both halves take the same value:
            </p>

            <div class="lgms-ss-cmdrow">
                <code class="lgms-ss-cmd" id="lgms-ss-cmd-wp"><?php echo esc_html( $wpCmd ); ?></code>
                <button type="button" class="button" data-lgms-ss="copy" data-target="lgms-ss-cmd-wp">Copy</button>
            </div>
            <div class="lgms-ss-cmdrow">
                <code class="lgms-ss-cmd" id="lgms-ss-cmd-app"><?php echo esc_html( $appCmd ); ?></code>
                <button type="button" class="button" data-lgms-ss="copy" data-target="lgms-ss-cmd-app">Copy</button>
            </div>

            <p class="lgms-ss-note">
                This screen never shows, stores or sets the value — only whether each half is present,
                how long it is, and whether the two agree. The comparison is a sha256 comparison made in
                code and the fingerprint is never rendered either.
                <strong>Refresh</strong> re-reads both halves, bypassing WordPress's option cache, so a
                value set on the command line a moment ago shows up without reloading the page.
            </p>
        </div>
        <?php
    }

    /** Shares HealthPanel's chip palette — one visual language on one tab. */
    private static function cls( string $status ): string
    {
        return 'lgms-h-' . ( in_array( $status, [ 'ok', 'warn', 'fail', 'unknown' ], true ) ? $status : 'neutral' );
    }
}
