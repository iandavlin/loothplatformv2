<?php
/**
 * LayoutUpgrade — the READ-PATH upgrade rules for a stored layout, as pure
 * functions.
 *
 * Lifted out of Plugin deliberately. Plugin.php cannot be loaded a second time
 * inside a WordPress process that already booted layout-v2 (class
 * redeclaration), which meant the gate for these rules could only have run
 * inside a maintenance window with the branch symlinked over the serve. These
 * rules touch no database and no WordPress state, so they belong somewhere a
 * test can `require` on its own — and now the gate runs any time, against the
 * real corpus, with main still serving.
 *
 * Ian, 2026-08-15 (ruling 7): v2 MAY insert a missing block into a stored page,
 * with the scope guard that an insert only ever SURFACES something the author
 * already declared in the form, and never invents content.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class LayoutUpgrade
{
    /**
     * PURE: given a layout and what the author declared, return the layout with
     * any missing block spliced in. No database, no WordPress — so it can be
     * exercised directly against real stored layouts by the gate.
     *
     * $declaredLicence — '' when the author chose no licence. Nothing is
     *                    inserted for '': that is the "never invent" guard.
     * $filed           — true when the post carries a term in either taxonomy.
     */
    public static function plan_inserts(
        array $layout,
        string $declaredLicence,
        bool $filed,
        bool $wantLicense,
        bool $wantTaxonomy
    ): array {
        if (empty($layout['blocks']) || !is_array($layout['blocks'])) return $layout;

        $hasLicense = false; $hasTaxonomy = false; $licenceish = false;
        foreach ($layout['blocks'] as $b) {
            if (!is_array($b)) continue;
            $type = $b['type'] ?? '';
            if ($type === 'license')  $hasLicense  = true;
            if ($type === 'taxonomy') $hasTaxonomy = true;
            if ($type === 'callout') {
                $body = (string) ($b['body'] ?? '');
                if ($body !== '') {
                    $text = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($body) : strip_tags($body);
                    if (Licenses::looks_like_licence($text)) $licenceish = true;
                }
            }
        }

        $insert = [];
        if ($wantLicense && !$hasLicense && !$licenceish && $declaredLicence !== '') {
            /* No `code`: the block resolves live, so this surfaces the author's
               own answer rather than pinning a copy of it. */
            $insert[] = ['type' => 'license', 'id' => 'lp_license_ins', 'title' => 'License'];
        }
        if ($wantTaxonomy && !$hasTaxonomy && $filed) {
            $insert[] = ['type' => 'taxonomy', 'id' => 'lp_filed_ins', 'title' => 'Filed under'];
        }
        if (!$insert) return $layout;

        /* Splice in before post-footer; append if the page has no footer block. */
        $at = count($layout['blocks']);
        foreach ($layout['blocks'] as $i => $b) {
            if (is_array($b) && ($b['type'] ?? '') === 'post-footer') { $at = $i; break; }
        }
        array_splice($layout['blocks'], $at, 0, $insert);

        return $layout;
    }
}
