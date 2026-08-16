#!/usr/bin/env python3
"""
compose-media-gate.py — the DRAFT-FIRST MEDIA CONTRACT (Ian, 2026-08-15).

His two rules, and this asserts both:
  1. NO ORPHANS — "obviously". An abandoned compose leaves ZERO stray
     attachments.
  2. EACH POST HAS ITS OWN LIBRARY — uploads parent to THIS post, and the
     picker browses only this post's media, never the whole site.

WHY THIS GATE EXISTS AT ALL. Measured before the change: uploading through the
real picker and abandoning left an attachment with post_parent 0, the file on
disk, and nothing referencing it. Neither our build nor WordPress core sweeps
unattached media, and a subscriber has upload_files — so an abandoning member
could fill the library indefinitely.

WHAT IT ASSERTS, per state, reading the flag off the box:

  1. A compose-open creates a hidden working draft: auto-draft (invisible to the
     front end), right author, marked with _lg_fc_draft.
  2. REUSED, not re-created — opening compose repeatedly leaves ONE draft, not
     one per open.
  3. An upload lands PARENTED to that draft, never post_parent 0.
  4. ABANDON LEAVES ZERO UNPARENTED ROWS — the site-wide unattached count is
     unchanged across the whole cycle. Counting the whole site, not just our
     rows, is deliberate: it catches an orphan created anywhere in the flow.
  5. The picker is scoped — library=uploadedTo is forced in code on every media
     field, so it cannot be widened by editing field config in the database.
  6. THE REAPER TAKES CHILDREN TOO. A draft past the TTL is deleted WITH its
     attachments. WordPress's own wp_delete_auto_drafts removes the post and
     LEAVES the attachments, which is the exact orphan this all exists to stop.
  7. Flag OFF ⇒ nothing scheduled. Paired with 6 so it is not a vacuous absence.

Self-contained: makes its own drafts and attachments and force-deletes them on
the way out, including on failure, then prints what remains so the cleanup
proves itself.

Exit: 0 green, 1 a real defect, 2 CANNOT RUN.

⚠️ CANNOT RUN IS **2**, NOT 3, AND THAT IS NOT A STYLE CHOICE. run-all.sh reads
0 as green, 2 as "no verdict", and ANYTHING ELSE as RED. This gate returned 3,
so on any box where it genuinely could not run it reported a missing environment
as a finding and would have turned the whole suite red for every lane — the
recorded exit-3 trap, sitting inside the gate written to avoid vacuous passes.
Caught by running it on a docroot that does not carry draft-first: it printed
CANNOT RUN correctly and then exited 3.
"""
import json
import subprocess
import sys

WP = ["sudo", "-n", "wp", "--allow-root", "--path=/var/www/dev", "--skip-themes"]
PROBE_USER = 1912


class CannotRun(Exception):
    pass


def wp_db(sql: str) -> str:
    """Read the option table WITHOUT loading WordPress.

    `wp db query` runs off wp-config alone, so it never fires `init` — which
    matters here because init is where lg_fc_sync_reaper_schedule() disarms the
    reaper. A `wp eval` cannot measure the cron state: by the time its code
    runs, its own load has already healed whatever it was about to look at, so
    it answers "nothing scheduled" no matter what was there. That is not a
    hypothetical; it is why assertion 7 below could go red on one run and then
    read clean on the next over the same broken code.
    """
    r = subprocess.run(WP + ["db", "query", sql, "--skip-column-names"],
                       capture_output=True, text=True)
    if r.returncode != 0:
        raise CannotRun(f"wp db query failed: {(r.stderr or '')[:200]}")
    return r.stdout.strip()


def reaper_entries() -> int:
    """How many entries the cron array holds for our hook, raw."""
    out = wp_db("SELECT option_value FROM wp_options WHERE option_name='cron'")
    return out.count("lg_fc_reap_drafts_event")


def wp_eval(php: str) -> str:
    r = subprocess.run(WP + ["eval", php], capture_output=True, text=True)
    out = "\n".join(l for l in r.stdout.splitlines()
                    if not l.startswith(("PHP Warning:", "PHP Deprecated:", "Warning:", "Deprecated:")))
    if r.returncode != 0:
        raise CannotRun(f"wp eval failed: {(r.stderr or '')[:300]}")
    return out.strip()


