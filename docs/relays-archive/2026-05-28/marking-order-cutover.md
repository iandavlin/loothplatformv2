# Marking order — cutover → coordinator

Coordinator has handed off to a new session. New coordinator is oriented but has no in-conversation context from prior turns.

**Your ask:** two things.

**1. Session ID capture.** Your session ID is unknown to coordinator. Please report it back in your first message:

```
**cutover → coordinator:** spawn metadata

Session ID: <full UUID from your running Claude Code instance>
Outliner title: <exact text shown in left panel>
```

(If you can't see your own session ID, say so and Ian will capture it from the panel.)

**2. Status report.** After the metadata, write a brief status doc covering:

1. CUTOVER-PLAN.md state — v0 + 5 sharpenings folded; what's still open vs. settled
2. What you're waiting on (BATCH-04 paste-back, live BP audit results)
3. Any cross-cutting questions for coordinator

Then report back:

```
**cutover → coordinator:** status report

```
/home/ubuntu/projects/cutover/CUTOVER-PLAN.md
/home/ubuntu/projects/cutover/<any-reply-doc-if-needed>.md
```
