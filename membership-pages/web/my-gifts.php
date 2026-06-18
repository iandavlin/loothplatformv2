<?php
/**
 * /my-gifts/ — standalone port of [lg_my_gifts] (gift-buyer dashboard).
 *
 * VERBATIM body port of Shortcodes::myGifts() (src/Wp/Shortcodes.php:5252).
 * Self-styled (inline <style>), so no extra CSS link needed. Server helpers
 * swapped:
 *   - wp_get_current_user()  → wordpress_logged_in_* cookie → wp_users lookup
 *   - Db::pdo() gift_codes   → same SQL vs the poller DB (lg_membership)
 *   - self::shortDate()      → lg_ms_short_date()
 *   - rest_url + wp_create_nonce('wp_rest') → lg_ms_home() + the nonce bridge
 *     (lg_membership_rest_nonce(); deployed GET /wp-json/looth/v1/rest-nonce)
 *   - esc_* → lg_membership_h
 *
 * Mutations POST to /wp-json/lg-member-sync/v1/me/gift-{send,resend,reassign,void}
 * with X-WP-Nonce — JS copied verbatim.
 *
 * GIFT-CAP NOTE: the original gates on user_can(GIFT_CAP='manage_gift_codes').
 * Admin-only pre-launch the router's manage_options gate already covers this
 * (admins hold the cap). When this flips to member-visible at go-live, add a
 * manage_gift_codes signal to whoami capabilities (or a usermeta read) here.
 *
 * Admin-only pre-launch (router enforces; self-gate is defense-in-depth).
 */
declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/whoami.php';
require '/srv/lg-shared/site-header.php';
require '/srv/lg-shared/site-footer.php';
require __DIR__ . '/_admin-gate.php';

$h   = 'lg_membership_h';
$ctx = lg_membership_header_ctx('');
lg_membership_prelaunch_gate_or_exit($ctx);

if (!function_exists('lg_ms_home')) {
    function lg_ms_home(string $p = ''): string { return 'https://' . LG_MEMBERSHIP_HOST . $p; }
}
if (!function_exists('lg_ms_short_date')) {
    function lg_ms_short_date(?string $datetime): string {
        $ts = $datetime ? strtotime($datetime) : false;
        return $ts ? gmdate('M j, Y', $ts) : 'unknown date';
    }
}

/* ---- resolve the logged-in user (email) via the WP DB ---- */
$email = '';
foreach ($_COOKIE as $ck => $cv) {
    if (strpos($ck, 'wordpress_logged_in_') === 0) {
        $parts = explode('|', urldecode((string) $cv), 4);
        if (!empty($parts[0])) {
            try {
                $st = lg_membership_db()->prepare("SELECT user_email FROM " . LG_MEMBERSHIP_TABLE_PREFIX . "users WHERE user_login = ? LIMIT 1");
                $st->execute([$parts[0]]);
                $email = (string) ($st->fetchColumn() ?: '');
            } catch (Throwable $e) {}
        }
        break;
    }
}

$pageWrap = function (string $inner) use ($ctx, $h): void {
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Gifts — The Looth Group</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
</head>
<body class="lg-membership-page lg-mygifts-page">
<?php lg_shared_render_site_header($ctx); ?>
<main id="lg-main">
<?= $inner ?>
</main>
<?php lg_shared_render_site_footer(['logo_url' => LG_MEMBERSHIP_LOGO]); ?>
</body>
</html>
<?php
};

if ($email === '') {
    $pageWrap('<div class="lg-mygifts lg-mygifts--noemail"><p><em>Your account is missing an email address — please contact support.</em></p></div>');
    return;
}

/* ?for= sanity check against the session. */
$expectedEmail = isset($_GET['for']) ? trim((string) rawurldecode((string) $_GET['for'])) : '';
if ($expectedEmail !== '' && strtolower($expectedEmail) !== strtolower($email)) {
    $logoutUrl = $h($ctx['logout_url'] ?? lg_ms_home('/wp-login.php?action=logout'));
    $pageWrap(
        '<div class="lg-mygifts lg-mygifts--oops" style="max-width:640px;margin:1em auto;padding:1.2em 1.4em;background:#fff3f0;border:1px solid #d97757;border-radius:8px;color:#1f1d1a;line-height:1.5;">'
        . '<p style="margin:0 0 .6em;font-size:1.05em;"><strong>Oops &mdash; you&rsquo;re signed in as the wrong account.</strong></p>'
        . '<p style="margin:0 0 .9em;">This gift dashboard belongs to <strong>' . $h($expectedEmail) . '</strong>, but you&rsquo;re currently signed in as <strong>' . $h($email) . '</strong>.</p>'
        . '<a href="' . $logoutUrl . '" style="display:inline-block;padding:.5em 1em;background:#1f1d1a;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:.92em;">Sign out and try again</a>'
        . '</div>'
    );
    return;
}

