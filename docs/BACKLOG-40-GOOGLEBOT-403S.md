# Backlog 40 — the 2,393 Googlebot 403s: measured, and they are not ours

**Investigated 2026-08-16 against live, read-only.** The charter asked which of
two causes it is: bot protection challenging Googlebot, or gated pages being
publicly linked. **It is the first, and the block is at the edge — not at our
server.**

## What was measured

| Measurement | Result |
|---|---|
| Googlebot status codes, current live access log | **41× 200, 12× 301, 1× 304 — no 403** |
| Googlebot status codes, rotated log (covers GSC's window) | **66× 200, 30× 301, 18× 404 — no 403** |
| Does origin issue 403s at all? | Yes — to *other* agents, on `/profile-api/v0/me/featured`, `/.vscode/sftp.json`, `/archive-api/v0/guitardle-score`, `/patreon-callback/` |
| What sits in front of live | **Cloudflare** (`server: cloudflare`, `cf-ray`) |
| A public request from another box (no UA / Googlebot UA / browser UA) | **403 in all three cases** |

**Origin has never answered Googlebot with a 403.** Not once, across both logs.
Our server is not the thing forbidding Google.

## What that means

The 403s Google reports are being issued **at the Cloudflare edge, before the
request reaches us** — which is exactly why they leave no trace in our access
logs. The edge demonstrably 403s traffic (all three agent tests above), and
Googlebot volume reaching origin is **low — 54 requests in a day** for a site
with ~3,801 indexed pages, consistent with a large share being turned away
upstream.

**Where the fix lives: the Cloudflare dashboard, which is Ian's.** The usual
causes, in the order worth checking:
1. **Bot Fight Mode** — Cloudflare's own docs warn it can challenge verified
   crawlers. It is the most common cause of exactly this symptom.
2. **WAF / custom rules** matching crawler paths or user agents.
3. **Security level** set high enough to challenge datacentre ranges.
4. Confirm **Verified Bots** are allowed.

## ⚠️ What I did NOT prove, stated plainly

My agent tests came from this box, whose IP Cloudflare 403s *regardless of user
agent* — a browser UA was refused too. So they prove **an edge block exists**;
they do **not** prove real Googlebot from Google's verified ranges is refused.
The strong evidence for that is the asymmetry: Google reports 2,393 403s and our
origin issued none.

Confirming it properly needs Cloudflare's **firewall events / security analytics**
filtered to Googlebot — dashboard access, so Ian's, and it will name the rule.

## A second finding — test-account profiles in Google's crawl

Googlebot is still crawling **profile URLs for accounts that no longer exist**,
and 404ing on all of them:

```
/u/piss   /u/poopy   /u/fart_52188d          <- crude test accounts
/u/gerryhayestest  /u/profileapp-test  /u/gift-basil-test
/u/single-gift-test  /u/qa_lite_1779037415  /u/qab50-1779041024
/u/smoketest1  /u/smoketestpublic  /u/stink_305efb  /u/tst-staple-1778083260
/u/ian  /u/1ian-davlin  /u/ian_921a24
```

**Checked before reporting it as exposure: none of these accounts still exist on
live** — the user table returns zero rows for them. So this is historical, not a
live leak: they were public member profiles while they existed, Google indexed
the URLs, and they now 404.

**What it costs today:** crawl budget spent on fixtures, and part of the 316
"Not found (404)" in the coverage export. **What it cost then:** those URLs were
publicly crawlable member profiles on a business site, including the crude ones.

**The lesson is the reusable part**, and it is cheap to act on: *anything a test
or a gate creates on live becomes publicly crawlable within days, and Google
remembers the URL long after the account is deleted.* Test fixtures belong on
dev2. Where a live probe is genuinely needed, it wants a name nobody minds seeing
in a search result and a cleanup in the same breath.

Nothing to fix urgently — the accounts are gone. If the 404s are worth clearing
faster than they age out, serving **410 Gone** on `/u/` for a known-dead set is
the lever, and that is a decision rather than a defect.
