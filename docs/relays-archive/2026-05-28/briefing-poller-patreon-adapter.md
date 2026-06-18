# Coordinator → poller: build the Patreon adapter

## What this is

BATCH-04 + BATCH-04B landed. You now have everything needed to build the
Patreon adapter. This briefing is self-contained — no further clarification
needed before you start.

## Decision recap (B-now/A-later)

- **B-now:** Poller ships with Patreon adapter. Arbiter reads Patreon-sourced
  tier from existing WP state. No Patreon API calls from the poller.
- **A-later:** Stripe adapter wired in when Stripe creds are provisioned on live.
  Stripe dormant at cutover.

## What the adapter does NOT do

It does NOT call the Patreon API. `lg-patreon-onboard` already does that on
its own cron (daily by default, configurable). The adapter reads the state
LGPO has already written to WP usermeta and user roles.

## Data model — what LGPO writes

### Usermeta keys

| Key | Value | Meaning |
|---|---|---|
| `payment_source` | `'patreon'` | This user's active tier is Patreon-sourced |
| `payment_source` | `'stripe'` | Stripe-sourced (poller manages these directly) |
| `payment_source` | absent | looth1 / no active paid source |
| `lgpo_patreon_tier_id` | Patreon tier ID string | Specific tier driving the role |
| `lgpo_patreon_user_id` | Patreon user ID string | Links WP account to Patreon identity |

### Role written by LGPO

`$user->set_role($new_role)` — sets exactly one role. After LGPO runs, the
user has exactly one of: `looth1`, `looth2`, `looth3` (looth4 is never
touched by LGPO — always skipped).

### Coexistence guard (already in LGPO)

LGPO skips users with `payment_source=stripe` + looth2/3. This means:
- If Stripe owns a user, LGPO won't downgrade them.
- If Patreon owns a user (`payment_source=patreon`), LGPO manages their role.

The adapter mirrors this same guard — Stripe-sourced users are not the
adapter's concern.

## Adapter read pattern

Given a `$wp_user_id`, the Patreon adapter returns the Patreon-attributed
source record:

```php
$payment_source = get_user_meta( $wp_user_id, 'payment_source', true );

if ( $payment_source !== 'patreon' ) {
    return null; // Not a Patreon-managed user — adapter has no opinion
}

$user = get_userdata( $wp_user_id );
$roles = (array) $user->roles;

// Determine current looth role (LGPO's last write)
$tier = 'looth1';
foreach ( [ 'looth3', 'looth2', 'looth1' ] as $r ) {
    if ( in_array( $r, $roles, true ) ) { $tier = $r; break; }
}

return [
    'source'   => 'patreon',
    'tier'     => $tier,
    'tier_id'  => get_user_meta( $wp_user_id, 'lgpo_patreon_tier_id', true ),
];
```

The Arbiter receives this alongside any Stripe source record and picks the
winner per the existing `RoleSourceWriter` priority logic.

## What to build

1. **`src/Patreon/PatreonSourceReader.php`** — the adapter class above.
   `readForUser(int $wp_user_id): ?array` returning the source record or
   null if not Patreon-managed.

2. **Wire into `RoleSourceWriter::readAllForUser()`** — add `patreon` as a
   source type alongside existing sources. The reader is read-only; it never
   writes.

3. **`InternalRestController` user-context response** — `provenance` derivation
   already handles `payment_source` implicitly via `deriveProvenance()`. Verify
   that a `payment_source=patreon` user with looth2/3 returns `provenance=paid`
   (not `lapsed` or `new`). If `deriveProvenance()` only looks at source rows
   and not usermeta, it needs a small update: treat `payment_source=patreon` +
   looth2/3 as a paid source.

4. **Smoke test** — find a looth2 or looth3 user on dev with
   `payment_source=patreon` (or set one manually) and verify:
   - `GET /wp-json/looth-internal/v1/user-context/{id}` returns
     `tier=lite` (looth2) or `tier=pro` (looth3), `provenance=paid`
   - `payment_source=stripe` user is untouched by the adapter

## One open question — answer it yourself, don't wait

**Does `deriveProvenance()` need updating?**

Check `src/Wp/InternalRestController.php::deriveProvenance()`. If it derives
provenance purely from `RoleSourceWriter::readAllForUser()` source rows, it
may return `lapsed` or `new` for Patreon users whose roles predate the
source-writer system (LGPO never writes source rows — it writes usermeta +
roles directly). Fix: in `deriveProvenance()`, if source rows are empty but
`payment_source=patreon` + looth2/3, return `paid`.

This is in-lane. You don't need coordinator sign-off to fix it.

## When done

Report back:

```
**poller → coordinator:** Patreon adapter live

- PatreonSourceReader wired into RoleSourceWriter
- deriveProvenance() updated: [yes/no, what changed]
- Smoke: user {id} → tier={x}, provenance=paid ✓
```

Path:
```
/home/ubuntu/projects/docs/SESSION-HANDOFF.md
```

— coordinator
