#!/usr/bin/env python3
"""
license-insert-gate.py — the READ-PATH UPGRADE contract for layout-v2.

Ian, 2026-08-15 (ruling 7): "V2 MAY INSERT a missing block into a stored page …
Scope guard: inserts only SURFACE what the author already declared in the form,
never invent content. Red-first gate on the insert path."

This gate asserts that rule against EVERY stored layout on the box, not a
fixture — because the rule is about real authors' pages, and the thing that
would hurt is a page gaining a licence its author never chose, or gaining a
SECOND licence next to one it already states in prose.

WHAT IT ASSERTS, per state, reading the flags off the box rather than assuming:

  OFF  1. Flags off ⇒ plan_inserts() is the IDENTITY function on every stored
          layout. Byte-identical, not merely "nothing obviously added".
       2. LIVENESS: the OFF assertion is not vacuous — the same corpus with the
          flags ON does change. An absence assertion without a liveness partner
          is true on an empty corpus, which is how "flag OFF ⇒ no-op" passes on
          a box with no data at all.

  ON   3. NEVER INVENT: no post whose author declared no licence gains one.
       4. NEVER DUPLICATE: no page ends up stating a licence twice — neither two
          `license` blocks, nor a `license` block beside licence-ish prose.
          Post 71142 is the live case: hand-written CC prose the strict
          recogniser deliberately will not swap.
       5. SURFACE ONLY: every inserted taxonomy block belongs to a post that
          actually carries a term.
       6. POSITION: an inserted block lands BEFORE post-footer, never after.
       7. IDEMPOTENT: running the upgrade twice inserts nothing the second time.

Exit: 0 green, 1 a real defect, 3 CANNOT RUN (environment, not a finding).
"""
import json
import os
import subprocess
import sys

WP_PATH = "/var/www/dev"
# Resolve from the repo THIS gate runs in — never another lane's desk. The old
# absolute paths pointed into the long-deleted frontend-compose worktree; the
# gate threw on import, run-all read that as RED, and its early-exit skipped
# TWENTY later gates for every lane (#153; found by lanes 148 and 150, 8/20).
_REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
LICENSES = os.path.join(_REPO, "lg-layout-v2/src/Licenses.php")
LAYOUT_UPGRADE = os.path.join(_REPO, "lg-layout-v2/src/LayoutUpgrade.php")


class CannotRun(Exception):
    pass


def _db(sql: str) -> list:
    """Raw MySQL, deliberately NOT a WP bootstrap.

    dev2 has an object-cache.php drop-in, and when Redis wedges (it did, on a
    full disk, 2026-08-15) every `wp eval` dies with 'Error establishing a Redis
    connection' — which would make this gate report a BOX fault as if the insert
    rule were unrunnable. `wp db query` also proved unreliable here: it swallows
    the result set of some SELECTs and prints "Query succeeded" instead of rows,
    which reads as an empty corpus and would turn every assertion vacuous.
    Straight mysql -N -B is the only form that reliably returns rows.
    """
    script = (
        'cd %s; '
        'U=$(wp --allow-root config get DB_USER); '
        'P=$(wp --allow-root config get DB_PASSWORD); '
        'H=$(wp --allow-root config get DB_HOST); '
        'N=$(wp --allow-root config get DB_NAME); '
        'mysql -u"$U" -p"$P" -h"$H" -N -B -e "$1" "$N"'
    ) % WP_PATH
    # SQL goes as a POSITIONAL ARG, not an env var: sudo strips the environment,
    # so an exported $LG_SQL arrives empty and mysql runs nothing — which reads
    # as "no rows" and would make every assertion below vacuously green.
    r = subprocess.run(["sudo", "-n", "bash", "-c", script, "_", sql],
                       capture_output=True, text=True)
    if r.returncode != 0:
        real = "\n".join(l for l in r.stderr.splitlines()
                          if "password on the command line" not in l.lower() and l.strip())
        raise CannotRun(f"mysql failed (rc={r.returncode}): {(real or r.stderr).strip()[:400]}")
    rows = []
    for line in r.stdout.splitlines():
        if not line.strip():
            continue
        rows.append(line.split("\t"))
    return rows


