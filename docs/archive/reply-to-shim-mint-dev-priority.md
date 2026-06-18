# Coordinator → shim-replacement (d9380b73): your mint path now gates profile-2.0 testing

Priority context you didn't have: your `/mint-token` work isn't just the cut-day
"fast first experience" anymore — it's now the **gate for proving (testing) the
entire profile-app authed `/me` surface.**

## What happened
profile-2.0 built spine increment 1 (the profile-header block) and it's logic-tested
green (schema applied, ceiling math, assemble, render/gate branches, write +
validation + `member↔members` normalize). But the **authed HTTP round-trip can't be
exercised** — to drive `PATCH /me/header` we need a valid `looth_id`, and there's no
way to mint one on dev yet:

- **The signing/mint path isn't usable on dev.** `Mint::mintForWpUserId()` throws
  `private key unreadable at /etc/looth/jwt-private.pem` — the key exists but is
  `root:looth-dev 0640`, and the **profile-app** DB lookup it needs is **peer-auth as
  the `profile-app` role**. No single user has *both* the key (looth-dev group) and
  the DB (profile-app role). So minting a token requires your endpoint's context.

## Ask
1. **Prioritize a working DEV mint path** — `/mint-token` (or an equivalent) that can
   actually mint a `looth_id` on dev — **ahead of the full cut-day flip.** Until it
   works, NO authed profile-app `/me` endpoint can earn the "dev-complete + tested"
   stamp (per Ian's invariant) — not just header, the whole surface.
2. **Resolve the key-vs-DB access tangle** in the mint path's design (the endpoint
   runs as a context that can read the key AND reach the profile_app DB / bridge).
3. While you're in `config.php`: profile-2.0 needs **`Block.php` added to its
   require list** (currently per-file `require_once`'d). `config.php` is shared —
   you own touching it; coordinate the add with this.

## Coordination
- You share `profile-app/Whoami.php` + `config.php` with profile-2.0 (`1c98b564`).
  Flag coordinator before touching; profile-2.0 is running spine increments in the
  same tree (serialize via coordinator — don't run a profile-app turn while one is live).
- This is additive to your existing plan, not a redesign — it's a sequencing bump.

— coordinator
