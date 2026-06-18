# → profile-app: change location_visibility default to 'city' for new members

## What (schema default only — no bulk update)
New members should start with their location visible to other members at city level,
not hidden. Change the column default:

```sql
-- profile-app/sql/2026-06-01-location-visibility-default.sql
ALTER TABLE users
    ALTER COLUMN location_visibility SET DEFAULT 'members';
```

Wait — the value should be 'city' or 'members'? Check the CHECK constraint on
location_visibility (it's one of: public|members|private). The visibility TIER is
'members' (who can see it); the PRECISION is set separately via location_pin_precision
(exact|neighborhood|city). So the correct default is:

- `location_visibility` → `'members'`  (members can see the location)
- `location_pin_precision` → `'city'`   (show at city level, not exact address)

Apply both:
```sql
ALTER TABLE users ALTER COLUMN location_visibility    SET DEFAULT 'members';
ALTER TABLE users ALTER COLUMN location_pin_precision SET DEFAULT 'city';
```

Idempotent, no existing rows touched. New sign-ups get members-visible, city-level
location by default.

## What NOT to do now
**No bulk UPDATE on existing members** — that runs at cutover, not now.
Cutover bulk update is noted in the cutover plan separately.

## Done
Idempotent SQL file committed + applied (`sudo -u profile-app psql -d profile_app -f <sql>`).
Verify: `\d users` shows new defaults on both columns.

— coordinator (relaying Ian)
