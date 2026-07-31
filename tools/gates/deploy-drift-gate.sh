#!/usr/bin/env bash
# GATE — DEPLOY DRIFT. Divergence turns a gate RED instead of waiting to be found.
#
#   bash tools/gates/deploy-drift-gate.sh              # dev2 + live (live read-only)
#   bash tools/gates/deploy-drift-gate.sh --box dev2   # one box
#   bash tools/gates/deploy-drift-gate.sh --probe      # dump raw box facts, assert nothing
#
# exit 0 = green   exit 1 = RED (real findings)   exit 2 = CANNOT RUN (no verdict)
#
# ─── WHY THIS EXISTS ────────────────────────────────────────────────────────────
# Ian, 2026-07-31: "we have a mandate to only pull except for extreme conditions.
# What was extreme about this?" — "why wasnt that built into the repo and pulled?
# So now we have untracked changes?"  Nothing was extreme. That night live picked
# up three pieces of state git did not know about, and dev2 turned out to be
# carrying a fourth that nobody had recorded and nobody could date.
#
# The deploy mandate is ONE PULL. Every check here answers one question: is this
# box still reachable by a pull, or has something been done to it by hand?
#
# ─── THE ALLOWLIST IS THE REGISTER, AND THAT IS THE POINT ───────────────────────
# Some divergence is legitimate and permanent (a dev-only mu-plugin; a flag on for
# one box). The gate does not try to guess which. It reads
# docs/runbooks/live-divergences.md and treats its DECLARE block as the allowlist:
#
#     DECLARED  → reported, green. Somebody wrote down why.
#     UNDECLARED→ RED.
#
# So the only way to make this gate green is to either fix the divergence or write
# it down. That is exactly the property that was missing: dev2's hand-edited
# strangler-membership.conf was invisible for an unknown number of weeks because
# nothing anywhere asserted it should be a symlink.
set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO_DOC="$HERE/../../docs/runbooks/live-divergences.md"
ROLE_SRC="$HERE/../../cutover/ensure-pg-roles.sh"
SERVE=/home/ubuntu/loothplatformv2-clean

BOXES="dev2 live"
PROBE_ONLY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --box)   BOXES="$2"; shift 2 ;;
    --probe) PROBE_ONLY=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

# ─── THE PROBE ──────────────────────────────────────────────────────────────────
# Emits normalised records so dev2 and live are judged by identical code. Runs
# locally on dev2 and over `ssh live-ro` on live — the SAME text either way, so the
# two boxes can never drift because the gate looked at them differently.
#
# ⚠️ PERMISSIONS ARE BACKWARDS BETWEEN THE BOXES and a naive read fails silently:
#   dev2 — /var/www/dev/wp-content/ is unreadable as `ubuntu`; needs sudo. Reading
#          it without gives an empty listing, which reads as "0 mu-plugins" — a
#          documented false negative.
#   live — `looth-ro` reads it directly, and `sudo` PROMPTS FOR A PASSWORD, so a
#          `sudo -n` there returns nothing and also reads as "0 mu-plugins".
# `_ls` therefore tries plain first and falls back to sudo -n, and the caller
# asserts a non-empty result before believing any absence.
read -r -d '' PROBE <<'PROBE_EOF'
SERVE=/home/ubuntu/loothplatformv2-clean
WP_MU=/var/www/dev/wp-content/mu-plugins
_ls(){ ls -1 "$1" 2>/dev/null || sudo -n ls -1 "$1" 2>/dev/null; }
_rl(){ readlink "$1" 2>/dev/null || sudo -n readlink "$1" 2>/dev/null; }
_isl(){ [ -L "$1" ] 2>/dev/null || sudo -n test -L "$1" 2>/dev/null; }
_ex(){ [ -e "$1" ] 2>/dev/null || sudo -n test -e "$1" 2>/dev/null; }
_cat(){ cat "$1" 2>/dev/null || sudo -n cat "$1" 2>/dev/null; }

echo "HOST $(hostname -f 2>/dev/null || hostname)"
echo "SERVEHEAD $(git -c safe.directory='*' -C $SERVE rev-parse --short HEAD 2>/dev/null || echo UNKNOWN)"

