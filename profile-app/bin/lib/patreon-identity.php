<?php
declare(strict_types=1);

/**
 * patreon-identity.php — the campaign members sweep, lifted verbatim in behaviour from
 * backfill-patreon-handles-dryrun.php (mentions lane, 2026-07-25; that run pulled 3,765
 * users across 19 pages) so there is one copy of the pagination + field selection.
 *
 * ITS ROLE CHANGED, 2026-07-27. It is no longer the NAME SOURCE — the slug is the
 * profile display name, cleaned (Ian). This is now the COLLISION RESOLVER: when two
 * members clean down to the same handle, or a bare first name is heading for one, we
 * ask Patreon for a fuller identity and derive something distinguishing from it
 * (`dave-thurston`), instead of minting `dave2` / `dave3`.
 *
 * That is why the creator-token dependency is acceptable again: it is consulted for the
 * ~47 contested members, not for all 1,633.
 *
 * Returns [ patreon_user_id => ['full_name'=>, 'vanity'=>, 'email'=>] ].
 * Never throws — on any failure it returns [] and sets $status, because a missing API
 * must degrade to "cannot expand this collision" (visible, reported), never to a crash
 * mid-migration.
 */

function looth_patreon_identity_sweep(PDO $wpDb, ?string &$status = null): array
{
    $api = [];
    try {
        $opt = function (string $k) use ($wpDb): string {
            $st = $wpDb->query('SELECT option_value FROM wp_options WHERE option_name = ' . $wpDb->quote($k));
            return (string) ($st->fetchColumn() ?: '');
        };
        $token    = $opt('lgpo_creator_access_token');
        $campaign = $opt('lgpo_campaign_id');
        if ($token === '' || $campaign === '') {
            throw new RuntimeException('missing lgpo_creator_access_token / lgpo_campaign_id in wp_options');
        }

        $cursor = null;
        $pages  = 0;
        do {
            $params = [
                'include'        => 'user',
                'fields[member]' => 'email,full_name,patron_status',
                'fields[user]'   => 'email,full_name,vanity',
                'page[count]'    => 200,
            ];
            if ($cursor !== null) $params['page[cursor]'] = $cursor;

            $ch = curl_init('https://www.patreon.com/api/oauth2/v2/campaigns/'
                . rawurlencode($campaign) . '/members?' . http_build_query($params));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code !== 200) throw new RuntimeException("members sweep http=$code page=$pages");

            $j = json_decode((string) $body, true);
            foreach (($j['included'] ?? []) as $inc) {
                if (($inc['type'] ?? '') !== 'user') continue;
                $api[(string) $inc['id']] = [
                    'full_name' => (string) ($inc['attributes']['full_name'] ?? ''),
                    'vanity'    => (string) ($inc['attributes']['vanity'] ?? ''),
                    'email'     => (string) ($inc['attributes']['email'] ?? ''),
                ];
            }
            // member-level fallback keyed by the related user id
            foreach (($j['data'] ?? []) as $m) {
                $uid = (string) ($m['relationships']['user']['data']['id'] ?? '');
                if ($uid === '') continue;
                if (($api[$uid]['full_name'] ?? '') === '') $api[$uid]['full_name'] = (string) ($m['attributes']['full_name'] ?? '');
                if (($api[$uid]['email'] ?? '') === '')     $api[$uid]['email']     = (string) ($m['attributes']['email'] ?? '');
                $api[$uid]['vanity'] = $api[$uid]['vanity'] ?? '';
            }

            $cursor = $j['meta']['pagination']['cursors']['next'] ?? null;
            $pages++;
        } while ($cursor !== null && $pages < 100);

        $status = 'OK — ' . count($api) . " users across $pages pages";
    } catch (Throwable $e) {
        $status = 'UNAVAILABLE (' . $e->getMessage() . ') — collisions cannot be expanded';
        return [];
    }
    return $api;
}