/* Gate the actionable dashboard on a VALID WP SESSION (the nonce), not on the
 * cookie-username string above. lg_membership_rest_nonce() only mints for a live
 * session; '' = stale/rotated session (or a Patreon identity with no WP auth
 * cookie). Without this the page renders Send/Void/Reassign buttons that POST an
 * empty X-WP-Nonce and silently 401. Show the re-auth state instead. */
$restNonce = lg_membership_rest_nonce();
if ($restNonce === '') {
    $pageWrap(lg_membership_session_expired_html());
    return;
}

/* Load gift codes for this buyer from the poller DB. */
try {
    $pdo  = lg_membership_poller_db();
    $stmt = $pdo->prepare(
        'SELECT g.id, g.code, g.tier, g.duration_days,
                g.recipient_email, g.recipient_name, g.gift_message,
                g.email_sent_at, g.redeemed_at, g.voided_at, g.created_at,
                rc.email AS redeemer_email
         FROM gift_codes g
         JOIN customers c ON c.id = g.purchased_by
         LEFT JOIN customers rc ON rc.id = g.redeemed_by
         WHERE c.email = ?
         ORDER BY g.id DESC'
    );
    $stmt->execute([$email]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('LGMS [lg_my_gifts]: DB error: ' . $e->getMessage());
    $pageWrap('<div class="lg-mygifts lg-mygifts--err"><p><em>Couldn\'t load your gift codes right now. Please try again in a moment.</em></p></div>');
    return;
}

$unsent = []; $sent = []; $redeemed = []; $voided = [];
foreach ($rows as $r) {
    if (!empty($r['voided_at']))           { $voided[]   = $r; }
    elseif (!empty($r['redeemed_at']))     { $redeemed[] = $r; }
    elseif (!empty($r['recipient_email'])) { $sent[]     = $r; }
    else                                   { $unsent[]   = $r; }
}

$totalActive = count($unsent) + count($sent) + count($redeemed);
$tierLabel   = static fn (string $t): string => match ($t) {
    'looth2' => 'Looth LITE',
    'looth3' => 'Looth PRO',
    default  => 'Looth',
};

$jsConfig = json_encode([
    'nonce'    => $restNonce,                      // gated non-empty above
    'restBase' => lg_ms_home('/wp-json/lg-member-sync/v1'),
]);

$urlBuyMore = $h(lg_ms_home('/lggift-buy/'));

ob_start();
?>
        <div class="lg-mygifts" id="lg-mygifts-root">
            <header class="lg-mygifts__hero">
                <h2 class="lg-mygifts__heading">My Gifts</h2>
                <?php if ( $totalActive === 0 && count( $voided ) === 0 ) : ?>
                    <p class="lg-mygifts__intro">You haven't purchased any gift codes yet. <a href="<?php echo $urlBuyMore; ?>">Buy gift codes →</a></p>
                <?php else : ?>
                    <p class="lg-mygifts__intro">
                        <?php printf( $h( '%d total · %d to send · %d sent · %d redeemed' ), $totalActive, count( $unsent ), count( $sent ), count( $redeemed ) ); ?>
                        <a class="lg-mygifts__buy-more" href="<?php echo $urlBuyMore; ?>">Buy more →</a>
                    </p>
                <?php endif; ?>
            </header>

            <div class="lg-mygifts__toast" id="lg-gift-toast" aria-live="polite" hidden></div>

            <?php if ( $unsent !== [] ) : ?>
            <section class="lg-mygifts__section lg-mygifts__section--unsent" id="lg-section-unsent">
                <div class="lg-mygifts__section-header">
                    <h3>Ready to send <span class="lg-mygifts__count" id="lg-unsent-count">(<?php echo count( $unsent ); ?>)</span></h3>
                    <?php if ( count( $unsent ) > 1 ) : ?>
                    <div class="lg-batch-controls" id="lg-batch-controls">
                        <button type="button" class="lg-btn lg-btn--sm" id="lg-batch-toggle">Select for batch send</button>
                        <button type="button" class="lg-btn lg-btn--primary lg-btn--sm" id="lg-batch-send-btn" hidden>
                            Send selected (<span id="lg-batch-count">0</span>)
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <p class="lg-mygifts__hint">Send each code to a recipient — or grab the code value and forward it yourself.</p>

                <div class="lg-batch-panel" id="lg-batch-panel" hidden>
                    <p class="lg-batch-panel__intro">Fill in recipients for the selected codes, then click <strong>Send all</strong>.</p>
                    <div id="lg-batch-rows"></div>
                    <div class="lg-batch-panel__shared">
                        <label>Optional message to include with every email<br>
                            <textarea class="lg-batch-panel__msg-area" id="lg-batch-msg" rows="2" placeholder="Optional message for all recipients…"></textarea>
                        </label>
                        <button type="button" class="lg-btn lg-btn--sm" id="lg-batch-apply-msg">Apply message to all</button>
                    </div>
                    <div class="lg-batch-panel__footer">
                        <button type="button" class="lg-btn lg-btn--primary" id="lg-batch-fire">Send all</button>
                        <button type="button" class="lg-btn" id="lg-batch-cancel">Cancel</button>
                        <span class="lg-batch-panel__progress" id="lg-batch-progress" hidden></span>
                    </div>
                </div>

                <table class="lg-mygifts__table" id="lg-table-unsent">
                    <thead><tr>
                        <th class="lg-col-check" id="lg-col-check-hdr" hidden><input type="checkbox" id="lg-select-all" title="Select all"></th>
                        <th>Tier</th><th>Code</th><th>Created</th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $unsent as $r ) : ?>
                        <tr class="lg-row" data-code-id="<?php echo (int) $r['id']; ?>">
                            <td class="lg-col-check" hidden>
                                <input type="checkbox" class="lg-row-check"
                                    data-code-id="<?php echo (int) $r['id']; ?>"
                                    data-tier-label="<?php echo $h( $tierLabel( (string) $r['tier'] ) ); ?>">
                            </td>
                            <td><?php echo $h( $tierLabel( (string) $r['tier'] ) ); ?></td>
                            <td><code class="lg-mygifts__code"><?php echo $h( (string) $r['code'] ); ?></code></td>
                            <td class="lg-mygifts__when"><?php echo $h( lg_ms_short_date( $r['created_at'] ) ); ?></td>
                            <td class="lg-mygifts__actions">
                                <button type="button" class="lg-btn lg-btn--action lg-action-open-send"
                                    data-code-id="<?php echo (int) $r['id']; ?>">Send →</button>
                                <button type="button" class="lg-btn lg-btn--danger-sm lg-action-void"
                                    data-code-id="<?php echo (int) $r['id']; ?>">Void</button>
                                <form class="lg-inline-form lg-send-form" data-code-id="<?php echo (int) $r['id']; ?>" hidden>
                                    <input type="email" class="lg-field" name="recipient_email" placeholder="Recipient email *" required>
                                    <input type="text"  class="lg-field" name="recipient_name"  placeholder="Recipient name (optional)">
                                    <textarea class="lg-field lg-field--msg" name="message" rows="2" placeholder="Optional personal message…"></textarea>
                                    <div class="lg-inline-form__btns">
                                        <button type="submit" class="lg-btn lg-btn--primary lg-btn--sm">Send gift</button>
                                        <button type="button" class="lg-btn lg-btn--sm lg-form-cancel">Cancel</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>

            <?php if ( $sent !== [] ) : ?>
            <section class="lg-mygifts__section lg-mygifts__section--sent" id="lg-section-sent">
                <h3>Sent — awaiting redemption <span class="lg-mygifts__count" id="lg-sent-count">(<?php echo count( $sent ); ?>)</span></h3>
                <table class="lg-mygifts__table" id="lg-table-sent">
                    <thead><tr><th>Tier</th><th>Recipient</th><th>Code</th><th>Sent</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ( $sent as $r ) :
                        $rEmail = (string) ( $r['recipient_email'] ?? '' );
                        $rName  = (string) ( $r['recipient_name']  ?? '' );
                    ?>
                        <tr class="lg-row" data-code-id="<?php echo (int) $r['id']; ?>">
                            <td><?php echo $h( $tierLabel( (string) $r['tier'] ) ); ?></td>
                            <td>
                                <?php if ( $rName !== '' ) : ?>
                                    <strong><?php echo $h( $rName ); ?></strong><br>
                                    <span class="lg-mygifts__sub"><?php echo $h( $rEmail ); ?></span>
                                <?php else : ?>
                                    <?php echo $h( $rEmail ); ?>
                                <?php endif; ?>
                            </td>
                            <td><code class="lg-mygifts__code lg-mygifts__code--small"><?php echo $h( (string) $r['code'] ); ?></code></td>
                            <td class="lg-mygifts__when"><?php echo $h( lg_ms_short_date( $r['email_sent_at'] ?? $r['created_at'] ) ); ?></td>
                            <td class="lg-mygifts__actions">
                                <button type="button" class="lg-btn lg-btn--action lg-action-resend"
                                    data-code-id="<?php echo (int) $r['id']; ?>">Resend</button>
                                <button type="button" class="lg-btn lg-btn--action lg-action-open-reassign"
                                    data-code-id="<?php echo (int) $r['id']; ?>">Reassign</button>
                                <button type="button" class="lg-btn lg-btn--danger-sm lg-action-void"
                                    data-code-id="<?php echo (int) $r['id']; ?>">Void</button>
                                <form class="lg-inline-form lg-reassign-form" data-code-id="<?php echo (int) $r['id']; ?>" hidden>
                                    <input type="email" class="lg-field" name="recipient_email"
                                        placeholder="New recipient email *"
                                        value="<?php echo $h( $rEmail ); ?>" required>
                                    <input type="text" class="lg-field" name="recipient_name"
                                        placeholder="New recipient name (optional)"
                                        value="<?php echo $h( $rName ); ?>">
                                    <textarea class="lg-field lg-field--msg" name="message" rows="2"
                                        placeholder="Optional personal message…"><?php echo $h( (string) ( $r['gift_message'] ?? '' ) ); ?></textarea>
                                    <div class="lg-inline-form__btns">
                                        <button type="submit" class="lg-btn lg-btn--primary lg-btn--sm">Reassign &amp; email</button>
                                        <button type="button" class="lg-btn lg-btn--sm lg-form-cancel">Cancel</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>

            <?php if ( $redeemed !== [] ) : ?>
            <section class="lg-mygifts__section lg-mygifts__section--redeemed">
                <h3>Redeemed <span class="lg-mygifts__count">(<?php echo count( $redeemed ); ?>)</span></h3>
                <table class="lg-mygifts__table">
                    <thead><tr><th>Tier</th><th>Recipient</th><th>Redeemed by</th><th>When</th></tr></thead>
                    <tbody>
                    <?php foreach ( $redeemed as $r ) :
                        $rName  = (string) ( $r['recipient_name']  ?? '' );
                        $rEmail = (string) ( $r['recipient_email'] ?? '' );
                        $by     = (string) ( $r['redeemer_email']  ?? '' );
                    ?>
                        <tr>
                            <td><?php echo $h( $tierLabel( (string) $r['tier'] ) ); ?></td>
                            <td><?php echo $h( $rName !== '' ? $rName : ( $rEmail !== '' ? $rEmail : '—' ) ); ?></td>
                            <td><?php echo $h( $by !== '' ? $by : '—' ); ?></td>
                            <td class="lg-mygifts__when"><?php echo $h( lg_ms_short_date( $r['redeemed_at'] ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>

            <?php if ( $voided !== [] ) : ?>
            <section class="lg-mygifts__section lg-mygifts__section--voided">
                <h3>Voided <span class="lg-mygifts__count">(<?php echo count( $voided ); ?>)</span></h3>
                <table class="lg-mygifts__table">
                    <thead><tr><th>Tier</th><th>Code</th><th>Voided</th></tr></thead>
                    <tbody>
                    <?php foreach ( $voided as $r ) : ?>
                        <tr>
                            <td><?php echo $h( $tierLabel( (string) $r['tier'] ) ); ?></td>
                            <td><code class="lg-mygifts__code lg-mygifts__code--small"><?php echo $h( (string) $r['code'] ); ?></code></td>
                            <td class="lg-mygifts__when"><?php echo $h( lg_ms_short_date( $r['voided_at'] ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>
        </div><!-- /lg-mygifts -->

        <style>
            .lg-mygifts { max-width: 920px; margin: 0 auto; padding: 1em 1.2em; }
            .lg-mygifts__hero { margin-bottom: 1.4em; }
            .lg-mygifts__heading { margin: 0 0 .25em; font-size: 1.6em; }
            .lg-mygifts__intro { margin: 0; color: rgba(0,0,0,0.6); display: flex; gap: 1em; align-items: baseline; flex-wrap: wrap; }
            .lg-mygifts__buy-more { color: var(--lg-sage, #87986A); text-decoration: none; }
            .lg-mygifts__buy-more:hover { text-decoration: underline; }

            .lg-mygifts__section { margin-top: 1.4em; padding: 1.1em 1.2em; border: 1px solid rgba(0,0,0,0.1); border-radius: 10px; background: #fff; }
            .lg-mygifts__section--unsent { background: rgba(236,179,81,0.05); border-color: rgba(236,179,81,0.3); }
            .lg-mygifts__section--redeemed { opacity: .85; }
            .lg-mygifts__section--voided { opacity: .7; }
            .lg-mygifts__section-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .5em; margin-bottom: .3em; }
            .lg-mygifts__section h3 { margin: 0; font-size: 1.05em; font-weight: 600; }
            .lg-mygifts__count { color: rgba(0,0,0,0.5); font-weight: 400; font-size: .9em; }
            .lg-mygifts__hint { margin: .3em 0 .9em; color: rgba(0,0,0,0.55); font-size: .9em; }
            .lg-mygifts__table { width: 100%; border-collapse: collapse; }
            .lg-mygifts__table th { text-align: left; padding: .5em .65em; font-size: .8em; text-transform: uppercase; letter-spacing: .04em; color: rgba(0,0,0,0.5); border-bottom: 1px solid rgba(0,0,0,0.08); font-weight: 600; }
            .lg-mygifts__table td { padding: .65em; border-bottom: 1px solid rgba(0,0,0,0.06); vertical-align: top; }
            .lg-mygifts__code { display: inline-block; padding: .25em .55em; background: rgba(0,0,0,0.04); border: 1px dashed rgba(0,0,0,0.15); border-radius: 4px; font: 14px ui-monospace, Menlo, Consolas, monospace; letter-spacing: .05em; }
            .lg-mygifts__code--small { font-size: 12px; padding: .15em .4em; }
            .lg-mygifts__when { color: rgba(0,0,0,0.55); font-size: .9em; white-space: nowrap; }
            .lg-mygifts__sub { color: rgba(0,0,0,0.55); font-size: .85em; }
            .lg-mygifts__actions { white-space: nowrap; }

            .lg-btn { display: inline-block; padding: .3em .75em; border-radius: 5px; border: 1px solid rgba(0,0,0,0.2); background: #fff; cursor: pointer; font-size: .85em; color: inherit; line-height: 1.4; transition: background .12s, border-color .12s; vertical-align: middle; font-family: inherit; }
            .lg-btn:hover:not(:disabled) { background: rgba(0,0,0,0.05); }
            .lg-btn--primary { background: var(--lg-amber, #ECB351); border-color: transparent; color: #1f1d1a; font-weight: 600; }
            .lg-btn--primary:hover:not(:disabled) { background: #d9a040; }
            .lg-btn--action { background: rgba(135,152,106,0.1); border-color: rgba(135,152,106,0.35); color: #4a5e2a; margin-right: .3em; }
            .lg-btn--action:hover:not(:disabled) { background: rgba(135,152,106,0.2); }
            .lg-btn--danger-sm { background: transparent; border-color: rgba(200,0,0,0.2); color: #a00; font-size: .78em; padding: .2em .5em; margin-left: .2em; }
            .lg-btn--danger-sm:hover:not(:disabled) { background: rgba(200,0,0,0.06); }
            .lg-btn--sm { font-size: .82em; padding: .25em .6em; }
            .lg-btn:disabled { opacity: .5; cursor: not-allowed; }

            .lg-inline-form { margin-top: .7em; padding: .75em; background: rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.1); border-radius: 6px; display: flex; flex-direction: column; gap: .45em; }
            .lg-field { width: 100%; padding: .4em .6em; border: 1px solid rgba(0,0,0,0.18); border-radius: 4px; font-size: .9em; color: inherit; background: #fff; box-sizing: border-box; font-family: inherit; }
            .lg-field--msg { resize: vertical; min-height: 56px; }
            .lg-inline-form__btns { display: flex; gap: .5em; }

            .lg-mygifts__toast { position: fixed; bottom: 1.5em; left: 50%; transform: translateX(-50%); background: #1f1d1a; color: #fff; padding: .65em 1.2em; border-radius: 6px; font-size: .9em; z-index: 9999; max-width: 420px; text-align: center; pointer-events: none; }
            .lg-mygifts__toast--error { background: #8b0000; }

            .lg-batch-controls { display: flex; gap: .5em; align-items: center; }
            .lg-batch-panel { margin: .75em 0; padding: 1em 1.1em; background: rgba(236,179,81,0.08); border: 1px solid rgba(236,179,81,0.4); border-radius: 8px; }
            .lg-batch-panel__intro { margin: 0 0 .7em; font-size: .9em; }
            .lg-batch-panel__shared { margin-top: .9em; font-size: .88em; }
            .lg-batch-panel__msg-area { width: 100%; margin-top: .3em; box-sizing: border-box; padding: .4em .6em; border: 1px solid rgba(0,0,0,0.18); border-radius: 4px; font-family: inherit; resize: vertical; }
            .lg-batch-panel__footer { display: flex; gap: .7em; align-items: center; margin-top: .9em; flex-wrap: wrap; }
            .lg-batch-panel__progress { font-size: .85em; color: rgba(0,0,0,0.6); }
            .lg-batch-row { margin-bottom: .7em; padding-bottom: .7em; border-bottom: 1px solid rgba(0,0,0,0.07); display: grid; grid-template-columns: 1fr 1fr; gap: .4em; }
            .lg-batch-row:last-child { border-bottom: none; }
            .lg-batch-row__label { grid-column: 1 / -1; font-size: .82em; font-weight: 600; color: rgba(0,0,0,0.55); }
            .lg-batch-row__msg { grid-column: 1 / -1; }

            .lg-col-check { width: 2em; text-align: center; }
            @media (max-width: 640px) {
                .lg-mygifts__table thead { display: none; }
                .lg-mygifts__table tr { display: block; padding: .65em 0; border-bottom: 1px solid rgba(0,0,0,0.08); }
                .lg-mygifts__table td { display: block; padding: .15em 0; border: none; }
                .lg-batch-row { grid-template-columns: 1fr; }
            }
        </style>

        <script>
        (function () {
            'use strict';
            var CFG = <?php echo $jsConfig; ?>;

            function apiCall(endpoint, payload) {
                return fetch(CFG.restBase + '/me/' + endpoint, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
                    body:    JSON.stringify(payload),
                }).then(function(r) {
                    return r.json().then(function(d) { d.__status = r.status; return d; });
                });
            }

            function sendGiftWithRecipientCheck(endpoint, payload) {
                return apiCall(endpoint, payload).then(function(d) {
                    if (!d || !d.needs_recipient_confirmation) return d;
                    var w = d.recipient_warning || {};
                    var msg;
                    if (w.kind === 'subscription') {
                        msg = payload.recipient_email + ' already has an active Looth subscription. ' +
                              'Sending a gift to this address will sit unredeemed unless they cancel their sub first ' +
                              '(redemption blocks if a sub is active). Send anyway?';
                    } else if (w.kind === 'gift') {
                        var days = parseInt(w.days_remaining, 10) || 0;
                        msg = payload.recipient_email + ' already has ' + days + ' day' + (days === 1 ? '' : 's') +
                              ' of an active Looth gift. Stacking a second gift just queues the time after the current one expires. Send anyway?';
                    } else {
                        msg = payload.recipient_email + ' already has a Looth account. Send the gift anyway?';
                    }
                    if (!window.confirm(msg)) {
                        return { ok: false, error: 'Cancelled — gift not sent.', __cancelled: true };
                    }
                    payload.acknowledged_recipient_warning = true;
                    return apiCall(endpoint, payload);
                });
            }

            var toastTimer;
            function toast(msg, isError) {
                var el = document.getElementById('lg-gift-toast');
                if (!el) return;
                el.textContent = msg;
                el.className   = 'lg-mygifts__toast' + (isError ? ' lg-mygifts__toast--error' : '');
                el.hidden      = false;
                clearTimeout(toastTimer);
                toastTimer = setTimeout(function() { el.hidden = true; }, isError ? 5500 : 3500);
            }

            function hideAllForms(table) {
                if (table) table.querySelectorAll('.lg-inline-form').forEach(function(f) { f.hidden = true; });
            }

            function showForm(row, cls) {
                hideAllForms(row.closest('table'));
                var form = row.querySelector(cls);
                if (!form) return;
                form.hidden = false;
                var first = form.querySelector('input,textarea');
                if (first) first.focus();
            }

            function removeRow(row, delay) {
                setTimeout(function() {
                    row.style.transition = 'opacity .35s';
                    row.style.opacity = '0';
                    setTimeout(function() { row.remove(); }, 360);
                }, delay || 0);
            }

            /* ---- Send ---- */
            document.querySelectorAll('.lg-action-open-send').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    showForm(btn.closest('tr'), '.lg-send-form');
                    btn.hidden = true;
                });
            });

            document.querySelectorAll('.lg-send-form').forEach(function(form) {
                form.querySelector('.lg-form-cancel').addEventListener('click', function() {
                    form.hidden = true;
                    var openBtn = form.closest('tr').querySelector('.lg-action-open-send');
                    if (openBtn) openBtn.hidden = false;
                });

                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var row    = form.closest('tr');
                    var codeId = parseInt(form.dataset.codeId, 10);
                    var sub    = form.querySelector('[type=submit]');
                    sub.disabled = true; sub.textContent = 'Sending…';

                    sendGiftWithRecipientCheck('gift-send', {
                        code_id:         codeId,
                        recipient_email: form.recipient_email.value.trim(),
                        recipient_name:  form.recipient_name.value.trim(),
                        message:         form.message.value.trim(),
                    }).then(function(d) {
                        if (d.ok) {
                            toast('Gift email sent!');
                            removeRow(row, 400);
                            setTimeout(function() { window.location.reload(); }, 1200);
                        } else {
                            if (!d.__cancelled) toast('Error: ' + (d.error || 'unknown error'), true);
                            sub.disabled = false; sub.textContent = 'Send gift';
                        }
                    }).catch(function() {
                        toast('Network error — please try again.', true);
                        sub.disabled = false; sub.textContent = 'Send gift';
                    });
                });
            });

            /* ---- Resend ---- */
            document.querySelectorAll('.lg-action-resend').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (!confirm('Re-send the gift email to the same recipient?')) return;
                    var codeId = parseInt(btn.dataset.codeId, 10);
                    btn.disabled = true; btn.textContent = 'Sending…';
                    apiCall('gift-resend', { code_id: codeId }).then(function(d) {
                        if (d.ok) {
                            toast('Gift email re-sent!');
                        } else {
                            toast('Error: ' + (d.error || 'unknown error'), true);
                        }
                        btn.disabled = false; btn.textContent = 'Resend';
                    }).catch(function() {
                        toast('Network error — please try again.', true);
                        btn.disabled = false; btn.textContent = 'Resend';
                    });
                });
            });

            /* ---- Reassign ---- */
            document.querySelectorAll('.lg-action-open-reassign').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    showForm(btn.closest('tr'), '.lg-reassign-form');
                });
            });

            document.querySelectorAll('.lg-reassign-form').forEach(function(form) {
                form.querySelector('.lg-form-cancel').addEventListener('click', function() {
                    form.hidden = true;
                });

                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var row    = form.closest('tr');
                    var codeId = parseInt(form.dataset.codeId, 10);
                    var sub    = form.querySelector('[type=submit]');
                    sub.disabled = true; sub.textContent = 'Reassigning…';

                    sendGiftWithRecipientCheck('gift-reassign', {
                        code_id:         codeId,
                        recipient_email: form.recipient_email.value.trim(),
                        recipient_name:  form.recipient_name.value.trim(),
                        message:         form.message.value.trim(),
                    }).then(function(d) {
                        if (d.ok) {
                            toast('Reassigned and emailed!');
                            setTimeout(function() { window.location.reload(); }, 900);
                        } else {
                            if (!d.__cancelled) toast('Error: ' + (d.error || 'unknown error'), true);
                            sub.disabled = false; sub.textContent = 'Reassign & email';
                        }
                    }).catch(function() {
                        toast('Network error — please try again.', true);
                        sub.disabled = false; sub.textContent = 'Reassign & email';
                    });
                });
            });

            /* ---- Void ---- */
            document.querySelectorAll('.lg-action-void').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (!confirm('Void this code? It will become permanently unusable.')) return;
                    var row    = btn.closest('tr');
                    var codeId = parseInt(btn.dataset.codeId, 10);
                    btn.disabled = true; btn.textContent = 'Voiding…';
                    apiCall('gift-void', { code_id: codeId }).then(function(d) {
                        if (d.ok) {
                            toast('Code voided.');
                            removeRow(row);
                        } else {
                            toast('Error: ' + (d.error || 'unknown error'), true);
                            btn.disabled = false; btn.textContent = 'Void';
                        }
                    }).catch(function() {
                        toast('Network error — please try again.', true);
                        btn.disabled = false; btn.textContent = 'Void';
                    });
                });
            });

            /* ---- Batch send ---- */
            var batchToggle  = document.getElementById('lg-batch-toggle');
            var batchSendBtn = document.getElementById('lg-batch-send-btn');
            var batchPanel   = document.getElementById('lg-batch-panel');
            var batchCountEl = document.getElementById('lg-batch-count');
            var batchRowsCt  = document.getElementById('lg-batch-rows');
            var batchMsg     = document.getElementById('lg-batch-msg');
            var batchApply   = document.getElementById('lg-batch-apply-msg');
            var batchFire    = document.getElementById('lg-batch-fire');
            var batchCancel  = document.getElementById('lg-batch-cancel');
            var batchProg    = document.getElementById('lg-batch-progress');
            var selAll       = document.getElementById('lg-select-all');
            var batchInMode  = false;

            function getChecked() { return Array.from(document.querySelectorAll('.lg-row-check:checked')); }
            function updateBatchCount() {
                var n = getChecked().length;
                if (batchCountEl) batchCountEl.textContent = n;
                if (batchSendBtn) batchSendBtn.hidden = (n === 0);
            }

            if (batchToggle) {
                batchToggle.addEventListener('click', function() {
                    batchInMode = !batchInMode;
                    batchToggle.textContent = batchInMode ? 'Cancel selection' : 'Select for batch send';
                    document.querySelectorAll('.lg-col-check').forEach(function(el) { el.hidden = !batchInMode; });
                    if (!batchInMode) {
                        document.querySelectorAll('.lg-row-check').forEach(function(cb) { cb.checked = false; });
                        if (batchSendBtn) batchSendBtn.hidden = true;
                        if (batchPanel)   batchPanel.hidden   = true;
                        if (selAll)       selAll.checked      = false;
                    }
                });
            }

            document.querySelectorAll('.lg-row-check').forEach(function(cb) {
                cb.addEventListener('change', updateBatchCount);
            });

            if (selAll) {
                selAll.addEventListener('change', function() {
                    document.querySelectorAll('.lg-row-check').forEach(function(cb) { cb.checked = selAll.checked; });
                    updateBatchCount();
                });
            }

            if (batchSendBtn) {
                batchSendBtn.addEventListener('click', function() {
                    var checked = getChecked();
                    if (checked.length === 0 || !batchPanel || !batchRowsCt) return;
                    batchPanel.hidden = false;
                    batchRowsCt.innerHTML = '';
                    checked.forEach(function(cb) {
                        var div = document.createElement('div');
                        div.className = 'lg-batch-row';
                        div.dataset.codeId = cb.dataset.codeId;
                        div.innerHTML =
                            '<div class="lg-batch-row__label">' + (cb.dataset.tierLabel || 'Code') + ' #' + cb.dataset.codeId + '</div>' +
                            '<input type="email" class="lg-field lg-batch-email" placeholder="Recipient email *" required>' +
                            '<input type="text"  class="lg-field lg-batch-name"  placeholder="Recipient name (optional)">' +
                            '<textarea class="lg-field lg-batch-row__msg lg-batch-msg-row" rows="1" placeholder="Message (optional)…"></textarea>';
                        batchRowsCt.appendChild(div);
                    });
                });
            }

            if (batchApply) {
                batchApply.addEventListener('click', function() {
                    var msg = batchMsg ? batchMsg.value : '';
                    document.querySelectorAll('.lg-batch-msg-row').forEach(function(ta) { ta.value = msg; });
                });
            }

            if (batchCancel) {
                batchCancel.addEventListener('click', function() { if (batchPanel) batchPanel.hidden = true; });
            }

            if (batchFire) {
                batchFire.addEventListener('click', function() {
                    var rows = batchRowsCt ? Array.from(batchRowsCt.querySelectorAll('.lg-batch-row')) : [];
                    if (rows.length === 0) return;

                    var missing = rows.filter(function(r) {
                        var e = r.querySelector('.lg-batch-email');
                        return !e || e.value.trim() === '';
                    });
                    if (missing.length > 0) { toast('Please fill in all recipient emails.', true); return; }

                    batchFire.disabled = true;
                    if (batchCancel) batchCancel.disabled = true;
                    if (batchProg) { batchProg.hidden = false; batchProg.textContent = '0 / ' + rows.length + ' sent'; }

                    var done = 0, errors = 0;

                    function fireNext(i) {
                        if (i >= rows.length) {
                            var msg = 'Sent ' + (done - errors) + ' of ' + rows.length + '.';
                            if (errors) msg += ' ' + errors + ' failed — check your data and retry those individually.';
                            toast(msg, errors > 0);
                            setTimeout(function() { window.location.reload(); }, 1400);
                            return;
                        }
                        var rowEl  = rows[i];
                        var codeId = parseInt(rowEl.dataset.codeId, 10);
                        var email  = rowEl.querySelector('.lg-batch-email').value.trim();
                        var name   = rowEl.querySelector('.lg-batch-name').value.trim();
                        var msg    = rowEl.querySelector('.lg-batch-msg-row').value.trim();

                        apiCall('gift-send', { code_id: codeId, recipient_email: email, recipient_name: name, message: msg, acknowledged_recipient_warning: true })
                            .then(function(d) {
                                done++;
                                if (!d.ok) errors++;
                                if (batchProg) batchProg.textContent = done + ' / ' + rows.length + ' sent';
                                fireNext(i + 1);
                            })
                            .catch(function() {
                                done++; errors++;
                                if (batchProg) batchProg.textContent = done + ' / ' + rows.length + ' sent';
                                fireNext(i + 1);
                            });
                    }
                    fireNext(0);
                });
            }
        })();
        </script>
<?php
$pageWrap((string) ob_get_clean());
