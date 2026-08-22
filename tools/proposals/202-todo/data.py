# -*- coding: utf-8 -*-
"""#202 — the measured state of Ian's todo list, 2026-08-22 ~17:00Z.

EVERY NUMBER IN HERE WAS MEASURED, NOT REMEMBERED. Provenance per field:

  visible_today  the live page fetched through the dev gate over loopback
                 (`curl --resolve dev2.loothgroup.com:443:127.0.0.1`), cards
                 parsed out of the rendered `Your list` accordion. 11 cards.
  waiting_h      for the 11 visible: the `merged`/`built` LABEL event from the
                 GitHub issue timeline — the moment it actually became Ian's.
                 For the 10 invisible: the merge commit date on origin/main.
                 NOT created_at: these issues were bulk-imported from a ledger
                 on 8/19, so age-since-created reads 3d for almost everything
                 and carries no information.
  door / action  resolved with lanes-page.py's OWN record reader, imported from
                 the file, over commit bodies on origin/main + park reasons.
                 So these are the doors the real renderer would emit today.
  lane_says      the VERBATIM park reason from `lanes --json`. PAGE.md #159:
                 the lane has already said the true thing better than any
                 wording derived from a label could.

THE FINDING: 10 items are owed by Ian and INVISIBLE on his list, and 6 of those
10 already have a door written. The list shows 8 doorless cards and hides 6
fully-equipped ones.
"""

# now = 2026-08-22 17:00Z, the moment the figures above were taken
GENERATED = "2026-08-22 17:00"

