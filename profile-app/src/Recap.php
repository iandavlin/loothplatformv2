<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * Recap — "your week", read-side, for the weekly digest email.
 *
 * The mirror image of Notifications::pushHubEvent(). The bell is written through
 * one door (internal-notify.php, from WP over loopback); it is READ back for the
 * digest through one door too, because WordPress still cannot reach this database:
 * the WP pool runs as `looth-dev`, which holds zero grants on `profile_app`. Same
 * constraint, same shape, opposite direction.
 *
 * THE SECTION IS "WHAT YOU MISSED", NOT "YOUR WEEK" (Ian, refined 2026-07-27).
 * Anything already dealt with must not appear — a notification looked at, a
 * connection made or actioned, a message read. Only fresh, still-outstanding items
 * go out. Three of those are free from columns that already exist, and all three
 * are enforced HERE, at the source, so every caller gets the same answer and no
 * renderer can widen them by accident:
 *
 *   looked at       -> notifications.is_read (the bell's own markRead maintains it)
 *   message read    -> message_recipients.unread_count + last_read_at
 *   connection made -> connections.status, read LIVE rather than trusted from the
 *                      bell row (see the notification query below)
 *
 * A FOURTH IS NOT FREE AND IS DELIBERATELY NOT BUILT: an item the member already
 * clicked through FROM a previous digest. Nothing marks a notification read when
 * someone clicks a link in the EMAIL — recap links go straight to /hub/?topic= and
 * never touch is_read (verified: markRead is called ONLY from /me-notifications,
 * the bell modal). So an email-only reader keeps seeing the same items, which is
 * the nag this section exists to kill. The obvious fix is a click-clear redirect,
 * and it is NOT here on purpose: mail-security scanners follow every link with no
 * human involved, and on this platform's OWN click data 7-10% of apparent clickers
 * are machines hitting 10-20 links inside four seconds. A GET that cleared items
 * would wipe their whole recap before they opened it, and the failure would look
 * exactly like the feature working. Awaiting Ian's ruling — WEEKLY-DIGEST-RECAP.md §9.
 *
 * A FIFTH WAS MEASURED, COSTED AND DECLINED: an item already sent in a PER-EVENT
 * email. Read state cannot catch it — an item can be unread in the bell and still
 * have been emailed — so it is a genuinely separate axis, and the correct instrument
 * would be a `notifications.emailed_at` stamp (NOT a scrape of wp_fsmpt_email_logs,
 * which retains 14 days, matches on subject string, and records a recipient address
 * rather than which item the mail was about). It is not built because the surface
 * does not exist yet. Measured on LIVE 2026-07-27:
 *
 *   - connections, DMs and reactions have NO per-event email sender AT ALL — they
 *     are our features in this database, and nothing emails about them. That is 87%
 *     of listable recap items with a structurally impossible overlap.
 *   - forums are the only surface BuddyBoss owns, and exactly THREE `forum.*` bell
 *     rows have ever existed on live (ids 789, 790, 825).
 *   - overlappable outbound mail: 5 sends in 14 days, list size 1,858.
 *
 * The overlap rate itself is UNTESTED, not measured-as-zero: the last overlappable
 * email (07-24 22:39) predates the first bell row of a type that could overlap it
 * (07-25 02:39) by four hours, so the comparable window contains zero events. The
 * upper bound holds; the rate is unknown. Do not restate this as "no overlap".
 * Triggers to revisit, and the script that evaluates them, are in
 * docs/atlas/RECAP-SUPPRESSION-PROPOSAL.md §2 / dev/measure-suppression-axes.sh.
 *
 * CONSEQUENCE WORTH STATING: the EMPTY section is now the COMMON case for anyone
 * who keeps up, not an edge case. It must render clean — and it does: the renderer
 * returns '' and the smart code emits nothing at all.
 *
 * WHAT THIS DELIBERATELY DOES NOT RETURN: message bodies, reply text, or any other
 * content. Counts, actors and links only (the privacy ruling). The SELECT lists
 * are the enforcement — `messages.body` is never in one.
 *
 * TITLES ARE NOT RESOLVED HERE. A notification's `target_id` is a WP post id, and
 * WP is the side that can cheaply turn that into a title (get_the_title). Sending
 * ids back and letting the caller hydrate avoids a cross-database join that
 * Postgres could not do anyway — `forums.topic` lives in the OTHER database
 * (`looth`), not this one.
 */
final class Recap
{
    /** Hard ceiling per member — a runaway week cannot make one recipient's payload huge. */
    private const MAX_ROWS = 50;

    /**
     * Recap material for one or more members, keyed by WP user id.
     *
     * Unbridged wp ids (the shared anonymous-posting account, an unprovisioned
     * member) simply do not appear in the result — the same "skipped: unbridged"
     * posture internal-notify.php takes, expressed as absence rather than an error.
     *
     * @param int[] $wpUserIds
     * @return array<int, array{display_name: string, notifications: list<array>, dms: list<array>}>
     */
    public static function forWpIds(array $wpUserIds, int $days = 7): array
    {
        $wpUserIds = array_values(array_unique(array_filter(
            array_map('intval', $wpUserIds),
            static fn(int $i): bool => $i > 0
        )));
        if (!$wpUserIds) return [];

        $days = max(1, min(90, $days));
        $pg   = Db::pg();

        // wp id ↔ uuid, both directions — the caller speaks wp ids, the store uuids.
        //
        // display_name comes back with them because the digest greets the member by
        // the name their PROFILE shows. It has to be THIS column: not WP's
        // user_login, not a Patreon handle, not user_nicename. A member who set
        // their own name must be greeted by it, and this row is what /u/ renders.
        $ph   = implode(',', array_fill(0, count($wpUserIds), '?'));
        $st   = $pg->prepare(
            "SELECT b.wp_user_id, u.uuid, u.display_name FROM users u
               JOIN wp_user_bridge b ON b.user_id = u.id
              WHERE b.wp_user_id IN ($ph)"
        );
        $st->execute($wpUserIds);

        $uuidByWp = [];
        $wpByUuid = [];
        $out      = [];
        foreach ($st->fetchAll() as $r) {
            $wp = (int)$r['wp_user_id'];
            $uuidByWp[$wp]                = (string)$r['uuid'];
            $wpByUuid[(string)$r['uuid']] = $wp;
            $out[$wp] = [
                'display_name'  => (string)($r['display_name'] ?? ''),
                'notifications' => [],
                'dms'           => [],
            ];
        }
        if (!$uuidByWp) return [];

        $uuids = array_values($uuidByWp);
        $uph   = implode(',', array_fill(0, count($uuids), '?'));

        // ── Bell rows: unread, in window ──────────────────────────────────────
        // No body/content column exists on this table, so there is nothing here to
        // leak even by accident; actor identity is hydrated the same way the bell's
        // own listFor() does it, from the LIVE users row rather than a stored copy,
        // so a member who renamed themselves reads correctly in the email.
        // "WHAT YOU MISSED", not "your week" (Ian, 2026-07-27). Anything the member
        // has already dealt with must not appear, and `is_read` alone does not carry
        // that: a connection row stays unread in the bell even after the member has
        // gone to the profile and accepted. Measured on this box — 3 unread
        // `connection_request` rows whose connection is already `accepted`. Those
        // would have told a member "X wants to connect" about someone they are
        // ALREADY connected to, which is precisely the nag this section exists to
        // kill. So the edge's own live status is the authority, not the bell row:
        //
        //   connection_request  -> only while the edge is still `pending` (still yours to action)
        //   connection_accept   -> only while the edge is still `accepted` (still true)
        //
        // Hub rows have no connection_id and are unaffected; their "already dealt
        // with" signal is `is_read`, which the bell maintains.
        $sql = "SELECT n.user_uuid, n.type, n.target_kind, n.target_id, n.anchor_id,
                       n.target_url, n.actor_count, n.created_at,
                       a.display_name AS actor_name, a.slug AS actor_slug
                  FROM notifications n
                  LEFT JOIN users a ON a.uuid = n.actor_uuid
                  LEFT JOIN connections c ON c.id = n.connection_id
                 WHERE n.user_uuid IN ($uph)
                   AND n.is_read = false
                   AND n.created_at >= now() - make_interval(days => ?)
                   AND (
                         n.connection_id IS NULL
                         OR (n.type = 'connection_request' AND c.status = 'pending')
                         OR (n.type = 'connection_accept'  AND c.status = 'accepted')
                       )
                 ORDER BY n.user_uuid, n.created_at DESC";
        $st = $pg->prepare($sql);
        $st->execute(array_merge($uuids, [$days]));

        foreach ($st->fetchAll() as $r) {
            $wp = $wpByUuid[(string)$r['user_uuid']] ?? null;
            if ($wp === null || count($out[$wp]['notifications']) >= self::MAX_ROWS) continue;
            $out[$wp]['notifications'][] = [
                'type'        => (string)$r['type'],
                'target_kind' => $r['target_kind'],
                'target_id'   => $r['target_id'] !== null ? (int)$r['target_id'] : null,
                'anchor_id'   => $r['anchor_id'] !== null ? (int)$r['anchor_id'] : null,
                'target_url'  => $r['target_url'],
                'actor_count' => (int)$r['actor_count'],
                'actor_name'  => $r['actor_name'],
                'actor_slug'  => $r['actor_slug'],
                'created_at'  => $r['created_at'],
            ];
        }

        // ── Unread DMs ────────────────────────────────────────────────────────
        // Senders are scoped to the messages the member has NOT read (created after
        // their last_read_at), not to everyone who ever posted in the thread — the
        // row says who is waiting on them, and naming someone whose message they
        // already read would be a lie in a one-line summary.
        //
        // The window is applied to last_message_at: a thread that has been sitting
        // unread for two months is old news, not "your week", and resurfacing it
        // every Monday forever is exactly the nagging Ian ruled out.
        $sql = "SELECT r.user_uuid, t.uuid AS thread_uuid, r.unread_count, t.last_message_at,
                       s.names, s.slugs
                  FROM message_recipients r
                  JOIN message_threads t ON t.id = r.thread_id
                  LEFT JOIN LATERAL (
                        SELECT array_agg(DISTINCT su.display_name) AS names,
                               array_agg(DISTINCT su.slug)         AS slugs
                          FROM messages m
                          JOIN users su ON su.uuid = m.sender_uuid
                         WHERE m.thread_id = t.id
                           AND m.sender_uuid <> r.user_uuid
                           AND m.deleted_at IS NULL
                           AND (r.last_read_at IS NULL OR m.created_at > r.last_read_at)
                  ) s ON true
                 WHERE r.user_uuid IN ($uph)
                   AND r.unread_count > 0
                   AND r.is_deleted = false
                   AND t.last_message_at >= now() - make_interval(days => ?)
                 ORDER BY r.unread_count DESC";
        $st = $pg->prepare($sql);
        $st->execute(array_merge($uuids, [$days]));

        foreach ($st->fetchAll() as $r) {
            $wp = $wpByUuid[(string)$r['user_uuid']] ?? null;
            if ($wp === null) continue;
            $names = self::pgArray($r['names']);
            $slugs = self::pgArray($r['slugs']);
            if (!$names) continue;                 // every unread message was deleted
            $out[$wp]['dms'][] = [
                'thread_uuid'     => (string)$r['thread_uuid'],
                'unread'          => (int)$r['unread_count'],
                'senders'         => $names,
                'sender_slugs'    => $slugs,
                'last_message_at' => $r['last_message_at'],
            ];
        }

        return $out;
    }

    /**
     * Postgres text[] literal → PHP list. PDO hands array_agg back as the raw
     * `{a,b}` literal, not an array, and display names contain commas and quotes
     * ("Doug Proper - Guitar Specialist, Inc.") — so this parses the literal
     * properly rather than exploding on ','.
     */
    private static function pgArray(?string $lit): array
    {
        if ($lit === null || $lit === '' || $lit === '{}' || $lit === '{NULL}') return [];
        $out = [];
        $len = strlen($lit);
        $i   = 1;                                   // skip '{'
        while ($i < $len && $lit[$i] !== '}') {
            if ($lit[$i] === '"') {                 // quoted element
                $i++;
                $buf = '';
                while ($i < $len && $lit[$i] !== '"') {
                    if ($lit[$i] === '\\') $i++;
                    $buf .= $lit[$i];
                    $i++;
                }
                $i++;                               // closing quote
                $out[] = $buf;
            } else {                                // bare element up to , or }
                $buf = '';
                while ($i < $len && $lit[$i] !== ',' && $lit[$i] !== '}') {
                    $buf .= $lit[$i];
                    $i++;
                }
                if ($buf !== 'NULL' && $buf !== '') $out[] = $buf;
            }
            if ($i < $len && $lit[$i] === ',') $i++;
        }
        return $out;
    }
}
