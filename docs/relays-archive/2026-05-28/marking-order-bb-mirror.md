# Marking order — BB-mirror → coordinator

Coordinator has handed off to a new session. New coordinator is oriented but has no in-conversation context from prior turns.

**Your ask:** produce a current status report-back so coordinator knows where you are.

Write a brief status doc (can be inline in your SESSION-HANDOFF or a new reply file) covering:

1. Where the postgres migration stands — what's live on dev, what's still pending
2. The render-bug bundle status — which are fixed, which are open
3. Any cross-cutting decisions or questions that need coordinator ratification
4. What you're planning next (v2 restyle + threading)

Then report back using the canonical format:

```
**BB-mirror → coordinator:** status report

```
/home/ubuntu/projects/bb-mirror/SESSION-HANDOFF.md
/home/ubuntu/projects/bb-mirror/<any-specific-reply-doc-if-needed>.md
```

<optional 1-3 line summary>
```

If your SESSION-HANDOFF.md already captures all of this cleanly, just point coordinator at it — no new file needed.
