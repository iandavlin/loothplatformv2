#!/usr/bin/env bash
# dev2 folder-permissions / ACL / secret-reader audit — run ON dev2 as ubuntu (sudo).
# Read-only. Derived from dev's working pool→path and reader→secret relationships.
PASS(){ printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
FAIL(){ printf '  \033[31mFAIL\033[0m  %s\n' "$1"; }
hdr(){ printf '\n== %s ==\n' "$1"; }
# can <user> reach+read <dir>? (traverses every parent — catches o+x / ownership / ACL gaps)
canread(){ sudo -u "$1" ls "$2" >/dev/null 2>&1 && PASS "$1 reads $2" || FAIL "$1 CANNOT reach/read $2"; }
cansec(){  if ! sudo test -e "$2"; then FAIL "$1 secret $2 MISSING"; \
           elif sudo -u "$1" cat "$2" >/dev/null 2>&1; then PASS "$1 reads secret $2"; \
           else FAIL "$1 CANNOT read secret $2 (needs setfacl -m u:$1:r)"; fi; }

echo "################ dev2 permissions / ACL audit ################"

hdr "0. Home traversal — gotcha #1 (every app lives under /home/ubuntu via /srv)"
sudo -u archive-poc test -x /home/ubuntu \
  && PASS "/home/ubuntu is o+x (pools can traverse in)" \
  || FAIL "/home/ubuntu NOT o+x -> ALL apps 403  (fix: chmod o+x /home/ubuntu)"

hdr "1. Each FPM pool user can reach its served app dir"
canread archive-poc /srv/archive-poc/web
canread bb-mirror   /srv/bb-mirror/web
canread events      /srv/events/web
canread membership  /home/ubuntu/projects/membership-pages/web
canread profile-app /srv/profile-app
canread looth-dev   /srv/bb-mirror/api/v0
sudo -u looth-dev test -r /var/www/dev/wp-load.php && PASS "looth-dev reads wp-load.php" || FAIL "looth-dev CANNOT read wp-load.php"

hdr "2. Shared header /srv/lg-shared — read by EVERY standalone pool"
for u in archive-poc bb-mirror events profile-app membership; do canread "$u" /srv/lg-shared; done

hdr "3. Secret-reader ACLs — gotcha #3 (ONLY the real reader is granted)"
cansec profile-app /etc/lg-internal-secret
cansec profile-app /etc/looth/jwt-private.pem
cansec membership  /etc/lg-membership-db
cansec events      /etc/lg-events-db

hdr "4. nginx (www-data) — wp-content traversal + uploads (image-serving chain)"
sudo -u www-data ls /var/www/dev/wp-content/uploads >/dev/null 2>&1 \
  && PASS "www-data traverses wp-content/uploads" \
  || FAIL "www-data CANNOT (add www-data to looth-dev group; uploads must be a symlink)"
id www-data | grep -q 'looth-dev' && PASS "www-data is in the looth-dev group" || FAIL "www-data NOT in looth-dev group (usermod -aG looth-dev www-data + restart nginx)"

echo; echo "################ done — every FAIL is a perms/ACL fix for the cut runbook ################"
