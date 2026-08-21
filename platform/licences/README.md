# Creative Commons legal code, held offline

The four licences the Loothprint compose form offers, as their **complete legal
code**, verbatim. `lg_fc_licences()` in
`platform/mu-plugins/lg-frontend-compose.php` reads these and the compose form's
ⓘ modal shows them.

## Why they are here rather than linked

Ian, 2026-08-21: *"Could we get a i that pops up a modal with the entire legal
contract?"* — and #191's ruling with it: **hold the text offline, do not send a
member to another site mid-form.** A member half-way through filling in a form is
the worst moment to hand them an external navigation, and a link is also a
promise about somebody else's uptime.

CC's own terms permit reproducing the legal code verbatim, so this is allowed as
well as kinder.

## Why this directory, and not one under `mu-plugins/`

dev2 and live symlink mu-plugins **one entry at a time** (33 individual symlinks).
A new directory under `mu-plugins/` is therefore a deploy step a `git pull` does
not perform, and a missed symlink here would mean an ⓘ that opens on an empty
modal.

`platform/licences/` needs no symlink at all: the mu-plugin reaches it with
`dirname(__DIR__) . '/licences/'`, which is exactly how `lg_fc_enabled()` already
reaches `platform/config/`. `__DIR__` resolves **through** the mu-plugin symlink
back into the repo checkout, so the files are found wherever the checkout is.

## Provenance — fetched 2026-08-21

| file | source | bytes | sha256 |
|---|---|---|---|
| `cc-by-4.0.txt`       | https://creativecommons.org/licenses/by/4.0/legalcode.txt       | 18657 | `9ba9550ad48438d0836ddab3da480b3b69ffa0aac7b7878b5a0039e7ab429411` |
| `cc-by-sa-4.0.txt`    | https://creativecommons.org/licenses/by-sa/4.0/legalcode.txt    | 20138 | `28a9529c7d0bb4dc51f4bf5c116a3d16ef247a052f7591466768ddf563fd1cf5` |
| `cc-by-nc-sa-4.0.txt` | https://creativecommons.org/licenses/by-nc-sa/4.0/legalcode.txt | 20850 | `e66c269d4819aaab34b49ef5220c4ddab6756f21bb5180761a4eb8561f2b7bbd` |
| `cc-by-nc-nd-4.0.txt` | https://creativecommons.org/licenses/by-nc-nd/4.0/legalcode.txt | 19127 | `38762e3777f4ec00a6f769062a7c3f704fb78ce08303ecff88558da4c49cf9ea` |

⚠️ **Do not hand-edit these.** They are a legal document reproduced verbatim, and
the checksums above are what makes "verbatim" a checkable claim rather than an
assurance. If CC publishes a revision, replace the file wholesale and update the
row — never patch a line.

## Version

**4.0 International**, the current generation, for all four. The compose form's
stored values name only the letters (`BY NC ND (…)`) and not a version, so 4.0 is
this box's answer to "which one did they mean"; it is stated here rather than left
implied.