emit_dir(){ # kind dir
  local kind="$1" d="$2" f t
  for f in $(_ls "$d"); do
    case "$f" in *.bak*|*.pre-symlink-*|*.dev-preflip|*.live-posture|*.bak-*) continue;; esac
    if _isl "$d/$f"; then
      t="$(_rl "$d/$f")"
      if _ex "$d/$f"; then echo "$kind $f symlink $t"; else echo "$kind $f DANGLING $t"; fi
    elif _ex "$d/$f"; then
      echo "$kind $f realfile -"
    fi
  done
}
emit_dir SNIP /etc/nginx/snippets
emit_dir POOL /etc/php/8.3/fpm/pool.d
emit_dir MU   "$WP_MU"
emit_dir SRV  /srv

# ── mu-plugin LOADERS and their __DIR__-relative code ──────────────────────────
# CLAUDE.md trap #7, and it took the whole site down on 2026-07-31.
#
# WordPress only auto-loads .php in the mu-plugins ROOT, so folder-structured
# plugins ship a thin loader that does `require __DIR__ . '/<folder>/main.php'`.
# PHP RESOLVES SYMLINKS BEFORE COMPUTING __DIR__. So the moment the loader itself
# becomes a symlink into the repo, __DIR__ stops being the docroot and becomes the
# repo — and the loader starts resolving its code from a different tree than the one
# it was deployed into. When that tree lacks the folder (or a box-local vendor/),
# `is_readable()` fails, the loader `return`s WITHOUT fatalling, and the plugin is
# simply never registered. Silent.
#
# On 2026-07-31 that unregistered the poller's REST route, so whoami could not read
# tiers, so every member and admin computed as `public`, and the site paywalled
# itself. Nothing fatalled and nothing 500'd.
#
# Emits, per loader: the sibling it requires, whether that sibling RESOLVES from
# where PHP will really compute __DIR__, and whether __DIR__ lands in the docroot.
MUDIR="$WP_MU"
for f in $(_ls "$MUDIR"); do
  case "$f" in *.php) ;; *) continue ;; esac
  sibs="$(_cat "$MUDIR/$f" | grep -oE "__DIR__ *\. *'/[A-Za-z0-9._-]+'" \
          | sed -E "s/.*'\/([^']+)'.*/\1/" | sort -u)"
  [ -z "$sibs" ] && continue
  real="$(readlink -f "$MUDIR/$f" 2>/dev/null || sudo -n readlink -f "$MUDIR/$f" 2>/dev/null)"
  [ -z "$real" ] && continue
  dir="$(dirname "$real")"
  case "$dir" in "$MUDIR") loc=docroot ;; *) loc=outside ;; esac
  # A loader that resolves its folder from WPMU_PLUGIN_DIR (WordPress's own
  # mu-plugins path) is SYMLINK-SAFE: that constant names the deployed tree whether
  # or not the loader itself is a symlink, so __DIR__ landing outside is harmless.
  #
  # ⚠️ COMMENTS ARE STRIPPED FIRST, AND THAT IS NOT FUSSINESS. The stock loader
  # carries the comment "plugins_url() detects WPMU_PLUGIN_DIR and returns the
  # mu-plugins URL" — so a plain grep matched PROSE and reported the pre-fix loader
  # as symlink-safe. That is a FALSE GREEN on the exact check guarding the outage,
  # which is worse than having no check. Measured on dev2, 2026-07-31.
  # PHP's own tokenizer decides what is code; nothing else is trustworthy here.
  if _cat "$MUDIR/$f" | php -r '''$t=token_get_all(stream_get_contents(STDIN));
      foreach($t as $x){ if(is_array($x)&&in_array($x[0],[T_COMMENT,T_DOC_COMMENT])) continue;
      echo is_array($x)?$x[1]:$x; }''' 2>/dev/null | grep -q "WPMU_PLUGIN_DIR"; then
    safe=safe
  else
    safe=unsafe
  fi
  for s in $sibs; do
    if _ex "$dir/$s"; then st=resolves; else st=MISSING; fi
    echo "LOADER $f $s $st $loc $safe"
  done
done

# Repo-side inventory, so "has a repo counterpart" is decided on the box itself.
for f in $(_ls "$SERVE/platform/nginx"); do echo "REPOSNIP $f"; done
for f in $(_ls "$SERVE/platform/mu-plugins"); do
  case "$f" in *.php) echo "REPOMU $f";; esac
