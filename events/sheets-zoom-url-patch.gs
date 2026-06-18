// ============================================================
// PATCH: add a "Zoom URL" column to the Showrunner Tracker and
// send it to WP on publish. The Zoom link is the ONE gated field
// on event pages — today the sheet has no entry point for it, so
// Sheet-published events render with no virtual-attend CTA.
//
// Apply these 5 edits in the Apps Script editor (Extensions >
// Apps Script). Line numbers reference the on-disk snapshot
// loothdev-sheets-bridge.apps-script.gs.txt — your bound copy may
// differ slightly; match on the surrounding code, not the number.
//
// The WP bridge ALREADY accepts `zoom_url` (optional param in
// loothdev-sheets-bridge.php) → writes it to the
// `zoom_url_for_looth_group_virtual_event` meta the event-header
// block reads. No WordPress-side change is needed.
// ============================================================


// ── EDIT 1 — CONFIG.COL (after `OTHER_ATTENDEES: 24,`) ──────────
// Add a new trailing column. 25 is the next free index.

    OTHER_ATTENDEES:  24,
    ZOOM_URL:         25, // ← NEW (WP publish) — the gated virtual-attend link


// ── EDIT 2 — HEADERS array (append after 'Other Attendees',) ────
// HEADERS is positional; the new entry must be LAST to line up
// with column 25.

  'Other Attendees',    // (existing)
  'Zoom URL',           // ← NEW (WP) — zoom_url_for_looth_group_virtual_event


// ── EDIT 3 — setupSheet() (near the other setColumnWidth calls,
//             e.g. just after the OTHER_ATTENDEES width ~line 327) ─
// Free-text column, just give it a width.

  sheet.setColumnWidth(CONFIG.COL.ZOOM_URL, 260);


// ── EDIT 4 — onEdit() wpRelevantCols (add to the array so editing
//             the Zoom URL on an already-published row prompts a
//             re-publish, matching the existing behavior) ─────────

    const wpRelevantCols = [
      CONFIG.COL.EPISODE_TITLE, CONFIG.COL.SHOW_NAME, CONFIG.COL.AIR_DATE,
      CONFIG.COL.SHOWRUNNER, CONFIG.COL.TOPIC, CONFIG.COL.BLURB,
      CONFIG.COL.EVENT_TIER, CONFIG.COL.REGION, CONFIG.COL.LANGUAGE,
      CONFIG.COL.ZOOM_URL,   // ← NEW
    ];


// ── EDIT 5 — publishRowToWp_() ──────────────────────────────────
// 5a. Read the cell, alongside the other `const region = ...` reads
//     (~line 1714):

  const zoom        = String(row[CONFIG.COL.ZOOM_URL - 1] || '').trim();

// 5b. Add to the payload, next to the existing optional fields
//     (after the `if (region) payload.region = region;` line ~1780).
//     Optional — only sent when filled, so in-person/recording-only
//     events simply omit it and the block renders no CTA:

  if (zoom) payload.zoom_url = zoom;


// ============================================================
// NOTES (no action needed — context for whoever applies this)
//
// • TIME FORMAT is already handled on the render side. The bridge
//   normalizes time to "7:30 pm" (date('g:i a')); the event-header
//   block now parses both that 12h form AND the legacy 24h
//   "15:00:00" form. No change needed here.
//
// • TIER mapping is correct as-is: the Event Tier dropdown
//   (Public / Looth Lite / Looth Pro) resolves to the `tier`
//   taxonomy term, which is what gates the Zoom link. Don't change it.
//
// • After publishing a Zoom URL change, anonymous visitors won't
//   see the update until the v2 anon render cache is invalidated.
//   That fix is on the WordPress side (lg-layout-v2) and is tracked
//   separately — it is NOT something this script can do.
// ============================================================
