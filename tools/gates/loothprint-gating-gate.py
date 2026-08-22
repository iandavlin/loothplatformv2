#!/usr/bin/env python3
"""gate 94 — on a Loothprint the tier gates the FILE, and it says so in download words.

#199. Ian, 2026-08-22, looking at a member's live Loothprint (post 72801, The
Cleanup Stik) while logged OUT, verbatim: "The gating is off too. We only need to
gate the file download and it shouldn't look like the video gate. I think there
is a block for that already available."

His screenshot showed TWO stacked, identical "MEMBERS-ONLY VIDEO" panels on a
page whose gallery was public. Both causes were measured before anything was
written, and this gate asserts that neither can come back:

  1. Renderer::AUTO_GATE_TYPES auto-gated `embed` from the post tier, so the
     build video became panel 1.
  2. The print file was emitted as a gated `callout variant=files`, and
     GateCta::variantFor() matches `callout` in NEITHER its download list nor its
     embed list — it falls through to the `embed` DEFAULT. So the ZIP drew the
     same card as the video. Panel 2, byte-identical to panel 1.

⚠️ WHY THIS ASSERTS THE RENDERED CARD AND NEVER THE BLOCK TYPE. The obvious gate
reads the synthesized layout back and checks it says `download`. That assertion
is TRUE of the broken build in the only way that matters: cause 2 above is not a
wrong block type, it is a right block type reaching a gate-CTA dispatch table
that has no arm for it. A layout can name the correct block and still paint the
video card. So every leg below reads `data-lg-gate="..."` and the eyebrow out of
the HTML a viewer would actually receive.

⚠️ AND WHY IT COUNTS PANELS RATHER THAN LOOKING FOR ONE. "There is a download
card" is true of the broken page too — it had a download card AND a video card.
Ian's third point was "no duplicate gate panels", so the assertion is a COUNT.

READS THE FLAG, NEVER HARDCODES A STATE (feedback-gate-reads-the-flag-not-a-
hardcoded-state). Every leg runs against the same build twice — once with
LG_V2_LOOTHPRINT_GATING unset, once with it armed — and asserts what each state
owes. So flipping the tracked default in config/loothprint-gating.php needs no
edit here, and the OFF state is checked rather than assumed inert.
LG_V2_LOOTHPRINT_GATING is passed through `env`, because sudo strips the
environment.

⚠️ IT MEASURES THIS TREE, NOT THE SERVE. lg-layout-v2 is symlinked into
~/loothplatformv2-clean, so a plain `wp eval-file` renders MAIN however correct
the branch is — the same trap that makes "verified on dev2" mean "verified main".
wp runs with --skip-plugins=lg-layout-v2 and the probe requires this tree's own
entry point; the probe echoes back the file the engine actually loaded and §0
asserts that path. Nothing on the serve is modified.

§F is #198 BUG A and has nothing to do with gating: a wp-admin metabox save used
to rebuild the layout from the form alone, and the form has no field for any
schema prop of type array or object — so a gallery came back without its
image_ids and a member's photos were gone. Six props across six blocks were in
that class. It is asserted here rather than in a gate of its own because it is
the same page, the same lane and the same probe post.

Exit: 0 green · 1 an open defect · 2 CANNOT RUN (never 3 — run-all.sh reads
anything that is not 0 or 2 as RED, so an environment failure must say 2).
"""

import json
import os
import subprocess
import sys

WP_PATH = "/var/www/dev"
PROBE_REL = "tools/gates/loothprint-gating-probe.php"

FAILS = []
CHECKS = [0]
TAG = "lg199g%d" % os.getpid()


def repo_root():
    return os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


def die(reason):
    print("GATE-ERROR  %s" % reason)
    print("############ GATE 94 CANNOT RUN ############")
    sys.exit(2)


def sh(cmd, timeout=180, extra_env=None):
    full = list(cmd)
    if extra_env:
        full = ["env"] + ["%s=%s" % kv for kv in sorted(extra_env.items())] + full
    return subprocess.run(["sudo", "-n", "-u", "looth-dev"] + full,
                          capture_output=True, text=True, timeout=timeout)


def check(label, got, want, detail=""):
    CHECKS[0] += 1
    if got == want:
        print("  ok   %-58s %s" % (label, got))
        return True
    print("  FAIL %-58s got %r, want %r %s" % (label, got, want, detail))
    FAILS.append("%s: got %r, want %r" % (label, got, want))
    return False


