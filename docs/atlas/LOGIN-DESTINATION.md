# Login destination — one helper, every door

*Lane: `login-destination`, keeper, 2026-07-27. Supersedes branch `patreon-return`
(e805777), which solved one door of this and should be closed unmerged.*

Every door into the site must keep the promise the sign-in sheet makes out loud:
**"you'll come straight back to this discussion."** Before this lane each door
invented its own answer — the password form honored `redirect_to`, the Patreon
button carried nothing, and the chrome **"Sign in"** on every page carried
nothing at all, so the single most-used door on the site landed everyone on
`/activity/`.

There is now **one helper every door calls.**

---

## The contract

### `lg-shared/lg-destination.php` — the core. Plain PHP. **No WordPress.**

> profile-app, membership-pages, bb-mirror and archive-poc all render the shared
> header with no WP loaded. **One `wp_*` call in this file fatals four standalone
> surfaces.** Real host validation lives in the WP adapter, not here.

| Function | Contract |
|---|---|
| `lg_dest_capture($raw): string` | Reduce a candidate to a bindable path, or `''`. |
| `lg_dest_here(): string` | The current request as a capturable path+query. |
| `lg_dest_login_url($dest='', $base='/wp-login.php'): string` | Build a sign-in href. Empty/invalid dest → bare `$base`, **byte-identical to before this lane**. |

`lg_dest_capture` **rejects** — each returning `''` so the caller keeps its own
default:

- empty / non-string / longer than 512 bytes
- anything not starting with `/` that isn't a same-host `http(s)` URL — this is
  what kills `javascript:`, `data:`, `mailto:` and off-host absolutes
- scheme-relative `//evil.example`
- **any raw backslash** — browsers fold `\` to `/`, so `/\evil.example` is
  scheme-relative too
- any control character or newline (header injection / log splitting)
- userinfo in the authority (`https://ourhost@evil.example/`)
- the **auth paths** — `/wp-login.php`, `/patreon-connect`, `/patreon-password`
  (prefix-matched, case-insensitive, trailing slash tolerant). Landing there is
  the infinite loop.

It **accepts** an absolute path with its query intact, and reduces a same-host
absolute URL to path+query. **Fragments are dropped** — the server never sees
them, so binding one is a lie.

Two rules that are easy to get wrong and expensive to lose:

- **`rawurlencode`, never `add_query_arg`.** `add_query_arg` does not encode its
  values, so a destination carrying its own query (`/hub/?topic=x&y=2`) is split
  into sibling params and arrives truncated.
- **`grep -c` counts LINES, not occurrences.** Verify a deploy with
  `grep -o | wc -l`, or `substr_count` in PHP.

### `lg-shared/lg-destination.js` — the JS twin (`window.lgDest`)

Same three functions, same rules, for the doors built client-side. Shipped by
`lg-shared/site-header.php` **for anon viewers only** (a signed-in member has no
sign-in door, so it costs the traffic that matters nothing). Every JS door keeps
an inline fallback: a missing helper must never cost someone the ability to sign
in. `tools/gates/dest-capture-gate.php` runs the SAME hostile table against both
implementations so they cannot drift.

### `platform/mu-plugins/lg-login-destination.php` — the WP adapter

- `lg_dest_capture_wp($raw)` — the core, plus `wp_sanitize_redirect()` +
  `wp_validate_redirect($v, '')` for real host validation. Core runs **first**
  (it's stricter, and it's the only one that knows about the auth-path loop).
- `lg_dest_requested()` — the current request's validated `redirect_to`, or `''`.
- `lg_dest_stash(int $uid, string $dest)` / `lg_dest_take(int $uid): string` —
  a **one-shot** store (user meta `_lg_login_dest`) that survives the new-member
  password detour. One-shot is what makes an abandoned destination unable to
  re-fire in a later session. `take` re-validates on the way out; user meta is
  not a trusted store.
- **Absorbs `lg-login-redirect-honor.php`** (9ab8fcd), which is deleted. Two
  filters on one chain is the bug waiting to happen.

