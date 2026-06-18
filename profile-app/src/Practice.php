<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

use PDO;

/**
 * Practice read/write helpers. A practice is an org-style entity with a
 * many-to-many relation to users via `practice_members`.
 */
final class Practice
{
    public const LOCATION_VIS_VALUES = ['public', 'members', 'private'];
    public const ROLES = ['owner', 'staff'];

    /** Load a practice row by slug. Returns null if not found or archived. */
    public static function loadBySlug(string $slug): ?array
    {
        $s = Db::pg()->prepare('SELECT * FROM practices WHERE slug = :s AND archived_at IS NULL');
        $s->execute([':s' => $slug]);
        $row = $s->fetch();
        return $row ? self::shape($row) : null;
    }

    public static function loadByUuid(string $uuid): ?array
    {
        $s = Db::pg()->prepare('SELECT * FROM practices WHERE uuid = :u AND archived_at IS NULL');
        $s->execute([':u' => $uuid]);
        $row = $s->fetch();
        return $row ? self::shape($row) : null;
    }

    public static function loadById(int $id): ?array
    {
        $s = Db::pg()->prepare('SELECT * FROM practices WHERE id = :i AND archived_at IS NULL');
        $s->execute([':i' => $id]);
        $row = $s->fetch();
        return $row ? self::shape($row) : null;
    }

    /** List the staff roster for a practice, in sort_order. */
    public static function members(int $practiceId): array
    {
        $s = Db::pg()->prepare('
            SELECT u.id, u.uuid, u.slug, u.display_name, u.avatar_url, pm.role, pm.sort_order
            FROM practice_members pm JOIN users u ON u.id = pm.user_id
            WHERE pm.practice_id = :p
            ORDER BY pm.sort_order, pm.added_at
        ');
        $s->execute([':p' => $practiceId]);
        $out = [];
        while ($r = $s->fetch()) {
            $out[] = [
                'id'           => (int)$r['id'],
                'uuid'         => $r['uuid'],
                'slug'         => $r['slug'] ?: ((string)(int)$r['id']),
                'display_name' => $r['display_name'],
                'avatar_url'   => $r['avatar_url'],
                'role'         => $r['role'],
            ];
        }
        return $out;
    }

    /** List the practices a user is attached to. */
    public static function forUser(int $userId): array
    {
        $s = Db::pg()->prepare('
            SELECT p.*, pm.role, pm.sort_order AS member_sort_order
            FROM practice_members pm JOIN practices p ON p.id = pm.practice_id
            WHERE pm.user_id = :u AND p.archived_at IS NULL
            ORDER BY pm.sort_order, pm.added_at
        ');
        $s->execute([':u' => $userId]);
        $out = [];
        while ($r = $s->fetch()) {
            $shape = self::shape($r);
            $shape['role'] = $r['role'];
            $out[] = $shape;
        }
        return $out;
    }

    /** Membership lookup. Returns role or null. */
    public static function userRole(int $practiceId, int $userId): ?string
    {
        $s = Db::pg()->prepare('SELECT role FROM practice_members WHERE practice_id=:p AND user_id=:u');
        $s->execute([':p' => $practiceId, ':u' => $userId]);
        $r = $s->fetchColumn();
        return $r === false ? null : (string)$r;
    }

    /** Find a free slug derived from $name. Returns the chosen slug. */
    public static function uniqueSlug(string $name, ?int $excludeId = null): string
    {
        $base = self::slugify($name);
        if ($base === '') $base = 'practice';
        $pg = Db::pg();
        $slug = $base;
        $i = 2;
        while (true) {
            $s = $pg->prepare('SELECT id FROM practices WHERE slug = :s');
            $s->execute([':s' => $slug]);
            $row = $s->fetch();
            if (!$row || ($excludeId !== null && (int)$row['id'] === $excludeId)) return $slug;
            $slug = $base . '-' . $i;
            $i++;
            if ($i > 999) return $base . '-' . bin2hex(random_bytes(3));
        }
    }

    public static function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    /**
     * Render a practice for a viewer. Hides location if visibility forbids.
     * Membership of the viewer is checked via userRole() upstream.
     */
    public static function renderForViewer(array $p, int $viewerUserId): array
    {
        $vis = $p['location_visibility'] ?? 'public';
        $canSeeLoc = match ($vis) {
            'public'  => true,
            'members' => $viewerUserId !== 0,
            'private' => false,
            default   => false,
        };
        if (!$canSeeLoc || empty($p['location_text'])) {
            $location = ['visibility' => $vis, 'hidden' => true];
        } else {
            $location = [
                'visibility' => $vis,
                'text'       => $p['location_text'],
                'lat'        => $p['lat'] !== null ? (float)$p['lat'] : null,
                'lng'        => $p['lng'] !== null ? (float)$p['lng'] : null,
                'country'    => $p['location_country'],
                'region'     => $p['location_region'],
                'city'       => $p['location_city'],
                'postcode'   => $p['location_postcode'],
            ];
        }
        return [
            'uuid'        => $p['uuid'],
            'slug'        => $p['slug'],
            'name'        => $p['name'],
            'tagline'     => $p['tagline'],
            'about'       => $p['about'],
            'website'     => $p['website'],
            'avatar_url'  => $p['avatar_url'],
            'location'    => $location,
            'created_at'  => $p['created_at'],
        ];
    }

    private static function shape(array $r): array
    {
        return [
            'id'                  => (int)$r['id'],
            'uuid'                => $r['uuid'],
            'slug'                => $r['slug'],
            'name'                => $r['name'],
            'tagline'             => $r['tagline'],
            'about'               => $r['about'],
            'website'             => $r['website'],
            'location_text'       => $r['location_text'],
            'lat'                 => $r['lat'],
            'lng'                 => $r['lng'],
            'location_country'    => $r['location_country'],
            'location_region'     => $r['location_region'],
            'location_city'       => $r['location_city'],
            'location_postcode'   => $r['location_postcode'],
            'location_visibility' => $r['location_visibility'],
            'avatar_url'          => $r['avatar_url'],
            'created_at'          => $r['created_at'],
            'updated_at'          => $r['updated_at'],
        ];
    }
}
