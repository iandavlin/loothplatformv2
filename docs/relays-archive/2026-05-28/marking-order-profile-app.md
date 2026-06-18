# Marking order — profile-app → coordinator

Coordinator has handed off to a new session. New coordinator is oriented but has no in-conversation context from prior turns. 

**Your ask:** produce a current status report-back so coordinator knows where you are.

Write a brief status doc (can be inline in your SESSION-HANDOFF or a new reply file) covering:

1. Where slice 3.5 stands (`/whoami` + batch users + cache + self-purge) — what's done, what's in flight, what's blocked
2. Any cross-cutting decisions or questions that need coordinator ratification
3. Anything you're waiting on from coordinator or Ian

Then report back using the canonical format:

```
**profile-app → coordinator:** status report

```
/home/ubuntu/projects/profile-app/SESSION-HANDOFF.md
/home/ubuntu/projects/profile-app/<any-specific-reply-doc-if-needed>.md
```

<optional 1-3 line summary>
```

If your SESSION-HANDOFF.md already captures all of this cleanly, just point coordinator at it — no new file needed.
