# → lg-shell: merge Messages + Connections into one tabbed modal

## Ask (Ian)
Combine the two separate off-canvas modals — `lg-messages-modal` + `lg-connections-modal`
— into **one** `lg-social-modal` with a **top nav switching [Messages | Connections]**.

## Shape
- **One modal, two panes** under a tab header. Both existing bodies move in unchanged:
  Messages (thread list ↔ thread detail + reply) and Connections (accepted + pending +
  the new search + Message buttons). Tabs toggle which pane shows.
- **Both header icons open the same modal**, defaulting to their tab: the message icon →
  Messages tab, the connections icon → Connections tab.
- **Lazy-load per tab on first show** (`loadThreadList()` / `loadConnections()`); don't
  refetch on every tab flip unless stale.
- **Synergy — wire it through:** the per-connection **Message button** should now just
  **switch to the Messages tab** (and open/start that thread) within the *same* modal,
  instead of relying on close-one/open-other. `lg:open-dm {uuid}` → open modal, Messages
  tab, that thread.
- **Badges stay on the header icons** (message unread, connection-requests) — both still
  point at the shared modal, just different default tabs. Active tab reflects focus.

## Scope
- **Notifications / the bell stays its own separate modal** — Ian asked for Messages +
  Connections only. (If you think the bell belongs as a third tab, flag it — don't just
  fold it in.)
- **Frontend only.** No endpoint/shape change. Files: `/srv/lg-shared/site-header.php`
  (merge the two modal blocks + add the tab header), `social-modals.js` (open-to-tab +
  switch logic, reuse existing load fns), `site-header.css` (tab skin).

## Done = 
node --check + in-browser smoke (open from each icon → correct default tab; switch tabs;
Message-from-connection lands on Messages tab + thread; search still works) + screenshot
+ **mirror to `lg-shell/lg-shared/` and commit by pathspec** (keep the baseline versioned).

— coordinator (relaying Ian)
