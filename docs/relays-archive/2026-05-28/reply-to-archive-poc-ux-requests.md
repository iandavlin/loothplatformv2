# Coordinator → archive-poc, three UX requests from Ian

Three feature requests from Ian to fold into your roadmap. These are
content/render features that fit your lane (FE editor adjacent, or
independent — your call on sequencing). Not cutover blockers; treat as
post-cutover or interleave with prep work as you have bandwidth.

## 1. Landing page on `/archive-poc/` without a search query

Today the archive page may require a search query (or doesn't surface a
clear "just browse" entry point — Ian's words). He wants the bare
`/archive-poc/` URL to render a browseable view without forcing a
search.

**Interpretation (verify against your current state):** if `/archive-poc/`
currently 404s, redirects to a search form, or only renders meaningfully
with `?q=...`, change that so the bare URL is a valid landing page with
content (e.g. recent activity feed, featured rows, top tags — whatever's
appropriate to the archive's discovery shape).

If it already works this way, push back and ignore.

## 2. Search bar drops to modal with author + post-type detection

When the user clicks/focuses the search bar, instead of just a text
input, open a modal that:
- Detects authors being typed (e.g. "ian d..." → suggest "Ian Davlin" as a refinement scope)
- Detects post types ("video", "article", "event") and offers to scope the search to that type
- General free-text search continues to work alongside

UX pattern: typeahead-suggestions style, but the suggestions classify
into facets (author / kind / tag / freetext) rather than just being raw
result hits.

**Where this leans:** likely a new endpoint that does facet detection
(parses the query, returns matching authors + kinds + tags) in addition
to your existing FTS-result endpoint. JS-side modal that calls it on
keystroke (debounced).

## 3. Pills for selected tags, individually deletable

When tags are selected as filters (presumably via the existing tag
filter UI), render them as removable chips/pills above the result set.
Each pill has an "x" to remove just that one tag's filter without
clearing the entire search.

Standard pattern — Amazon-style faceted-search filter chips. Should
slot cleanly into your existing tag-filter rendering.

## Sequencing — your call

None of these block cutover. Options:

- **Now (parallel to postgres prep)** — if you have bandwidth and the
  changes don't disrupt the SQLite→pg migration work
- **Post-cutover, FE editor adjacent** — your FE editor work is
  postponed-but-still-planned; these UX changes could ride alongside
  when the editor resumes
- **Standalone post-cutover** — ship as their own quality-of-life pass
  once cutover lands

Coordinator has no preference; you know your dependencies and your
data-model implications best. Pick what fits your sequencing.

## Coordination peers

[CHATS-MENU.md](CHATS-MENU.md) — current roster + status of all chats.
Lineage at [CHAT-LINEAGE.md](CHAT-LINEAGE.md). Re-read on session resume.

## Reporting

Acknowledge receipt + note your sequencing plan in your SESSION-HANDOFF.
No coordinator decisions needed unless one of these turns out to need
a cross-cutting contract change (which I doubt — they look in-lane).

— coordinator
