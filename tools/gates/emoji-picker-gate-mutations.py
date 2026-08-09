#!/usr/bin/env python3
"""Red-first harness for gate 19. Snapshots file CONTENT and restores it — never
`git checkout --`, which would wipe uncommitted work under test."""
import subprocess, sys, os

ROOT = "/home/ubuntu/worktrees/emoji-picker-build"
GATE = [sys.executable, os.path.join(ROOT, "tools/gates/emoji-picker-gate.py")]

F = {
    "header":   f"{ROOT}/lg-shared/site-header.php",
    "modals":   f"{ROOT}/lg-shared/social-modals.js",
    "sheet":    f"{ROOT}/webroot/messenger-sheet.js",
    "settings": f"{ROOT}/webroot/app-settings.js",
    "config":   f"{ROOT}/platform/config/emoji-picker.php",
    "loader":   f"{ROOT}/webroot/pwa-loader.php",
}

MUTATIONS = [
    # (name, file, old, new, "what this defect would be in production")
    ("indented <?php if leaks whitespace when OFF", "header",
     "<?php if ($emoji_picker): /* flush-left",
     "            <?php if ($emoji_picker): /* flush-left",
     "OFF stops being byte-identical to the feature not existing"),

    ("desktop insert skips the InputEvent", "modals",
     "  ta.dispatchEvent(new InputEvent('input', { bubbles: true }));\n  ta.focus();",
     "  ta.focus();",
     "Send stays DISABLED on a composer that visibly has text — the dead button"),

    ("phone insert skips the InputEvent", "sheet",
     "    ta.dispatchEvent(new InputEvent('input', { bubbles: true }));\n  }\n\n  function mgEpkCloseAll",
     "  }\n\n  function mgEpkCloseAll",
     "same dead Send button, on the phone"),

    ("desktop insert appends instead of inserting at caret", "modals",
     "    ta.setRangeText(emoji, s, e, 'end');           /* caret lands AFTER the emoji */",
     "    ta.setRangeText(emoji, ta.value.length, ta.value.length, 'end');",
     "every emoji lands at the END of the message, never where the member was typing"),

    ("phone vocabulary drifts from desktop by one glyph", "sheet",
     "'🎸 guitar rock strat les paul', '🪕 banjo'",
     "'🎸 guitar rock strat les paul', '🎻 banjo'",
     "the phone offers a glyph the desktop does not"),

    ("phone button helper stops checking the flag", "sheet",
     "  function mgEpkBtnHtml() {\n    if (!mgEpkOn()) return '';",
     "  function mgEpkBtnHtml() {",
     "the ☺ ships to every member with the feature switched OFF"),

    ("flag check accepts any truthy value", "sheet",
     "  function mgEpkOn() { return window.LG_EMOJI_PICKER === 1; }",
     "  function mgEpkOn() { return !!window.LG_EMOJI_PICKER; }",
     "fail-open: a stray truthy global exposes the feature"),

    ("pwa-loader emits the global even when OFF", "loader",
     "    if (is_array($_raw) && ($_raw['enabled'] ?? false) === true) $flags['LG_EMOJI_PICKER'] = 1;",
     "    $flags['LG_EMOJI_PICKER'] = 1;",
     "OFF stops being byte-identical on the wire"),

    ("a dark entry for the panel is dropped", "settings",
     "      D + ' .lg-epk__h{background:#1c1f22!important;color:#9aa097!important}',\n",
     "",
     "the sticky category heading renders light-on-dark — the sage-tint blind spot"),

    ("the ☺ button gets its own dark override", "settings",
     "      D + ' .lg-epk__none{color:#9aa097!important}',",
     "      D + ' .lg-epk__none{color:#9aa097!important}',\n      D + ' .lg-msg__emoji-btn{color:#ff0000!important}',",
     "two buttons on one row stop matching in dark"),

    ("config says ON but the surface is not rebuilt", "config",
     "\t'enabled' => false,",
     "\t'enabled' => true,",
     "(liveness) the shipped-state assertion must track the flag, not a hardcode"),
]

print(f"RED-FIRST: {len(MUTATIONS)} mutations, each must turn the gate RED\n")
base = subprocess.run(GATE, capture_output=True, text=True)
print(f"baseline (unmutated): exit {base.returncode}  "
      f"{'GREEN as expected' if base.returncode == 0 else '!! NOT GREEN — fix before mutating'}\n")
if base.returncode != 0:
    sys.exit(2)

bad = 0
for name, key, old, new, consequence in MUTATIONS:
    path = F[key]
    with open(path, encoding="utf-8") as fh:
        snap = fh.read()
    if old not in snap:
        print(f"  ✗ SKIPPED (anchor not found): {name}")
        bad += 1
        continue
    try:
        with open(path, "w", encoding="utf-8") as fh:
            fh.write(snap.replace(old, new, 1))
        r = subprocess.run(GATE, capture_output=True, text=True)
        caught = r.returncode != 0
        # A mutation must be caught as a FINDING (exit 1), not as a crash (exit 2).
        how = "RED" if r.returncode == 1 else ("DEAD/exit%d" % r.returncode)
        print(f"  {'✓' if caught else '✗ MISSED'}  {how:<10} {name}")
        if not caught:
            print(f"          would ship: {consequence}")
            bad += 1
        elif r.returncode != 1:
            print(f"          ⚠ caught, but as {how} rather than a clean finding")
    finally:
        with open(path, "w", encoding="utf-8") as fh:
            fh.write(snap)

after = subprocess.run(GATE, capture_output=True, text=True)
print(f"\nrestored: exit {after.returncode} "
      f"({'green again — no residue' if after.returncode == 0 else '!! FILES LEFT MUTATED'})")
if after.returncode != 0:
    bad += 1
print(f"\n{len(MUTATIONS) - bad}/{len(MUTATIONS)} mutations caught")
sys.exit(1 if bad else 0)
