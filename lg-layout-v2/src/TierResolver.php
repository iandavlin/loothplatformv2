<?php
/**
 * TierResolver — single source of truth for "does this viewer satisfy this tier?"
 *
 * Blocks declare `gated_tier: "looth-pro"`. The renderer hands the viewer +
 * required tier to this resolver, gets a boolean back, and either renders or
 * skips. The resolver internally handles:
 *
 *   - taxonomy membership (the WP `tier` taxonomy on the user)
 *   - admin role bypass
 *   - delinquent state downgrade (v1's "looth1" is now a state, not a tier)
 *   - preview-as override (admin querying ?lg_preview_role=public)
 *
 * Viewers are passed as a plain array so the resolver runs in the CLI harness
 * (no WP needed) and in the WP plugin (with a viewer assembled from
 * wp_get_current_user + user_meta + the tier taxonomy).
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class TierResolver
{
    /** Hierarchy: any tier satisfies tiers ranked at or below itself. */
    private const RANK = [
        'public'     => 0,
        'looth-lite' => 1,
        'looth-pro'  => 2,
        'admin'      => 99,
    ];

    /** Canonical list of tier names blocks can declare. */
    public const TIERS = ['public', 'looth-lite', 'looth-pro', 'admin'];

    /** WP role → canonical tier mapping. The Arbiter writes one of these
     *  four roles to every paid user; for gating we collapse to the
     *  taxonomy slugs blocks declare. See
     *  /home/ubuntu/projects/docs/STRANGLER-COORDINATION.md §1 — looth1
     *  is the Arbiter's resting state (no paid entitlement) so it maps
     *  to public, looth4 is comp/VIP and gets pro for gating purposes. */
    public const ROLE_TIERS = [
        'looth1' => 'public',
        'looth2' => 'looth-lite',
        'looth3' => 'looth-pro',
        'looth4' => 'looth-pro',
    ];

    /** Translate a user's WP role list into canonical tier slugs. Returns
     *  an array because a user can in principle hold more than one looth-N
     *  role (shouldn't, but we union them rather than pick arbitrarily so
     *  the highest wins through the rank check in satisfies()). Roles
     *  outside ROLE_TIERS contribute nothing. */
    public static function tiersFromRoles(array $roles): array
    {
        $out = [];
        foreach ($roles as $role) {
            if (!is_string($role)) continue;
            if (isset(self::ROLE_TIERS[$role])) $out[self::ROLE_TIERS[$role]] = true;
        }
        return array_keys($out);
    }

    /**
     * @param array $viewer {
     *     @type bool        $is_admin       admin role (bypasses gating unless preview_role is set)
     *     @type bool        $is_delinquent  billing has lapsed; tiers downgrade to public
     *     @type string[]    $tiers          taxonomy memberships (e.g. ['looth-lite', 'looth-pro'])
     *     @type string|null $preview_role   admin override: render as if viewer were this role
     * }
     */
    public static function satisfies(array $viewer, string $required): bool
    {
        if (!in_array($required, self::TIERS, true)) return false;
        if ($required === 'public') return true;

        /* Admin previewing as a specific role: act as that role, no admin bypass. */
        $previewRole = $viewer['preview_role'] ?? null;
        if ($previewRole !== null && in_array($previewRole, self::TIERS, true)) {
            return self::satisfies(self::asRole($previewRole), $required);
        }

        if (!empty($viewer['is_admin']) && $required !== 'admin') return true;
        if ($required === 'admin') return !empty($viewer['is_admin']);

        $effectiveTiers = !empty($viewer['is_delinquent']) ? ['public'] : ($viewer['tiers'] ?? []);
        $effectiveRank = 0;
        foreach ($effectiveTiers as $t) {
            $effectiveRank = max($effectiveRank, self::RANK[$t] ?? 0);
        }

        return $effectiveRank >= (self::RANK[$required] ?? 99);
    }

    /** Build a synthetic viewer for a named role (used by preview-as). */
    private static function asRole(string $role): array
    {
        return [
            'is_admin'      => $role === 'admin',
            'is_delinquent' => false,
            'tiers'         => $role === 'public' ? [] : [$role],
            'preview_role'  => null,
        ];
    }

    /** Convenience: the default "anonymous public" viewer. */
    public static function anonymous(): array
    {
        return ['is_admin' => false, 'is_delinquent' => false, 'tiers' => [], 'preview_role' => null];
    }
}