# band: 1 = nothing else moves until he says
#       2 = one word and members see it   (a flip)
#       3 = just needs his eyes           (a look)
ITEMS = [
    # ---- BAND 1 -----------------------------------------------------------
    dict(n=197, band=1, visible=False, waiting_h=1.4, blocker=True,
         action="Pick your window for the live billing swap",
         title="GO-LIVE BLOCKER: live's billing app is a separate repo",
         lane_says="script+runbook merged; window is Ian's, keeper verifies each step",
         door=None, behind="live checkout still runs the old separate app"),
    dict(n=191, band=1, visible=False, waiting_h=5.9,
         action="Deploy the licence fix to live, and correct three posts",
         title="Loothprint licence: one option is mislabelled",
         lane_says="merged; dev2 corrected + gated; live label fix waits for Ian's deploy + his 3-row correction",
         door="/preview/191-licence-modal/compose/?type=loothprint",
         behind="3 published posts on live still show the contradictory terms"),
    dict(n=183, band=1, visible=False, waiting_h=21.4,
         action="Look at the Comp Timers screen and name a cutover date",
         title="Comp expiry is NOT enforced on live — two looth4 timers",
         lane_says="merged; flag OFF with empty cutover — awaiting Ian's look at the Comp Timers screen and his cutover date",
         door=None, behind="comp expiry stays unenforced on live until the date is set"),

    # ---- BAND 2 — a flip --------------------------------------------------
    dict(n=104, band=2, visible=True, waiting_h=88,
         action="Say GO to switch on mail containment",
         title="Mail-containment: gate on host not LG_ENV (latent security)",
         lane_says=None, door=None, behind="a latent security gap stays open"),
    dict(n=88, band=2, visible=True, waiting_h=88,
         action="Say GO to hide the “+” from logged-out phones",
         title="Logged-out mobile bottom dash: “+” implies you can post",
         lane_says=None, door=None, behind="logged-out visitors still see a post button that cannot work"),
    dict(n=87, band=2, visible=True, waiting_h=88,
         action="Say GO to stop the bell emptying your recap",
         title="Recap: the mobile bell's 700ms mark-all-read empties the recap",
         lane_says=None, door=None, behind="members lose their recap on every bell tap"),
    dict(n=84, band=2, visible=True, waiting_h=88,
         action="Say GO to switch on the phone Back button",
         title="Mobile/PWA post → hub BACK NAV",
         lane_says=None, door=None, behind="phone readers still have no way back to the hub"),
    dict(n=81, band=2, visible=True, waiting_h=88,
         action="Say GO to switch the sitemap on",
         title="SEO/sitemap",
         lane_says=None, door=None, behind="search engines still cannot see the site map"),
    dict(n=179, band=2, visible=False, waiting_h=24.1,
         action="Open a Loothprint you wrote and tap Edit — then say GO on the paywall toggle",
         title="Compose: the edit door, one pill family, the paywall toggle",
         lane_says="merged as dea38d0 — Ian approved from the preview; paywall toggle flag OFF awaiting his look",
         door="/loothprint/fret-sander-v2/",
         behind="authors still cannot paywall their own print"),
    dict(n=186, band=2, visible=False, waiting_h=21.2,
         action="Say GO on the 39-file stray sweep",
         title="Loothprint uploads: limits, in-and-out cleanup",
         lane_says="merged; limits + stamp-scoped cleanup live on dev2; 39-file stray sweep awaits Ian's GO",
         door="/compose/?type=loothprint",
         behind="39 stray upload files stay on the box"),
    dict(n=199, band=2, visible=False, waiting_h=0.5,
         action="Look at the 12 gating shots, then say GO to switch it on",
         title="Loothprint gating: ONLY the file download is gated",
         lane_says="merged dark behind LG_V2_LOOTHPRINT_GATING; Ian's look via the 12 preview shots, flip after",
         door="/preview/199-loothprint-gating/loothprint/lane199-after/",
         behind="logged-out visitors still get the wrong card on a print"),
    dict(n=200, band=2, visible=False, waiting_h=0.3,
         action="Say GO on the featured-member consent switch",
         title="Featured members: Ian's picks stay on the front page",
         lane_says="merged; pin absolute per Ian; dev2 band verified rendering (=1); consent local HELD pending Ian",
         door=None, behind="the consent switch is held OFF on dev2 waiting for you"),

    # ---- BAND 3 — a look --------------------------------------------------
    dict(n=93, band=3, visible=True, waiting_h=70,
         action="Write a loothprint from the site and send it for review",
         title="Front-end COMPOSE", lane_says=None,
         door="/compose/?type=loothprint", behind=None),
    dict(n=150, band=3, visible=True, waiting_h=63,
         action="Check a Patreon member cannot be charged twice at checkout",
         title="Checkout is Patreon-blind: a live Patreon member can double-pay",
         lane_says="merged to main as 0496a73, flag lgms_double_pay_block OFF, awaiting Ian's phone check + flip decision",
         door=None, behind=None),
    dict(n=149, band=3, visible=True, waiting_h=63,
         action="Check a dual holder keeps membership after cancelling Stripe",
         title="Dual holders: cancel Stripe while paying Patreon = membership loss",
         lane_says=None, door=None, behind=None),
    dict(n=107, band=3, visible=True, waiting_h=62,
         action="Pick a featured member from the dash",
         title="FEATURED MEMBERS", lane_says="merged to main as 0db578a — completion wall down, consent decision pending Ian",
         door="/wp-admin/admin.php?page=lg-featured-member", behind=None),
    dict(n=148, band=3, visible=True, waiting_h=53,
         action="Look at the join page — three tiers, and check the prices",
         title="Multiple tiers", lane_says="merged to main as c879589, dev2 flag ON pending Ian's dash pricing + join-page look",
         door="/lgjoin/", behind=None),
    dict(n=170, band=3, visible=True, waiting_h=47,
         action="Check the header Join button signed out and signed in",
         title="LIVE header Join: Patreon for the world, our join page for all",
         lane_says="merged as 4873b1e, three-state header live, dev2 stays ON for anon — live's allowlist state is go-live business",
         door=None, behind=None),
    dict(n=187, band=3, visible=False, waiting_h=18.5,
         action="Look at a Loothprint page — same photos, under half the download",
         title="Loothprint singles serve the hero at full size",
         lane_says="merged; 83% off the hero, 46-73% off every article shape; 4 dev2-only broken photos await a data copy",
         door="/preview/187-loothprint-images/loothprint/fret-sander-v2/", behind=None),
    dict(n=189, band=3, visible=False, waiting_h=19.4,
         action="Look at the form's new uploader — four pictures, then try it",
         title="Compose form gets its own uploader",
         lane_says="merged; no-modal uploader live on dev2, awaiting Ian's look at the picture page",
         door="/footer-mockups/189-uploader/", behind=None),
    dict(n=194, band=3, visible=False, waiting_h=3.9,
         action="Set a product's tier and region on the new Products tab",
         title="Membership dash needs a Products tab",
         lane_says="merged; tab live on dev2's membership dash awaiting Ian's click",
         door=None, behind=None),
]

# The fifth family (#172's ruling): landed, nothing in it for him.
QUIET = [dict(n=138, title="Approve should start work by itself")]

BANDS = {
    1: ("Nothing else moves until you say", "#b4402f"),
    2: ("One word and members see it", "#b8860b"),
    3: ("Just needs your eyes", "#5f7d33"),
}

# Measured facts quoted on the index page.
FACTS = dict(
    visible_today=11,
    invisible=10,
    invisible_with_doors=6,
    visible_without_doors=8,
    parked_branches=31,
    parked_naming_ian=14,
    oldest_wait_days=3.7,
    live_behind_commits=30,
)