def corpus() -> list:
    """Every stored layout plus the two facts the insert rule may use.

    Pulled from the DB rather than a fixture: the rule protects real pages, and
    a fixture cannot contain the one hand-written licence (post 71142) that
    makes duplicate-suppression matter. Layouts come back base64'd because the
    stored value is PHP-SERIALIZED and contains tabs/newlines that would shred
    a TSV parse.
    """
    # MySQL's TO_BASE64 wraps at 76 chars; the newlines would shred the row parse.
    layouts = _db("SELECT post_id, REPLACE(TO_BASE64(meta_value), CHAR(10), '') "
                  "FROM wp_postmeta WHERE meta_key='_lg_layout_v2' AND meta_value <> ''")
    lic = {r[0]: r[1] for r in _db(
        "SELECT post_id, meta_value FROM wp_postmeta "
        "WHERE meta_key='loothprint_creative_commons'") if len(r) > 1}
    filed = {r[0] for r in _db(
        "SELECT DISTINCT tr.object_id FROM wp_term_relationships tr "
        "JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id "
        "WHERE tt.taxonomy IN ('loothprint_type','shared_category')") if r}

    out = []
    for row in layouts:
        if len(row) < 2:
            continue
        pid, b64 = row[0], row[1]
        out.append({"id": pid, "b64": b64,
                    "licence_raw": lic.get(pid, ""), "filed": pid in filed})
    return out


def plan(rows: list, want_license: bool, want_taxonomy: bool) -> dict:
    """Run the REAL LayoutUpgrade::plan_inserts, in plain PHP.

    plan_inserts is pure precisely so this is possible: no WordPress, no cache,
    no plugin. A gate that needed the branch symlinked over the serve could only
    ever run inside a maintenance window.
    """
    payload = json.dumps({"rows": rows, "wl": want_license, "wt": want_taxonomy})
    php = r'''<?php
require_once getenv("LG_LICENSES");
require_once getenv("LG_LAYOUT_UPGRADE");
$in = json_decode(file_get_contents("php://stdin"), true);
$out = [];
foreach ($in["rows"] as $r) {
    $layout = unserialize(base64_decode($r["b64"]));
    if (!is_array($layout)) { $layout = json_decode(base64_decode($r["b64"]), true); }
    if (!is_array($layout) || empty($layout["blocks"])) continue;
    $declared = \LG\LayoutV2\Licenses::from_meta((string) $r["licence_raw"]);
    $after = \LG\LayoutV2\LayoutUpgrade::plan_inserts(
        $layout, $declared, (bool) $r["filed"], (bool) $in["wl"], (bool) $in["wt"]);
    $out[$r["id"]] = ["before" => $layout, "after" => $after, "declared" => $declared];
}
echo json_encode($out);
'''
    r = subprocess.run(
        ["php", "-r", php.split("<?php", 1)[1]],
        input=payload, capture_output=True, text=True,
        env={**os.environ, "LG_LICENSES": LICENSES, "LG_LAYOUT_UPGRADE": LAYOUT_UPGRADE},
    )
    if r.returncode != 0:
        raise CannotRun(f"plan_inserts failed (rc={r.returncode}): {r.stderr.strip()[:400]}")
    try:
        return json.loads(r.stdout)
    except json.JSONDecodeError as e:
        raise CannotRun(f"plan JSON did not parse: {e} :: {r.stdout[:200]}")


def types(layout: dict) -> list:
    return [b.get("type") for b in layout.get("blocks", []) if isinstance(b, dict)]


def looks_like_licence(text: str) -> bool:
    import re
    t = (text or "").strip()
    if not t:
        return False
    if "creative commons" in t.lower():
        return True
    return bool(re.search(r"\bCC[\s-]?BY\b", t, re.I) or re.search(r"\bBY[\s-]+(NC|SA|ND)\b", t, re.I))


def prose_licence_present(layout: dict) -> bool:
    import re
    for b in layout.get("blocks", []):
        if not isinstance(b, dict) or b.get("type") != "callout":
            continue
        body = re.sub(r"<[^>]+>", " ", str(b.get("body") or ""))
        if looks_like_licence(body):
            return True
    return False


