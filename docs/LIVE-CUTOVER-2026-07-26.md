# LIVE cutover — deploy-one-pull (2026-07-26, Ian at the keyboard)

Keeper-sequenced: **Step A (code, the old way, one last time) → verify → Step B
(mechanism) → verify.** A failure after A is the code; after B it is the mechanism.
Run every command yourself; stop and paste output to keeper on ANY line that prints
SKIPPED, FATAL, or a non-200 smoke. Skips never break serving — every gate refuses
rather than clobbers. Ian's machine snapshot is the outermost rollback; finer levels
are listed at the bottom.

## Step A — bring live current the old way (the last cp there will ever be)

```bash
cd /home/ubuntu/loothplatformv2-clean
git status --porcelain          # MUST print nothing. If it prints anything: STOP.
git pull --ff-only              # df4a96c -> 2ad2b6a (composer v2 + the whole backlog)
LGWP=$(sed -n 's/^LG_WP_PATH=//p' /etc/looth/env | tr -d '"'); echo "$LGWP"
                                # sanity: must print your docroot. If empty: STOP.
for f in hub-polish.js mobile-hub.css pwa.js; do sudo cp "webroot/$f" "$LGWP/$f"; done
sudo systemctl reload php8.3-fpm   # old-way ritual; makes the 20-commit PHP jump deterministic
```

### Step A smokes (all green before B)

```bash
for p in / /hub/ /directory/members/; do curl -s -o /dev/null -w "%{http_code} $p\n" "https://loothgroup.com$p"; done
md5sum "$LGWP/hub-polish.js" webroot/hub-polish.js      # the two md5s must be EQUAL
curl -s https://loothgroup.com/pwa.js | head -c 40      # expect: /* Looth PWA bootstrap
                                                        # (static tonight; LG_V appears after B)
```

**Phone check (the real smoke):** open the Hub, confirm the composer v2 renders
(new composer, link button), post or preview a reply. Only proceed when satisfied.

## Step B — the mechanism (deploy = pull, forever after)

```bash
sudo bash /home/ubuntu/loothplatformv2-clean/platform/bin/live-one-pull.sh audit
```
Read-only. Expect: vhost resolved by server_name with "TRACKED … L-1 does NOT
apply"; webroot `same=24 diff=0 absent=0` (A made the 3 DIFFs equal); lg-shared
css/js SYMLINK + site-header.php COPY-SAME; 86.php COPY-SAME; opcache
validate_timestamps=On. Anything else: stop, paste REPORT.txt to keeper (its
directory is printed on the first line).

```bash
sudo bash /home/ubuntu/loothplatformv2-clean/platform/bin/live-one-pull.sh apply
```
What it does, in order, all md5-gated: converts the 24 webroot files + icons/lib/
push/sponsors-deck + img.php to symlinks; verifies the vhost already serves tracked
config; links the FPM live posture (platform/fpm/live @748764b); converts
/srv/lg-shared PER FILE — **your two `.bak-20260726b` files are excluded by name and
reported as PRESERVED, untouched in place**; converts lg-snippets whole-dir (the
86.php copy dies); mu-plugins are REPORT-ONLY per keeper's ruling; installs
`/usr/local/bin/lg-deploy`; then **full restarts of nginx AND php8.3-fpm** (brief
blip) and smokes.

**`ROLLBACK.sh` + `REPORT.txt` land in `/home/ubuntu/deploy-backups/live-apply-<timestamp>/`**
(exact path printed on the script's first output line). Send REPORT.txt to keeper.

### Step B verification

```bash
for p in / /hub/ /directory/members/; do curl -s -o /dev/null -w "%{http_code} $p\n" "https://loothgroup.com$p"; done
curl -s https://loothgroup.com/pwa.js | head -c 60      # NOW expect: window.LG_V={...
curl -s -I https://loothgroup.com/hub-polish.js | grep -i cache-control   # expect 1y immutable
ls -la "$LGWP/hub-polish.js" "$LGWP/pwa.js"             # both must be -> into the checkout
ls -la /srv/lg-shared/*.bak-20260726b                    # BOTH still there — your backups
```
Phone again: Hub loads, composer composes, nothing visually off.

## Deploys from tomorrow on

```bash
lg-deploy        # = git pull --ff-only in the checkout, guarded on clean main.
```
Nothing else. Statics update instantly through the symlinks, PHP within 2s
(opcache mtime revalidation — keeper-verified On/2s on live), `?v=` versions are
filemtimes read at request time. lg-deploy runs nginx -t + reload / fpm reload BY
ITSELF only when a pull changes `platform/nginx/` or `platform/fpm/`.

## Rollback levels (finest first)

1. **Any single conversion**: `sudo bash /home/ubuntu/deploy-backups/live-apply-<ts>/ROLLBACK.sh`
   (or cherry-pick single `mv` lines from it), then
   `sudo systemctl restart nginx php8.3-fpm`.
2. **Step A code only**: `git -C /home/ubuntu/loothplatformv2-clean show df4a96c:webroot/<f> | sudo tee "$LGWP/<f>" >/dev/null`
   for any of the 3 files (their pre-cutover bytes are the df4a96c blobs — keeper
   verified 24/24 byte-identical).
3. **Machine level**: Ian's snapshot.

## Deferred by ruling (keeper queues; not tonight)

- Tracking + converting live's 4 real mu-plugins (2 ours, 2 third-party) — reported
  by the apply run.
- lg-login-redirect-honor.php mu-plugin symlink on live (new file rides the pull,
  but its mu-plugins symlink was never created there — F1 lane's deploy note).
- lib/quill on dev2 (recycle pending keeper's call).
