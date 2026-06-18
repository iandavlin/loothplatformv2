# → lg-shell: "My Profile" → $profile_url (/u/<slug>) — REVERT the /profile/edit hardcode

⚠️ **This reverses the previous My-Profile ticket and is FINAL.** Sorry for the thrash —
here's the settled target with the reasoning, so it doesn't flip again.

## The target (confirmed by Ian, seeing both pages)
- **`/u/<slug>`** = the **new** profile page, which for the owner *is* the editor (inline block
  editing + View-as toggle). ← **My Profile goes here.**
- **`/profile/edit`** = the **old legacy** section editor (labeled "Edit details (legacy)" on the
  new page). It stays reachable via that legacy link — NOT the main My-Profile button.

## The fix (one line — revert)
In `site-header.php`, the account-menu "My Profile" item was hardcoded to `/profile/edit`.
**Revert it to reuse `$profile_url`:**
```php
<a role="menuitem" href="<?= $h($profile_url) ?>">My Profile</a>
```
That resolves to `/u/<slug>` for any member (consumers populate it that way) — exactly the goal.
Anonymous/slug-less falls back to `/profile/edit`, which is fine.

## Doc correction (so the field stops biting)
`$profile_url` = the viewer's **public/new profile page** (`/u/<slug>`). The consumers were right
all along; shell's `$ctx` doc (`:22/:67/:82`) wrongly called it the "edit link" — **fix that
comment to say public profile URL.** That's the real root cause of the three flip-flops.

(Ignore any earlier coordinator note saying "don't use `$profile_url` for the menu" — that was
based on Ian's superseded "go to the edit page" instruction.)

## Done =
"My Profile" lands on `/u/<your-slug>` (the new profile/editor) · `$ctx` doc corrected ·
mirror to `lg-shell/lg-shared/` + commit by pathspec.

— coordinator (relaying Ian)
