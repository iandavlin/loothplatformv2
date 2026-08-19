<?php
/**
 * composer-categorize-last — THE TRACKED CONFIG for #129, ledger 44.
 *
 * Ian, 8/16 (thinking-aloud → ruled): the composer's required "Where" step is "a
 * pain point for posting"… "maybe we add it at the end as optional. bonus points".
 * Ian, 8/19: the end-of-flow control is a TAG FIELD that opens "a new modal with a
 * decent heirarchical layout". Write first, categorize last.
 *
 * ── WHY A FILE, NOT A CONSTANT ───────────────────────────────────────────────
 * Same split that forced platform/config/frontend-compose.php, and for the same
 * reason — read that header too. This feature straddles runtimes that share no
 * config:
 *
 *   · the WIZARD    — bb-mirror's hub app (its own FPM pool, no WP loaded)
 *   · the TAXONOMY  — WordPress, via platform/mu-plugins/
 *   · `wp lg-recat` — WP-CLI, which has NO pool environment at all
 *
 * A tracked PHP file read relative to __DIR__ is the same file on disk in every
 * one of those, and it lands with the pull. An env var is the wrong home on this
 * box twice over: WP cron carries no Environment=, and an fpm fastcgi_param never
 * reaches getenv().
 *
 * ── THE FORUM IDS ARE CONFIG, NOT CONSTANTS (Ian's build note, 8/19) ─────────
 * dev2 and live are different WordPress installs, so the SAME forum has a
 * DIFFERENT post ID on each. `default_forum_id` below is dev2's "Discussions"
 * (73564, created 8/19 for ruling (a)); live's twin forum will not be 73564. Live
 * therefore needs its own value placed alongside its own flag flip — see FLAGS.md.
 * Hardcoding an ID would have filed every live discussion into whatever post
 * happened to hold that number.
 *
 * ⚠️ AND A DEFAULT FORUM IS ONLY REAL IF THE MIRROR KNOWS IT. Measured 8/19: forum
 * 73564 exists in WordPress (publish, top-level, forum_type=forum, status=open, no
 * children) but is ABSENT from the Postgres mirror `forums.forum` — the mirror's
 * newest row is 67776, and forum rows only arrive there via the bbp_new_forum /
 * bbp_edit_forum hooks, which whatever created 73564 did not fire. The picker, the
 * hub's forum reads and the postable-forum contract ALL read Postgres, so until it
 * is mirrored a topic filed there points at a forum row that does not exist.
 * lg_ccl_default_forum_ok() exists to make that state fail LOUD instead of filing
 * posts into a void, and the flag must not be flipped ON while it returns false.
 * Resync is one dispatch: bb_mirror_sync_dispatch('forum', 73564, 'upsert').
 */

