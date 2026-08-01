# The account-level email cadence — one store, three surfaces

account-following lane, 2026-08-01. Written because three lanes touch one setting
and the two design documents that describe it **disagree with each other**.

Status: **both flags OFF, zero members carry a cadence, nothing is live.**
Two defects are open and both must land **before** either flag moves.

---

## 1. The setting

One **account-level** choice — `instant | daily | weekly`, absent ⇒ `instant`.
Not per-thread: following six discussions on Daily would otherwise be six daily
emails, which defeats the feature. Ian ruled it account-wide, and the copy on the
control says so in as many words ("Applies to every discussion you follow").

| | |
|---|---|
| **store** | WP usermeta `lg_disc_email_cadence` |
| **flood guard** | WP usermeta `lg_disc_digest_watermark` |
| **writer** | `lg_fd_set_cadence()` — `platform/mu-plugins/lg-follow-digest.php` |
| **reader** | `lg_fd_cadence()`, same file |
| **who may see it** | `lg_fd_cadence_ui_enabled($uid)` — per-member, same file |

## 2. The surfaces

| lane | surface | how it writes |
|---|---|---|
| account-following | `/manage-subscription/` | `lg_fd_cadence_state` / `lg_fd_cadence_set` (admin-ajax) → `lg_fd_set_cadence()` |
| thread-follow | the follow modal | `POST /bb-mirror-api/v0/follow {cadence}` → **raw `update_user_meta` — see defect A** |
| follow-digest | — | owns the store, the writer, the transports and the sender |

**`/manage-subscription/` has NO WP BOOT** (its `:3` says so). It cannot call
`lg_fd_cadence_ui_enabled()` or `wp_create_nonce()` at render time. So:

- the page's own `LG_FOLLOWING_CADENCE` is only a **cheap pre-gate** (off ⇒ not a
  byte rendered, nobody pays for a fetch);
- the markup ships `hidden`, and **the server reveals it** — `lg_fd_cadence_state`
  is per-member and 404s anyone the sender would not serve, and the JS then
  **removes the node**;
- the nonce rides the state response (added 2026-08-01), because that endpoint has
  already refused non-served members, so it cannot widen who may write.

> ⚠️ **The CSS line is the hidden state, not the attribute.** The UA sheet's
> `[hidden]{display:none}` loses to `.lg-manage-sub__fol-freq{display:flex}`.
> Without the explicit `[hidden]` override, `hidden` hides **nothing** and the
> control paints for exactly the members the endpoint refuses. Gated on the
> *served* stylesheet, and measured in a real browser.

## 3. Why one write path, concretely

`lg_fd_set_cadence()` is not a wrapper. It **stamps the watermark** on entry to a
batched cadence, and that stamp is the flood guard. Skip it and:

1. `lg_fd_suppress_instant` (:494) sees a non-instant cadence → **instant mail off**
2. `lg_fd_due_recipients` (:512-521) requires a watermark → **never due, no digest**
3. → the member **receives nothing at all**, from a control they just used.

Proven, not argued: `platform/bin/cadence-seam-proof.php` (sends no mail).
Arm A writes through the writer; arm B reproduces `follow.php:212` verbatim. Both
make `lg_fd_cadence()` return `daily` — *that is why the bug is invisible to any
test that checks the stored value*. Arm A is arm B's control.

```
sudo -u looth-dev env LG_FOLLOW_DIGEST=1 LG_FD_MU_DIR=/tmp/lg-fd-gate-mu \
     php platform/bin/cadence-seam-proof.php
```

## 4. OPEN DEFECT A — two write paths

`bb-mirror/api/v0/follow.php:212` does a raw
`update_user_meta($uid, 'lg_disc_email_cadence', $cadence)`, skipping the writer.
Its own comment eight lines above says "neither writes usermeta directly".

It also gates on bare `LG_FOLLOW_CADENCE_LIVE` while the sender gates per-member,
so it will accept a cadence from a member the sender refuses — the write
`lg_fd_ajax_set:429` deliberately refuses.

**Fix** (~4 lines, already written for them at `lg-follow-digest.php:338-346`):
call `lg_fd_cadence_ui_enabled()` and `lg_fd_set_cadence()`. `follow.php`
full-bootstraps WP at `:43`, so both are in scope.

> **Root cause is a doc disagreement, so patching only the code will regress.**
> `bb-mirror/config.php:439-441` states the seam as *"write THIS lane, through
> follow.php"* (raw store). `lg-follow-digest.php:320-323` states it as *"NEITHER
> SURFACE CAN REACH THE STORE DIRECTLY"*. **One of those paragraphs has to go.**

## 5. OPEN DEFECT B — the writer is not self-healing

Fixing A stops new members falling in. It does **not** get out the ones already in,
because the stamp keys on a cadence *transition*:

```php
$was = lg_fd_cadence($uid);                                   // already 'daily'
update_user_meta($uid, LG_FD_CADENCE_META, $cadence);         // 'daily'
if ('instant' !== $cadence && $cadence !== $was) { …stamp… }  // false ⇒ NO STAMP
```

**To a person:** they press Daily, the write returns `ok:true`, the page repaints
`daily` — because the store really does say daily — and nothing changes, ever.
The UI is honest about the store and the store is still wrong. There is no
sequence of clicks that fixes it; only `instant → daily` works, by accident.

**Fix** — one condition, in the writer, not the callers:

```php
if ('instant' !== $cadence && ($cadence !== $was || '' === lg_fd_watermark($uid)))
```

which is also what the docblock at `:279-285` already claims the function does.

## 6. What is gated

`tools/gates/cadence-control-gate.py` — gate **15/15**, 34 assertions.

- **PRESENT** when `LG_FOLLOWING_CADENCE` is on, **ABSENT** when off — diffed
  across two surfaces that really exist (`/manage-subscription/` vs the lane
  preview), never by reading the repo.
- **STILL HIDDEN** when the flag is on but the sender would not serve the member.
- `hidden` really hides — on the served stylesheet *and* measured in a browser.
- the page's only cadence write is the sanctioned transport — asserted on the
  served JS with comments **lexed out**, not grepped.
- `--prove` runs every predicate against the surface it was written to **reject**.
- `--cdp` adds the strongest one: flag ON in the HTML, control **removed from the
  member's DOM** — with a liveness control (stub the reply to `ok`, require it to
  reappear) so the absence can never be vacuous.

Needs the preview up: `sudo tools/preview/lane-preview.sh up account-following`.

## 7. Turning it on

Not a lane's call. Both flags move together, in Ian's window, **after** defects A
and B land:

- `LG_FOLLOW_CADENCE_LIVE` (bb-mirror/config.php) + `FREQ_BATCHER_LIVE` (forums.js)
- `LG_FOLLOW_DIGEST_ENABLED` (`platform/config/follow-digest.php`) — and note the
  allowlist is a **separate** switch that defaults to nobody.
- `LG_FOLLOWING_CADENCE` (membership-pages) for the account page.
