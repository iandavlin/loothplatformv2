# #129 composer redesign — Phase 1 MEASUREMENT (taxonomy → forum)

Measured on **dev2** (`siteurl = https://dev2.loothgroup.com`) on **2026-08-19**, read-only.
Queried the stores directly — MySQL `looth_import` for the taxonomy, Postgres
`looth` schema `forums` for the forum tree — because a tool that sanitises on read
cannot audit the store.

Reproduce: `handoffs/plans/129-measure/` holds the two extracts and the matcher.

---

## 1. Which taxonomy the ruling means

**`shared_category`**, labelled **"Content Topics"** (singular "Content Topic").

- Registered by ACF, definition in the DB at `wp_posts.ID = 21219`
  (`post_type = acf-taxonomy`) — **not in any PHP file in the repo**
  (`grep -rl shared_category` over plugins/themes/mu-plugins returns nothing).
- `hierarchical = 1`, `public = 1`, `show_in_rest = 1`, rewrite slug
  `content_categories`.
- **36 terms**: 7 top-level, 29 children.
- It is already the composer's *"And roughly what area of work?"* field on the
  Loothprint flow (`content_topic_broad_terms`, `lg-frontend-compose.php:324`).

Its 7 top-level terms shadow the forum category parents almost exactly, which is
what makes it the right spine for the chips:

| top-level term | uses | forum counterpart |
|---|---|---|
| Repair and Restoration | 211 | #3818 (category parent) |
| New Builds | 145 | #3839 (category parent) |
| Tools, Spaces, Robots and Widgets | 97 | #3857 (category parent) |
| Business | 46 | #3873 (category parent) |
| Vintage | 15 | — none |
| Perspective | 13 | — none |
| Local Looths | 0 | the parentless local-chapter forums |

### 1a. ⚠ It is NOT registered for `topic` — the blocking implementation fact

`object_type` is exactly these 8, and `topic` is not among them:

```
post-type-videos, post-imgcap, post-regular, loothcuts,
loothprint, useful_links, coe-questions, document
```

Measured consequence: **1,406 term assignments exist and ZERO are on a discussion.**

```
taxonomies actually applied to post_type='topic':   topic-tag 332,  post_tag 5
shared_category on topics:                          0
shared_category lives on:  post-type-videos 358, loothprint 129, post-imgcap 61,
                           useful_links 37, shorty 25, coe-questions 14, …
```

So the chip strip would be the **first writer** of `shared_category` onto a
discussion. That needs `register_taxonomy_for_object_type('shared_category','topic')`
in the mu-plugin. It must NOT be done by editing the ACF definition: that lives in
the database, and a DB edit is not traceable to a commit.

## 2. The forum tree

`post_type = forum`: 45 publish, 8 private, 4 draft, 2 hidden.

The **postable** set is not "the published forums" — it is what the picker's own SQL
returns (`bb-mirror/web/_chrome.php:300`, run here verbatim against PG): public +
open + `forum_type='forum'` + has no children + not in
`LG_BB_MIRROR_NONPOSTABLE_FORUM_IDS` (3876 Quick Questions, 67251 Anonymous
Questions).

**Result: 37 postable leaf forums** across 6 category parents plus 4 parentless.

### 2a. ⚠ Forum slugs and titles are NOT unique — key the mapping on ID

Among those 37:

| duplicate slug | the two forums |
|---|---|
| `acoustic` | #3823 Acoustic Repair · #3845 Acoustic Builds |
| `amps-pickups-and-pedals` | #3826 (Repair) · #3849 (New Builds) |
| `finish` | #3829 Finish Repair · #3847 Finish New Builds |
| `folk-bluegrass-irish-old-time-instruments` | #3835 (Repair) · #3852 (New Builds) |

and two titles are outright identical in both trees — *Amps, Pickups, and Pedals*
and *Folk, Bluegrass, Irish, Old Time Instruments*.

A mapping keyed on slug or title is therefore ambiguous for 8 of 37 forums. **The
committed mapping must key on forum ID**, and the parent is what disambiguates a
name match. (Distinct from the out-of-scope two-Generals / Middle-Tennessee item.)

## 3. Coverage — how many terms map cleanly

