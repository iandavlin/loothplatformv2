<?php
/**
 * hub-author-comma-fix — a display name containing a literal comma broke the
 * Hub's author filter entirely. hub_url() (bb-mirror/web/forums/_filter-
 * rail.php) joined selected authors with implode(',', ...) and the parser
 * (_hub-filters.php's hub_filters_parse()) split back on the same character,
 * so any ONE name with its own comma sliced into fragments matching neither
 * the real author. Measured live: "John Lehmann, Old Naples Guitars" -> two
 * bogus banner headers, zero matching cards; 6 authors on live carry a
 * comma and are currently unfilterable this way, including the destination
 * backlog 27's archive icon links to.
 *
 * OFF (default): the delimiter stays ',' — today's exact (broken-for-comma-
 * names) behaviour, byte-identical.
 * ON: the delimiter becomes \x1F (ASCII Unit Separator), a character no
 * display name plausibly contains, so a name's own comma survives the
 * round-trip intact. See _hub-filters.php's HUB_AUTHOR_DELIM.
 */
return ['enabled' => false];
