# Marking order — poller → coordinator

Coordinator has handed off to a new session. New coordinator is oriented but has no in-conversation context from prior turns.

**Your ask:** produce a current status report-back so coordinator knows where you are.

Write a brief status doc (can be inline in your SESSION-HANDOFF or a new reply file) covering:

1. What's shipped on dev (user-context endpoint, looth_tier_changed action, PurgeNotifier) — confirm still clean
2. Where you are on the Patreon adapter spec — what's drafted, what's blocked (BATCH-04 still pending?)
3. `LG_PROFILE_APP_URL` constant — done or still open?
4. Any cross-cutting questions for coordinator

Then report back using the canonical format:

```
**poller → coordinator:** status report

```
/home/ubuntu/projects/docs/SESSION-HANDOFF.md
/home/ubuntu/projects/<poller-dir>/<any-specific-reply-doc-if-needed>.md
```

<optional 1-3 line summary>
```

If your SESSION-HANDOFF.md already captures all of this cleanly, just point coordinator at it — no new file needed.
