<?php
/**
 * _load-under-test.php — load a branch class WITHOUT double-declaring it, and
 * without silently testing the wrong copy.
 *
 * ── WHY THIS EXISTS: THE SUITE ONLY WORKED WHILE THE CODE WAS UNDEPLOYED ─────
 *
 * Every test here runs under `wp eval-file` against the SERVING WordPress, but
 * requires its subject from the WORKTREE. That worked for as long as the serving
 * WP did not declare those classes itself.
 *
 * On 2026-07-30 the branch merged and `lg-weekly-digest` went ACTIVE at 3.0.1 in
 * the serving checkout. WP now declares LG_WD_Recap at boot. `require_once` keys
 * on the resolved PATH, and the worktree path is a different path, so it does not
 * dedupe — it re-declares. Result:
 *
 *     Fatal error: Cannot declare class LG_WD_Recap, because the name is already
 *     in use in .../worktrees/.../class-lg-wd-recap.php on line 36
 *
 * Six tests went from GREEN to fatal with nobody touching a test. The guard on the
 * digest died at exactly the moment the digest went live — the inverse of
 * "a branch that never fires is untested": a harness that only passes pre-merge.
 *
 * ── WHY NOT JUST `if (!class_exists(...))` ──────────────────────────────────
 *
 * Because that fixes the fatal by quietly switching the subject. The test would
 * then exercise the DEPLOYED class while its name, its path and its report all
 * still say it is testing the branch. That is CLAUDE.md rule 4 — verify the thing,
 * not the thing next to it — and it is the same vacuous-pass family as the red
 * proof that passed because `sudo` dropped the environment.
 *
 * So this helper reports WHICH BYTES it proved, and refuses to run when they are
 * not the bytes you asked for:
 *
 *   class not yet declared  -> require the worktree copy.        UNDER TEST: branch
 *   declared, bytes equal   -> reuse it, say so.                 UNDER TEST: branch (== deployed)
 *   declared, bytes DIFFER  -> exit 2, CANNOT RUN.               proves nothing; says so loudly
 *
 * Exit 2 is deliberate: run-suite.sh reports CANNOT RUN louder than RED, because a
 * failing test is doing its job and a dead one is lying.
 */

if (!function_exists('lg_wd_load_under_test')) {

/**
 * @param string $branchFile Absolute path to the branch copy of the class file.
 * @param string $class      The class it declares.
 */
function lg_wd_load_under_test(string $branchFile, string $class): void
{
    if (!is_file($branchFile)) {
        fwrite(STDERR, "CANNOT RUN: no such file under test: $branchFile\n");
        exit(2);
    }

    if (!class_exists($class, false)) {
        require_once $branchFile;
        if (!class_exists($class, false)) {
            fwrite(STDERR, "CANNOT RUN: $branchFile did not declare $class\n");
            exit(2);
        }
        echo "UNDER TEST: $class from BRANCH $branchFile\n";
        return;
    }

    // Already declared by the serving WP. Prove it is the same code before reusing it.
    $ref    = new ReflectionClass($class);
    $loaded = (string) $ref->getFileName();

    $branchBytes = (string) file_get_contents($branchFile);
    $loadedBytes = is_file($loaded) ? (string) file_get_contents($loaded) : '';

    if ($loadedBytes === '' || hash('sha256', $branchBytes) !== hash('sha256', $loadedBytes)) {
        fwrite(STDERR,
            "CANNOT RUN: $class is already loaded from a DIFFERENT copy.\n" .
            "  loaded : $loaded\n" .
            "  branch : $branchFile\n" .
            "  These bytes differ, so this test would prove the deployed copy, not your change.\n" .
            "  Fix: merge+pull so the serve matches, or run this test where the class is not preloaded.\n");
        exit(2);
    }

    echo "UNDER TEST: $class from $loaded (byte-identical to branch copy)\n";
}

}
