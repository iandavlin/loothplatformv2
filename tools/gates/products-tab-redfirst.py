#!/usr/bin/env python3
"""
RED-FIRST for GATE 93 (#194) — every assertion reddens for its OWN named reason.

    python3 tools/gates/products-tab-redfirst.py

A gate that has never been seen RED is a gate nobody has tested. Each mutation
below breaks exactly one thing and must produce a FAIL on the assertion that
CLAIMS to watch it — not merely "the gate went red", which any typo achieves.

⚠️ THE FIRST MUTATION IS THE DEFECT THIS FEATURE EXISTS TO PREVENT. M1 makes the
writer ignore its argument and stamp a constant, which is precisely what
StripeLifecycle did for the whole life of the one-tier ruling: a member buying
Looth LITE at $5 was granted Pro. #148's own gate could not see it, because the
obvious assertion — "a PRO purchase grants looth3" — passes on a constant. A3
must fail here or §A is decoration.

⚠️ SNAPSHOTS, NEVER `git checkout --`. Restoring from HEAD would wipe
uncommitted work under test and turn one harness bug into a run of false
verdicts (feedback-mutation-harness-must-snapshot-not-checkout). Every file is
copied to a per-run temp dir before the first mutation and restored from there.

⚠️ A MUTATION THAT DOES NOT APPLY IS AN ERROR, NOT A PASS. If the anchor text
has drifted the harness stops and says so. Silently "mutating" nothing and
watching the gate stay green is how a harness reports a dead assertion as a
live one.

⚠️ NO-OP CONTROLS ARE PART OF THE EVIDENCE. If rewording a comment reddens the
gate, the gate is measuring prose rather than behaviour — which gate 90's own
F4/F5 did on their first run. Every source check in gate 93 runs through PHP's
tokenizer for exactly that reason, and N1/N2 prove it.
"""

import os
import shutil
import subprocess
import sys
import tempfile

REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
GATE = os.path.join(REPO, "tools", "gates", "products-tab-gate.php")

PC     = "lg-patreon-stripe-poller/src/Membership/ProductCatalog.php"
PANEL  = "lg-patreon-stripe-poller/src/ProductsPanel.php"
ADMIN  = "lg-patreon-stripe-poller/src/Admin.php"
HEALTH = "lg-patreon-stripe-poller/src/Membership/Health.php"
PRICE  = "lg-patreon-stripe-poller/src/StripePrice.php"
REPOA  = "lg-stripe-billing/src/Adapters/PdoProductRepository.php"
SYNC   = "lg-stripe-billing/src/Core/ProductSyncHandler.php"
SVC    = "lg-stripe-billing/src/Core/CheckoutService.php"
IMPORT = "lg-stripe-billing/bin/stripe-import-catalog.php"

FILES = [PC, PANEL, ADMIN, HEALTH, PRICE, REPOA, SYNC, SVC, IMPORT]

CHECKOUT_GUARD = """        if ($this->products->tierForPrice($priceId) === null) {
            throw new InvalidArgumentException("Price {$priceId} is not mapped to a membership tier.");
        }

        $priceData       = $this->products->findPriceData($priceId);"""