done

# Flag state actually in force on this box: env[] in pool files, fastcgi_param in
# nginx snippets. Reads the FILES AS THE BOX HAS THEM, which is the only thing that
# tells you what the workers are really running.
#
# Each flag is tagged tracked/untracked by whether its CONTAINING FILE is a symlink
# into the serving checkout. That tag is the whole question:
#   tracked   → the flag arrived by `git pull`. Not divergence, however many there
#               are. LG_MS_SLUG, LG_POST_TYPE and friends are routing plumbing that
#               lives in tracked confs and would otherwise bury the real findings.
#   untracked → the flag was set by hand, on this box, in a file the repo does not
#               control. THAT is the divergence class, and it is what must be
#               declared. Both of 2026-07-31's flag divergences are untracked.
_tracked(){ # path -> "tracked" | "untracked"
  local t; t="$(_rl "$1")"
  case "$t" in "$SERVE"/*) echo tracked;; *) echo untracked;; esac
}
for f in $(_ls /etc/php/8.3/fpm/pool.d); do
  case "$f" in *.bak*|*.pre-symlink-*|*.dev-preflip|*.live-posture) continue;; esac
  tag="$(_tracked "/etc/php/8.3/fpm/pool.d/$f")"
  _cat "/etc/php/8.3/fpm/pool.d/$f" | sed -n 's/^[[:space:]]*env\[\(LG_[A-Z0-9_]*\)\][[:space:]]*=[[:space:]]*"\?\([^"]*\)"\?[[:space:]]*$/FLAG '"$tag"' pool:'"$f"' \1 \2/p'
done
for f in $(_ls /etc/nginx/snippets); do
  case "$f" in *.bak*|*.pre-symlink-*) continue;; esac
  tag="$(_tracked "/etc/nginx/snippets/$f")"
  _cat "/etc/nginx/snippets/$f" | sed -n 's/^[[:space:]]*fastcgi_param[[:space:]]\+\(LG_[A-Z0-9_]*\)[[:space:]]\+\([^;]*\);.*$/FLAG '"$tag"' nginx:'"$f"' \1 \2/p'
done

# Postgres roles present on this box.
#
# ⚠️ THE TWO BOXES NEED DIFFERENT CREDENTIALS AND NEITHER WORKS ON THE OTHER:
#   dev2 — `sudo -u postgres` (ubuntu has no PG role of its own)
#   live — no sudo (it prompts), but `looth_ro` peer-auths over TCP and pg_roles is
#          readable by any role: it is a view over pg_authid with the hashes removed.
# Getting this wrong returns zero roles, and zero roles would mean "every role is
# missing" — so ROLESRC is emitted on success and the caller treats its ABSENCE as
# CANNOT RUN rather than as a box with no roles.
ROLEQ="select rolname from pg_roles where rolname not like 'pg_%';"
roles=""
if [ -z "$roles" ]; then roles="$(sudo -n -u postgres psql -tAc "$ROLEQ" 2>/dev/null)"; fi
if [ -z "$roles" ]; then roles="$(psql -h 127.0.0.1 -U looth_ro -d profile_app -tAc "$ROLEQ" 2>/dev/null)"; fi
if [ -z "$roles" ]; then roles="$(psql -h /var/run/postgresql -d postgres -tAc "$ROLEQ" 2>/dev/null)"; fi
if [ -n "$roles" ]; then
  echo "ROLESRC ok"
  for r in $roles; do echo "ROLE $r"; done
fi
PROBE_EOF

probe_box() {
  case "$1" in
    dev2) bash -c "$PROBE" 2>/dev/null ;;
    live) timeout 120 ssh live-ro "bash -s" <<< "$PROBE" 2>/dev/null ;;
  esac
}

# ─── THE ALLOWLIST ──────────────────────────────────────────────────────────────
# Parsed from the DECLARE block in docs/runbooks/live-divergences.md. One record
# per line:   <box> <kind> <identifier>   (everything after # is prose)
DECLARED=""
if [ -r "$REPO_DOC" ]; then
  DECLARED="$(sed -n '/^<!-- DECLARE-BEGIN -->/,/^<!-- DECLARE-END -->/p' "$REPO_DOC" \
              | grep -vE '^<!--|^```|^\s*$|^\s*#' || true)"
fi
declared() { # box kind ident
  printf '%s\n' "$DECLARED" | awk -v b="$1" -v k="$2" -v i="$3" \
    '$1==b && $2==k && $3==i {found=1} END{exit !found}'
}

# Required PG roles — read from the ONE manifest, cutover/ensure-pg-roles.sh, so
# this gate and the migration artifact can never disagree about the role set.
REQ_ROLES="$(sed -n '/^ROLES="/,/^"/p' "$ROLE_SRC" 2>/dev/null \
             | grep -oE '^[a-z_-]+\|' | tr -d '|' || true)"

red=0; dead=0

for box in $BOXES; do
  echo "── $box ──────────────────────────────────────────────────────────────"
  facts="$(probe_box "$box")"

  # ── LIVENESS. Every check below is an ABSENCE assertion, and an absence
  # assertion passes vacuously against a box you could not read.
  if [ -z "$facts" ] || ! printf '%s\n' "$facts" | grep -q '^HOST '; then
    echo "  CANNOT RUN — probe returned nothing for $box (ssh down, or unreadable)."
    dead=$((dead+1)); echo; continue
  fi
  nsnip=$(printf '%s\n' "$facts" | grep -c '^SNIP ' || true)
  npool=$(printf '%s\n' "$facts" | grep -c '^POOL ' || true)
  nmu=$(printf   '%s\n' "$facts" | grep -c '^MU '   || true)
  nrole=$(printf '%s\n' "$facts" | grep -c '^ROLE ' || true)
  if [ "$nsnip" -lt 5 ] || [ "$npool" -lt 3 ] || [ "$nmu" -lt 10 ]; then
    echo "  CANNOT RUN — probe read implausibly little (snippets=$nsnip pools=$npool"
    echo "               mu=$nmu). That is the documented permission false-negative,"
    echo "               not a clean box. Refusing to report green."
    dead=$((dead+1)); echo; continue
  fi
  # Roles are read with different credentials per box; absence of ROLESRC means the
  # read FAILED, which must not be mistaken for "this box has no roles".
  if ! printf '%s\n' "$facts" | grep -q '^ROLESRC ok'; then
    echo "  CANNOT RUN — could not enumerate Postgres roles on $box. Zero roles would"
    echo "               read as 'every role missing'; that is a credential failure,"
    echo "               not a finding."
    dead=$((dead+1)); echo; continue
  fi
  echo "  $(printf '%s\n' "$facts" | sed -n 's/^HOST //p')  serving-checkout @ $(printf '%s\n' "$facts" | sed -n 's/^SERVEHEAD //p')"
  echo "  liveness: snippets=$nsnip pools=$npool mu-plugins=$nmu pg-roles=$nrole"

  if [ "$PROBE_ONLY" = 1 ]; then printf '%s\n' "$facts" | sed 's/^/    /'; echo; continue; fi

  # ── CHECK 1 — every symlink resolves INTO THE SERVING CHECKOUT ──────────────
  # 2026-07-31: an nginx snippet was found pointing at ~/worktrees/shop-planner.
  # It WORKED, and would have broken silently the moment that lane rebased. A lane
  # worktree is not a deploy artifact; the serving checkout is the only tree a pull
  # updates.
  echo "  [1] symlink targets resolve into the serving checkout"
  while read -r kind name type target; do
    [ -z "${kind:-}" ] && continue
    case "$type" in
      symlink)
        case "$target" in
          "$SERVE"/*) ;;
          /home/ubuntu/worktrees/*|/home/ubuntu/projects/*)
            echo "      ❌ $kind $name -> $target"
            echo "         points OUTSIDE the serving checkout (lane worktree / projects)"
            red=1 ;;
          *)
            if declared "$box" "extlink" "$name"; then
              echo "      DECLARED  $kind $name -> $target"
            else
              echo "      ❌ $kind $name -> $target  (not the serving checkout, undeclared)"
              red=1
            fi ;;
        esac ;;
      DANGLING)
        echo "      ❌ $kind $name -> $target  DANGLING — target does not exist"
        echo "         a pull that removed the source leaves exactly this"
        red=1 ;;
    esac
  done <<< "$(printf '%s\n' "$facts" | grep -E '^(SNIP|POOL|MU|SRV) ')"

  # ── CHECK 2 — a snippet with a repo counterpart IS a symlink to it ─────────
  # THIS IS THE ONE THAT BIT US. live's strangler-membership.conf was replaced
  # with a real file to set a fastcgi_param, after which it stopped receiving repo
  # updates and the only record was a comment inside the file itself.
  echo "  [2] nginx snippets with a repo counterpart are symlinks, not hand-edited copies"
  while read -r _ f; do
    [ -z "${f:-}" ] && continue
    line="$(printf '%s\n' "$facts" | awk -v f="$f" '$1=="SNIP" && $2==f {print; exit}')"
    [ -z "$line" ] && continue
    t="$(printf '%s\n' "$line" | awk '{print $3}')"
    if [ "$t" = "realfile" ]; then
      if declared "$box" "snippet-copy" "$f"; then
        echo "      DECLARED  $f is a real file, not a symlink"
      else
        echo "      ❌ $f is a REAL FILE but the repo has platform/nginx/$f"
        echo "         it will NOT receive repo updates; deploy is no longer one pull"
        red=1
      fi
    fi
  done <<< "$(printf '%s\n' "$facts" | grep '^REPOSNIP ')"

  # ── CHECK 3 — FPM pools use THIS BOX'S variant, never the cut-box defaults ──
  # symlink-farm repointed all seven live pools to platform/fpm/*.conf on
  # 2026-07-31 — the dev variants: pm.max_children 2 instead of 6-12, and no
  # LG_*_PUBLIC_HOST. It did not bite only because php-fpm was never reloaded.
  case "$box" in live) want=live ;; *) want=dev2 ;; esac
  echo "  [3] FPM pools point at platform/fpm/$want/ (never the top-level dev variants)"
  while read -r _ name type target; do
    [ -z "${name:-}" ] && continue
    [ "$type" = "symlink" ] || {
      if declared "$box" "pool-copy" "$name"; then
        echo "      DECLARED  $name is a real file, not a symlink"
      else
        echo "      ❌ pool $name is a REAL FILE — will not receive repo updates"
        red=1
      fi
      continue
    }
    case "$target" in
      "$SERVE/platform/fpm/$want/"*) ;;
      "$SERVE/platform/fpm/"*)
        echo "      ❌ pool $name -> $target"
        echo "         WRONG VARIANT for this box — expected platform/fpm/$want/."
        echo "         On live this cuts pm.max_children to 2 and drops LG_*_PUBLIC_HOST."
        red=1 ;;
      *) echo "      ❌ pool $name -> $target  (outside platform/fpm/)"; red=1 ;;
    esac
  done <<< "$(printf '%s\n' "$facts" | grep '^POOL ')"

  # ── CHECK 4 — every PG role the apps peer-auth as EXISTS ───────────────────
  echo "  [4] Postgres roles the apps connect as exist"
  if [ -z "$REQ_ROLES" ]; then
    echo "      CANNOT RUN — could not read the role manifest from $ROLE_SRC"
    dead=$((dead+1))
  else
    for r in $REQ_ROLES; do
      if printf '%s\n' "$facts" | grep -qx "ROLE $r"; then :; else
        echo "      ❌ role '$r' MISSING — every request from its pool will log"
        echo "         FATAL: role \"$r\" does not exist, and the page will break"
        red=1
      fi
    done
    echo "      checked ${REQ_ROLES//$'\n'/ }" | tr '\n' ' '; echo
  fi

  # ── CHECK 5 — every repo mu-plugin has its individual symlink ──────────────
  # The symlink SET is not in the repo, so a pull that adds a mu-plugin leaves it
  # dark until someone links it. Four needed linking by hand on 2026-07-31.
  echo "  [5] every repo mu-plugin is linked into the docroot"
  while read -r _ f; do
    [ -z "${f:-}" ] && continue
    if printf '%s\n' "$facts" | awk -v f="$f" '$1=="MU" && $2==f {found=1} END{exit !found}'; then :; else
      if declared "$box" "mu-absent" "$f"; then
        echo "      DECLARED  $f not linked on this box"
      else
        echo "      ❌ $f is in the repo but has NO symlink — the code is dark"
        red=1
      fi
    fi
  done <<< "$(printf '%s\n' "$facts" | grep '^REPOMU ')"

  # ── CHECK 6 — flag state matches the register ──────────────────────────────
  # A flag set on a box and written down nowhere is how "it works on dev2" becomes
  # a two-hour hunt.
  # Only UNTRACKED flags are assertable. A flag in a tracked file arrived by pull,
  # which is the goal state, not a finding — asserting on those buries the two real
  # divergences under ~25 lines of routing plumbing (measured, 2026-07-31).
  ntracked=$(printf '%s\n' "$facts" | grep -c '^FLAG tracked ' || true)
  echo "  [6] LG_* flags set by hand (untracked file) are declared in the register"
  echo "      ($ntracked further flag(s) come from tracked files — those arrived by pull)"
  while read -r _ _ src name val; do
    [ -z "${name:-}" ] && continue
    # Declared identifier is SOURCE-SCOPED (`nginx:foo.conf:LG_BAR`), not just the
    # flag name. Declaring a bare name would silence that flag in every file on the
    # box — so a legitimate lane-preview declaration would also hide a later hand
    # edit of a serving snippet, which is precisely the thing being hunted.
    if declared "$box" "flag" "$src:$name"; then
      echo "      DECLARED  $name=$val ($src)"
    else
      echo "      ❌ $name=$val set BY HAND in $src"
      echo "         undeclared, and it will not survive restoring that file to a"
      echo "         symlink — declare it, or move it into a tracked per-box file"
      red=1
    fi
  done <<< "$(printf '%s\n' "$facts" | grep '^FLAG untracked ')"

  # ── CHECK 7 — mu-plugin loaders resolve their __DIR__-relative code ────────
  # CLAUDE.md trap #7. PHP resolves symlinks BEFORE computing __DIR__, so a
  # symlinked loader resolves its code from the REPO rather than the docroot it was
  # deployed into. The loader then `return`s without fatalling, the plugin is never
  # registered, and nothing anywhere reports an error.
  # On 2026-07-31 this unregistered the poller's REST route -> whoami could not read
  # tiers -> every member and admin computed as `public` -> the site paywalled itself.
  echo "  [7] mu-plugin loaders resolve their __DIR__-relative code"
  nload=$(printf '%s\n' "$facts" | grep -c '^LOADER ' || true)
  if [ "$nload" -eq 0 ]; then
    echo "      ·  no __DIR__-relative loaders found"
  else
    while read -r _ f sib st loc safe; do
      [ -z "${f:-}" ] && continue
      if [ "$st" = MISSING ] && [ "${safe:-unsafe}" != safe ]; then
        echo "      ❌ $f requires ./$sib and IT DOES NOT RESOLVE from where PHP"
        echo "         computes __DIR__. The loader returns silently and the plugin is"
        echo "         NEVER REGISTERED — no fatal, no 500, no error page."
        red=1
      elif [ "$loc" = outside ] && [ "${safe:-unsafe}" = safe ]; then
        echo "      ✔ $f is symlinked, but resolves ./$sib via WPMU_PLUGIN_DIR"
        echo "         — symlink-safe, so __DIR__ landing in the repo is harmless"
      elif [ "$loc" = outside ]; then
        if declared "$box" "loader-outside" "$f"; then
          echo "      DECLARED  $f resolves ./$sib from outside the docroot"
        else
          echo "      ❌ $f is symlinked, so __DIR__ is the REPO, not the docroot."
          echo "         ./$sib resolves today — from the repo tree — but the plugin is"
          echo "         now served from a different tree than it was deployed into, and"
          echo "         is one box-local file (vendor/, .env) away from loading nothing."
          red=1
        fi
      else
        echo "      ✔ $f -> ./$sib resolves, __DIR__ stays in the docroot"
      fi
    done <<< "$(printf '%s\n' "$facts" | grep '^LOADER ')"
  fi
  echo
done

echo "──────────────────────────────────────────────────────────────────────"
if [ "$red" -ne 0 ]; then
  echo "############ DEPLOY DRIFT GATE: RED ############"
  echo "Either fix the divergence, or declare it in docs/runbooks/live-divergences.md."
  echo "Undeclared box state is the thing this gate exists to stop."
  exit 1
fi
if [ "$dead" -ne 0 ]; then
  echo "############ DEPLOY DRIFT GATE: CANNOT RUN ############"
  exit 2
fi
echo "############ DEPLOY DRIFT GATE: GREEN ############"
