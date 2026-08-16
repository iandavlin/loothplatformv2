# Backlog 11.6 — filter notifications by type + clear a type

**Seat** featured-members · **2026-08-16** · branch `directory-location` @ `0b8f71a`
**Flag** `profile-app/config/notifications.php` → `filter_and_bulk_by_type`, **OFF**
**Mock** https://dev2.loothgroup.com/footer-mockups/notif-filter-bulk/

---

## 1. It was smaller than the ticket — two questions were already answered

Do not re-litigate these; they are settled in the tree and cited in the code:

- **Delete-vs-dismiss is RULED.** Ian, 2026-08-08: **DELETE means DISMISS**, behind
  `config/notifications.php`. `dismissAll`/`deleteAll` and a clear-*everything*
  button already existed. 11.6 adds "…of one type" to machinery that was built.
- **Types are already first-class**, so the filter needed no new data.

## 2. ⚠ THE ONE THING THAT MUST NOT SHIP WITHOUT IAN

**The weekly digest is built from these rows, and his rule is "empty means send
no email"** (`Recap.php:67,102`).

Measured on live 2026-08-15:

| | |
|---|---|
| members holding any notification | **325** |
| of those, holding only ONE type | **277 (85%)** |
| of those, holding unread rows | **252** |

So for most members, clearing "their" type **empties the store and silently stops
their weekly email**. **Dismissal does not avoid this** — `Recap` already excludes
dismissed rows (`AND n.dismissed_at IS NULL`), so dismissed and deleted are
identical from the digest's side.

Three options were put to Ian in the mock: **(a)** warn at the moment of clearing
(what the UI does), **(b)** give the digest a floor so an emptied bell never
cancels the email, **(c)** both. **Recommended (c).** *No ruling yet.* The flag's
docblock repeats this; **do not flip it until that lands.**

**Also**: `dismissed_at` **does not exist on live** — only dev2. So today on live a
delete is real and permanent, and enabling dismissal needs a live migration
(Ian's, as schema owner).

## 3. What shipped

| layer | file |
|---|---|
| store | `src/Notifications.php` — `FILTER_TYPES`, `filterType()`, `listFor($type)`, `countsByType()`, `deleteAllOfType`, `dismissAllOfType` |
| endpoint | `api/v0/me-notifications.php` — `GET ?type=`, typed `DELETE` |
| desktop | `lg-shared/social-modals.js` |
| phone | `webroot/bottom-nav.js` (+ styles in `lg-shared/site-header.css`) |
| proof | `profile-app/bin/notif-type-filter-proof.php` |
| gate | `tools/gates/notif-type-filter-gate.py` + probe — **UNREGISTERED** |

**Safety, built three ways:** the typed clear is owner-scoped **and** type-scoped
in one WHERE; an unknown type clears **zero** and never widens; and the endpoint
checks the typed branch **before** clear-everything, so a client sending both can
never be serviced by clear-all.

**OFF is inert with no client flag.** The server sends `counts` only while the flag
is on, and both surfaces open with `if (!counts) return ''`. A cached client
cannot grow chips on its own. (Same convention as `read_policy`.)

**Edit the SERVED copies**: `/srv/lg-shared` → `loothplatformv2-clean/lg-shared`, so
`lg-shared/*` is what runs. `lg-shell/lg-shared/` holds smaller, different files.

## 4. The gate, and the lesson inside it

Nine assertions, run against the **real endpoint** with a **real bystander member**
— "only that member" cannot be tested with one account.

**Two of six mutations did not fire at first, and they were the two that mattered.**
Dropping the type scope and dropping the owner scope both returned **NO VERDICT,
not RED** — my mutation used `--` as a comment, which is SQL and not PHP, so the
file stopped parsing and the probe died before the gate could disagree. **An
invalid mutation proves nothing and looks exactly like a passing one.** Made
valid, both fire. Keep mutations *valid*, not merely wrong.

`Auth::pinUserForTest` is a **CLI-only** seam (a probe member has no WP bridge row,
so no mintable JWT). It is shaped like an auth bypass, so the guard is **proven**:
under a non-CLI SAPI it throws. Probes are PID-keyed, repair on **entry**, and the
gate reds if teardown leaves anything.

## 5. Not done / not verified
- **The UI has never been seen in a browser.** Both files pass `node --check` and
  the gate asserts the guards structurally, but that is syntax and shape, not
  rendering. It needs the flag on somewhere — after merge, on the serve.
- **Gate is unregistered** in `run-all.sh`, awaiting a keeper number.
- Ian's digest-floor ruling (§2).
