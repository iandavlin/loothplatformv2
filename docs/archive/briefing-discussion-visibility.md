# Feature briefing — Discussion-author visibility (profile Public/Member toggle, 2026-06-07)

**Goal (Ian):** ONE per-user **profile toggle** — *"discussion posting: Public or Member-only."*
- **Public** → the user's real name + avatar + profile link shown to **everyone, incl. logged-out**.
- **Member-only** → **logged-out (public) viewers** see **"private member" + the fallback avatar** (real
  identity hidden from the open web); **logged-in members** see the real author.
- **Scope = DISCUSSIONS only.** CPTs (articles/videos/loothprints) stay **fully visible to logged-out**
  (vis 1, public) — untouched by this.
- ⚠️ **NOT** the per-post composer "anon" button — that's a **separate, deferred** feature
  (`briefing-anon-posting-rebuild.md`). Don't conflate.

It's a **viewer-state × author-preference** mask: `viewer logged-out` AND `author = member-only` → masked.
It applies the author's **live preference at render time** (not per-post), so changing the toggle re-masks
all their past discussions — correct for a privacy setting.

## The 3 pieces

### 1. Profile-app — the setting (profile-app lane)
- Add **`discussion_visibility`** (`public` | `member`) to the user record + a set-endpoint.
- **Include it in the user payload** — `/profile-api/v0/users` (and `/whoami` for self) — so the Hub can
  read each discussion author's preference.
- **Default = `member`** (Ian 6/7) — names hidden from the public until a user opts to Public.

### 2. Profile UI — the toggle (profile-page lane / profile-app)
The Public/Member toggle on the profile settings page → PUTs `discussion_visibility`.

### 3. Hub render — the mask (hub-coord)
In the discussion author render (`_feed.php` discussion cards, `_reply-render.php`, `_topic-replies.php`):
- if **viewer is logged-out** AND **author.discussion_visibility = member** → render **"private member"** +
  the **fallback avatar**, **no real name, no avatar URL, no `user_uuid`, no `/u/<slug>` link**.
- else → real author.
- 🔴 **LEAK-SAFE (the bar = secure-from-the-inspector):** for logged-out viewers of a member-only author,
  the real identity must be **ABSENT from the DOM/JSON** — masked **server-side**, never CSS-hidden. Same
  discipline as the gated teasers.
- **CPT author rendering is unaffected** — this only touches discussion (forum) authors.

## 🔴 PERFORMANCE RULES (Ian 6/7 — do NOT skip)
The masking is **logged-out-only**, so it must add **zero cost for logged-in members** and stay cheap on
the high-traffic anon path:
1. **Logged-in → don't even read it.** Gate the mask on `if (viewer is logged-out)` FIRST. For a logged-in
   viewer the render never touches `discussion_visibility` — real author, exactly as today. No extra read.
2. **Logged-out → no N+1, no per-author profile-app call.** Carry `discussion_visibility` as a **column on
   `forums.person`** (person sync sets it) so it rides the author JOIN the feed already does — one boolean,
   no extra query. (This is why we pick path **(a)** below, not per-render profile-app resolution.)
3. **Masked authors do LESS work** — when an author is masked, **skip identity resolution entirely** (emit
   "private member" + fallback; don't fetch name/avatar/uuid/slug at all). Cheaper than today.
4. The logged-out feed is **cacheable** (identical for all public viewers) — the mask computes once.

### Where the preference comes from — use path (a) (perf decision above)
- **(a) CHOSEN:** add `discussion_visibility` to **`forums.person`** + carry it in the person sync; the Hub
  checks `person.discussion_visibility` (in-JOIN, free). Source of truth stays profile-app (§1); person is
  the synced cache the feed already reads.
- (b) per-render profile-app resolution is the audit-aligned direction for identity generally, but for THIS
  logged-out-hot mask it would add a call on the anon path — so don't use it for the mask decision.

## Contract (coordinator-governed)
- Field: **`discussion_visibility`** (`public`|`member`), owned by profile-app, surfaced in the user payload.
- Mask condition: **logged-out viewer + member-only author** (logged-in members always see real names).
- Masked display: **"private member" + fallback avatar**, leak-safe, no link. Announce to Buck (mobile
  discussion render mustn't re-expose identity).

## Report back (to coordinator)
`DONE · FILES · VERIFIED (toggle persists + Hub masks member-only authors @ logged-out leak-safe + members see real + CPTs unaffected) · NEEDS-OTHER-LANE · BLOCKED`.
Report session ID + outliner title for CHATS-MENU + lineage.
