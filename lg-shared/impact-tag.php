<?php
// impact.com "Stewmac Affiliate Catcher" — UTT tag, account P-A3499220 / campaign
// A3499220. ONE definition, emitted from the two places that between them cover
// every page:
//   - lg-shared/site-header.php               → every lg-chrome surface (front, hub,
//     profiles, events, membership, sponsor pages — standalone renderers that never
//     fire wp_head)
//   - platform/mu-plugins/lg-impact-tracking.php → WP-theme pages (sponsor-product,
//     sponsor-post, anything still on BuddyBoss chrome) via wp_head
// The window.__lgImpactTag guard dedupes pages that render both, so double-emission
// is harmless; the !window.ire_o leg also skips when the legacy Code Snippets row
// already installed the loader (ire_o is set synchronously), so the live transition
// window cannot double-load the UTT either way. Replaces the DB-scoped Code Snippets row 98 ("Stewmac Affiliate
// Catcher"), which must stay disabled — see the lane deploy notes.
if (!function_exists("lg_impact_tag_render")) {
    function lg_impact_tag_render(): void { ?>
<script type="text/javascript">
if(!window.__lgImpactTag&&!window.ire_o){window.__lgImpactTag=1;
(function(i,m,p,a,c,t){c.ire_o=p;c[p]=c[p]||function(){(c[p].a=c[p].a||[]).push(arguments)};t=a.createElement(m);var z=a.getElementsByTagName(m)[0];t.async=1;t.src=i;z.parentNode.insertBefore(t,z)})("https://utt.impactcdn.com/P-A3499220-b563-4611-8e0e-c45cc7df6ab61.js","script","impactStat",document,window);
impactStat("transformLinks");
impactStat("trackImpression");
}
</script>
<?php }
}