> ### ⚠ The ordering that must not be "tidied"
> The `login_redirect` filter is registered **inside an `init` (priority 0)
> callback**, not at top level. BuddyBoss registers `bb_login_redirect()` at
> `PHP_INT_MAX` during `plugins_loaded`; same priority + **later registration**
> = runs last, i.e. after the stomp. Flattening this into a top-level
> `add_filter` silently restores the `/activity/` stomp **with every test still
> green**. Administrators stay untouched — BuddyBoss already excludes them.

---

## The rulings (Ian, 2026-07-27 — do not relitigate)

1. **Never trust the destination.** Same-host, path-only, empty fallback. A
   hostile value costs the destination, never the login.
2. **A brand-new member goes to the password page**, overriding any carried
   destination — then, after they set **or skip** it, to the destination they
   originally asked for. Nothing carried → today's behaviour (set → `/`,
   skip → their profile).
3. **Never land on an auth URL.** And never bounce someone off a gated surface:
   the surface's own gate shows its teaser, it does not redirect to login.
4. **wp-login keeps Card 2 ("Connect Your Patreon") as a way in** — still the
   only door for a Patreon member who has never logged in.
5. **A bare login is untouched.** No destination requested = no behaviour
   change; BuddyBoss's `/activity/` default keeps winning. Every diff in this
   lane is inert until someone actually asks for a destination.

---

## The doors

**Server-rendered — call `lg_dest_login_url(lg_dest_here())`:**

| File | Door |
|---|---|
| `lg-shared/site-header.php` | desktop chrome "Sign in" — **the big one**, present on every page |
| `lg-shared/site-header.php` | mobile drawer "Sign in" |
| `profile-app/web/_render_blocks.php` | profile + practice members-only gate cards |
| `profile-app/web/directory-members.php` | join card + gated map pins |
| `profile-app/web/_render.php` | `/profile/edit` sign-in interstitial (absolute base) |
| `membership-pages/lib/whoami.php` | session-expired card (absolute base) |
| `membership-pages/web/{join,connect-your-patreon,manage-subscription}.php` | `/manage-subscription/` |
| `bb-mirror/web/_chrome.php` | ntm/frm anon panels |
| `bb-mirror/web/forums/_single-topic.php` | reply-form anon CTA (canonical topic path, deliberate) |
| `bb-mirror/api/v0/topic.php` | discussion-modal fragment (canonical topic path — it renders inside another page) |
| `archive-poc/api/v0/comments.php` | comments iframe "Log in" — see below |
| `archive-poc/standalone/render.php` | reactions gate |
| `lg-layout-v2/blocks/post-footer/render.php` | reactions gate |

**Client-built — `window.lgDest.loginUrl(...)` with an inline fallback:**
`webroot/hub-polish.js` (sign-in sheet; binding stays at **open** time, not build
time — the reader may have moved threads), `archive-poc/web/fp-discuss.js` (was
sending `location.pathname`, silently **dropping the query**),
`archive-poc/web/archive.js` (like gate + member-map teaser).

**Inherited for free:** `webroot/bottom-nav.js` builds its phone account-tray
Sign in from `hdrHref('.lg-chrome__signin, .lg-chrome__menu-signin a')`, so it
rides the header fix with no change of its own. On a phone the header hamburger
is hidden outright by bottom-nav (`display:none !important`) — the drawer item is
the *source of truth for the href*, the tray is the surface the reader taps.

### The comments iframe is a special case

`archive-poc/api/v0/comments.php` renders **inside an iframe**, so `lg_dest_here()`
would capture the API path and sign-in would land the reader on a bare comments
fragment. The parent hands over its own path as **`?from=`** (both openers do:
`bb-mirror/web/forums.js` and `archive-poc/standalone/render.php`), and the
`Referer` is the fallback for any opener still running cached JS. A referer of
exactly `/` is discarded — that's the signature of an origin-only referrer
policy, not a reader who was on the front page.

---

## The Patreon leg