# (id, file, find, replace, assertion that MUST fail, what it simulates)
MUTATIONS = [
    # ---- A. the refusal moves, and it RESOLVES -----------------------------
    ("M1", PC,
     "                $tier === '' ? null : $tier,",
     "                'looth3',",
     "A3", "the writer ignores its argument and stamps a constant — #148's exact defect"),

    ("M2", REPOA,
     """             WHERE pr.stripe_price_id = ?
               AND p.kind = 'membership'
               AND p.active = 1""",
     """             WHERE pr.stripe_price_id = ?
               AND p.kind = 'membership'""",
     "A6", "an ARCHIVED product becomes buyable once it is mapped"),

    ("M3", PC,
     "                $tier === '' ? null : $tier,",
     "                $tier,",
     "A8", "un-mapping stores the empty string, which Health's IS NULL test cannot see"),

    ("M4", SVC, CHECKOUT_GUARD,
     "        $priceData       = $this->products->findPriceData($priceId);",
     "A5", "checkout stops refusing unmapped prices, so §A is about a door that no longer exists"),

    # ---- B. a tier that does not exist -------------------------------------
    ("M5", PC,
     "        'looth2' => 'Looth Lite (looth2)',",
     "        'looth1' => 'Looth One (looth1)',\n        'looth2' => 'Looth Lite (looth2)',",
     "B2", "the unpaid resting tier becomes something a card payment can buy"),

    ("M6", PC,
     "        'looth3' => 'Looth Pro (looth3)',",
     "        'looth3' => 'Looth Pro (looth3)',\n        'looth4' => 'Comp (looth4)',",
     "B3", "the permanent comp bypass goes on sale"),

    ("M7", PC,
     "        $tier = trim( $tier );",
     "        $tier = strtolower( trim( $tier ) );",
     "B4", "a wrong-case tier is silently corrected instead of refused"),

    ("M8", PC,
     "        if ( ! isset( self::SELLABLE[ $tier ] ) ) {",
     "        if ( false ) {",
     "B2", "the sellable list stops being consulted at all, so any real role can be sold"),

    ("M9", PC,
     "        $tier   = self::assertTier( $tier );",
     "        try { $tier = self::assertTier( $tier ); } catch ( \\Throwable $e ) { $tier = ''; }",
     "B5", "a refusal quietly un-maps the product instead of leaving it alone"),

    ("M10", PC,
     """            throw new RuntimeException( sprintf(
                'There is no membership tier called "%s" that can be sold.""",
     """            return 'looth2'; throw new RuntimeException( sprintf(
                'There is no membership tier called "%s" that can be sold.""",
     "B6", "a bad tier falls back to a default and writes a row plus an audit line for it"),

    ("M11", PC,
     "'There is no membership tier called \"%s\" that can be sold. Choose one of: %s — or \"none\". '",
     "'That tier cannot be sold. '",
     "B7", "the refusal stops saying what IS allowed, so nobody knows what to type"),

    ("M12", PC,
     "        if ( ! array_key_exists( $region, self::REGIONS ) ) {",
     "        if ( false ) {",
     "B8", "any string is accepted as a region tag"),

    ("M13", PC,
     "        if ( $row === null ) {",
     "        if ( false ) {",
     "B9", "a product that was never synced is not refused by name"),

    ("M14", PC,
     "        if ( function_exists( 'get_role' ) && get_role( $tier ) === null ) {",
     "        if ( false ) {",
     "B10", "a tier this box has no ROLE for is accepted, so a buyer is granted nothing"),

    # ---- C. the two screens ------------------------------------------------
    ("M15", PC,
     "'unmapped'          => (bool) $p['active'] && (string) $p['kind'] === self::KIND && $ref === '',",
     "'unmapped'          => (string) $p['kind'] === self::KIND && $ref === '',",
     "C1d", "the tab counts ARCHIVED products as unmapped and Health does not"),

    ("M16", PC,
     "            if ( ! empty( $p['unmapped'] ) ) { $n++; }",
     "            $n++;",
     "C2", "the count stops being about unmapped products at all"),

    ("M17", PC,
     "    public const AUDIT_SUBJECT = 'product';",
     "    public const AUDIT_SUBJECT = 'webhook';",
     "C4", "a dash write is filed as a webhook receipt, so Health reports Stripe was in touch"),

    ("M18", HEALTH,
     "SUM(active = 1 AND kind = 'membership' AND (ref IS NULL OR ref = ''))            AS unmapped,",
     "SUM(kind = 'membership' AND (ref IS NULL OR ref = ''))            AS unmapped,",
     "C1d", "HEALTH drifts instead — the same disagreement from the other side"),

    # ---- D. Stripe never overwrites ----------------------------------------
    ("M19", SYNC,
     """            'membership', // only used on first INSERT — updates skip kind/ref
            null,""",
     """            'membership', // only used on first INSERT — updates skip kind/ref
            'looth3',""",
     "D1", "a product event starts carrying a tier of its own"),

    ("M20", SYNC,
     "            (string) $stripeProduct->name,",
     "            'A product',",
     "D2", "the sync stops carrying the real name, so D1 would be measuring a dead handler"),

    ("M21", REPOA,
     """             ON DUPLICATE KEY UPDATE
                 name   = VALUES(name),""",
     """             ON DUPLICATE KEY UPDATE
                 ref    = VALUES(ref),
                 name   = VALUES(name),""",
     "D3", "a webhook overwrites the tier somebody set in the dash"),

    ("M22", REPOA,
     """             ON DUPLICATE KEY UPDATE
                 name   = VALUES(name),
                 active = VALUES(active)""",
     """             ON DUPLICATE KEY UPDATE
                 id = id""",
     "D4", "the update clause is empty, so D3 would pass on a sync that does nothing"),

    ("M23", PANEL,
     "Stripe never sets them and never overwrites them &mdash; a <code>product.updated</code> event syncs",
     "Products are synced from Stripe &mdash; a <code>product.updated</code> event syncs",
     "D5", "the screen stops saying where the setting lives, so people go looking in Stripe"),

    # ---- E. the write discipline -------------------------------------------
    ("M24", PANEL,
     """        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Nope.' );
        }""",
     "",
     "E1", "anyone who can reach admin-post.php can remap a product"),

    ("M25", PANEL,
     "        check_admin_referer( self::NONCE );",
     "",
     "E2", "a cross-site request can remap a product"),

    # ⚠️ MUTATES THE SQL *AND* ITS PARAMETERS TOGETHER. Dropping only the column
    # left execute() with four parameters for three placeholders — a fatal, not
    # a wrong answer, and the gate died at exit 255 with no FAIL line at all.
    ("M26", PC,
     """            $st = $pdo->prepare(
                'UPDATE products SET ref = ?, kind = ?, region_tag = ? WHERE stripe_product_id = ?'
            );
            $st->execute( [
                $tier === '' ? null : $tier,
                self::KIND,
                $region,
                $stripeProductId,
            ] );""",
     """            $st = $pdo->prepare(
                'UPDATE products SET ref = ?, region_tag = ? WHERE stripe_product_id = ?'
            );
            $st->execute( [
                $tier === '' ? null : $tier,
                $region,
                $stripeProductId,
            ] );""",
     "E4", "kind stops being written, so the dash and the import mean different things"),

    ("M27", PC,
     "        if ( $from === $to ) {",
     "        if ( false ) {",
     "E9", "every press of Save writes an audit line, burying the real changes"),

    ("M28", PC,
     "            return [ 'changed' => false, 'name' => (string) $row['name'], 'from' => $from, 'to' => $to ];",
     "            return [ 'changed' => true, 'name' => (string) $row['name'], 'from' => $from, 'to' => $to ];",
     "E10", "a no-op reports itself as a change"),

    # ⚠️ A MUTATION MUST BE WRONG, NOT BROKEN. Wrapping the audit insert in a
    # bare try/catch closed the enclosing try early — a parse error, so the gate
    # died with no FAIL line instead of reddening E12. This drops the rollback
    # instead: same guarantee, valid PHP.
    ("M29", PC,
     """            if ( $inTx && $pdo->inTransaction() ) {
                $pdo->rollBack();
            }""",
     """            if ( false ) {
                $pdo->rollBack();
            }""",
     "E12", "the rollback is dropped, so a failed audit leaves the mapping applied anyway"),

    ("M30", PC,
     "                    'from'              => $from,",
     "",
     "E8", "the audit line stops recording what the value WAS"),

    # ---- F. one writer ------------------------------------------------------
    ("M31", PRICE,
     "    public const INTERVALS = [ 'month' => 'Monthly', 'year' => 'Yearly' ];",
     "    public const INTERVALS = [ 'month' => 'Monthly', 'year' => 'Yearly' ];\n"
     "    public const OOPS = \"UPDATE products SET ref = 'looth3' WHERE id = 1\";",
     "F3", "a second writer of products.ref appears somewhere else in the monorepo"),

    ("M32", IMPORT,
     """    $regionTagSql = $regionTag !== null
        ? ", region_tag = '" . addslashes($regionTag) . "'"
        : ', region_tag = NULL';""",
     "    $regionTagSql = '';",
     "F2", "the import script stops stamping region_tag and the two definitions drift"),

    ("M33", PANEL,
     "                                <?php foreach ( ProductCatalog::SELLABLE as $slug => $label ) : ?>",
     "                                <option value=\"looth4\">Comp (looth4)</option>\n"
     "                                <?php foreach ( ProductCatalog::SELLABLE as $slug => $label ) : ?>",
     "F5", "the screen offers a tier the writer would refuse — a control that cannot work"),

    # ---- G. the screen ------------------------------------------------------
    ("M34", PANEL,
     """        if ( $dead > 0 ) {
            printf(
                '<li class="lgms-p-more">%d archived price%s</li>',""",
     """        foreach ( array_filter( $prices, static fn( $p ) => ! $p['active'] ) as $p ) {
            printf( '<li class="off">%s</li>', esc_html( (string) $p['unit_amount_cents'] ) );
        }
        if ( false ) {
            printf(
                '<li class="lgms-p-more">%d archived price%s</li>',""",
     "G4", "eleven price rows are drawn in full, burying the two a member can reach"),

    ("M35", PANEL,
     "                $rowClass = $p['unmapped'] ? 'is-unmapped' : ( $p['active'] ? '' : 'is-archived' );",
     "                $rowClass = $p['ref'] === '' ? 'is-unmapped' : '';",
     "G6", "archived products with no tier are painted red, training people to ignore the colour"),

    ("M36", PANEL,
     """        <?php if ( $products === [] ) : ?>""",
     """        <?php if ( false ) : ?>""",
     "G8", "an empty catalogue renders an empty table instead of saying it is empty"),

    ("M37", PANEL,
     "            $dbError = $e->getMessage();",
     "            $products = [];",
     "G10", "a database it cannot reach is reported as a catalogue with nothing in it"),

    ("M38", ADMIN,
     "        ProductsPanel::boot();",
     "",
     "G14", "the save handler is never registered, so every Save is a dead link"),

    ("M39", ADMIN,
     "        add_menu_page(",
     "        add_menu_page(\n            'LG Products', 'LG Products', 'manage_options', 'lg-products',\n            [ self::class, 'render' ], 'dashicons-cart', 31,\n        );\n        add_menu_page(",
     "G15", "a second top-level menu appears, against Ian's 8/22 standing rule"),
]

