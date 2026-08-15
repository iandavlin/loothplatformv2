<?php
/**
 * board-projects.php — the item → PROJECT map for the work board.
 *
 * Ian, on the live board 2026-08-15: *"nested and have names of the projects
 * rather than the p0 etc."* The FILE keeps its P-band semantics; the BOARD
 * wears project names. Priority survives invisibly, as sort weight and badge
 * colour, not as section headers.
 *
 * WHY THIS IS A COMMITTED FILE AND NOT A CLEVER HEURISTIC.
 *   Guessing a project from a title reads fine until it is wrong, and a wrong
 *   grouping is worse than an ungrouped item: it hides work under a name its
 *   owner would never look under. So the rules here are EXPLICIT and ordered,
 *   first match wins, and anything unmatched lands in a visible "unsorted"
 *   group rather than being quietly filed somewhere plausible. A gap in this
 *   map should be VISIBLE ON THE BOARD, not absorbed by it.
 *
 * HOW TO ADD ONE. Add the project to PROJECTS (order = where it sits before
 * priority weighting), then a rule to RULES. `ids` is the stable handle;
 * `title` is a case-insensitive regex for when an id is ambiguous — and one is:
 * the index carries the id "9" TWICE (Shop Layout Planner in P1, Advanced
 * search in P2), so those two are separated by title, not id.
 *
 * Nothing here changes what the backlog MEANS. Delete this file and the board
 * still renders — every item simply lands in "unsorted".
 */

return [

    /* Display name and the resting order. Priority still sorts within and
       across these; this is the tiebreak and the reading order. */
    'projects' => [
        'membership'   => ['name' => 'Membership & Stripe',      'order' => 10],
        'board'        => ['name' => 'The work board',           'order' => 20],
        'guitardle'    => ['name' => 'Guitardle',                'order' => 30],
        'mobile'       => ['name' => 'Mobile & PWA',             'order' => 40],
        'notifs'       => ['name' => 'Notifications & email',    'order' => 50],
        'profiles'     => ['name' => 'Member profiles',          'order' => 60],
        'hub'          => ['name' => 'Hub, SEO & discovery',     'order' => 70],
        'compose'      => ['name' => 'Composing & authoring',    'order' => 80],
        'messages'     => ['name' => 'Messages',                 'order' => 90],
        'featured'     => ['name' => 'Featured members',         'order' => 100],
        'darkmode'     => ['name' => 'Dark mode',                'order' => 110],
        'security'     => ['name' => 'Security & hygiene',       'order' => 120],
        'craft'        => ['name' => 'Craft gates & infra',      'order' => 130],
        'apps'         => ['name' => 'Standalone apps',          'order' => 140],
    ],

    /* Ordered. FIRST MATCH WINS. */
    'rules' => [
        ['project' => 'membership', 'ids' => ['17']],
        ['project' => 'board',      'ids' => ['29']],
        ['project' => 'guitardle',  'ids' => ['22', '24', '25']],
        ['project' => 'featured',   'ids' => ['18']],
        ['project' => 'darkmode',   'ids' => ['21']],

        // The id-9 collision: separated by title, never by id alone.
        ['project' => 'apps',       'ids' => ['9'], 'title' => '/shop\s*layout|planner/i'],
        ['project' => 'hub',        'ids' => ['9'], 'title' => '/advanced\s*search|facet/i'],

        ['project' => 'profiles',   'ids' => ['19', '20', '31', '4.4', '4.5', '4.6']],
        ['project' => 'security',   'ids' => ['S1', 'S2', 'S3', '15']],
        ['project' => 'notifs',     'ids' => ['4.1', '4.0', '5', '11.6', 'E1', 'E2', 'E3', 'E4', 'E5']],
        ['project' => 'mobile',     'ids' => ['4.2', '3.6', '3.7', '3.8', '3.10', '13', '23', '28']],
        ['project' => 'hub',        'ids' => ['3.5', '27', '7', '12', '8', '3.9']],
        ['project' => 'compose',    'ids' => ['6', '16', '10']],
        ['project' => 'messages',   'ids' => ['11.5']],
        ['project' => 'craft',      'ids' => ['14', '13.5', '30']],

        /* Keyword fallbacks — only for shapes the ids above have not claimed.
           Deliberately narrow: a loose pattern here is how an item ends up
           filed under a name nobody would look for it under. */
        ['project' => 'guitardle',  'title' => '/\bguitardle\b/i'],
        ['project' => 'membership', 'title' => '/\bstripe\b|\bmembership\b/i'],
        ['project' => 'darkmode',   'title' => '/\bdark mode\b/i'],
        ['project' => 'messages',   'title' => '/\bmessages? (composer|thread)\b/i'],
        ['project' => 'mobile',     'title' => '/\bPWA\b|\bmobile\b/i'],
        ['project' => 'notifs',     'title' => '/\bnotification|\bbell\b|\bdigest\b|\brecap\b/i'],
        ['project' => 'security',   'title' => '/anon-readable|serves \d+ non-PHP/i'],
    ],
];
