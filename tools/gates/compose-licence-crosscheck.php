<?php
/**
 * compose-licence-crosscheck.php — the probe half of gate 92's §F.
 *
 * ⚠️ THERE ARE TWO LICENCE TABLES ON THIS BOX and they must not drift:
 *
 *   · platform/mu-plugins/lg-frontend-compose.php  lg_fc_licences()
 *       what the compose form OFFERS and therefore what gets STORED.
 *   · lg-layout-v2/src/Licenses.php                Licenses::ACF_CHOICES
 *       what the layout engine RECOGNISES when it reads that stored value.
 *
 * They cannot be merged: the compose form is an mu-plugin and must not depend on
 * a regular plugin's class being loaded. So the duplication is deliberate, and
 * the honest answer to duplication you cannot remove is to gate the agreement.
 *
 * ⚠️ THE COST OF THIS DRIFTING IS SILENT AND WAS REAL. Correcting the fourth
 * choice's wording (#191) broke `Licenses::from_exact_prose()` for every post
 * saved afterwards — that recogniser matches the ACF choice string EXACTLY, on
 * purpose, because a loose match there would rewrite an author's prose. Nothing
 * errors: `upgrade_license_callouts()` simply walks past those posts and the
 * licence block never appears on them. Measured on main before the fix:
 * from_exact_prose('BY NC ND (…Non-Commercial only, No Derivatives)') → ''.
 *
 * ⚠️ IT MUST READ THE BRANCH'S COPY, and that is not automatic. The serve
 * symlinks lg-layout-v2 into ~/loothplatformv2-clean, so under WordPress the
 * autoloader resolves this class to MAIN — which is the very state being tested
 * for. So this runs as PLAIN PHP with an absolute require, and echoes back the
 * file it actually loaded so the gate can assert it.
 *
 * Input : LG191_IN — JSON {"values": [...], "legacy": [...], "shorts": {v: s}}
 * Output: JSON, one entry per string, plus the resolved file path.
 */

declare(strict_types=1);

$file = $argv[1] ?? '';
if ($file === '' || !is_readable($file)) {
    fwrite(STDERR, "usage: php compose-licence-crosscheck.php <path/to/Licenses.php>\n");
    exit(2);
}
require $file;

$cls = 'LG\LayoutV2\Licenses';
if (!class_exists($cls, false)) {
    fwrite(STDERR, "$file did not define $cls\n");
    exit(2);
}

$in = json_decode((string) getenv('LG191_IN'), true);
if (!is_array($in)) {
    fwrite(STDERR, "LG191_IN is not JSON\n");
    exit(2);
}

$look = static function (string $v) use ($cls): array {
    $meta = $cls::from_meta($v);
    return [
        'exact' => $cls::from_exact_prose($v),
        'meta'  => $meta,
        'short' => $meta !== '' ? $cls::short($meta) : '',
    ];
};

$out = ['file' => (new ReflectionClass($cls))->getFileName(),
        'choices' => count($cls::ACF_CHOICES),
        'offered' => [], 'legacy' => []];
foreach (($in['values'] ?? []) as $v) {
    $out['offered'][$v] = $look((string) $v);
}
foreach (($in['legacy'] ?? []) as $v) {
    $out['legacy'][$v] = $look((string) $v);
}
echo json_encode($out);
