/**
 * manage-following.js — the client half of "Discussions you're following".
 *
 * Ian, 2026-07-30. Lane: account-following. Surface: /manage-subscription/.
 *
 * ── WHAT THIS FILE IS AND IS NOT ─────────────────────────────────────────────
 * The LIST is rendered server-side; this file never builds a row. It does three
 * things and nothing else:
 *
 *   1. CORRECTS the 🔔/✉ marks against bb-mirror's follow endpoint, and prints
 *      the account-level email truth the server render cannot know.
 *   2. Unfollows one discussion.
 *   3. Unfollows all of them, behind a confirm.
 *
 * ── EVERY WRITE GOES THROUGH follow.php ──────────────────────────────────────
 * POST /bb-mirror-api/v0/follow  { topic_id, channel:'notify'|'email', on:false }
 * That endpoint is the ONLY writer of forums.topic_follow and the only caller of
 * the bbPress subscription writers (its header calls that the structural
 * enforcement of "nothing auto-subscribes"). This page does not open a second
 * write path, and it never sends a user id — the endpoint is self-scoped by
 * cookie, so there is no shape of request from here that touches another member.
 *
 * ── WHY IT ASKS THE ENDPOINT WHAT IT ALREADY RENDERED ────────────────────────
 * The server read the two stores directly. It could not read one thing: whether
 * discussion email is enabled for this member AT THE ACCOUNT LEVEL. BuddyBoss
 * gates every send on that flag, so a lit envelope on a member whose account
 * switch is off would be this page telling a member they get mail that will
 * never be sent — §8.1.3(a), and the reason Ian's 2026-07-28 ruling exists:
 * "the UI must tell the truth about what is actually going to happen to that
 * member." follow.php's GET returns `email_master`, so we ask it, and while we
 * are there we take its per-topic state as the more recent truth.
 *
 * ── NO BULK ENDPOINT EXISTS ──────────────────────────────────────────────────
 * "Stop all" is therefore N sequential POSTs, run in series so a member with a
 * long list cannot hammer the WP pool. Sequential also means a partial failure
 * is reportable ("stopped 9 of 12") instead of a silent half-success.
 */