return array(

	/**
	 * OFF until Ian has looked at the running thing. His standing rule: every
	 * member-facing feature merges behind a flag defaulted OFF, because the dev2
	 * serve serves main and nothing can be verified there until it is merged.
	 *
	 * OFF is a no-op: the hub composer renders the Where step exactly as before,
	 * byte-identical server HTML, and the taxonomy is not registered for `topic`.
	 * Asserted per-state (absent / OFF / ON) by the gate rather than assumed.
	 */
	'enabled' => false,

	/**
	 * Ruling (a), Ian 8/19: a NEW TOP-LEVEL forum is the default landing, not the
	 * old #3837 "General" — which measured as a CHILD of Repair and Restoration,
	 * so defaulting there filed every untagged discussion, business questions
	 * included, inside the repair tree.
	 *
	 * dev2: 73564 "Discussions". Live: different ID, set at flip time.
	 */
	'default_forum_id' => 73564,

	/**
	 * Ruling (b), Ian 8/19: "defaults workably" — an unmapped topic lands in the
	 * default forum, and dedicated forums for the two heavy unmapped topics (New
	 * Builds 145 uses, Tools/Spaces/Robots/Widgets 97) stay open with Ian and are
	 * NON-BLOCKING. So this map deliberately contains only pairs that were
	 * MEASURED correct; nothing here is a guess, and the 15 unmapped terms fall to
	 * the default rather than being invented into a forum.
	 *
	 * KEYED ON FORUM ID BECAUSE NAMES AND SLUGS ARE NOT UNIQUE. Measured across
	 * the 37 postable forums: the slugs `acoustic`, `finish`,
	 * `amps-pickups-and-pedals` and `folk-bluegrass-irish-old-time-instruments`
	 * each belong to TWO forums, and two titles are outright identical across the
	 * Repair and New Builds trees. 8 of 37 are ambiguous by name; the parent is
	 * what tells them apart, which is why the comments carry it.
	 *
	 * DO NOT REPLACE THIS WITH RUNTIME NAME-MATCHING. Measured 8/19: matching gets
	 * 21 of 36 terms right and produces confidently WRONG pairs — Machine Shop ->
	 * Shop Organisation, Local Looths -> Ohio Local Looths — and the highest
	 * non-exact score in the whole run, 0.909, is on a wrong pair (Electronics
	 * Repair -> Electric Repair, which is a different thing: electronics is
	 * pickups and amps, electric is electric guitars). Full working:
	 * handoffs/plans/129-composer-redesign-MEASUREMENT.md.
	 *
	 * taxonomy term slug (shared_category, "Content Topics") => postable forum ID
	 */
	'taxo_forum_map' => array(
		'acoustic-repair'                                     => 3823,   // Acoustic Repair [Repair and Restoration] 138u -> Acoustic Repair [Repair and Restoration]
		'acoustic-builds'                                     => 3845,   // Acoustic Builds [New Builds] 112u -> Acoustic Builds [New Builds]
		'tools-jigs-and-fixtures'                             => 3871,   // Tools, Jigs and Fixtures [Tools, Spaces, Robots and Widgets] 85u -> Tools and Jigs [Tools, Spaces, Robots, and Widgets]
		'3d-printing'                                         => 3863,   // 3D Printing [Tools, Spaces, Robots and Widgets] 80u -> 3D Printing [Tools, Spaces, Robots, and Widgets]
		'electric-repair'                                     => 3820,   // Electric Repair [Repair and Restoration] 75u -> Electric Repair [Repair and Restoration]
		'electric-builds'                                     => 3842,   // Electric Builds [New Builds] 68u -> Electric Builds [New Builds]
		'finish-repair'                                       => 3829,   // Finish Repair [Repair and Restoration] 40u -> Finish Repair [Repair and Restoration]
		'cad-cam'                                             => 3866,   // CAD/CAM [Tools, Spaces, Robots and Widgets] 40u -> CAD/CAM [Tools, Spaces, Robots, and Widgets]
		'shop-orginization'                                   => 3869,   // Shop Organization [Tools, Spaces, Robots and Widgets] 31u -> Shop Organisation [Tools, Spaces, Robots, and Widgets]
		'finishing-new-builds'                                => 3847,   // Finishing New Builds [New Builds] 24u -> Finish New Builds [New Builds]
		'cnc'                                                 => 3860,   // CNC [Tools, Spaces, Robots and Widgets] 22u -> CNC [Tools, Spaces, Robots, and Widgets]
		'customer-relations'                                  => 15865,  // Customer Relations [Business] 21u -> Customer Relations [Business]
		'folk-irish-bluegrass-old-time-instrument-builds'     => 3852,   // Folk, Irish, Bluegrass, Old Time Instrument Builds [New Builds] 20u -> Folk, Bluegrass, Irish, Old Time Instruments [New Builds]
		'amps-pickups-and-pedal-builds'                       => 3849,   // Amps, Pickups and Pedal Builds [New Builds] 19u -> Amps, Pickups, and Pedals [New Builds]
		'folk-bluegrass-irish-old-time-repair'                => 3835,   // Folk, Bluegrass, Irish, Old Time Repair [Repair and Restoration] 15u -> Folk, Bluegrass, Irish, Old Time Instruments [Repair and Restoration]
		'design-and-testing'                                  => 3854,   // Design and Testing [New Builds] 15u -> Design and Testing [New Builds]
		'paper-work-and-drudgery'                             => 15862,  // Paper Work and Drudgery [Business] 7u -> Paper Work and Drudgery [Business]
		'touring-tech'                                        => 43277,  // Touring Tech [Repair and Restoration] 2u -> Touring Tech [Repair and Restoration]
		'job-postings'                                        => 4829,   // Job Postings [Business] 0u -> Job Postings [Business]
		'resumes'                                             => 4832,   // Resumes [Business] 0u -> Resumes [Business]
		'plek'                                                => 7544,   // PLEK [Tools, Spaces, Robots and Widgets] 0u -> PLEK Machine [Tools, Spaces, Robots, and Widgets]
	),

	/**
	 * The 15 terms with NO mapping, and why each is absent. They land in the
	 * default forum, which ruling (b) settled as workable. Listed rather than
	 * silently missing, so nobody re-derives this: a term that is deliberately
	 * unmapped and a term that was forgotten look identical in a map.
	 *
	 *   AWAITING A DEDICATED FORUM (Ian, non-blocking, ruling (b)):
	 *     new-builds                      145u  no generic child exists
	 *     tool-spaces-robots-and-widgets   97u  no generic child exists
	 *
	 *   AWAITING A HUMAN CALL — a plausible target exists but is not measured safe:
	 *     repair-and-restoration          211u  #3837 General is its generic child,
	 *                                           but that is now the OLD default
	 *     business                         46u  #15868 General Business, a
	 *                                           parent->child hop, not a name match
	 *     electronics-repair               29u  #3826 Amps, Pickups, and Pedals
	 *                                           (Repair side) — NOT #3820, which is
	 *                                           what the 0.909 match wrongly said
	 *     amp-repair                       10u  #3826 as well
	 *     machine-shop                      3u  #3860 CNC is nearer than the Shop
	 *                                           Organisation the matcher chose
	 *     local-looths                      0u  it is the PARENT of the chapters;
	 *                                           picking one is arbitrary among 3
	 *
	 *   NO FORUM MODELS THEM AT ALL — correct to leave unmapped:
	 *     vintage 15u · perspective 13u · violin-family-restoration 12u ·
	 *     marketing 9u · pickup-winding 2u · lasers 0u · scanners 0u
	 */
);