def refuse_off_dev2():
    """This gate mints rows. Live pulls all of main, so it must only run here."""
    r = sh(["wp", "--path=" + WP_PATH, "option", "get", "siteurl"])
    url = (r.stdout or "").strip()
    if "dev2.loothgroup.com" not in url:
        die("siteurl is %r — this gate mints rows and only ever runs on dev2" % url)


def probe(mode, tree, pid=0, flag=None):
    env = {"LG199_TREE": tree, "LG199_MODE": mode, "LG199_TAG": TAG}
    if pid:
        env["LG199_PID"] = str(pid)
    if flag is not None:
        env["LG_V2_LOOTHPRINT_GATING"] = flag
    r = sh(["wp", "--path=" + WP_PATH, "--skip-plugins=lg-layout-v2",
            "eval-file", os.path.join(tree, PROBE_REL)], extra_env=env)
    out = "\n".join(l for l in (r.stdout or "").splitlines()
                    if not l.startswith("PHP Warning") and not l.startswith("Warning:"))
    return r.returncode, out, (r.stderr or "")


def measure(tree, pid, flag):
    rc, out, err = probe("measure", tree, pid, flag)
    if rc != 0:
        die("probe(measure, flag=%s) exited %d: %s" % (flag, rc, err.strip()[:300]))
    line = next((l for l in out.splitlines() if l.startswith("{")), "")
    if not line:
        die("probe(measure, flag=%s) printed no JSON: %s" % (flag, (out + err)[:300]))
    return json.loads(line)


def main():
    tree = repo_root()
    probe_path = os.path.join(tree, PROBE_REL)
    if not os.path.isfile(probe_path):
        die("no probe at %s" % probe_path)
    if not os.path.isfile(os.path.join(tree, "lg-layout-v2/lg-layout-v2.php")):
        die("no lg-layout-v2 in %s" % tree)
    refuse_off_dev2()

    print("=== GATE 94: a Loothprint gates its FILE, in download words, once ===")
    print("    tree under test: %s" % tree)

    rc, out, err = probe("setup", tree)
    if rc != 0:
        die("probe(setup) exited %d: %s" % (rc, err.strip()[:300]))
    pid = 0
    for line in out.splitlines():
        if line.startswith("POST_ID="):
            pid = int(line.split("=", 1)[1])
    if not pid:
        die("probe(setup) minted no post: %s" % (out + err)[:300])
    print("    per-run probe post: %d (tag %s)" % (pid, TAG))

    try:
        off = measure(tree, pid, None)
        on = measure(tree, pid, "1")
        run_legs(tree, off, on)
    finally:
        rc, out, _ = probe("teardown", tree)
        print("    teardown: %s" % (out.strip() or "rc=%d" % rc))

    print()
    if FAILS:
        print("############ GATE 94 RED — %d of %d checks failed ############"
              % (len(FAILS), CHECKS[0]))
        for f in FAILS:
            print("  - %s" % f)
        return 1
    print("############ GATE 94 GREEN — %d checks ############" % CHECKS[0])
    return 0


