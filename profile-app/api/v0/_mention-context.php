<?php
declare(strict_types=1);

/**
 * Second line for mention-dropdown / tag-picker rows (handles-invisible ruling,
 * Ian final 2026-07-26: rows are name + avatar + location/business — never a handle).
 *
 * business name when the member has one, else their location AT MEMBERS PRECISION —
 * the suggest endpoint is members-only, so users.location_members_precision is exactly
 * the audience contract to honor. Pure function, computed server-side so no client
 * ever holds fields the member scoped away.
 *
 * @param array $r users row: business_name, location_visibility,
 *                 location_members_precision, location_city, location_region,
 *                 location_country
 */
function lg_profile_mention_context(array $r): ?string
{
    $biz = trim((string) ($r['business_name'] ?? ''));
    if ($biz !== '') return $biz;
    if (($r['location_visibility'] ?? 'members') === 'private') return null;
    $prec   = (string) ($r['location_members_precision'] ?? 'city');
    $city   = trim((string) ($r['location_city'] ?? ''));
    $region = trim((string) ($r['location_region'] ?? ''));
    if ($prec === 'private') return null;
    if ($prec === 'state') return $region !== '' ? $region : (trim((string) ($r['location_country'] ?? '')) ?: null);
    // city + street both surface as city here — a picker row never needs a street
    $parts = array_values(array_filter([$city, $region], 'strlen'));
    return $parts ? implode(', ', $parts) : null;
}