# Rewording a comment must change NOTHING. If either of these reddens the gate,
# the gate is grading prose.
NOOPS = [
    ("N1", PC,
     "     * The tiers a card payment may buy.",
     "     * Which tiers may be purchased with a card.",
     "a docblock reworded in the writer"),
    ("N2", PANEL,
     "     * Active prices first and plainly; archived ones behind a count.",
     "     * Live prices are listed; retired ones are only counted.",
     "a docblock reworded in the panel"),
]


def run_gate():
    p = subprocess.run([ "php", GATE ], cwd=REPO, capture_output=True, text=True, timeout=600)
    return p.returncode, p.stdout + p.stderr


def failed_assertions(out):
    ids = set()
    for line in out.splitlines():
        s = line.strip()
        if s.startswith("FAIL "):
            ids.add(s.split()[1])
    return ids


def main():
    snap = tempfile.mkdtemp(prefix="lg-gate93-redfirst-")
    for f in FILES:
        dst = os.path.join(snap, f.replace("/", "__"))
        shutil.copy2(os.path.join(REPO, f), dst)

    def restore():
        for f in FILES:
            shutil.copy2(os.path.join(snap, f.replace("/", "__")), os.path.join(REPO, f))

    try:
        code, out = run_gate()
        if code != 0:
            print("BASELINE IS NOT GREEN — nothing below would mean anything.\n")
            print(out[-4000:])
            return 2
        print(f"baseline: GREEN\n")

        good = bad = 0

        for mid, f, find, repl, target, why in MUTATIONS:
            path = os.path.join(REPO, f)
            src = open(path).read()
            if src.count(find) != 1:
                restore()
                print(f"{mid}: ANCHOR DRIFTED in {f} (found {src.count(find)} times, need exactly 1).")
                print("     A mutation that does not apply is an ERROR, not a pass. Stopping.")
                return 2
            open(path, "w").write(src.replace(find, repl))

            code, out = run_gate()
            fails = failed_assertions(out)
            restore()

            if code == 0:
                print(f"  DEAD  {mid} [{target}] gate stayed GREEN — {why}")
                bad += 1
            elif target in fails:
                print(f"  red   {mid} [{target}] {why}")
                good += 1
            else:
                print(f"  WRONG {mid} [{target}] went red on {sorted(fails)} instead — {why}")
                bad += 1

        for nid, f, find, repl, why in NOOPS:
            path = os.path.join(REPO, f)
            src = open(path).read()
            if src.count(find) != 1:
                restore()
                print(f"{nid}: ANCHOR DRIFTED in {f}. Stopping.")
                return 2
            open(path, "w").write(src.replace(find, repl))
            code, out = run_gate()
            restore()
            if code == 0:
                print(f"  inert {nid} {why} — gate still GREEN, as it must be")
                good += 1
            else:
                print(f"  PROSE {nid} {why} — the gate went RED on a comment change: {sorted(failed_assertions(out))}")
                bad += 1

        total = len(MUTATIONS) + len(NOOPS)
        print(f"\nRED-FIRST {good}/{total}")
        return 0 if bad == 0 else 1
    finally:
        restore()
        shutil.rmtree(snap, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
