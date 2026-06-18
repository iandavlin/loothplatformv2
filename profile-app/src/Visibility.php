<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * Visibility — THE single visibility decision point (Ian 6/12 refactor).
 *
 * Every read surface asks this class — u.php SSR, the directory list + pin
 * feeds, pins-public, user/users APIs, the hub search mask, and the
 * /profile-media file store. No surface carries its own copy of the rules;
 * Profile::canSee and Block::gateDecision stay as thin call-throughs for the
 * existing renderer plumbing.
 *
 * The model (Ian's 6/12 rulings — do not relitigate):
 *  1. MASTER SWITCH users.profile_visibility 'public'|'private'. Private =
 *     OWNER-ONLY: invisible to members too (page, directory, map, search,
 *     files); admins excepted. ONE DIAL (Ian 6/12 pm): the existing
 *     profile-visibility chip's 'private' state IS this switch — me-header
 *     writes both columns; nothing new for members to learn.
 *  2. Imported / never-touched members default MEMBERS-ONLY. The public
 *     finder is explicit opt-in: location_public_precision <> 'private'.
 *  3. Location keeps the two-audience precision dials
 *     (members/public × street/city/state/private).
 *  4. Admins see everything — with two standing exceptions that hold even
 *     for admins: a location dialed members-'private' hides the pin, and a
 *     Location section removed from the layout is off-map for everyone.
 *
 * Viewer struct (resolve once per request via viewer()):
 *   ['id' => int (0 = anonymous), 'uuid' => ?string, 'admin' => bool]
 */
final class Visibility
{
    /** Identity chrome — always servable (forum bylines, comments, messages). */
    private const FILE_PUBLIC_CLASSES = ['avatars', 'banners'];

    private static ?array $viewer = null;

    /** The current HTTP viewer, resolved once. */
    public static function viewer(): array
    {
        if (self::$viewer !== null) return self::$viewer;
        $u = Auth::currentUser();
        self::$viewer = [
            'id'    => $u ? (int)$u['id'] : 0,
            'uuid'  => $u ? strtolower((string)$u['uuid']) : null,
            'admin' => $u ? Auth::isAdmin() : false,
        ];
        return self::$viewer;
    }

    /** Audience of $viewer relative to a subject: 'owner'|'admin'|'member'|'public'. */
    public static function audience(array $viewer, int $subjectId): string
    {
        if ($viewer['id'] !== 0 && $viewer['id'] === $subjectId) return 'owner';
        if (!empty($viewer['admin']))                            return 'admin';
        return $viewer['id'] !== 0 ? 'member' : 'public';
    }

    /** Renderer-vocabulary role ('me'|'admin'|'member'|'public') for the block pipeline. */
    public static function role(array $viewer, int $subjectId): string
    {
        $aud = self::audience($viewer, $subjectId);
        return $aud === 'owner' ? 'me' : $aud;
    }

    /**
     * MASTER SWITCH — may this viewer know the subject exists at all?
     * Gates the /u/ page, directory cards AND teaser dots, map pins, search
     * hits, batch identity lookups, and gated file classes.
     * $subject: user id, or a users row already carrying profile_visibility
     * (pass the row in list loops — no per-row query).
     */
    public static function profileVisible(array $viewer, int|array $subject): bool
    {
        $subjectId = is_array($subject) ? (int)$subject['id'] : $subject;
        $aud = self::audience($viewer, $subjectId);
        if ($aud === 'owner' || $aud === 'admin') return true;

        if (is_array($subject) && array_key_exists('profile_visibility', $subject)) {
            return (string)$subject['profile_visibility'] !== 'private';
        }
        $s = Db::pg()->prepare('SELECT profile_visibility FROM users WHERE id = :i');
        $s->execute([':i' => $subjectId]);
        return (string)$s->fetchColumn() !== 'private';
    }

    /**
     * Audience × visibility truth table — the ONE copy.
     * $aud: owner|admin|member|public (renderer roles 'me' accepted as owner).
     * $vis: public|members|private (DB literals; UI 'member' tolerated).
     */
    public static function audienceCanSee(string $aud, string $vis): bool
    {
        if ($aud === 'owner' || $aud === 'me' || $aud === 'admin') return true;
        $vis = $vis === 'member' ? 'members' : $vis;
        if ($aud === 'member') return $vis === 'public' || $vis === 'members';
        return $vis === 'public';                                  // anonymous
    }

    /**
     * A section/block on the subject's profile. Combines the master switch,
     * the header ceiling, and the block's own visibility (effective = the
     * more restrictive of the two). 'resume' reads users.resume_visibility
     * (singleton column, not a profile_sections row).
     */
    public static function sectionVisible(array $viewer, int $subjectId, string $key): bool
    {
        if (!self::profileVisible($viewer, $subjectId)) return false;
        $aud = self::audience($viewer, $subjectId);
        if ($aud === 'owner' || $aud === 'admin') return true;

        $ceiling = Block::headerCeiling($subjectId);               // DB literal
        if ($key === 'header') return self::audienceCanSee($aud, $ceiling);

        if ($key === 'resume') {
            $s = Db::pg()->prepare('SELECT resume_visibility FROM users WHERE id = :i');
            $s->execute([':i' => $subjectId]);
            $blockVis = (string)($s->fetchColumn() ?: 'members');
        } else {
            $blockVis = Block::blockVisibility($subjectId, $key);  // default 'members'
        }
        return self::audienceCanSee($aud, Block::effectiveVisibility($ceiling, $blockVis));
    }

    /**
     * Pure precision rule — what location detail an audience gets. Shared by
     * the location block, dir_member_display, and both pin feeds, so a pin can
     * never out-resolve a card. Returns street|city|state|private.
     */
    public static function precisionForAudience(string $aud, ?string $membersPrec, ?string $publicPrec): string
    {
        if ($aud === 'owner' || $aud === 'me') return 'street';
        $mp = Block::precisionFromInput($membersPrec) ?? 'city';    // never-touched default: members see city
        if ($aud === 'admin') return $mp === 'private' ? 'private' : 'street';
        if ($aud === 'member') return $mp;
        $pp = Block::precisionFromInput($publicPrec) ?? 'private';  // never-touched default: NOT on the public finder
        // Public never sees more than members (a members-private location is
        // off the map for everyone below admin — teaser dots included).
        return $mp === 'private' ? 'private' : $pp;
    }

    /**
     * Resolved precision for a users row. Expects the row to carry
     * location_members_precision / location_public_precision and (when
     * available) profile_visibility + loc_on_profile (layout flag).
     */
    public static function locationPrecision(array $viewer, array $row): string
    {
        if (!self::profileVisible($viewer, $row)) return 'private';
        if (array_key_exists('loc_on_profile', $row) && empty($row['loc_on_profile'])) {
            return 'private';                                       // section off the layout = off-map for everyone
        }
        return self::precisionForAudience(
            self::audience($viewer, (int)$row['id']),
            $row['location_members_precision'] ?? null,
            $row['location_public_precision'] ?? null
        );
    }

    /**
     * File-store decision (/profile-media/<class>/<uuid>/<file>).
     * avatars/banners = identity chrome, always servable. gallery follows the
     * gallery section's visibility; resumes follow users.resume_visibility;
     * both inherit the master switch. Unknown classes fail closed.
     */
    public static function fileVisible(array $viewer, string $class, string $subjectUuid): bool
    {
        if (in_array($class, self::FILE_PUBLIC_CLASSES, true)) return true;

        $s = Db::pg()->prepare('SELECT id FROM users WHERE uuid = :u');
        $s->execute([':u' => strtolower($subjectUuid)]);
        $subjectId = (int)$s->fetchColumn();
        if ($subjectId < 1) return false;

        switch ($class) {
            case 'gallery': return self::sectionVisible($viewer, $subjectId, 'gallery');
            case 'resumes': return self::sectionVisible($viewer, $subjectId, 'resume');
            default:        return false;                           // unknown class: closed
        }
    }

    /**
     * Spec entry point — Visibility::can(viewer, subjectId, what).
     *   what: 'profile' | 'section:<key>' | 'location' | 'file:<class>:<uuid>'
     */
    public static function can(array $viewer, int $subjectId, string $what): bool
    {
        if ($what === 'profile') return self::profileVisible($viewer, $subjectId);
        if ($what === 'location') {
            $s = Db::pg()->prepare(
                "SELECT id, profile_visibility, location_members_precision, location_public_precision,
                        (profile_layout IS NULL OR profile_layout @> '[\"location\"]'::jsonb) AS loc_on_profile
                   FROM users WHERE id = :i");
            $s->execute([':i' => $subjectId]);
            $row = $s->fetch();
            return $row ? self::locationPrecision($viewer, $row) !== 'private' : false;
        }
        if (str_starts_with($what, 'section:')) {
            return self::sectionVisible($viewer, $subjectId, substr($what, 8));
        }
        if (str_starts_with($what, 'file:')) {
            $p = explode(':', $what, 3);
            return isset($p[2]) && self::fileVisible($viewer, $p[1], $p[2]);
        }
        return false;                                               // unknown ask: closed
    }
}
