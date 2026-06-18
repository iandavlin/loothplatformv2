# Standalone-before-launch inventory

Built from the **live nav walk + URL probes** (Chrome, logged-in admin) cross-referenced
with WP pages/CPTs and the nginx strangler routes — NOT the raw 60-row page dump. Dead
test/mockup pages are listed once at the bottom and excluded.

Status key: ✅ standalone live · ⬜ WP-templated, to build · ❓ decision needed · 🗑 kill/skip

---
## ✅ Already standalone (done — for reference)
| Surface | Lane |
|---|---|
| `/` → redirects to `/archive-poc/` (feed) | archive-poc |
| `/front-page/` (discovery feed), `/archive/` (search) | archive-poc |
| `/forum/` (+ `/forums/`, `/forums-poc/` → 301) | bb-mirror |
| `/events/` | events |
| `/directory/members/` | profile-app |
| `/u/<slug>`, `/p/<slug>`, `/profile/edit` | profile-app |
| `/membership-guide/` | poller |
| `/activity/` → folds into the feed | archive-poc |
| **Content CPTs routed:** post-imgcap, loothprint, loothcuts, useful_links, member-benefit, document | standalone |

## ⬜ Money pages — MOSTLY DORMANT AT CUT (Stripe dormant — Ian)
At cutover Stripe ships dormant, so the Stripe-driven pages are **OFF / deferred to Stripe-A-later**.
**Only ONE is launch-critical:**
| Page | Launch status | Build |
|---|---|---|
| **`/manage-subscription/`** | **LIVE at cut — read-only** | standalone, shows the user's **Patreon membership** (read poller DB direct PDO; "manage on Patreon" link). **No Stripe, no form → no nonce.** |
| `/lgjoin/` (Join), `/lggift-buy/`, `/lggift/` | OFF (Stripe checkout dormant) | defer to Stripe-A-later |
| `/my-gifts/`, `/affiliate-earnings/`, `/request-refund/` | OFF / dormant | defer |
| `/welcome/`, `/regional-pricing-not-available/` | transactional (Stripe flow) | defer |
> **The nonce-mint endpoint is NOT launch-blocking** — it gates the *form* pages, which are all
> dormant at cut. It ships with the Stripe-A-later batch, not now.
> Join at cut = the **Patreon onboarder** (live), not Stripe.

## ❓ Legacy info/content pages — decide: standalone vs redirect vs kill
| Page | State | Likely call |
|---|---|---|
| `/calendar/` | 200 WP-templated | standalone? or fold into `/events/`? |
| `/sponsors/` (Our Sponsors) | 200 WP-templated | standalone (real public surface) |
| `/about/`, `/contact/` | login-gated WP | standalone static, or keep-WP |
| `/privacy/`, `/terms/` | login-gated WP | standalone static (legal — should be PUBLIC, not login-gated) |
| `/shops/` | login-gated WP | real surface or kill? |
| `/members/` (BB) | login-gated WP | likely **redirect → `/directory/members/`** (superseded) |
| `/billing-refund/` | **404 — broken footer link** | fix the link → correct slug, then standalone |

## 📰 Content CPTs not yet standalone — decide which launch standalone
| CPT | State |
|---|---|
| post-type-videos, sponsor-post | **backed out** (low blob coverage) — re-enable or leave WP |
| sponsor-page, sponsor-product | TBD |
| post-regular (Articles-Text), shorty, public-post | TBD |
| member-spotlight, international-loothi (events), event CPT | TBD |

## ✉️ Weekly email (your ask — separate scope)
`looth-group-weekly`, `weekly-email-sign-up`, `weekly_email` CPT. Needs: content source +
a **sender** (no email infra in profile-app/standalone today) + dev-safety sink. Scope TBD.

## 🗑 Excluded — dead / test / mockup (NOT launch surfaces, don't re-add)
test-checklist (admin-only), loothprint-test / loothprint-test-2, mp2t-test,
directory-mockup-v1/v2, form-mockup, auto-draft, sorry, letters-to-danta,
bob-taylor-efficiency / bob-taylor-show-form, edit-* author/sponsor forms, showrunner-form,
image-rectifier, shop-layout-planner / shop-planner-page, member-spotlight-questionnaire,
efficiency / edit-efficiency-consultant, testimonial-with-forms, sponsor-post-dashboard.

---
### Decisions — RULED (Ian, this session)
1. **Legacy pages:**
   - **Build standalone:** `/calendar/`, `/sponsors/`, `/about/` (and `/contact/` — assume with about).
   - **Nav → loothtool.com (external, NOT built here):** `/privacy/`, `/terms/`, `/shops/` live on the
     other install. → lg-shell: repoint these footer links to loothtool.com + add nav-to-loothtool.
   - **`/members/`** → redirect to `/directory/members/` (superseded).
   - **`/billing-refund/`** → fix the broken footer link (404).
2. **Video:** NOT currently standalone (intentionally backed out — only 9/319 video posts have blobs).
   To enable → build the **render→WP fallback** so uncovered posts fall back to WP instead of 404,
   then re-enable video + sponsor interception. Standalone-lane ticket.
3. **Weekly email = replicate the ARCHIVE LISTING page** (not a sender). There's a page on the
   archive listing past weekly emails (`weekly_email` CPT / "Looth Group Weekly"); replicate that
   listing surface on standalone. Standalone/archive-lane ticket. (Sign-up form + actual sending
   are separate, later.)
