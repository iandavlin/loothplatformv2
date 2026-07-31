# Mobile: discussion embed broken + card video won't play — REPRODUCED

Lane `mobile-embed`, 2026-07-31. Backlog #3.7 + #7 (Ian, 7/31).
Parked mid-lane for token budget; this file is the resume point.

Both bugs reproduced **as a logged-in member at 390×844 with touch emulation**, on the
branch under test, with a control that isolates each cause. No fix written yet.

## How it was reproduced (repeatable)

`tools/exercise-harness` on this worktree — the loopback harness, NOT the dev2 serve
(the serve is `main`, so it cannot show a branch's behaviour):

```bash
BR=/home/ubuntu/worktrees/mobile-embed
cp $BR/tools/exercise-harness/hub-router.php /tmp/mobile-embed-exercise/   # world-traversable
sudo -u bb-mirror env LG_BB_MIRROR_ENV=dev2 LG_HARNESS_BRANCH=$BR \
  php -S 127.0.0.1:8891 -t $BR/bb-mirror/web /tmp/mobile-embed-exercise/hub-router.php &
```

`LG_HARNESS_BRANCH` is new this lane (334f7cc) — the router used to hardcode
thread-follow's worktree for `/hub-polish.js` and `/lg-shared`, so a second lane got its
own PHP with **another branch's JS**. That is exactly the surface both these bugs live on.

Member cookies for uid 1912 (`claude_admin`) per the harness README. One persistent CDP
websocket for the whole run — per-command sockets drop `Emulation.setDeviceMetricsOverride`
and the run then false-PASSes as desktop.

Fixture: **topic 72375**, `/hub/touring-tech/test-3/`, body = `Test.` + a bare
`https://youtu.be/kJ5XRl7PWxM` link. It is the only YouTube facade card on page 1 of
`/hub/?type=discussions`.

## Measured, 390px, signed in

```
viewport      : 390x844      matches ≤640 : True      touch points : 5
yt facade card: True         fc-play shown: hidden:none    autoplay wired: False
```

### #3.7 — the embed. Surface: the MOBILE DISCUSSION SHEET, not the card, not the page.

On a phone, **every** discussion tap opens `#looth-rep-sheet` (hub-polish.js:2290) — it is
the only way to read a discussion from the hub. The sheet fetches the resolved OP from
`/hub/?body=<tid>` and assigns it raw:

- `webroot/hub-polish.js:3672` — `if (html && html.trim()) body.innerHTML = html;`
- `webroot/hub-polish.js:3536` — the pre-paint excerpt clone, same omission
- `webroot/hub-polish.js:3475` — `full.innerHTML = html;` for the **replies**, same again

None of the three calls `bbProcessEmbeds`. The desktop reader modal does, on the identical
data path — `bb-mirror/web/forums.js:2955`:
`dmB.innerHTML = newHtml; if (window.bbProcessEmbeds) window.bbProcessEmbeds(dmB);`

Measured in the sheet:

```
op body html: <p>Test. </p><p><a href="https://youtu.be/kJ5XRl7PWxM" …>https://youtu.be/…</a></p>
players     : 0        .bb-embed : 0        bare yt link : 1
```

**Control that isolates it** — the standalone topic page at the *same* 390px width:

```
.post__body players: 1   .bb-embed: 1   bare yt link: 0
```

So it is not the viewport, not CSS, not the provider regex. `forums.js:1679` sweeps
`.post__body` on load on every viewport; the sheet is the one render path that never got
the call. It affects **every** provider `bbBuildEmbed` handles (YouTube, Vimeo, IG, X),
in the OP *and* in replies.

### #7 — the card's video. Four coupled places, all deliberate, all dated 2026-06-17.

`.fc-cover--video[data-yt-play]` is emitted on discussion cards on every viewport
(`_feed.php:1708`), and the whole Instagram-style mobile play engine still exists — it is
switched off:

| Place | State |
|---|---|
| `webroot/hub-polish.js:706` | `wireVideoLinkCards()` → bare `return;` |
| `webroot/hub-polish.js:724` | `wireVideoAutoplay()` → bare `return;` (measured: `data-lg-vidauto` absent) |
| `webroot/hub-polish.js:2285` | `.fc-cover--video` dropped from the tap-exempt list → a cover tap opens the sheet |
| `bb-mirror/web/forums.css:4358` | `@media (max-width:640px){ .fc-play{display:none} }` (measured: `display:none`) |

Measured: tapping the cover (hit-tested, real `touchStart`/`touchEnd`) → **0 iframes on the
card, sheet opens instead.** That is the reported symptom exactly, and it is the documented
2026-06-17 decision — `docs/atlas/DISCUSSION-CARD-VIDEO.md` §"Desktop only, on purpose".

**So #7 is a REVERSAL, not a regression.** Ian 7/31 wants inline play on the card on mobile;
Ian 2026-06-17 asked for the opposite. That is the scope question to put to him before code:
tapping the cover cannot both play the video and open the discussion.

## Next steps (in order)

1. **Scope to Ian** — cover plays inline / title-and-body still opens the sheet? And does
   inline play mean tap-to-play or the old scroll-autoplay-muted engine? Mock at 390 behind
   the dev gate, URL to keeper.
2. **Red-first gate**, mobile viewport, asserting the *behaviour*: sheet OP with a provider
   link renders a player; card cover tap yields an iframe. Both must fail on this build
   first — they do today, which is what the numbers above record.
3. **Fix behind a flag, DEFAULT OFF.** #3.7 and #7 are independent and should flag
   separately — #3.7 is a missing call with a desktop precedent, #7 reverses an Ian ruling.
   The flag has to reach JS: the server-markup pattern (`LG_THREAD_FOLLOW_ENABLED` →
   suppressed markup) does not work for a docroot overlay, so emit the bit from
   `bb-mirror/web/_chrome.php:723` next to `window.LG_FORUM_BASE` and have hub-polish.js
   read it. Flag OFF must be a proven byte-identical no-op **and be gated**.
4. Must not regress the facade decision in `DISCUSSION-CARD-VIDEO.md`: no eager
   third-party players, mqdefault thumb with no srcset stays as documented.
