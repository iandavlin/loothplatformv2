#!/usr/bin/env python3
"""Red-first for gate 88 — does each assertion actually bite?

A green gate is worth nothing until it has been shown to go red, and red for the
REASON IT CLAIMS. Each mutation below breaks exactly one property and names the
assertion that must notice. Two no-op controls must stay GREEN — a harness where
everything reddens is measuring the harness.

⚠️ IT SNAPSHOTS, IT NEVER `git checkout --`s
(feedback-mutation-harness-must-snapshot-not-checkout). Restoring from HEAD would
wipe the uncommitted work under test, which once turned one harness bug into ten
false "the assertion is decoration" verdicts. The snapshot is taken from the
working tree, restored via atexit AND on SIGINT/SIGTERM, and the restore is
verified byte-for-byte before the run is called finished.
"""
import atexit
import os
import shutil
import signal
import subprocess
import sys
import tempfile

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PLUGIN = os.path.join(REPO, "platform/mu-plugins/lg-frontend-compose.php")
GATE = os.path.join(REPO, "tools/gates/compose-limits-gate.py")

SNAP = None


def snapshot():
    global SNAP
    fd, SNAP = tempfile.mkstemp(prefix="lg186-snap-", suffix=".php")
    os.close(fd)
    shutil.copyfile(PLUGIN, SNAP)


def restore(*_):
    if SNAP and os.path.isfile(SNAP):
        shutil.copyfile(SNAP, PLUGIN)


atexit.register(restore)
signal.signal(signal.SIGINT, lambda *a: (restore(), sys.exit(130)))
signal.signal(signal.SIGTERM, lambda *a: (restore(), sys.exit(143)))


def read():
    with open(PLUGIN, encoding="utf-8") as fh:
        return fh.read()


def write(s):
    with open(PLUGIN, "w", encoding="utf-8") as fh:
        fh.write(s)


def run_gate():
    """→ (rc, set-of-failed-assertion-ids)."""
    r = subprocess.run([sys.executable, GATE], capture_output=True, text=True, timeout=900)
    failed = set()
    for line in (r.stdout or "").splitlines():
        line = line.strip()
        if line.startswith("FAIL "):
            failed.add(line.split()[1])
    return r.returncode, failed, r


