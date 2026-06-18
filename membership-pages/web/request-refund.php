<?php
/**
 * /request-refund/ — port of [lg_refund_request].
 *
 * Verbatim port: prefilled name/email, eligible-items list (subscriptions + gift
 * purchases from the poller DB), reason checkboxes, comments, honeypot, and the
 * fetch() POST to /wp-json/lg-member-sync/v1/refund-request — the JS is copied
 * unchanged. That route is permission_callback __return_true (no nonce), so the
 * POST works as-is. Only the chrome changes. Admin-only pre-launch.
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

/** sanitize_title() shim — stable element IDs for the fixed reason strings. */
function lg_ms_sanitize_title(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string) $s, '-');
}
function lg_ms_short_date(string $datetime): string {
    $ts = $datetime ? strtotime($datetime) : false;
    return $ts ? gmdate('M j, Y', $ts) : 'unknown date';
}
function lg_ms_tier_label_for_price(PDO $db, string $priceId): string {
    if ($priceId === '') return '';
    try {
        $stmt = $db->prepare('SELECT pr.name AS product_name FROM prices pp JOIN products pr ON pr.id = pp.product_id WHERE pp.stripe_price_id = ? LIMIT 1');
        $stmt->execute([$priceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string) $row['product_name'] : '';
    } catch (Throwable $e) { return ''; }
}
/** Reproduces Shortcodes::eligibleRefundItems() against the poller DB. */
function lg_ms_eligible_refund_items(string $email, int $windowDays): array {
    $items = [];
    try {
        $db = lg_membership_poller_db();
        $cs = $db->prepare('SELECT * FROM customers WHERE email = ? AND deleted_at IS NULL LIMIT 1');
        $cs->execute([$email]);
        $customer = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($customer === null) return [];
        $customerId = (int) $customer['id'];
        $cutoffTs   = time() - ($windowDays * 86400);

        $stmt = $db->prepare(
            "SELECT stripe_subscription_id, stripe_price_id, status, current_period_start, current_period_end
             FROM subscriptions WHERE customer_id = ? AND status IN ('active','trialing','past_due') ORDER BY id DESC"
        );
        $stmt->execute([$customerId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $chargedAt = $row['current_period_start'];
            $eligible  = $chargedAt && strtotime((string) $chargedAt) >= $cutoffTs;
            $tier      = lg_ms_tier_label_for_price($db, (string) $row['stripe_price_id']);
            $detail    = $tier
                ? "{$tier}, last charged " . lg_ms_short_date((string) $chargedAt)
                : 'last charged ' . lg_ms_short_date((string) $chargedAt);
            $items[] = ['kind' => 'subscription', 'id' => (string) $row['stripe_subscription_id'], 'label' => 'Subscription', 'detail' => $detail, 'eligible' => (bool) $eligible];
        }

        $stmt = $db->prepare(
            "SELECT stripe_session_id, MIN(created_at) AS purchased_at, COUNT(*) AS qty,
                    SUM(redeemed_at IS NOT NULL) AS redeemed, SUM(voided_at IS NOT NULL) AS voided
             FROM gift_codes WHERE purchased_by = ? AND stripe_session_id IS NOT NULL
             GROUP BY stripe_session_id ORDER BY MIN(id) DESC"
        );
        $stmt->execute([$customerId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $totalQty = (int) $row['qty'];
            $active   = $totalQty - (int) $row['voided'] - (int) $row['redeemed'];
            if ($active <= 0) continue;
            $purchasedAt = (string) $row['purchased_at'];
            $eligible    = $purchasedAt && strtotime($purchasedAt) >= $cutoffTs;
            $redeemed    = (int) $row['redeemed'];
            $detail      = "{$totalQty}-seat purchase on " . lg_ms_short_date($purchasedAt);
            $detail     .= $redeemed > 0 ? " ({$active} unredeemed codes refundable; {$redeemed} already used)" : " ({$active} active codes)";
            $items[] = ['kind' => 'gift_purchase', 'id' => (string) $row['stripe_session_id'], 'label' => 'Gift purchase', 'detail' => $detail, 'eligible' => (bool) $eligible];
        }
    } catch (Throwable $e) {
        error_log('request-refund: ' . $e->getMessage());
        return [];
    }
    return $items;
}

// ---- resolve the logged-in user (email/name) via the WP DB ----
$emailValue = '';
$nameValue  = '';
foreach ($_COOKIE as $name => $val) {
    if (strpos($name, 'wordpress_logged_in_') === 0) {
        $parts = explode('|', urldecode((string) $val), 4);
        if (!empty($parts[0])) {
            try {
                $st = lg_membership_db()->prepare("SELECT user_email, display_name, user_login FROM " . LG_MEMBERSHIP_TABLE_PREFIX . "users WHERE user_login = ? LIMIT 1");
                $st->execute([$parts[0]]);
                if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
                    $emailValue = (string) $u['user_email'];
                    $nameValue  = trim((string) ($u['display_name'] ?: $u['user_login']));
                }
            } catch (Throwable $e) {}
        }
        break;
    }
}

$endpoint   = '/wp-json/lg-member-sync/v1/refund-request';
$heading    = 'Request a refund';
$windowDays = 30;
try {
    $st = lg_membership_db()->prepare("SELECT option_value FROM " . LG_MEMBERSHIP_TABLE_PREFIX . "options WHERE option_name = 'lgms_refund_window_days' LIMIT 1");
    $st->execute();
    $wd = (int) ($st->fetchColumn() ?: 30);
    $windowDays = max(1, $wd);
} catch (Throwable $e) {}

$items = $emailValue !== '' ? lg_ms_eligible_refund_items($emailValue, $windowDays) : [];

$reasons = [
    'I was charged in error or did not intend to subscribe',
    'Duplicate or unauthorized charge',
    'I was charged after canceling my subscription',
    'I cannot access the content I paid for',
    'The service is not working as advertised',
    'A technical issue is preventing me from using the site',
    'Other (please explain in comments)',
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Request a Refund — The Looth Group</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
</head>
<body class="lg-membership-page lg-refund-page">
<?php lg_shared_render_site_header($ctx); ?>
<main id="lg-main">
        <div class="lg-refund">
            <h3 class="lg-refund__heading"><?= $h($heading) ?></h3>
            <p class="lg-refund__intro">Sorry to see you go. Tell us a bit about why and we'll process your refund.</p>
            <p class="lg-refund__policy" style="font-size:0.95em;color:#444;">
                <strong>Our refund policy:</strong> We refund subscription charges and gift purchases within
                <strong><?= (int) $windowDays ?> days</strong> of the original charge.
                Items outside the window are reviewed case-by-case &mdash; submit a request and we'll get back to you.
            </p>
            <form class="lg-refund__form" data-lg-refund>
                <div class="lg-refund__row">
                    <label class="lg-refund__label"><span>Name</span>
                        <input type="text" name="name" required value="<?= $h($nameValue) ?>">
                    </label>
                    <label class="lg-refund__label"><span>Email</span>
                        <input type="email" name="email" required value="<?= $h($emailValue) ?>">
                    </label>
                </div>

                <?php if ($items !== []): ?>
                <fieldset class="lg-refund__fieldset">
                    <legend>What would you like refunded? <em style="opacity:.6;">(pick one &mdash; submit again for additional items)</em></legend>
                    <div class="lg-refund__items">
                        <?php foreach ($items as $i => $item):
                            $id    = 'lg-refund-item-' . $i;
                            $value = $item['kind'] . ':' . $item['id'];
                            $note  = $item['eligible']
                                ? '<em style="color:#080;">Within refund window</em>'
                                : '<em style="color:#b00;">Outside ' . (int) $windowDays . '-day window &mdash; we will still review your request</em>';
                        ?>
                            <label class="lg-refund__item" for="<?= $h($id) ?>" style="display:block;padding:0.4em 0;">
                                <input type="radio" id="<?= $h($id) ?>" name="items[]" value="<?= $h($value) ?>" data-eligible="<?= $item['eligible'] ? '1' : '0' ?>">
                                <strong><?= $h($item['label']) ?></strong>
                                <span style="color:#666;">&mdash; <?= $h($item['detail']) ?></span>
                                <br>
                                <span style="margin-left:1.6em;font-size:0.9em;"><?= $note ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <?php else: ?>
                    <p style="color:#666;font-style:italic;">We did not find any refundable purchases on your account. You can still submit a request below if you believe this is in error.</p>
                <?php endif; ?>

                <fieldset class="lg-refund__fieldset">
                    <legend>Why are you requesting a refund? <em style="opacity:.6;">(select all that apply)</em></legend>
                    <div class="lg-refund__reasons">
                        <?php foreach ($reasons as $reason): $id = 'lg-refund-r-' . lg_ms_sanitize_title($reason); ?>
                            <label class="lg-refund__reason" for="<?= $h($id) ?>">
                                <input type="checkbox" id="<?= $h($id) ?>" name="reasons[]" value="<?= $h($reason) ?>">
                                <span><?= $h($reason) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <label class="lg-refund__label lg-refund__label--full">
                    <span>Anything else you'd like us to know? <em style="opacity:.6;">(optional)</em></span>
                    <textarea name="comments" rows="4"></textarea>
                </label>

                <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">

                <div class="lg-refund__submit-row">
                    <button type="submit" class="lg-refund__submit">Send refund request</button>
                </div>
            </form>
            <div class="lg-refund__result" data-lg-refund-result aria-live="polite"></div>
        </div>
        <script>
        (function(){
            const ENDPOINT = '<?= $h($endpoint) ?>';
            const form     = document.querySelector('[data-lg-refund]');
            const resultEl = document.querySelector('[data-lg-refund-result]');
            const submitBt = form ? form.querySelector('button[type="submit"]') : null;
            if (!form) return;

            form.addEventListener('submit', async function(e){
                e.preventDefault();
                const reasons = Array.from(form.querySelectorAll('input[name="reasons[]"]:checked')).map(i => i.value);
                const items   = Array.from(form.querySelectorAll('input[name="items[]"]:checked')).map(i => i.value);
                if (reasons.length === 0) {
                    resultEl.className   = 'lg-refund__result is-error';
                    resultEl.textContent = 'Please select at least one reason.';
                    return;
                }
                const payload = {
                    name:     (form.name.value     || '').trim(),
                    email:    (form.email.value    || '').trim(),
                    reasons:  reasons,
                    items:    items,
                    comments: (form.comments.value || '').trim(),
                    website:  (form.website.value  || '').trim(),
                };
                submitBt.disabled = true;
                resultEl.className   = 'lg-refund__result is-pending';
                resultEl.textContent = 'Sending...';
                try {
                    const res  = await fetch(ENDPOINT, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify(payload),
                    });
                    const data = await res.json();
                    if (data.ok) {
                        form.style.display    = 'none';
                        resultEl.className    = 'lg-refund__result is-success';
                        resultEl.innerHTML    = '<strong>Thanks - we got your request.</strong> We will review it within a couple of business days and email you when the refund is processed.';
                    } else {
                        resultEl.className   = 'lg-refund__result is-error';
                        resultEl.textContent = data.error || 'Could not send your request. Please try again.';
                    }
                } catch (err) {
                    resultEl.className   = 'lg-refund__result is-error';
                    resultEl.textContent = 'Network error: ' + err.message;
                } finally {
                    submitBt.disabled = false;
                }
            });
        })();
        </script>
</main>
<?php lg_shared_render_site_footer(['logo_url' => LG_MEMBERSHIP_LOGO]); ?>
</body>
</html>