def run_legs(tree, off, on):
    # ── §0 liveness: we measured the BRANCH, and the flag really moved ───────
    print("\n  §0 the probe measured this tree, and the flag actually moved")
    check("engine loaded from this tree",
          off["engine_file"].startswith(tree), True,
          "(loaded %s)" % off["engine_file"])
    check("flag reads OFF with nothing set", off["flag"], False)
    check("flag reads ON via env", on["flag"], True)
    # Without this the two states could be the same render and every
    # per-state assertion below would be comparing a thing to itself.
    check("the two states are not the same HTML",
          off["anon"]["bytes"] != on["anon"]["bytes"], True)
    check("the post really carries a tier", off["post_tier"], "looth-lite")

    def block(state, bid):
        return next((b for b in state["blocks"] if b["id"] == bid), None)

    # ── §A the OFF state is the defect, exactly as reported ─────────────────
    # Not decoration: this is what makes every ON assertion below a measured
    # improvement rather than a description of a build nobody compared.
    print("\n  §A flag OFF reproduces what Ian photographed")
    # THREE, not the two in his screenshot, and the difference is the point.
    # Live 72801 had an EMPTY OnShape field, so it showed video + file. This
    # probe carries a CAD link, so the same defect produces video + file + CAD.
    # Every gated block on a print was drawing the video card, and the count a
    # member saw was simply however many of those fields the author filled in.
    check("anon sees THREE gate panels", len(off["anon"]["gate_variants"]), 3)
    check("...and every one wears the video face",
          set(off["anon"]["gate_variants"]), {"embed"})
    check("anon does NOT get the video", off["anon"]["video_payload"], False)
    check("the print file is a callout", (block(off, "lp_download") or {}).get("type"), "callout")

    # ── §B ON: the media is public ──────────────────────────────────────────
    print("\n  §B flag ON — gallery, write-up and video are public")
    check("anon gets the real video payload", on["anon"]["video_payload"], True)
    check("the embed block carries no gate", (block(on, "lp_video") or {}).get("gated_tier"), "")
    # scrubGatedAnchors used to strip this whenever the post carried a tier.
    check("a youtube link in the prose survives", on["anon"]["prose_yt_anchor"], True)
    check("...and OFF it was still being stripped", off["anon"]["prose_yt_anchor"], False)

    # ── §C ON: exactly one gate, and it is the download ─────────────────────
    print("\n  §C flag ON — one gate panel, wearing the download face")
    check("anon sees exactly ONE gate panel", len(on["anon"]["gate_variants"]), 1)
    check("...and it is the download variant", on["anon"]["gate_variants"], ["download"])
    check("...with download words", on["anon"]["gate_eyebrow"], "Members-only download")
    check("...never the video eyebrow",
          "video" in on["anon"]["gate_eyebrow"].lower(), False)
    # The card was written against the callout's items[0] shape and would fall
    # back to the generic "Members-only content" on a download block.
    check("...naming the actual file",
          on["anon"]["gate_headline"].startswith("the-print.zip"), True,
          "(headline %r)" % on["anon"]["gate_headline"])
    check("...and its type", "ZIP" in on["anon"]["gate_headline"], True)
    # The teaser names the file. It must never hand over its address.
    check("the gate card leaks NO zip url", on["anon"]["zip_href"], False)
    check("the print file is a download block", (block(on, "lp_download") or {}).get("type"), "download")
    # Without a baked file_id the standalone renderer resolves nothing and the
    # download vanishes from the served page entirely.
    check("...with its file_id baked", (block(on, "lp_download") or {}).get("file_id") > 0, True)
    check("...still gated to the post tier",
          (block(on, "lp_download") or {}).get("gated_tier"), "looth-lite")
    # Ian's ruling names the file. A second gated block is a second panel.
    check("the CAD link is not a second gate",
          (block(on, "lp_onshape") or {}).get("gated_tier"), "")

    # ── §D ON: the member gets the thing they paid for ──────────────────────
    print("\n  §D flag ON — a member gets the file, and no card")
    check("member sees no gate panel", len(on["member"]["gate_variants"]), 0)
    check("member gets the zip href", on["member"]["zip_href"], True)
    check("member gets the download row", on["member"]["download_row"], True)
    check("member gets the video too", on["member"]["video_payload"], True)

    # ── §E the ghost tile — #198 BUG B, and it is unflagged ─────────────────
    print("\n  §E tile count == image count, in BOTH states (unflagged repair)")
    for name, st in (("OFF", off), ("ON", on)):
        # The probe puts the ZIP in more_images on purpose: that was the pinned
        # cause. It is not today's cause, but it must not make a tile either.
        check("[%s] a reader gets two tiles" % name, st["anon"]["tiles"], 2)
        check("[%s] ...and no placeholder" % name, st["anon"]["placeholders"], 0)
        check("[%s] the ZIP is not a gallery member" % name,
              len((block(st, "lp_gallery") or {}).get("image_ids", [])), 2)
    # The pad is an author affordance, not a bug — it must survive where it helps.
    check("edit mode still pads to three", off["anon_editor"]["tiles"]
          + off["anon_editor"]["placeholders"], 3)
    check("...and the pad is what makes up the difference",
          off["anon_editor"]["placeholders"], 1)

    # ── §F the poisoner — #198 BUG A ────────────────────────────────────────
    print("\n  §F a metabox save cannot empty a list it has no field for")
    rc, out = metabox_roundtrip(tree)
    if rc != 0:
        FAILS.append("metabox round-trip probe failed: %s" % out[:200])
        CHECKS[0] += 1
        print("  FAIL %-58s %s" % ("metabox round-trip ran", out[:120]))
        return
    res = json.loads(out)
    check("the metabox loaded from this tree",
          res["metabox_file"].startswith(tree), True, "(loaded %s)" % res["metabox_file"])
    # Liveness: if the form walker stopped producing anything, "image_ids
    # survived" would be true of a probe that measured nothing.
    check("the form walker still produces the scalar props",
          sorted(res["form_only"]), ["columns", "id", "layout", "type", "variant"])
    check("...and drops image_ids, as it always has",
          "image_ids" in res["form_only"], False)
    check("the carry-across restores image_ids", res["carried"], [4001, 4002])
    check("a slot that changed type carries nothing", res["type_change_carried"], False)
    check("a columns container is NOT carried", res["columns_carried"], False)