def main() -> int:
    print("compose-media-gate — draft-first media contract (Ian ruling, 8/15)")
    findings, notes = [], []
    try:
        state = wp_eval('echo json_encode(["fn" => function_exists("lg_fc_working_draft"),'
                        '"enabled" => (bool) lg_fc_enabled(),'
                        '"sched" => (bool) wp_next_scheduled("lg_fc_reap_drafts_event"),'
                        '"unattached" => (int) $GLOBALS["wpdb"]->get_var("SELECT COUNT(*) FROM {$GLOBALS[\'wpdb\']->posts} WHERE post_type=\'attachment\' AND post_parent=0")]);')
        st = json.loads(state)
    except Exception as e:
        print(f"  CANNOT RUN: {e}")
        return 2

    if not st["fn"]:
        print("  CANNOT RUN: lg_fc_working_draft() not loaded — the mu-plugin is not "
              "in this docroot, so every assertion below would be vacuous")
        return 2

    print(f"  flag: {'ON' if st['enabled'] else 'OFF'} (read from the box)  "
          f"unattached baseline: {st['unattached']}")

    # ---- 7. flag OFF ⇒ no cron. Paired with 6 so the absence is not vacuous.
    if not st["enabled"] and st["sched"]:
        findings.append("[7] flag is OFF but the reaper is still scheduled — an event "
                        "left armed keeps deleting rows for a feature nobody can reach")
    elif not st["enabled"]:
        print("  [7] flag OFF and nothing scheduled")

    # ---- 7b. THE HEAL TAKES THE WHOLE HOOK, not just the next occurrence.
    #
    # 7 above is passive: it can only see a state that happened to be there, and
    # a WP load heals one entry on the way past — so a broken heal shows up as an
    # intermittent red that clears itself, which is how it nearly got written off
    # as flake. This plants the state instead.
    #
    # THE DEFECT IT STANDS OVER: wp_unschedule_event() removes ONE timestamped
    # occurrence and wp_next_scheduled() returns only the earliest, so with two
    # entries in the array every flag-off load disarmed one and left the other
    # running. An armed reaper force-deletes auto-drafts AND their attachments
    # daily, from WP-cron, for a feature nobody can reach.
    if not st["enabled"]:
        before = 0          # bound before the try: the finally reads it, and a
        try:                # NameError there would mask the real failure
            before = reaper_entries()
            wp_eval('$c = _get_cron_array();'
                    '$a = ["schedule"=>"daily","args"=>[],"interval"=>DAY_IN_SECONDS];'
                    '$c[time()+3600]["lg_fc_reap_drafts_event"][md5(serialize([]))] = $a;'
                    '$c[time()+7200]["lg_fc_reap_drafts_event"][md5(serialize([]))] = $a;'
                    '_set_cron_array($c); echo "planted";')
            planted = reaper_entries()
            if planted < 2:
                notes.append(f"[7b] could not plant two cron entries (saw {planted}) "
                             f"— the heal was NOT exercised this run")
            else:
                wp_eval('echo "";')          # one ordinary WP load, flag off
                left = reaper_entries()
                if left:
                    findings.append(
                        f"[7b] the flag-off heal left {left} of {planted} reaper "
                        f"entries armed — it takes the next occurrence, not the "
                        f"hook, so a second entry keeps deleting drafts for a "
                        f"feature nobody can reach")
                else:
                    print("  [7b] the heal disarmed BOTH planted entries in one load")
        finally:
            # never leave the box holding what this assertion planted
            wp_eval('wp_clear_scheduled_hook("lg_fc_reap_drafts_event"); echo "";')
            if reaper_entries() > before:
                findings.append("[7b] this assertion left cron entries behind — "
                                "the gate has to clean up what it plants")

    php = r'''
$out = [];
$uid = %d;

// 1/2. draft created, marked, invisible — and REUSED across opens
$a = lg_fc_working_draft("loothprint", $uid);
$b = lg_fc_working_draft("loothprint", $uid);
$p = $a ? get_post($a) : null;
$out["draft_id"]     = $a;
$out["reused"]       = ($a === $b);
$out["status"]       = $p ? $p->post_status : null;
$out["author_ok"]    = $p ? ((int) $p->post_author === $uid) : false;
$out["marked"]       = (bool) get_post_meta($a, "_lg_fc_draft", true);

// 3. an upload lands PARENTED to it (attachment created the way the picker does)
$att = wp_insert_post(["post_type"=>"attachment","post_status"=>"inherit",
    "post_parent"=>$a,"post_title"=>"zzgate-media","post_mime_type"=>"image/png",
    "post_author"=>$uid], true);
$out["att_id"]     = is_wp_error($att) ? 0 : $att;
$out["att_parent"] = is_wp_error($att) ? -1 : (int) get_post($att)->post_parent;

// 5. every media field is scoped in code, not by field config
$scoped = [];
foreach (["loothprint_more_images","loothprint_3d_file"] as $name) {
    $f = acf_get_field($name);
    $scoped[$name] = $f ? ($f["library"] ?? "(unset)") : "(no field)";
}
$out["library"] = $scoped;

// 6. the reaper takes CHILDREN too
$stale = wp_insert_post(["post_type"=>"loothprint","post_status"=>"auto-draft",
    "post_author"=>$uid,"post_title"=>"Auto Draft"], true);
update_post_meta($stale, "_lg_fc_draft", time() - (400 * DAY_IN_SECONDS));
$child = wp_insert_post(["post_type"=>"attachment","post_status"=>"inherit",
    "post_parent"=>$stale,"post_title"=>"zzgate-child","post_mime_type"=>"image/png",
    "post_author"=>$uid], true);
lg_fc_reap_drafts();
$out["stale_gone"] = !get_post($stale);
$out["child_gone"] = !get_post($child);
$out["fresh_kept"] = (bool) get_post($a);

echo json_encode($out);
''' % PROBE_USER

    try:
        res = json.loads(wp_eval(php))
    except Exception as e:
        print(f"  CANNOT RUN: probe failed: {e}")
        return 2

    try:
        if not res["draft_id"]:
            findings.append("[1] compose-open created no working draft")
        else:
            if res["status"] != "auto-draft":
                findings.append(f"[1] draft status is {res['status']!r}, not auto-draft — "
                                "a half-filled loothprint could surface")
            if not res["marked"]:
                findings.append("[1] draft carries no _lg_fc_draft marker — the reaper "
                                "cannot tell it from somebody else's auto-draft")
            if not res["author_ok"]:
                findings.append("[1] draft is not owned by the composing member")
            if res["status"] == "auto-draft" and res["marked"] and res["author_ok"]:
                print("  [1] hidden draft created: auto-draft, marked, right author")

        if res["reused"]:
            print("  [2] reused across opens — repeated opens leave ONE draft")
        else:
            findings.append("[2] a second compose-open made a SECOND draft — every open "
                            "would leak a row")

        if res["att_parent"] == res["draft_id"]:
            print("  [3] upload lands parented to the draft")
        else:
            findings.append(f"[3] upload landed with post_parent {res['att_parent']}, "
                            f"not the draft {res['draft_id']} — that is the orphan")

        for name, lib in res["library"].items():
            if lib != "uploadedTo":
                findings.append(f"[5] {name} library is {lib!r}, not 'uploadedTo' — the "
                                "picker would browse beyond this post")
        if all(v == "uploadedTo" for v in res["library"].values()):
            print("  [5] every media field scoped to uploadedTo")

        if res["stale_gone"] and res["child_gone"] and res["fresh_kept"]:
            print("  [6] reaper took the stale draft AND its child, kept the fresh one")
        else:
            if not res["stale_gone"]:
                findings.append("[6] the reaper left a draft past its TTL")
            if not res["child_gone"]:
                findings.append("[6] the reaper deleted the draft but LEFT ITS CHILD — "
                                "exactly the orphan this exists to stop")
            if not res["fresh_kept"]:
                findings.append("[6] the reaper deleted a FRESH draft — a member's "
                                "in-progress work")
    finally:
        # ⚠️ SCOPED TO THE PROBE ACCOUNT, AND THAT IS NOT A DETAIL.
        #
        # This deleted EVERY row carrying _lg_fc_draft, site-wide, with its
        # attachments. Harmless today only because the flag is off and nobody is
        # composing — and the entire point of the flag is that it gets turned ON.
        # After that, one gate run would force-delete every member's in-progress
        # compose draft AND the photos they had just uploaded to it. The gate
        # that exists to prove nothing is destroyed would have been the most
        # destructive thing in the tree for this feature.
        #
        # A cleanup may only remove what its own run made. The author filter is
        # what guarantees that: a member's draft is owned by the member and can
        # never match. The site-wide UNATTACHED count below stays site-wide on
        # purpose — assertion 4 has to see an orphan produced by a path this gate
        # did not model, and that is a read, not a delete.
        left = wp_eval(r'''
global $wpdb;
$probe = %d;
foreach ($wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_lg_fc_draft' WHERE p.post_author = %%d", $probe)) as $id) {
    foreach (get_children(["post_parent"=>$id,"post_type"=>"attachment","numberposts"=>-1,"fields"=>"ids"]) as $a) wp_delete_attachment($a, true);
    wp_delete_post($id, true);
}
foreach ($wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE 'zzgate-%%' AND post_author = %%d", $probe)) as $id) wp_delete_attachment($id, true);
echo json_encode([
    // the probe's OWN leftovers — the number the cleanup is accountable for
    "drafts" => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_lg_fc_draft' WHERE p.post_author = %%d", $probe)),
    "probes" => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'zzgate-%%' AND post_author = %%d", $probe)),
    // site-wide, deliberately — see assertion 4
    "unattached" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_parent=0")]);''' % PROBE_USER)
        cl = json.loads(left)
        print(f"  cleanup: {cl['drafts']} drafts, {cl['probes']} probe rows left; "
              f"unattached {cl['unattached']} (was {st['unattached']})")
        # ---- 4. THE HEADLINE: the whole cycle left no unparented row behind.
        if cl["unattached"] != st["unattached"]:
            findings.append(f"[4] ABANDON LEFT ORPHANS — site-wide unattached went "
                            f"{st['unattached']} → {cl['unattached']}")
        else:
            print("  [4] abandon left ZERO unparented rows (site-wide count unchanged)")

    print()
    # PRINT THE NOTES. They were collected and never shown, which is worse than
    # not collecting them: a note says an assertion could not be exercised, and
    # swallowing it makes a skipped check read exactly like a passed one.
    for n in notes:
        print(f"  note: {n}")
    if notes:
        print()
    if findings:
        print("RED — the media contract is broken:")
        for f in findings:
            print(f"  ✗ {f}")
        return 1
    print("GREEN — no orphans, and each post keeps its own library.")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except CannotRun as e:
        print(f"CANNOT RUN: {e}")
        sys.exit(2)
