<?php
/**
 * archive-poc default front-page widgets.
 *
 * Single source of truth for the four sponsor/looth/CTA arrays. Both
 * index.php (SSR) and api/v0/_config.php (webhook) read from here so the
 * dash can request an "effective" config view (defaults overlaid with the
 * saved config.json) and the form pre-populates with what's actually
 * rendering, not an empty placeholder.
 *
 * Returns: ['sponsors' => [...], 'local_looths' => [...], 'cta_member' => [...], 'cta_public' => [...]]
 *
 * Note: the rows[] default lives in rows.json, NOT here, so this file can be
 * called from anywhere without depending on the row-config schema.
 */

declare(strict_types=1);

return [
    // Sponsor tiles in the right pane. `bg` is sampled from each logo's
    // native background so the tile + artwork read as one branded card.
    'sponsors' => [
        ['name' => 'Total Vise',            'url' => 'https://loothgroup.com/sponsor-page/total-vise/',           'logo' => 'https://loothgroup.com/wp-content/uploads/2024/06/Sponsor-Total-Vise-300x80.webp',  'bg' => '#212060'],
        ['name' => 'StewMac',               'url' => 'https://loothgroup.com/sponsor-page/stewmac/',              'logo' => 'https://loothgroup.com/wp-content/uploads/2024/06/Sponsor-Stew-Mac-300x80.webp',    'bg' => '#fff'],
        ['name' => 'Go Acoustic Audio',     'url' => 'https://loothgroup.com/sponsor-page/go-acoustic-audio/',    'logo' => 'https://loothgroup.com/wp-content/uploads/2024/06/Sponsor-Go-Acoustic-300x80.webp', 'bg' => '#262626'],
        ['name' => 'Strings Micro Factory', 'url' => 'https://loothgroup.com/sponsor-page/strings-micro-factory/','logo' => 'https://loothgroup.com/wp-content/uploads/2024/06/SMF-Logo-Horizontal-300x92.jpg',  'bg' => '#000'],
        ['name' => 'GluBoost',              'url' => 'https://loothgroup.com/sponsor-page/gluboost/',             'logo' => 'https://loothgroup.com/wp-content/uploads/2026/04/gluboost-logo.png',               'bg' => '#000'],
        ['name' => 'Member Benefits',       'url' => 'https://loothgroup.com/archive/?_post_type=member-benefit', 'logo' => 'https://loothgroup.com/wp-content/uploads/2024/06/Member-Benefits-300x80.webp',    'bg' => '#fff'],
    ],

    // Featured-member band on the LOGGED-IN front page (Bento layout,
    // Buck/Ian 2026-06-11). Rotate via the config.json overlay; enabled=false
    // hides the band. Dan Erlewine first.
    'featured_member' => [
        'enabled'   => true,
        'name'      => 'Dan Erlewine',
        'role'      => 'Master Luthier & Repair Authority',
        'where'     => 'Athens, Ohio',
        'bio'       => 'Sixty years on the bench. Author of the Guitar Player Repair Guide - now sharing the craft, hands-on, with the Looth Group.',
        'avatar'    => 'https://i.ytimg.com/vi/2IBxue3zPxE/hqdefault.jpg',
        'cta_label' => "See Dan's videos",
        'cta_href'  => '/hub/?author=Dan%20Erlewine&type=video',
    ],

    // Public front-page Hub teaser ("What members are talking about") —
    // Bento/Classic deck section, Buck 2026-06-11. Snapshot of real public-tier
    // posts; refresh via the config.json overlay (or wire live data later).
    'hub_teaser' => [
        'enabled' => true,
        'items' => [
            ['title' => 'Gibson LG-1 1957 fingerboard extension glue will not loosen', 'excerpt' => 'Today I have spent the better part of five hours attempting to get the fingerboard extension free on this guitar. I am using the method I have used for dozens...', 'replies' => 4],
            ['title' => 'National Triolian - Fishman Resophonic/Lipstick pickup rewiring', 'excerpt' => 'I am trying to figure out a wiring scheme for a modded National Triolian resonator guitar. It has a Fishman Resophonic end pin jack pickup...', 'replies' => 2],
            ['title' => 'Some questions on a Taylor Acoustic...', 'excerpt' => 'I have a Taylor Acoustic in for a setup and I had a couple of questions. My main one is how you would go about gluing the label back on?', 'replies' => 5],
            ['title' => 'Heat Blanket controller', 'excerpt' => 'I have been using a really old timer and rheostat control, with a separate thermometer, on my heat blanket setup for a while. It has worked, but...', 'replies' => 5],
            ['title' => 'Stainless steel fretwire for compound radius', 'excerpt' => 'I have a friend who builds flat tops who wants to give stainless a try. I have done a handful of refrets and he was asking advice...', 'replies' => 3],
            ['title' => 'Reputable looths in Albuquerque, NM?', 'excerpt' => 'A buddy of mine needs work on his mid-60s Jaguar. I know it is missing a saddle and needs a general going-over and a setup...', 'replies' => 6],
        ],
    ],

    'local_looths' => [
        ['name' => 'Tri State Looths (NYC)',  'url' => 'https://loothgroup.com/groups/tri-state-looths-nyc/',  'avatar' => 'https://loothgroup.com/wp-content/uploads/group-avatars/38/6703eb1642637-bpthumb.jpg'],
        ['name' => 'SoCal Looths',            'url' => 'https://loothgroup.com/groups/socal-looths/',          'avatar' => 'https://loothgroup.com/wp-content/uploads/group-avatars/39/67059fc8c8f52-bpthumb.png'],
        ['name' => 'SW Ontario Looths',       'url' => 'https://loothgroup.com/groups/sw-ontario-looths/',     'avatar' => 'https://loothgroup.com/wp-content/uploads/group-avatars/40/695029893f4d0-bpthumb.jpg'],
        ['name' => 'DMV Looths',              'url' => 'https://loothgroup.com/groups/dmv-looths/',            'avatar' => 'https://loothgroup.com/wp-content/uploads/group-avatars/41/67bf465a9aa9b-bpthumb.png'],
        ['name' => 'Looth Troop PNW',         'url' => 'https://loothgroup.com/groups/looth-troop-pnw/',       'avatar' => 'https://loothgroup.com/wp-content/uploads/group-avatars/42/67c3666232607-bpthumb.jpg'],
        ['name' => 'Looths of Ireland',       'url' => 'https://loothgroup.com/groups/looths-of-ireland/',     'avatar' => 'https://loothgroup.com/wp-content/uploads/group-avatars/43/67c4978e6ed05-bpthumb.png'],
        ['name' => 'Middle Tennessee Looths', 'url' => 'https://loothgroup.com/groups/middle-tennessee-looths/','avatar' => 'https://loothgroup.com/wp-content/uploads/group-avatars/45/68b649a51295e-bpthumb.png'],
        ['name' => 'Basque Country Looths',   'url' => 'https://loothgroup.com/groups/basque-country-looths/', 'avatar' => 'https://loothgroup.com/wp-content/uploads/group-avatars/46/68fb57ee07b48-bpthumb.png'],
        ['name' => 'Ohio Local Looths',       'url' => 'https://loothgroup.com/groups/ohio-local-looths/',     'avatar' => 'https://loothgroup.com/wp-content/uploads/group-avatars/47/691e1eeae828a-bpthumb.png'],
    ],

    'cta_member' => [
        ['label' => 'Add Forum Post',       'url' => 'https://loothgroup.com/add-a-post-on-the-forum/', 'style' => 'primary'],
        ['label' => 'Weekly Email',         'url' => 'https://loothgroup.com/looth-group-weekly/',      'style' => 'secondary'],
        ['label' => 'Member Map',           'url' => '#member-map', 'style' => 'secondary', 'attr' => 'data-action="open-member-map"', 'icon' => '<svg class="cta-btn__icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>'],
        ['label' => 'Search the Archive',   'url' => '#search', 'style' => 'ghost', 'action' => 'open-search-modal'],
    ],

    'cta_public' => [
        ['label' => 'Join Looth Group',     'url' => 'https://www.patreon.com/c/theloothgroup/membership', 'style' => 'primary'],
        ['label' => 'Weekly Newsletter',    'url' => 'https://loothgroup.com/looth-group-weekly/',          'style' => 'secondary'],
        ['label' => 'Search the Archive',   'url' => '#search', 'style' => 'ghost', 'action' => 'open-search-modal'],
    ],
];