def metabox_roundtrip(tree):
    """Round-trip a gallery through the real MetaBox parser + carry-across.

    Plain PHP with an absolute require, never through WordPress: under WP the
    autoloader resolves MetaBox out of the SERVING checkout, which is main, and
    main is the broken state being tested for. The probe echoes the file it
    loaded and §F asserts that path.
    """
    php = r'''<?php
$T = getenv('LG199_TREE');
require_once "$T/lg-layout-v2/src/Autoload.php";
use LG\LayoutV2\Manifest;
Manifest::configure("$T/lg-layout-v2/blocks");
$m = Manifest::get('gallery');

/* The exact skip the metabox form walker performs. */
$raw = ['type'=>'gallery','id'=>'g1','columns'=>'3','layout'=>'grid','variant'=>'variant-1'];
$block = ['type'=>'gallery','id'=>'g1'];
foreach ($m['schema']['props'] as $p => $d) {
    $t = (string)($d['type'] ?? 'string');
    if ($t === 'array_of_objects' || $t === 'array' || $t === 'object') continue;
    if (!array_key_exists($p, $raw)) continue;
    $block[$p] = $t === 'integer' ? (int)$raw[$p] : (string)$raw[$p];
}
$formOnly = array_keys($block);

$prev = ['g1' => ['type'=>'gallery','id'=>'g1','image_ids'=>[4001,4002]]];
$rm = new ReflectionMethod('LG\LayoutV2\MetaBox', 'carry_unrepresented_props');
$rm->setAccessible(true);
$rm->invokeArgs(null, [&$block, $m, $prev]);

/* A recycled id on a different type must not inherit the old props. */
$other = ['type'=>'wysiwyg','id'=>'g1'];
$rm->invokeArgs(null, [&$other, Manifest::get('wysiwyg'), $prev]);

/* The columns container is rebuilt from nested slots — carrying it would undo
   every add/remove/move made in the same submit. */
$cols = ['type'=>'columns','id'=>'c1'];
$prevCols = ['c1' => ['type'=>'columns','id'=>'c1','columns'=>[['blocks'=>[]],['blocks'=>[]]]]];
$rm->invokeArgs(null, [&$cols, Manifest::get('columns'), $prevCols]);

echo json_encode([
  'metabox_file'        => (new ReflectionClass('LG\LayoutV2\MetaBox'))->getFileName(),
  'form_only'           => $formOnly,
  'carried'             => array_values((array)($block['image_ids'] ?? [])),
  'type_change_carried' => array_key_exists('image_ids', $other),
  'columns_carried'     => array_key_exists('columns', $cols),
]);
'''
    path = "/tmp/lg199-metabox-%s.php" % TAG
    with open(path, "w") as fh:
        fh.write(php)
    os.chmod(path, 0o644)
    try:
        r = subprocess.run(["env", "LG199_TREE=" + tree, "php", path],
                           capture_output=True, text=True, timeout=60)
        line = next((l for l in (r.stdout or "").splitlines() if l.startswith("{")), "")
        if r.returncode != 0 or not line:
            return 1, ((r.stdout or "") + (r.stderr or ""))
        return 0, line
    finally:
        try:
            os.unlink(path)
        except OSError:
            pass


if __name__ == "__main__":
    try:
        sys.exit(main())
    except subprocess.TimeoutExpired as e:
        die("timed out: %s" % e)
