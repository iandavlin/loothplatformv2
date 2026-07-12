<?php
$db = new PDO("pgsql:host=/var/run/postgresql;dbname=looth", "", "");
$db->exec("SET search_path TO forums, public");
$forums = $db->query("
    SELECT f.id, f.slug, f.title, f.parent_forum_id, f.effective_group_id, p.title AS parent_title
      FROM forum f LEFT JOIN forum p ON p.id = f.parent_forum_id
     WHERE f.visibility = \x27public\x27 AND f.status = \x27open\x27 AND f.forum_type = \x27forum\x27
       AND f.id NOT IN (67251, 3876)
       AND f.id NOT IN (SELECT parent_forum_id FROM forum WHERE parent_forum_id IS NOT NULL)
     ORDER BY COALESCE(f.parent_forum_id, f.id), f.menu_order ASC
")->fetchAll(PDO::FETCH_ASSOC);
$cur = false; $hdr = 0; $genHdrs = 0;
foreach ($forums as $f) {
  $pid = $f["parent_forum_id"] !== null ? (int)$f["parent_forum_id"] : null;
  if ($pid !== $cur) {
    $label = $pid !== null ? $f["parent_title"] : "General";
    if ($label === "General") $genHdrs++;
    printf("\nHEADER[%d]: %s\n", ++$hdr, strtoupper($label));
    $cur = $pid;
  }
  printf("      - %-34s (id=%s, eff_gid=%s)\n", $f["title"], $f["id"], $f["effective_group_id"] ?? "NULL");
}
printf("\n\n>>> TOTAL HEADERS: %d   >>> HEADERS LABELLED \"General\": %d\n", $hdr, $genHdrs);
