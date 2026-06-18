<?php
/**
 * /manage-subscription/ data layer — read-only Patreon membership.
 *
 * Reads lg_patreon_members (poller DB) by wp_user_id to surface the current
 * Patreon-attributed membership shape: status, tier label, last charge,
 * next charge, monthly amount. Plus reads the "Manage on Patreon" linkout URL
 * from wp_options (lgpo_patreon_link).
 *
 * Stripe is dormant at cut (coord §3h, B-now/A-later), so this surface does
 * NOT join against `subscriptions` / `entitlements`. Once Stripe ships, a
 * companion query reads the Stripe-side state and the controller picks the
 * canonical source per `payment_source` usermeta — see PatreonSourceReader
 * in the poller plugin for the existing read-side discriminator.
 */

declare(strict_types=1);

if (!function_exists('lg_membership_load_patreon_link')) {
/**
 * Patreon-billing linkout URL (from WP options). Defaults to Patreon's
 * generic page if the option is unset.
 */
function lg_membership_load_patreon_link(): string {
    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        $stmt = lg_membership_db()->prepare(
            "SELECT option_value FROM " . LG_MEMBERSHIP_TABLE_PREFIX . "options
             WHERE option_name = 'lgpo_patreon_link' LIMIT 1"
        );
        $stmt->execute();
        $val = (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $val = '';
    }
    return $cache = ($val !== '' ? $val : 'https://www.patreon.com/');
}
}

if (!function_exists('lg_membership_load_patreon_membership')) {
/**
 * Read the user's Patreon membership row from lg_patreon_members.
 *
 * @return array{
 *     wp_user_id: int,
 *     patron_status: ?string,
 *     last_charge_status: ?string,
 *     last_charge_date: ?string,
 *     next_charge_date: ?string,
 *     tier_label: ?string,
 *     will_pay_amount_cents: ?int,
 *     currently_entitled_amount_cents: ?int,
 *     synced_at: ?string,
 * }|null  null = no Patreon record on file for that user.
 */
function lg_membership_load_patreon_membership(int $wp_user_id): ?array {
    if ($wp_user_id <= 0) return null;

    try {
        $stmt = lg_membership_poller_db()->prepare(
            'SELECT wp_user_id, email, patron_status, last_charge_status, last_charge_date,
                    next_charge_date, tier_label,
                    will_pay_amount_cents, currently_entitled_amount_cents, synced_at
               FROM lg_patreon_members
              WHERE wp_user_id = ?
              LIMIT 1'
        );
        $stmt->execute([$wp_user_id]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($row) || $row === []) return null;

    return [
        'wp_user_id'                      => (int) $row['wp_user_id'],
        'email'                           => $row['email'] !== null ? (string) $row['email'] : null,
        'patron_status'                   => $row['patron_status']                    !== null ? (string) $row['patron_status']      : null,
        'last_charge_status'              => $row['last_charge_status']               !== null ? (string) $row['last_charge_status'] : null,
        'last_charge_date'                => $row['last_charge_date']                 !== null ? (string) $row['last_charge_date']   : null,
        'next_charge_date'                => $row['next_charge_date']                 !== null ? (string) $row['next_charge_date']   : null,
        'tier_label'                      => $row['tier_label']                       !== null ? (string) $row['tier_label']         : null,
        'will_pay_amount_cents'           => $row['will_pay_amount_cents']            !== null ? (int)    $row['will_pay_amount_cents']            : null,
        'currently_entitled_amount_cents' => $row['currently_entitled_amount_cents']  !== null ? (int)    $row['currently_entitled_amount_cents']  : null,
        'synced_at'                       => $row['synced_at']                        !== null ? (string) $row['synced_at']          : null,
    ];
}
}

if (!function_exists('lg_membership_format_status_label')) {
/**
 * Human-readable label for a patron_status enum value. Maps the Patreon
 * API's snake_case to copy that fits in a pill / heading.
 */
function lg_membership_format_status_label(?string $patron_status): string {
    return match ($patron_status) {
        'active_patron'   => 'Active',
        'declined_patron' => 'Payment declined',
        'former_patron'   => 'Former member',
        default           => 'Not a member',
    };
}
}

if (!function_exists('lg_membership_format_status_kind')) {
/**
 * Categorical kind for CSS theming. Mirrors the human label but in a
 * style-friendly slug. Independent so we can change copy without
 * touching CSS class names.
 */
function lg_membership_format_status_kind(?string $patron_status): string {
    return match ($patron_status) {
        'active_patron'   => 'active',
        'declined_patron' => 'declined',
        'former_patron'   => 'former',
        default           => 'none',
    };
}
}

if (!function_exists('lg_membership_format_amount')) {
/**
 * Minor-unit cents → "$N" or "$N.NN". Returns empty string when null/0.
 * Stripe convention but Patreon also reports in cents here.
 */
function lg_membership_format_amount(?int $cents): string {
    if ($cents === null || $cents <= 0) return '';
    if ($cents % 100 === 0) {
        return '$' . (int)($cents / 100);
    }
    return '$' . number_format($cents / 100, 2);
}
}

if (!function_exists('lg_membership_format_date')) {
/**
 * MySQL datetime → "Jun 2, 2026". Returns empty string on null/empty.
 */
function lg_membership_format_date(?string $mysql_dt): string {
    if ($mysql_dt === null || $mysql_dt === '' || str_starts_with($mysql_dt, '0000')) return '';
    $ts = strtotime($mysql_dt);
    return $ts === false ? '' : date('M j, Y', $ts);
}
}
