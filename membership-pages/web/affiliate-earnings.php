<?php
/**
 * /affiliate-earnings/ — port of [lg_affiliate_portal].
 *
 * Verbatim port: affiliate stats + earnings estimate + payout history from the
 * poller DB, referral link, and a "Request withdrawal" button. The button POSTs
 * to /wp-json/lg-member-sync/v1/affiliate-withdraw with X-WP-Nonce — minted via
 * the nonce-via-loopback bridge (lg_membership_rest_nonce). JS copied unchanged.
 * Admin-only pre-launch; only the chrome changes.
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

/** Reproduces Shortcodes::affiliateEarningsEstimate() against the poller DB. */
function lg_ms_affiliate_estimate(PDO $pdo, int $affId, float $commissionPct, float $retentionBonusPct): array {
    $tierPriceCents = [];
    try {
        $stmt = $pdo->query(
            "SELECT p.ref, MIN(pr.unit_amount_cents) AS cents FROM products p
             JOIN prices pr ON pr.product_id = p.id AND pr.type = 'recurring' AND pr.interval = 'month' AND pr.active = 1
             WHERE p.ref IN ('looth2','looth3','looth4') AND p.active = 1 AND (p.region_tag IS NULL OR p.region_tag = '')
             GROUP BY p.ref"
        );
        foreach ($stmt as $r) { $tierPriceCents[(string) $r['ref']] = (int) $r['cents']; }
    } catch (Throwable $e) {}

    $perTier = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT tier, COUNT(*) AS n, SUM(CASE WHEN retention_bonus_eligible_at IS NOT NULL THEN 1 ELSE 0 END) AS n_ret
             FROM affiliate_conversions WHERE affiliate_id = ? GROUP BY tier"
        );
        $stmt->execute([$affId]);
        $perTier = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    $grossCents = 0; $retCents = 0;
    foreach ($perTier as $row) {
        $tier = (string) ($row['tier'] ?? ''); $n = (int) ($row['n'] ?? 0); $nRet = (int) ($row['n_ret'] ?? 0);
        $price = $tierPriceCents[$tier] ?? 0;
        if ($price === 0) continue;
        $grossCents += (int) round($n * $price * ($commissionPct / 100));
        $retCents   += (int) round($nRet * $price * 12 * ($retentionBonusPct / 100));
    }

    $paidOutCents = 0;
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(paid_cents), 0) FROM lg_affiliate_payouts WHERE affiliate_id = ? AND status = 'paid'");
        $stmt->execute([$affId]);
        $paidOutCents = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {}

    return ['gross_cents' => $grossCents, 'retention_cents' => $retCents, 'paid_out_cents' => $paidOutCents];
}

// ---- resolve the logged-in WP user id (affiliate keyed on wp_user_id) ----
$wpUserId = 0;
foreach ($_COOKIE as $name => $val) {
    if (strpos($name, 'wordpress_logged_in_') === 0) {
        $parts = explode('|', urldecode((string) $val), 4);
        if (!empty($parts[0])) {
            try {
                $st = lg_membership_db()->prepare("SELECT ID FROM " . LG_MEMBERSHIP_TABLE_PREFIX . "users WHERE user_login = ? LIMIT 1");
                $st->execute([$parts[0]]);
                $wpUserId = (int) ($st->fetchColumn() ?: 0);
            } catch (Throwable $e) {}
        }
        break;
    }
}

$aff = null;
if ($wpUserId > 0) {
    try {
        $pdo  = lg_membership_poller_db();
        $stmt = $pdo->prepare(
            'SELECT a.id, a.slug, a.commission_pct, a.commission_pct_annual, a.retention_bonus_pct,
                    COUNT(DISTINCT cl.id) AS clicks, COUNT(DISTINCT cv.id) AS conversions,
                    COUNT(DISTINCT CASE WHEN cv.retention_bonus_eligible_at IS NOT NULL THEN cv.id END) AS retention_eligible,
                    COALESCE(SUM(db.amount_cents), 0) AS total_debits_cents
             FROM affiliates a
             LEFT JOIN affiliate_clicks cl ON cl.affiliate_id = a.id
             LEFT JOIN affiliate_conversions cv ON cv.affiliate_id = a.id
             LEFT JOIN affiliate_debits db ON db.affiliate_id = a.id
             WHERE a.wp_user_id = ? GROUP BY a.id LIMIT 1'
        );
        $stmt->execute([$wpUserId]);
        $aff = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('affiliate-earnings: ' . $e->getMessage()); }
}