(function () {
    'use strict';

    var sec = document.getElementById('lg-following');
    if (!sec) return;

    var API   = '/bb-mirror-api/v0/follow';
    var nonce = '';

    function rows() { return Array.prototype.slice.call(sec.querySelectorAll('.lg-manage-sub__fol-row')); }
    function idsOf(list) { return list.map(function (r) { return parseInt(r.getAttribute('data-topic'), 10); })
                                      .filter(function (n) { return n > 0; }); }

    /** Every followed id, including ones past the render cap — "Stop all" means all. */
    function allIds() {
        return (sec.getAttribute('data-all-ids') || '')
            .split(',').map(function (s) { return parseInt(s, 10); })
            .filter(function (n) { return n > 0; });
    }

    function markEl(row, which) {
        return row.querySelector('.lg-manage-sub__fol-mark[data-mark="' + which + '"]');
    }

    function paintMark(row, which, on) {
        var el = markEl(row, which);
        if (!el) return;
        el.classList.toggle('is-on', !!on);
        var label = which === 'notify'
            ? (on ? 'Notifications on' : 'Notifications off')
            : (on ? 'Emails on' : 'Emails off');
        el.setAttribute('title', label);
        el.setAttribute('aria-label', label);
    }

    function isOn(row, which) {
        var el = markEl(row, which);
        return !!(el && el.classList.contains('is-on'));
    }

    function refreshCounts() {
        var live = rows().filter(function (r) { return !r.classList.contains('is-going'); });
        var emailing = live.filter(function (r) { return isOn(r, 'email'); }).length;

        var countEl = sec.querySelector('.lg-manage-sub__fol-count');
        if (countEl) {
            var b = countEl.querySelector('b');
            if (b) b.textContent = live.length + ' discussion' + (live.length === 1 ? '' : 's');
        }
        var ec = document.getElementById('lg-fol-emailcount');
        if (ec) ec.textContent = emailing + ' of them email you';

        var stop = document.getElementById('lg-fol-stopall');
        if (stop) {
            // Below the render cap the DOM is the whole truth; at or above it the
            // server's total still is, so don't shrink a number we can't verify.
            var known = allIds().length;
            var total = (known > live.length && rows().length < known) ? known : live.length;
            stop.textContent = 'Stop all ' + total;
            stop.setAttribute('data-count', String(total));
            stop.disabled = total === 0;
        }

        if (live.length === 0) {
            var list = sec.querySelector('.lg-manage-sub__fol-list');
            if (list && !document.getElementById('lg-fol-nowempty')) {
                var p = document.createElement('p');
                p.className = 'lg-manage-sub__fol-empty';
                p.id = 'lg-fol-nowempty';
                p.textContent = "You're not following any discussions now.";
                list.parentNode.insertBefore(p, list.nextSibling);
            }
            var foot = sec.querySelector('.lg-manage-sub__fol-foot');
            if (foot) foot.hidden = true;
            var more = document.getElementById('lg-fol-more');
            if (more) more.hidden = true;
        }
    }

    /* ── 1. hydrate ──────────────────────────────────────────────────────────
     * Only the RENDERED ids: the endpoint caps a batch at 200 and there is no
     * reason to ask about rows nobody can see. */
    function hydrate() {
        var visible = idsOf(rows());
        if (!visible.length) return Promise.resolve();

        return fetch(API + '?topics=' + visible.join(','), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.authenticated) return;
                nonce = j.nonce || '';

                rows().forEach(function (row) {
                    var st = j.state && j.state[row.getAttribute('data-topic')];
                    if (!st) return;
                    paintMark(row, 'notify', st.notify);
                    paintMark(row, 'email',  st.email);
                });

                var master = document.getElementById('lg-fol-master');
                if (master) {
                    master.textContent = j.email_master
                        ? 'Emails are on for your account.'
                        : "Discussion emails are off for your account, so none of these will email you.";
                }
                refreshCounts();
            })
            .catch(function () { /* marks keep the server's read; nothing claimed falsely */ });
    }

    /** One channel off for one topic. Resolves true on success. */
    function turnOff(topicId, channel) {
        return fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify({ topic_id: topicId, channel: channel, on: false })
        })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (j) { return !!(j && j.ok); })
            .catch(function () { return false; });
    }

    /**
     * Unfollow = both bits off. A row can hold either, both or neither, so send
     * only what is actually on — and always re-read the row afterwards rather
     * than assuming, so a half-failure shows as a half-failure.
     */
    function unfollow(row) {
        var id = parseInt(row.getAttribute('data-topic'), 10);
        if (!(id > 0)) return Promise.resolve(false);

        var jobs = [];
        if (isOn(row, 'notify')) jobs.push('notify');
        if (isOn(row, 'email'))  jobs.push('email');
        // A row with neither bit lit is already unfollowed as far as the stores
        // are concerned; still ask for both so a stale render self-heals.
        if (!jobs.length) jobs = ['notify', 'email'];

        row.classList.add('is-busy');
        return jobs.reduce(function (chain, ch) {
            return chain.then(function (okSoFar) {
                return turnOff(id, ch).then(function (ok) {
                    if (ok) paintMark(row, ch, false);
                    return okSoFar && ok;
                });
            });
        }, Promise.resolve(true)).then(function (ok) {
            row.classList.remove('is-busy');
            if (ok) {
                row.classList.add('is-going');
                row.parentNode.removeChild(row);
                var all = allIds().filter(function (n) { return n !== id; });
                sec.setAttribute('data-all-ids', all.join(','));
            }
            refreshCounts();
            return ok;
        });
    }

    /* ── 2. per-row unfollow ─────────────────────────────────────────────── */
    sec.addEventListener('click', function (ev) {
        var btn = ev.target.closest ? ev.target.closest('[data-unfollow]') : null;
        if (!btn || !sec.contains(btn)) return;
        ev.preventDefault();
        var row = btn.closest('.lg-manage-sub__fol-row');
        if (!row) return;
        unfollow(row).then(function (ok) {
            if (!ok) alert('Could not unfollow that one — try again.');
        });
    });

    /* ── 3. show all ─────────────────────────────────────────────────────── */
    var more = document.getElementById('lg-fol-more');
    if (more) {
        more.addEventListener('click', function () {
            var open = sec.classList.toggle('is-expanded');
            more.setAttribute('aria-expanded', open ? 'true' : 'false');
            more.textContent = open
                ? 'Show fewer ↑'
                : 'Show all ' + rows().length + ' →';
        });
    }

    /* ── 4. stop all ─────────────────────────────────────────────────────────
     * The control the earlier mock was rated bad for lacking. It confirms first,
     * it names the count, and it says out loud that the Weekly Digest survives —
     * that is the thing a member is actually afraid of when they press red. */
    var stop = document.getElementById('lg-fol-stopall');
    if (stop) {
        stop.addEventListener('click', function () {
            var ids   = allIds();
            var count = ids.length;
            if (!count) return;

            var dlg = document.createElement('dialog');
            dlg.className = 'lg-manage-sub__fol-dlg';
            dlg.innerHTML =
                '<h3>Stop all ' + count + '?</h3>' +
                '<p>This turns off notifications <b>and</b> email for all ' + count +
                ' discussion' + (count === 1 ? '' : 's') + ' you follow.</p>' +
                '<p>Your Weekly Digest and Event Reminders are not affected. You can follow any ' +
                'discussion again from the discussion itself.</p>' +
                '<div class="lg-manage-sub__fol-dlg-btns">' +
                '<button type="button" class="lg-manage-sub__fol-stopall is-cancel" value="no">Cancel</button>' +
                '<button type="button" class="lg-manage-sub__fol-stopall is-go" value="yes">Stop all ' + count + '</button>' +
                '</div>';
            document.body.appendChild(dlg);

            function close() { try { dlg.close(); } catch (e) {} dlg.remove(); }
            dlg.querySelector('.is-cancel').addEventListener('click', close);
            dlg.querySelector('.is-go').addEventListener('click', function () {
                close();
                run(ids);
            });
            if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
        });
    }

    /**
     * Sequential on purpose: N POSTs to the WP pool fired at once is a
     * self-inflicted spike, and in series a failure is countable.
     */
    function run(ids) {
        stop.disabled = true;
        stop.textContent = 'Stopping…';

        var done = 0, failed = 0;
        ids.reduce(function (chain, id) {
            return chain.then(function () {
                var row = sec.querySelector('.lg-manage-sub__fol-row[data-topic="' + id + '"]');
                if (row) {
                    return unfollow(row).then(function (ok) { ok ? done++ : failed++; });
                }
                // Past the render cap there is no row — write both bits blind.
                return turnOff(id, 'notify')
                    .then(function (a) { return turnOff(id, 'email').then(function (b) { return a && b; }); })
                    .then(function (ok) { ok ? done++ : failed++; });
            });
        }, Promise.resolve()).then(function () {
            stop.disabled = false;
            refreshCounts();
            if (failed) {
                alert('Stopped ' + done + ' of ' + ids.length + '. ' + failed +
                      ' could not be changed — reload and try those again.');
            }
        });
    }

    hydrate();
}());