# (label, find, replace, assertion-ids that MUST redden, is_noop)
MUTATIONS = [
    ("M1  the size cap stops being applied",
     "    $cap = $photo ? $lim['photo_b'] : $lim['file_b'];",
     "    $cap = PHP_INT_MAX;",
     ["A.photo.sideload.refused", "A.file.refused"], False),

    ("M2  the refusal stops naming the number",
     "            ? sprintf('That photo is %s — a bit big. Photos need to be %s or smaller.',\n"
     "                      lg_fc_mb($size), lg_fc_mb($cap))",
     "            ? 'That photo is too big.'",
     ["A.photo.sideload.says10"], False),

    ("M3  ONLY ACF's hook is registered — the original defect, exactly",
     "add_filter('wp_handle_sideload_prefilter', 'lg_fc_upload_prefilter');",
     "// mutation: the sideload hook is gone",
     ["A.photo.sideload.refused", "A.file.refused"], False),

    ("M4  the collector stops requiring the stamp",
     "         JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s\n"
     "         WHERE p.post_type = 'attachment' AND p.post_parent = %d AND m.meta_value = %d\",\n"
     "        LG_FC_UPLOAD_STAMP, $post_id, $post_id));",
     "         LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s\n"
     "         WHERE p.post_type = 'attachment' AND p.post_parent = %d AND %d = %d\",\n"
     "        LG_FC_UPLOAD_STAMP, $post_id, $post_id, $post_id));",
     ["D.keep.unstamped"], False),

    ("M5  the structural meta walk goes blind",
     "    foreach ($wpdb->get_results($wpdb->prepare(\n"
     "        \"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d\", $post_id)) as $m) {",
     "    foreach (array() as $m) {",
     ["D.keep.gallery", "D.keep.zipfield", "D.keep.thumb", "D.keep.repeater", "D.keep.layout"], False),

    ("M6  the walk reads KEYS as well as values (the serialized trap)",
     "            foreach ($v as $x) {           // VALUES only — a key is not a reference\n"
     "                $walk($x);\n"
     "            }",
     "            foreach ($v as $k => $x) { $walk($k); $walk($x); }",
     ["D.collect.unused_gone", "D.collect.count"], False),

    ("M7  the body/filename leg is dropped",
     "        if (($stem !== '' && strpos($text, $stem) !== false)\n"
     "            || preg_match('/\\b' . $aid . '\\b/', $text)) {\n"
     "            continue;\n"
     "        }",
     "        if (false) { continue; }",
     ["D.keep.inbody"], False),

    ("M8  the cross-post lead-image guard is dropped",
     "        if ((int) $wpdb->get_var($wpdb->prepare(\n"
     "                \"SELECT COUNT(*) FROM {$wpdb->postmeta}\n"
     "                 WHERE meta_key = '_thumbnail_id' AND meta_value = %d AND post_id <> %d\",\n"
     "                $aid, $post_id)) > 0) {\n"
     "            continue;\n"
     "        }",
     "        if (false) { continue; }",
     ["D.keep.othersthumb"], False),

    ("M9  trashing starts destroying files",
     "add_action('before_delete_post', 'lg_fc_delete_post_files');",
     "add_action('before_delete_post', 'lg_fc_delete_post_files');\n"
     "add_action('wp_trash_post', 'lg_fc_delete_post_files');",
     ["F.trash_keeps_files"], False),

    ("M10 the write-up validator stops stripping tags (ACF's own blind spot)",
     "    $text = trim(html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES, 'UTF-8'));",
     "    $text = (string) $value;",
     ["C.writeup.p_tags", "C.writeup.nbsp", "C.writeup.br"], False),

    ("M11 the photo count validator stops refusing",
     "    if (count($value) <= $max) {",
     "    if (true) {",
     ["B.count.11_refused"], False),

    ("M12 the collector forgets to check the flag",
     "function lg_fc_collect_unused(int $post_id): int\n{\n    if (!lg_fc_enabled()) {\n        return 0;\n    }",
     "function lg_fc_collect_unused(int $post_id): int\n{\n    if (false) {\n        return 0;\n    }",
     ["D.collect.unused_gone"], False),

    ("N1  CONTROL: a comment is reworded",
     "/** The stamp that makes the collector safe. See lg_fc_collect_unused(). */",
     "/** The stamp that makes the collector safe. (reworded by the red-first) */",
     [], True),

    ("N2  CONTROL: a local variable is renamed",
     "    $mb = $bytes / (1024 * 1024);\n"
     "    return ($mb >= 10 ? (string) round($mb) : number_format($mb, 1)) . 'MB';",
     "    $megabytes = $bytes / (1024 * 1024);\n"
     "    return ($megabytes >= 10 ? (string) round($megabytes) : number_format($megabytes, 1)) . 'MB';",
     [], True),
]


def main():
    snapshot()
    base_rc, base_failed, base = run_gate()
    if base_rc != 0:
        print("CANNOT RUN: the gate is not green before mutation (rc=%d)" % base_rc)
        print((base.stdout or "")[-1500:])
        return 2
    print("baseline: GREEN\n")

    good = bad = 0
    for label, find, repl, expect, noop in MUTATIONS:
        src = read()
        if src.count(find) != 1:
            print("  %-58s SKIPPED — anchor matched %d times (stale mutation)"
                  % (label, src.count(find)))
            bad += 1
            continue
        write(src.replace(find, repl, 1))
        lint = subprocess.run(["php", "-l", PLUGIN], capture_output=True, text=True)
        if lint.returncode != 0:
            print("  %-58s SKIPPED — mutation does not parse (a mutation must be "
                  "WRONG, not INVALID)" % label)
            restore()
            bad += 1
            continue
        rc, failed, _ = run_gate()
        restore()

        if noop:
            ok = rc == 0
            print("  %-58s %s" % (label, "STILL GREEN ✓" if ok else "REDDENED ✗ — a no-op must not"))
        else:
            missing = [e for e in expect if e not in failed]
            ok = rc != 0 and not missing
            detail = "RED on %s ✓" % ", ".join(expect) if ok else (
                "STAYED GREEN ✗" if rc == 0 else "red, but %s did not fire ✗" % ", ".join(missing))
            print("  %-58s %s" % (label, detail))
        good += 1 if ok else 0
        bad += 0 if ok else 1

    # The restore is VERIFIED, not assumed: a mutation left on disk is a defect
    # shipped, and it would look exactly like a passing run.
    with open(SNAP, "rb") as a, open(PLUGIN, "rb") as b:
        restored = a.read() == b.read()
    print("\nrestore verified byte-for-byte: %s" % ("yes" if restored else "NO — FIX BY HAND"))
    print("red-first: %d of %d behaved" % (good, good + bad))
    return 0 if (bad == 0 and restored) else 1


if __name__ == "__main__":
    sys.exit(main())