def main() -> int:
    print("license-insert-gate — the read-path upgrade contract (Ian ruling 7)")
    try:
        rows = corpus()
    except CannotRun as e:
        print(f"  CANNOT RUN: {e}")
        return 3

    if not rows:
        print("  CANNOT RUN: no stored layouts on this box — every assertion would be vacuous")
        return 3
    print(f"  corpus: {len(rows)} stored layouts read straight from the DB")

    fails = []
    off = plan(rows, False, False)
    on = plan(rows, True, True)
    if not off or not on:
        print("  CANNOT RUN: no layout unserialized — the corpus decoded to nothing")
        return 3
    print(f"  decoded: {len(on)} layouts")

    # ---- [1] OFF is the identity function --------------------------------
    changed_off = [pid for pid, d in off.items()
                   if json.dumps(d["before"], sort_keys=True) != json.dumps(d["after"], sort_keys=True)]
    if changed_off:
        fails.append(f"[1] flags OFF changed {len(changed_off)} layout(s): {changed_off[:5]}")
    else:
        print(f"  [1] OFF: all {len(off)} layouts byte-identical — the no-op holds")

    # ---- [2] LIVENESS ----------------------------------------------------
    changed_on = [pid for pid, d in on.items()
                  if json.dumps(d["before"], sort_keys=True) != json.dumps(d["after"], sort_keys=True)]
    if not changed_on:
        fails.append("[2] LIVENESS: flags ON changed NOTHING — [1] proved only that the corpus is inert")
    else:
        print(f"  [2] LIVENESS: ON changes {len(changed_on)} layout(s) — [1] is a real assertion")

    invented, duplicated, unfiled, misplaced = [], [], [], []
    for pid, d in on.items():
        before, after = types(d["before"]), types(d["after"])
        added_lic = after.count("license") - before.count("license")
        added_tax = after.count("taxonomy") - before.count("taxonomy")

        if added_lic > 0 and not d["declared"]:
            invented.append(pid)
        if after.count("license") > 1:
            duplicated.append(pid)
        if added_lic > 0 and prose_licence_present(d["before"]):
            duplicated.append(pid)
        if added_tax > 0 and not any(r["filed"] for r in rows if r["id"] == pid):
            unfiled.append(pid)
        if (added_lic > 0 or added_tax > 0) and "post-footer" in after:
            foot = after.index("post-footer")
            for t in ("license", "taxonomy"):
                if t in after and after.index(t) > foot:
                    misplaced.append(pid)

    for label, ids, msg in (
        ("[3]", invented,   "post(s) gained a licence their author never chose"),
        ("[4]", duplicated, "post(s) would state a licence TWICE"),
        ("[5]", unfiled,    "post(s) gained taxonomy chips with no terms"),
        ("[6]", misplaced,  "post(s) got an inserted block AFTER post-footer"),
    ):
        ids = sorted(set(ids))
        if ids:
            fails.append(f"{label} {len(ids)} {msg}: {ids[:5]}")
        else:
            print(f"  {label} clean — no {msg}")

    # ---- [7] idempotent --------------------------------------------------
    import base64 as _b64
    second = [{"id": pid,
               "b64": _b64.b64encode(json.dumps(d["after"]).encode()).decode(),
               "licence_raw": next((r["licence_raw"] for r in rows if r["id"] == pid), ""),
               "filed": next((r["filed"] for r in rows if r["id"] == pid), False)}
              for pid, d in on.items()]
    twice = plan(second, True, True)
    non_idem = [pid for pid, d in twice.items()
                if json.dumps(d["before"], sort_keys=True) != json.dumps(d["after"], sort_keys=True)]
    if non_idem:
        fails.append(f"[7] NOT IDEMPOTENT — a second pass changed {len(non_idem)}: {non_idem[:5]}")
    else:
        print("  [7] idempotent — a second pass inserts nothing")

    print()
    if fails:
        print("RED — the insert path violates the ruling:")
        for f in fails:
            print(f"  ✗ {f}")
        return 1
    print(f"GREEN — inserts only surface what the author declared "
          f"({len(changed_on)} pages would gain a block).")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except CannotRun as e:
        print(f"CANNOT RUN: {e}")
        sys.exit(3)
