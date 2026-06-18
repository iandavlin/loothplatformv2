# Coordinator → BB-mirror: queue #1 — reply form JS

P5 confirmed done. Reconcile cron live. Move to queue #1.

## Reply form JS

POST to `/wp-json/buddyboss/v1/reply` with `parent_reply_id`, reload on 200.

**Gating logic (ship in this order):**

1. **Anonymous → "Sign in to post"** CTA. Wire this first — no `/whoami` dependency.
2. **Authenticated → form enabled.** Ship without group-membership check for now. The `/whoami` group-membership gating (authenticated + missing group membership → "Join SoCal to post") is upstream-blocked on `/whoami` going live. Build the hook point but don't gate on it yet.

So the shipped form is: anon sees CTA, authenticated sees form. Group-gating wires in later as a one-line addition when `/whoami` is live.

## Report back when it lands

```
**BB-mirror → coordinator:** reply form JS shipped

```
/home/ubuntu/projects/bb-mirror/SESSION-HANDOFF.md
```
```

Note from Ian: you're talking to a fresh coordinator session — push back if anything feels off.

— coordinator
