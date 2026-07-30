#!/usr/bin/env python3
"""Compare WP ACF author_* usermeta against profile-app profile_socials.

Canonicalises both sides with the SAME rule looth_social_url() uses
(_render_blocks.php:539) so we compare rendered link targets, not raw storage.
"""
import re, sys, collections

S = "/tmp/claude-1000/-home-ubuntu-worktrees-profile-social-links/892d7038-6003-43c4-815e-ae36c61e881a/scratchpad"

WP_KIND = {
    'author_website': 'web', 'author_instagram': 'instagram',
    'author_facebook': 'facebook', 'author_youtube': 'youtube',
    'author_linktree': 'linktree',
}
BASE = {
    'web': 'https://', 'instagram': 'https://instagram.com/', 'x': 'https://x.com/',
    'youtube': 'https://youtube.com/@', 'facebook': 'https://facebook.com/',
    'tiktok': 'https://tiktok.com/@', 'patreon': 'https://patreon.com/',
    'linktree': 'https://linktr.ee/',
}

def social_url(kind, v):
    v = v.strip()
    if not v: return ''
    if kind == 'email': return 'mailto:' + v
    if kind == 'phone': return 'tel:' + re.sub(r'[^\d+]', '', v)
    if re.match(r'^https?://', v, re.I): return v          # already absolute
    h = v.lstrip('@/')
    if kind == 'bandcamp':
        return 'https://' + h if '.' in h else 'https://%s.bandcamp.com' % h
    return BASE.get(kind, 'https://') + h

def norm(u):
    """Loose compare: ignore scheme, www., trailing slash/?, case of host."""
    u = re.sub(r'^https?://', '', u.strip(), flags=re.I)
    u = re.sub(r'^www\.', '', u, flags=re.I)
    return u.rstrip('/?').lower()

def load(path, kindmap=None):
    d = collections.defaultdict(dict)
    for line in open(path, encoding='utf-8', errors='replace'):
        parts = line.rstrip('\n').split('\t')
        if len(parts) < 3: continue
        email, k, v = parts[0], parts[1], '\t'.join(parts[2:])
        if kindmap:
            k = kindmap.get(k)
            if not k: continue
        if v.strip(): d[email][k] = v.strip()
    return d

wp = load(S + '/wp_socials.tsv', WP_KIND)
pg = load(S + '/pg_socials.tsv')

both = sorted(set(wp) & set(pg))
phantom, divergent, missing, match = [], [], [], 0

for e in both:
    kinds = set(wp[e]) | set(pg[e])
    for k in sorted(kinds):
        w, p = wp[e].get(k), pg[e].get(k)
        if w and not p:   phantom.append((e, k, social_url(k, w)))
        elif p and not w: missing.append((e, k, social_url(k, p)))
        elif w and p:
            wu, pu = social_url(k, w), social_url(k, p)
            if norm(wu) != norm(pu): divergent.append((e, k, wu, pu))
            else: match += 1

print("WP users w/ author_* set : %d" % len(wp))
print("PG users w/ socials      : %d" % len(pg))
print("comparable (in both)     : %d" % len(both))
print("WP-only (no PG profile)  : %d" % len(set(wp) - set(pg)))
print()
print("== A. PHANTOM  (renders on posts/events, ABSENT from profile) : %d rows, %d users"
      % (len(phantom), len({e for e, _, _ in phantom})))
for e, k, u in phantom: print("   %-34s %-10s %s" % (e, k, u))
print()
print("== B. DIVERGENT (both set, different target) : %d rows, %d users"
      % (len(divergent), len({e for e, _, _, _ in divergent})))
for e, k, wu, pu in divergent:
    print("   %-34s %-10s\n       posts/events: %s\n       profile     : %s" % (e, k, wu, pu))
print()
print("== C. MISSING  (in profile, NOT on posts/events) : %d rows, %d users"
      % (len(missing), len({e for e, _, _ in missing})))
for e, k, u in missing: print("   %-34s %-10s %s" % (e, k, u))
print()
print("== D. AGREE : %d rows" % match)