```
wp-login Card 2  ──►  /patreon-connect?return=<path>  ──►  Patreon OAuth
                             (state carries return_target)
                                        │
                    ┌───────────────────┴────────────────────┐
        EXISTING account                              NEW account
        lgpo_terminal() → <return_target>?onboarded=…  lg_dest_stash(uid, dest)
                                                      → /patreon-password/
                                                             │
                                              set ──► lg_dest_take() → dest, else /
                                              skip ─► /patreon-password/?skip=1
                                                      → lg_dest_take() → dest, else /u/<slug>
```

- `lg-snippets/snippets/86.php` — Card 2 passes the request's validated
  `redirect_to` **directly** to `lgpo_shortcode(['return' => …])`, not through
  shortcode text: a path carries query separators and brackets the shortcode
  parser would mangle.
- `lg-patreon-stripe-poller/lg-patreon-onboard.php` —
  `lgpo_sanitize_return_path()` is now a thin wrapper over `lg_dest_capture_wp()`.
  The inline `preg_match('#^/[^/]#')` guard it replaced let through
  `/\evil.example`, every control char but `\n`, and `/wp-login.php` itself.
- **Skip routes through `?skip=1`** rather than linking straight at a peeked
  path, because the stash is one-shot: *following* the link has to be what
  consumes it. Otherwise an abandoned page leaves a destination armed.

---

## Not covered (deliberately)

- **The Stripe/join purchase flow** (`membership-pages/web/lgjoin.php:170`,
  `$jsLogin`) — parked. It would attach the same way: swap the
  `lg_ms_home('/wp-login.php')` literal for `lg_dest_login_url($dest, lg_ms_home('/wp-login.php'))`.
- **The signed email-unsubscribe variant** — owned by the thread-follow lane.
  Room is left in the contract: a signed token resolving to a destination should
  resolve to a *path*, then go through `lg_dest_capture` like anything else. The
  signature authenticates the sender; it does not make the path trustworthy.
- **`platform/mu-plugins/looth-auth-issue.php:52`** — `looth_auth_issue_safe_return()`
  is the one hand-rolled validator left. Same posture, weaker: no backslash
  check, no auth-path check, 2048-byte cap. It is not on this lane's door list
  and it sits behind its own gate (`looth-auth-issue-gate.sh`), so it was left
  alone rather than quietly widened. **Follow-on work.**
- **`lg-shared/errors/403.html:45`** — a static error page; there is no
  server-side request to capture. A JS door using `window.lgDest` would work.
- **`lg-apps/apps/shop-planner/assets/shop-planner.js:25`** — takes `login_url`
  from its own localized config; not a chrome door.

### BuddyBoss Pro SSO — verified dormant on dev2, **unverified on live**

`class-bb-sso-provider.php::redirect_to_last_location` calls `bb_login_redirect()`
**directly**, bypassing the `login_redirect` filter entirely — our exception
cannot reach it. There is **no `bb_sso_settings` option on dev2**, so the leg is
dormant here. *Confirm on live before treating it as out of scope.* If it is
live, it needs its own ruling — it is not a quiet fix.

---

## Gates

| Gate | What it proves |
|---|---|
| `tools/gates/dest-capture-gate.php` | the **validator** — 36 hostile/contract cases against `lg_dest_capture`, plus URL-building assertions, plus JS-twin parity. `--legacy` re-runs the same table against the pre-lane inline guard and **fails 18 of them**: that is the RED this gate was written against. |
| `tools/gates/login-doors-gate.php` | the **doors** — renders the real serving header partial and asserts both sign-in hrefs carry the destination, that a hostile/auth-page request falls back to a byte-identical bare login, that a signed-in member gets no door and no JS twin, that no converted door still emits a bare sign-in URL, and that **trap #1** holds (editing the dead `lg-shell/lg-shared/site-header.php` copy fails here rather than shipping nothing). |
| `tools/e2e-webkit/tests/anon-gate.spec.js` | the **browser** — 9 specs across desktop + phone: header button, phone drawer item, bottom-nav tray, the round trip through `wp-login.php`, a query surviving whole, and never binding an auth path. |