Matched hierarchy-aware (a name match only counts when the term's parent and the
forum's parent agree), with normalisation for `&`/punctuation, British-vs-American
*Organisation/Organization*, the `buisness` typo, plural stemming, and the
side-of-tree words *Repair/Builds/Restoration*.

| bucket | terms | share of the 1,406 assignments |
|---|---|---|
| **A.** map cleanly, auto-derivable | **21** | 57.9% |
| **A′.** auto-match got it CONFIDENTLY WRONG | **2** | 0.2% |
| **B.** need a hand-written pair | **4** | 16.4% |
| **C.** no forum exists at all | **9** | 25.5% |

### A′. The two the matcher got wrong — this is the headline finding

| term | auto-matched to | why it is wrong |
|---|---|---|
| Machine Shop (3 uses) | #3869 Shop Organisation (token-overlap 0.75) | a machine shop is not shop tidiness; CNC (#3860) is the nearer home |
| Local Looths (0 uses) | #60681 Ohio Local Looths (token-overlap 0.80) | it is the *parent* of the chapters; picking Ohio is arbitrary among 3 |

And one more trap inside bucket B: **`Electronics Repair` scores 0.909 against
`Electric Repair`** and is semantically a different thing (electronics = pickups and
amps; electric = electric guitars). The highest score in the whole run that isn't an
exact match is on a wrong pair.

**So: name-matching cannot be trusted on its own.** This is the measured argument for
plan v2's "committed mapping, seeded from a measured name-match" rather than a
runtime derivation. The hand-work is bounded and small: **6 rows to decide** (the 4
in B plus the 2 in A′), against 21 that need no thought.

### A. The 21 clean pairs

| term | → forum | match |
|---|---|---|
| Acoustic Repair (138) | #3823 Acoustic Repair | exact |
| Acoustic Builds (112) | #3845 Acoustic Builds | exact |
| Tools, Jigs and Fixtures (85) | #3871 Tools and Jigs | token-overlap 0.80 |
| 3D Printing (80) | #3863 3D Printing | exact |
| Electric Repair (75) | #3820 Electric Repair | exact |
| Electric Builds (68) | #3842 Electric Builds | exact |
| Finish Repair (40) | #3829 Finish Repair | exact |
| CAD/CAM (40) | #3866 CAD/CAM | exact |
| Shop Organization (31) | #3869 Shop Organisation | exact after s/z |
| Finishing New Builds (24) | #3847 Finish New Builds | token-overlap 0.75 |
| CNC (22) | #3860 CNC | exact |
| Customer Relations (21) | #15865 Customer Relations | exact |
| Folk, Irish, Bluegrass, Old Time Instrument Builds (20) | #3852 (New Builds) | core-token |
| Amps, Pickups and Pedal Builds (19) | #3849 (New Builds) | core-token |
| Folk, Bluegrass, Irish, Old Time Repair (15) | #3835 (Repair) | core-token |
| Design and Testing (15) | #3854 Design and Testing | exact |
| Paper Work and Drudgery (7) | #15862 Paper Work and Drudgery | exact |
| Touring Tech (2) | #43277 Touring Tech | exact |
| Job Postings (0) | #4829 Job Postings | exact |
| Resumes (0) | #4832 Resumes | exact |
| PLEK (0) | #7544 PLEK Machine | core-token |

Note the four core-token rows: the *parent* is doing the work. `Folk, Bluegrass,
Irish, Old Time Instruments` is two different forums, and only the parent tells them
apart.

### B. The 4 needing a hand-written pair

| term | uses | nearest | the decision |
|---|---|---|---|
| New Builds | 145 | — | top-level chip with **no generic child** to land on (see §5) |
| Business | 46 | #15868 General Business | almost certainly right, but it is a parent→child hop, not a name match |
| Electronics Repair | 29 | #3820 Electric Repair (0.909) | wrong at 0.909; #3826 Amps, Pickups, and Pedals is the real home |
| Amp Repair | 10 | #3823 Acoustic Repair (0.64) | wrong; #3826 Amps, Pickups, and Pedals (Repair side) |

### C. The 9 terms with no forum

| term | uses | note |
|---|---|---|
| Repair and Restoration | 211 | top-level; its generic child **is** #3837 General |
| Tools, Spaces, Robots and Widgets | 97 | top-level, **no generic child** (see §5) |
| Vintage | 15 | genuinely no forum — a cross-cutting topic |
| Perspective | 13 | genuinely no forum — a cross-cutting topic |
| Violin Family Restoration | 12 | no forum |
| Marketing | 9 | no forum (Business has no marketing sub-forum) |
| Pickup Winding | 2 | no forum |
| Lasers | 0 | no forum |
| scanners | 0 | no forum (lowercase in the store) |

These land on the default. That is the correct behaviour, not a gap — a chip that
names something the forums don't model should still not block the post.

### D. The 14 postable forums no chip can reach

Mostly correct by design: 4 Sponsor forums, 2 Market Place, 3 local chapters,
Suggestion Box, 2 "Share Your … Content", Neck Reset Database, #3826 Amps/Pickups
(reachable once B is decided), and #3837 General (it is the default). The mapping is
one-directional — term → forum — so an unreachable forum is only unreachable *by a
chip*, and the picker is not the only door.

## 4. Ian's evidence line is off by one rank

The issue body says *"the busy General sub-forum is the #1 destination for new
topics."* Measured over `forums.topic.created_at`:

| forum | new topics / 180d | / 365d | all-time |
|---|---|---|---|
| #3823 Acoustic Repair | **38** | **87** | 182 |
| #3837 **General** | 20 | 40 | 107 |
| #3820 Electric Repair | 21 | 38 | 121 |
| #3845 Acoustic Builds | 24 | 37 | 87 |

**General is #2, not #1**, in both windows. Acoustic Repair leads it better than
2:1 over the year. General took 40 of the year's 404 new topics — **9.9%**.

Quick Questions' 181 all-time is a frozen number: newest topic **2025-08-05**, and it
is on the nonpostable list, so it takes 0 new topics now.

**This does not disturb the ruling** — General is still the right *generic* landing
place, which is a different property from being the busiest. Recording it because the
plan cited "#1 destination" as the evidence, and that specific claim is not what the
data says.

## 5. Two things that need a ruling before Phase 2 code

1. **#3837 "General" is a child of "Repair and Restoration", not a site-wide forum.**
   Defaulting every uncategorised discussion there files it *under Repair and
   Restoration*. A member posting a business question with no chip lands in the repair
   tree. Options: accept it; or create a genuinely top-level General; or land the
   default in the parentless block.
2. **Two of the four big top-level chips have nowhere natural to go.** Repair and
   Restoration has its General (#3837) and Business has General Business (#15868), but
   **New Builds (145 uses)** and **Tools, Spaces, Robots and Widgets (97 uses)** have
   no generic child at all. Options: create "General" sub-forums under each; map to
   the busiest child (#3845 Acoustic Builds / #3871 Tools and Jigs — both a semantic
   guess); or let them fall to the default and inherit problem 1.

Together these two are 25.5% of all term use, so they are not an edge case.
