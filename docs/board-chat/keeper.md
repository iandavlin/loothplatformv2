# The board chat — Ian and keeper

### 2026-08-16 18:20:17 — ian-via-board

> hello ?

### 2026-08-16 18:27 — keeper

> Here — the channel works both ways now. This chat commits through the board and I answer in place; my doorbell for it is now armed, so replies come in well under a minute. State right now: the banner fix you verified and both guitardle fixes are staged for your live deploy — the verification suite is minutes from its verdict, then you get the run-on-live sequence here or in the sidebar, whichever you answer from.

### 2026-08-16 23:45:20 — stripe-membership

> DISK AUDIT part 1 of 3 complete - the 39 holdout worktrees are now 6.
> 
> Reclaimed 1.1G (disk 90% -> 86%, 2.9G -> 4.0G free). 33 directories removed, every one with a documented restore path in /home/ubuntu/worktree-rescue-2026-08-16/RECOVERY.md.
> 
> What the naive checks would have destroyed:
> - git log --oneline --not --remotes=origin DROPS the implicit HEAD when only negative refs are given. It printed EMPTY for all 5 salvage candidates regardless of content. The real count was 11+2+1+1+1 commits existing nowhere on origin.
> - git log @{u}..HEAD returns 0 when a branch has NO upstream at all - messages-group-names read as clean and its only commit was on no origin ref anywhere.
> - My registered column was checked against keeper-repo alone. These worktrees have THREE parent repos: loothplatformv2-clean (27), keeper-repo (8), /home/ubuntu/projects (8). The 8 I first called ORPHAN were registered all along.
> - DELETE-SAFE means content is recoverable, NOT safe to delete. My first table marked all four LIVE lanes deletable, including my own worktree - they are the four biggest dirs at 434M. Cross-checked tmux + fleet manifest + /proc cwd before touching anything.
> 
> Salvaged first: rescue/map-infinite, rescue/messages-group-names, rescue/reply-images-6, rescue/social-poster-meta pushed to origin (SHAs re-verified present AFTER deletion). consolidate-poller carried 502 uncommitted lines across 6 poller files - captured as a verified patch, not lost.
> 
> FINDING FOR IAN: /home/ubuntu/projects points at a DIFFERENT repo (iandavlin/looth-platform via ssh alias github-looth) with NO entry in ~/.ssh/config on this box - it cannot be pushed to or fetched from. 8 worktrees hung off it, including bespoke-cutover with 11 real commits of hub work I could not push anywhere. Those commits survive only in /home/ubuntu/projects/.git locally - one disk failure from being the only copy.
> 
> LEFT ALONE deliberately: the 4 live lanes, plus emoji-picker-build and front-page-editor (keeper-registered, parked, both carry uncommitted work - yours to resume or release, not mine to delete).
> 
> Parts 2 (/var/www, ~/.local) and 3 (the 2.7G archives, inventory only) next.

### 2026-08-16 23:55:11 — stripe-membership

> DISK AUDIT COMPLETE - all 3 parts. 93% -> (keeper) 90% -> 84%. 2.9G -> 4.8G free, ~1.9G reclaimed. Full report: docs/DISK-AUDIT-2026-08-16.md on branch stripe-membership (5b3b44e).
> 
> PART 2 executed: ~/.local/share/claude/versions held 3 CLI versions; only 2.1.233 is mapped by any process (the 4 live lanes), so 2.1.226+2.1.228 went - 580M, re-downloadable. code-server logs 152M -> 29M (kept newest 2 of 10).
> 
> PART 2 proposed, NOT executed (needs Ian):
> - /var/www/dev.bak-overlays-20260625-020312 is 1.6G, HALF of /var/www. Real copy (distinct inodes, not hardlinks), referenced by no nginx or php config, named 6/25 but written into until 7/4. The IDENTICAL directory also exists on LIVE (live image built from dev2) - so 1.6G on both boxes.
> - npm caches ~400M regenerable. Keeping ~/.cache/ms-playwright (295M) - the e2e gates need it.
> 
> SECURITY FINDING, two boxes, opposite mechanisms, same file: /.well-known/ is gate-exempt and serves ANONYMOUSLY by design (Android asset links need it - assetlinks.json verified present, 326B, do not delete). Someone parked 73M of build artifacts there.
> - dev2 has location ^~ /.well-known/, which stops the .php regex location matching, so there is NO php handler and Provision.php falls through as a STATIC file. Measured: 8,665 bytes of raw PHP source, byte-identical to the file. That is the /archive-api/v0/*.php disclosure class recurring ONE DIRECTORY OVER, and the V2-PHP-SOURCE gate is blind here too.
> - live has no ^~ block, so it EXECUTES instead - and Provision.php is a pure class definition, so it emits nothing. Inert there. But live does publish 33 PHP sources renamed .txt and 57 zips, incl. a full repo archive (1,481 files).
> Scanned clean: no live-key shapes, no AUTH_KEY, and all 22 DB_PASSWORD hits are $_ENV reads or references to /etc/lg-poller-db - ZERO quoted literals. One sk_test_ (sandbox), consistent with Ian keep-sandbox-keys ruling. So: source/IP disclosure, not a credential leak - and crawlable, which ties straight into backlog 40.
> HONEST CAVEAT: external reachability is read off the conf, NOT measured. Loopback bypasses the gate (control: /hub/ returned 200 with no cookie) and an EIP hairpin does not route from inside the VPC, so this box cannot self-test as an outside client. It needs one fetch from off-box to confirm.
> 
> PART 3 - archives inventoried, NOTHING TOUCHED:
> - dev26-archive-20260704/backups (681M) is a VERIFIED FULL DUPLICATE of ~/backups: 15/15 files identical by name+size, identical md5 on the 571M dump. Deleting either copy is a clean 681M.
> - That same archive holds bespoke-cutover-FULL.bundle, which verifies as a complete history containing a7e5233 - so the 11 hub commits I could not push DO have a second copy. Both are on this one disk.
> - dev1-import (1.3G) is NOT duplicated: 738M projects + 583M of 394 claude transcripts, dev1 frozen at 7/26-28. Distinct from ~/projects (live to today).
> 
> Decision table in the doc. Taking the duplicate backups + the 1.6G overlay backup + dev1-import to a bucket + the npm caches puts the disk near 60%. The structural point: the irreplaceable things here (dev1 transcripts, that bundle) and the pure waste (a 681M byte-identical duplicate) sit on the same 29G volume with no off-box copy. The durable fix is a bucket, not a bigger disk.
> 
> Nothing was deleted that is not provably recoverable; all 33 removals have restore paths in /home/ubuntu/worktree-rescue-2026-08-16/RECOVERY.md.
