# exercise-harness — run UNMERGED branch code on dev2 without a serve window

Built by the thread-follow lane, 2026-07-29. See `docs/atlas/THREAD-FOLLOW-SPEC.md` §11.1.

## The problem it solves

A lane's code lives in `~/worktrees/<lane>`. The serving checkout `~/loothplatformv2-clean` is on
`main` and **only ever pulls** — everything on the box (`/srv/*`, the 33 mu-plugin symlinks, the
webroot overlay farm, the nginx confs) is symlinked out of it. Flipping it to a branch to "just take
a look" deletes files the running system needs; that is how nginx came up dead after a reboot on
2026-07-26.

So a lane that wants to *execute* its own code has historically asked for a "serve window". This
harness removes the need.

## The idea

Run the branch on **loopback `php -S`, one server per FPM pool, each as the SAME UNIX USER nginx
would use.** That last part is what makes it a real test rather than a lookalike — the app keeps its
production permissions posture, including the things it is *not* allowed to read.

| Pool user | Serves | Note |
|---|---|---|
| `bb-mirror` | the Hub (`bb-mirror/web`) | still cannot read `wp-load.php`, exactly as in prod |
| `looth-dev` | `bb-mirror/api/v0/*`, WP pages, mu-plugins | the WP pool |
| `profile-app` | `profile-app/api/v0/*` | peer-auth to the `profile_app` database |

Databases are the **real** dev2 ones. Nothing is mocked.

## Running it

```bash
BR=/home/ubuntu/worktrees/<lane>          # your worktree

# The Hub, as the Hub's own pool user. Emulates nginx `alias` + `try_files`, and
# proxies /bb-mirror-api/v0/follow to the WP-pool server below (see hub-router.php).
sudo -u bb-mirror env LG_BB_MIRROR_ENV=dev2 \
  php -S 127.0.0.1:8791 -t $BR/bb-mirror/web  $BR/tools/exercise-harness/hub-router.php &

# A bb-mirror API endpoint that needs WP, on the pool it is actually routed to.
sudo -u looth-dev env LG_BB_MIRROR_ENV=dev2 \
  php -S 127.0.0.1:8792 -t $BR/bb-mirror/api/v0 &
```

Copy the router out of the worktree first if the pool user cannot traverse your path
(`/home/ubuntu` is `drwxr-x--x`, so the *files* are readable but a scratch dir under
`/tmp/claude-*` is not — put helpers somewhere world-traversable such as `/tmp/<lane>-exercise/`).

### Authenticating as a real member

```bash
sudo -u looth-dev wp --path=/var/www/dev eval '
$uid = 1912; $exp = time() + 7*DAY_IN_SECONDS;   # claude_admin, the headless test account
echo LOGGED_IN_COOKIE . "=" . wp_generate_auth_cookie($uid,$exp,"logged_in") . "\n";
echo SECURE_AUTH_COOKIE . "=" . wp_generate_auth_cookie($uid,$exp,"secure_auth") . "\n";'
```

Pass those as a `Cookie:` header. The endpoint resolves the acting user through real WP auth,
so `get_current_user_id()`, nonces and capability checks all behave normally.

## Testing a mu-plugin — and the load-order trap

`unsub-router2.php` is the pattern. A mu-plugin cannot simply be `require`d after `wp-load.php`:
that registers its hooks **after every regular plugin**, so at equal priority it loses to things
like BuddyPress that it would beat in production. That difference is not cosmetic — it is exactly
how the thread-follow lane's unsubscribe page appeared broken (302 to `wp-login`) when it was fine,
and it is also how a real ordering bug hid *behind* that.

Instead, point `WPMU_PLUGIN_DIR` at a farm of symlinks to every real mu-plugin plus yours:

```bash
mkdir -p /tmp/<lane>-exercise/mu
for f in $(sudo ls /var/www/dev/wp-content/mu-plugins/); do
  ln -sfn "$(sudo readlink -f /var/www/dev/wp-content/mu-plugins/$f)" /tmp/<lane>-exercise/mu/$f
done
ln -sfn $BR/platform/mu-plugins/<yours>.php /tmp/<lane>-exercise/mu/<yours>.php
```

`define('WPMU_PLUGIN_DIR', …)` before `require wp-load.php` wins, because WP only defines it when
it is not already defined. The symlinks resolve to the same final targets as today, so `__DIR__`
inside each plugin is unchanged.

**Test both orders.** Faithful order tells you what production does; plugin-loaded-last tells you
whether you are relying on luck.

## Rules

- **Never** point any of this at `~/loothplatformv2-clean`, and never write to `/var/www/dev`.
- Kill the servers when done — each `php -S` that has loaded WP costs ~100-150MB, and dev2 runs
  close to its limit with several lanes up.
- Writes go to the **real** dev2 databases. Use `claude_admin` (1912), not a real member, and clean
  up anything synthetic.
