# DISCUSSION SURFACE — CANON

**Status: canonical ruling (Ian, 2026-07-09). This governs every lane that touches discussion
topics or replies.** If an older doc, a mock, or a lane plan disagrees, THIS wins.

## The ruling

**The discussion surface is the MODAL. Both viewports.**

Discussion topics and their replies are rendered and composed in the **§4e discussion modal**
(`webroot/hub-polish.js`) — the same modal on **desktop and mobile** (mobile clones the desktop
modal; one code path, two stylings). That modal **is** the discussion experience members use.

**The legacy full forum pages do NOT matter for features.** The bbPress/forum single-topic page
(`bb-mirror/web/forums/_single-topic.php` and its topic-page reply form) is a **deprecated
permalink / no-JS / crawler fallback**. In Ian's words (2026-07-09): *"renders on the legacy forum
page mean nothing — we only care about the modals on desktop or mobile."*

## What this means for a lane

Building ANY discussion feature — reply images, reactions, notification click-throughs, mentions,
edit/delete, gating — you:

- **Build + compose + render in the modal** (`hub-polish.js` §4e), desktop and mobile. That is the
  target and the parity pair.
- **Do NOT invest in `_single-topic.php` render.** Don't add feature rendering there, don't mock
  there, don't verify there. A screenshot of a feature on the legacy topic page is **not evidence**
  — re-shoot it inside the modal.
- Notification click-throughs land in the modal via the hub-deeplinks system (mobile
  `lgOpenTopicMobile` / `?topic=`; desktop deep-link + read-on-clickthrough). No legacy full-page
  notification URLs. (See the notifications merge-gate criterion.)

## The one caveat (so nobody "fixes" it wrong)

The permalink page still **serves** — bots, shared/SEO links, and no-JS clients land on it. It is
**not deleted**, just not a feature-render target. Two consequences:

1. Don't rip it out. It's a fallback that must keep returning valid HTML.
2. **Access-control / leak-safety is URL-and-data-layer, page-independent.** A gated topic's reply
   image must be un-fetchable at its direct URL regardless of which surface would render it. That
   contract still applies in full — it is not weakened by the render target being the modal.

## Cross-refs

- `MOBILE-DESKTOP-SPLIT.md` — how the one modal is styled per breakpoint.
- `HUB-RENDER-ARCHITECTURE-AUDIT.md` — the render pipeline this modal sits in.
