# dark-anon-sweep — state note (2026-08-15)

**RESOLVED, not parked.** Fleet-quiet order (Ian browsing dev2) came and went;
resumed by keeper once his look was done. Gate 36 ratchet reshape is complete
and self-consistent; committing next.

## What changed since the last note
- Live verification runs (post-resume) surfaced a REAL finding: two clean
  live-injection captures of the identical post-merge state, ~40 min apart,
  disagreed by 2–8x on several surfaces (box load swung 0.4–7.1 with other
  lanes' gates running concurrently). Traced one contributing bug — `measure()`
  only cleared localStorage on the os-dark path, so `lg-set-boot` (written by
  app-settings.js after any dark resolution) could leak into the next
  app-dark test and flip it onto a different render path — and fixed it, but
  the swings persisted after the fix, so the dominant cause is CPU-contention
  timing (wall-clock settle delays, not event-based) rather than harness
  state leakage alone.
- Given that, `BASELINE` is now the per-surface **max of two independent
  captures** (146 findings total, not a single run's number) — verified by
  direct computation that both source datasets read at-or-under the new
  BASELINE on every surface (zero violations). Documented in both the gate's
  module docstring and `docs/CRAFT-STANDARD.md` row 36, including why a
  single-run baseline would have been worse than no gate at all (flaps red
  on noise, teaches people to ignore it).
- `_ratchet_selftest()` still passes (5/5) after the BASELINE update.
- Buck-fence guard clean.

## Next
1. Commit `tools/gates/anon-dark-contrast-gate.py` + `docs/CRAFT-STANDARD.md`.
2. Rebase over main (very active tonight — expect an append-point collision
   at the same spot gate 38/39 hit), push, confirm identical to remote.
3. Reply to keeper on the board: exact baseline numbers, the noise finding,
   and the self-test's red-first proof.

Not blocked on anything right now.