$isAdmin = ($ctx['capabilities']['manage_options'] ?? false) === true;

/* Gate the actionable portal (the "Request withdrawal" button) on a VALID WP
 * SESSION (the nonce), not the cookie-username string used to resolve the
 * affiliate above. lg_membership_rest_nonce() only mints for a live session; ''
 * = stale/rotated session (or a Patreon identity with no WP auth cookie). Render
 * the re-auth state instead of a live withdraw button that POSTs an empty
 * X-WP-Nonce and silently 401s. */
$restNonce = lg_membership_rest_nonce();
if ($restNonce === '') {
    lg_membership_render_session_expired_or_exit($ctx, 'Affiliate Earnings — The Looth Group');
}

// Header + (optional) "no affiliate account" early body are emitted inside the shell.
$bodyHtml = '';
if ($aff === null) {
    $bodyHtml = '<p><em>No affiliate account linked to your profile.</em></p>';
    if ($isAdmin) {
        $bodyHtml .= '<p style="margin-top:.5em;font-size:.92em;color:#555;">You\'re logged in as admin — affiliate payouts live in <a href="/wp-admin/admin.php?page=lg-affiliates">wp-admin → Affiliates</a>.</p>';
    }
} else {
    $pdo           = lg_membership_poller_db();
    $affLink       = '/lgjoin/?ref=' . rawurlencode((string) $aff['slug']);
    $clicks        = (int) $aff['clicks'];
    $conversions   = (int) $aff['conversions'];
    $rate          = $clicks > 0 ? round($conversions / $clicks * 100) . '%' : '—';
    $debits        = (int) $aff['total_debits_cents'];
    $retElig       = (int) $aff['retention_eligible'];
    $withdrawNonce = $restNonce;                   // gated non-empty above
    $est           = lg_ms_affiliate_estimate($pdo, (int) $aff['id'], (float) $aff['commission_pct'], (float) $aff['retention_bonus_pct']);
    $balanceCents  = max(0, $est['gross_cents'] + $est['retention_cents'] - $debits - $est['paid_out_cents']);

    $myPayouts = []; $myPayoutsTotal = 0;
    try {
        $st = $pdo->prepare('SELECT id, requested_cents, paid_cents, status, method, notes, requested_at, resolved_at FROM lg_affiliate_payouts WHERE affiliate_id = ? ORDER BY id DESC LIMIT 100');
        $st->execute([(int) $aff['id']]);
        $myPayouts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stCnt = $pdo->prepare('SELECT COUNT(*) FROM lg_affiliate_payouts WHERE affiliate_id = ?');
        $stCnt->execute([(int) $aff['id']]);
        $myPayoutsTotal = (int) $stCnt->fetchColumn();
    } catch (Throwable $e) {}
    $payoutsVisibleCap = 25;
    $hasPendingMine = false;
    foreach ($myPayouts as $p) { if (($p['status'] ?? '') === 'requested') { $hasPendingMine = true; break; } }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Affiliate Earnings — The Looth Group</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
</head>
<body class="lg-membership-page lg-affiliate-page">
<?php lg_shared_render_site_header($ctx); ?>
<main id="lg-main">
<?php if ($aff === null): ?>
        <?= $bodyHtml ?>
<?php else: ?>
        <div class="lg-aff-portal" style="max-width:560px;">
            <h2 style="margin:0 0 .8em;font-size:1.3em;">Your affiliate earnings</h2>

            <div style="background:#f7fbf2;border:1px solid #d4e0b8;border-radius:5px;padding:.8em 1em;margin-bottom:1em;">
                <table style="border-collapse:collapse;width:100%;">
                    <tr>
                        <td style="padding:.2em .8em .2em 0;color:#555;width:50%;font-size:.92em;">Your referral link</td>
                        <td>
                            <input type="text" value="<?= $h($affLink) ?>" readonly onclick="this.select()"
                                   style="width:100%;font-size:12px;font-family:monospace;padding:4px 6px;border:1px solid #ccc;border-radius:3px;">
                        </td>
                    </tr>
                    <tr><td style="padding:.2em .8em .2em 0;color:#555;font-size:.92em;">Clicks</td><td style="font-weight:600;"><?= $clicks ?></td></tr>
                    <tr><td style="padding:.2em .8em .2em 0;color:#555;font-size:.92em;">Conversions</td><td style="font-weight:600;"><?= $conversions ?></td></tr>
                    <tr><td style="padding:.2em .8em .2em 0;color:#555;font-size:.92em;">Conversion rate</td><td style="font-weight:600;"><?= $h($rate) ?></td></tr>
                    <tr><td colspan="2" style="border-top:1px solid #d4e0b8;padding-top:.6em;"></td></tr>
                    <tr><td style="padding:.2em .8em .2em 0;color:#555;font-size:.92em;">Estimated commission</td><td style="font-weight:600;">$<?= number_format($est['gross_cents'] / 100, 2) ?></td></tr>
                    <?php if ($est['retention_cents'] > 0): ?>
                    <tr><td style="padding:.2em .8em .2em 0;color:#555;font-size:.92em;">Retention bonuses (<?= (int) $retElig ?>)</td><td style="font-weight:600;color:#b45309;">+$<?= number_format($est['retention_cents'] / 100, 2) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($debits > 0): ?>
                    <tr><td style="padding:.2em .8em .2em 0;color:#555;font-size:.92em;">Refund debits</td><td style="font-weight:600;color:#dc2626;">−$<?= number_format($debits / 100, 2) ?></td></tr>
                    <?php endif; ?>
                    <tr><td colspan="2" style="border-top:1px solid #d4e0b8;padding-top:.6em;"></td></tr>
                    <tr><td style="padding:.35em .8em .35em 0;color:#1f1d1a;font-weight:700;">Estimated balance</td><td style="font-weight:700;font-size:1.1em;color:#1f1d1a;">$<?= number_format($balanceCents / 100, 2) ?></td></tr>
                </table>
            </div>

            <p style="color:#555;font-size:.85em;margin-bottom:1.2em;line-height:1.5;">
                <strong>Estimate only.</strong> Calculated as conversions × standard monthly tier prices × your commission rate (<?= (float) $aff['commission_pct'] ?>% monthly, <?= (float) $aff['commission_pct_annual'] ?>% annual sign-up). Annual signups, regional pricing, refund timing, and partial-month cancellations all shift the final number — we'll reconcile when you request a payout.
            </p>

            <?php if ($hasPendingMine): ?>
                <div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:5px;padding:.7em 1em;margin-bottom:1em;font-size:.92em;color:#78350f;">
                    <strong>Request pending.</strong> We've got your withdrawal request and will be in touch.
                </div>
                <button type="button" disabled style="background:#e5e7eb;color:#9ca3af;border:none;padding:.65em 1.3em;border-radius:5px;font-weight:600;font-size:1em;cursor:not-allowed;">Request withdrawal</button>
            <?php else: ?>
                <button type="button" id="lgms-aff-portal-withdraw" style="background:#ECB351;color:#1f1d1a;border:none;padding:.65em 1.3em;border-radius:5px;font-weight:600;cursor:pointer;font-size:1em;">Request withdrawal</button>
                <span id="lgms-aff-portal-msg" style="display:none;margin-left:.8em;font-size:.9em;"></span>
            <?php endif; ?>

            <?php if ($myPayouts !== []):
                $shown = count($myPayouts); $hidden = max(0, $shown - $payoutsVisibleCap); $beyondDb = max(0, $myPayoutsTotal - $shown);
            ?>
                <h3 style="margin:2em 0 .6em;font-size:1em;color:#555;text-transform:uppercase;letter-spacing:.05em;">Payout history</h3>
                <table id="lgms-payouts-history" style="border-collapse:collapse;width:100%;max-width:640px;font-size:.9em;">
                    <thead>
                        <tr style="border-bottom:1px solid #e5e7eb;color:#666;text-align:left;">
                            <th style="padding:.4em .6em .4em 0;font-weight:600;">Requested</th>
                            <th style="padding:.4em .6em;font-weight:600;">Amount</th>
                            <th style="padding:.4em .6em;font-weight:600;">Status</th>
                            <th style="padding:.4em 0 .4em .6em;font-weight:600;">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myPayouts as $idx => $p):
                            $status = (string) ($p['status'] ?? '');
                            $amt    = $status === 'paid' && $p['paid_cents'] !== null ? number_format(((int) $p['paid_cents']) / 100, 2) : number_format(((int) $p['requested_cents']) / 100, 2);
                            $color  = $status === 'paid' ? '#15803d' : ($status === 'denied' ? '#dc2626' : '#b45309');
                            $method = (string) ($p['method'] ?? ''); $note = (string) ($p['notes'] ?? '');
                            $extras = trim($method . ($note !== '' ? ($method !== '' ? ' · ' : '') . $note : ''));
                            $cls    = $idx >= $payoutsVisibleCap ? ' class="lgms-payout-extra"' : '';
                        ?>
                        <tr<?= $cls ?> style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:.5em .6em .5em 0;color:#555;"><?= $h(substr((string) $p['requested_at'], 0, 10)) ?></td>
                            <td style="padding:.5em .6em;font-weight:600;">$<?= $h($amt) ?><?php if ($status === 'paid' && $p['paid_cents'] !== null && (int) $p['paid_cents'] !== (int) $p['requested_cents']): ?><span style="color:#999;font-weight:400;font-size:.85em;"> (req'd $<?= number_format(((int) $p['requested_cents']) / 100, 2) ?>)</span><?php endif; ?></td>
                            <td style="padding:.5em .6em;color:<?= $color ?>;font-weight:600;text-transform:uppercase;font-size:.85em;letter-spacing:.04em;"><?= $h($status) ?></td>
                            <td style="padding:.5em 0 .5em .6em;color:#666;"><?= $h($extras) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($hidden > 0 || $beyondDb > 0): ?>
                <p style="margin:.6em 0 0;font-size:.85em;color:#666;">
                    Showing <?= min($shown, $payoutsVisibleCap) ?> of <?= $myPayoutsTotal ?>.
                    <?php if ($hidden > 0): ?><a href="#" id="lgms-payouts-show-all" style="color:#15803d;font-weight:600;text-decoration:none;">Show all <?= $hidden + min($shown, $payoutsVisibleCap) ?> &rarr;</a><?php endif; ?>
                    <?php if ($beyondDb > 0): ?><span style="color:#888;">(<?= $beyondDb ?> older row<?= $beyondDb === 1 ? '' : 's' ?> not loaded — contact admin to retrieve.)</span><?php endif; ?>
                </p>
                <style>.lgms-payout-extra { display: none; }</style>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <script>
        (function(){
            var showAll = document.getElementById('lgms-payouts-show-all');
            if (showAll) {
                showAll.addEventListener('click', function(e){
                    e.preventDefault();
                    document.querySelectorAll('.lgms-payout-extra').forEach(function(tr){ tr.style.display = 'table-row'; });
                    showAll.style.display = 'none';
                });
            }
            var withdrawBtn = document.getElementById('lgms-aff-portal-withdraw');
            if (withdrawBtn) {
                withdrawBtn.addEventListener('click', async function() {
                    var btn = this, msg = document.getElementById('lgms-aff-portal-msg');
                    btn.disabled = true; btn.textContent = 'Sending…';
                    try {
                        var res  = await fetch('/wp-json/lg-member-sync/v1/affiliate-withdraw', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?= $h($withdrawNonce) ?>' },
                            body: JSON.stringify({}),
                        });
                        var data = await res.json();
                        if (data.ok) {
                            msg.style.display = 'inline'; msg.style.color = '#15803d';
                            msg.textContent = 'Request sent! Reload to see it in your payout history.';
                        } else {
                            btn.disabled = false; btn.textContent = 'Request withdrawal';
                            msg.style.display = 'inline'; msg.style.color = '#dc2626';
                            msg.textContent = data.error || 'Something went wrong.';
                        }
                    } catch(e) {
                        btn.disabled = false; btn.textContent = 'Request withdrawal';
                        msg.style.display = 'inline'; msg.style.color = '#dc2626';
                        msg.textContent = 'Network error.';
                    }
                });
            }
        })();
        </script>
<?php endif; ?>
</main>
<?php lg_shared_render_site_footer(['logo_url' => LG_MEMBERSHIP_LOGO]); ?>
</body>
</html>
