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

    /* ── EMAIL FREQUENCY — ACCOUNT-LEVEL, AND IT WRITES ──────────────────────
     * The sent digest's footer links here promising "Change how often"
     * (lg-follow-digest.php:1039). Until this wiring existed that link landed a
     * member on a page with no such setting — the UI-lies class, in an email that
     * had already been sent. This is the half that makes the link true.
     *
     * ── ONE STORE, ONE WRITE PATH ────────────────────────────────────────────
     * The store is WP usermeta lg_disc_email_cadence, absent ⇒ instant. This page
     * does NOT touch it, and does NOT go through follow.php's POST {cadence}
     * either. Both writes here go through follow-digest's admin-ajax transports,
     * which are the only callers of lg_fd_set_cadence():
     *
     *   GET-ish  action=lg_fd_cadence_state → {ok, cadence, options, nonce}
     *   write    action=lg_fd_cadence_set   → {ok, cadence}   (echoes the STORED value)
     *
     * WHY NOT follow.php, WHICH ALSO ACCEPTS A CADENCE: because it writes the
     * usermeta key directly (follow.php:212) and so skips the flood guard inside
     * lg_fd_set_cadence — the watermark stamp. A member written that way has their
     * instant mail suppressed (lg-follow-digest.php:494) while the collector
     * refuses to build them a batch for want of a watermark (:512-521), and
     * receives NOTHING. Reported to the board 2026-08-01; not this lane's file to
     * fix. Routing around it is not a workaround, it is the correct path — the
     * transports below are the ones follow-digest built for this surface (:396).
     *
     * ── THE SERVER DECIDES WHETHER THIS EXISTS ───────────────────────────────
     * The markup ships `hidden`. lg_fd_cadence_state is per-member — it 404s
     * anyone the sender would not actually serve — so it, not the page's flag, is
     * what reveals the control. Anything other than a clean ok:true REMOVES the
     * block outright. A member who cannot be served must not see the setting at
     * all, because seeing it and setting it is what causes the silence.
     */
    var FREQ_LABELS = { instant: 'Instant', daily: 'Daily', weekly: 'Weekly' };

    /** admin-ajax, form-encoded — the idiom the rest of this page already uses. */
    function ajax(action, extra) {
        var d = new URLSearchParams();
        d.set('action', action);
        for (var k in extra) { if (extra.hasOwnProperty(k)) d.set(k, extra[k]); }
        return fetch('/wp-admin/admin-ajax.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: d.toString()
        }).then(function (r) { return r.json().catch(function () { return null; }); });
    }

    function initCadence() {
        var box = document.getElementById('lg-fol-freq');
        if (!box) return;                       // page flag off — nothing rendered at all

        var seg   = box.querySelector('.lg-manage-sub__fol-freq-seg');
        var note  = document.getElementById('lg-fol-freq-note');
        var tok   = '';
        var value = '';

        /** Fail-closed: the control leaves the DOM rather than sitting there inert. */
        function drop() {
            if (box.parentNode) box.parentNode.removeChild(box);
        }

        function opts() {
            return Array.prototype.slice.call(seg.querySelectorAll('[data-cadence]'));
        }

        /* Repaint from a value the SERVER returned, never from the click. Roving
         * tabindex so the group is one tab stop and arrows move within it. */
        function paint(v) {
            value = v;
            opts().forEach(function (b) {
                var on = b.getAttribute('data-cadence') === v;
                b.setAttribute('aria-checked', on ? 'true' : 'false');
                b.tabIndex = on ? 0 : -1;
            });
            // Nothing checked ⇒ the group still needs a tab stop.
            if (!opts().some(function (b) { return b.tabIndex === 0; }) && opts()[0]) {
                opts()[0].tabIndex = 0;
            }
        }

        function busy(on) {
            box.setAttribute('data-busy', on ? '1' : '0');
            opts().forEach(function (b) { b.disabled = !!on; });
        }

        function say(msg) { if (note) note.textContent = msg || ''; }

        function choose(want) {
            if (!want || want === value) return;
            var was = value;
            busy(true);
            say('Saving…');
            paint(want);                        // optimistic, reverted below on failure
            ajax('lg_fd_cadence_set', { cadence: want, nonce: tok }).then(function (j) {
                if (j && j.ok && typeof j.cadence === 'string') {
                    paint(j.cadence);           // the STORED value, which may differ
                    say(j.cadence === want ? 'Saved.' : 'Saved as ' + (FREQ_LABELS[j.cadence] || j.cadence) + '.');
                } else {
                    paint(was);
                    say('Could not save — try again.');
                }
            }).catch(function () {
                paint(was);
                say('Network error — try again.');
            }).then(function () { busy(false); });
        }

        seg.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('[data-cadence]') : null;
            if (b && !b.disabled) choose(b.getAttribute('data-cadence'));
        });

        /* Radiogroup keyboard contract. Without this the control is a row of
         * buttons wearing radio roles — it announces as a radiogroup and then does
         * not behave like one, which is its own small lie. */
        seg.addEventListener('keydown', function (e) {
            var list = opts();
            if (!list.length) return;
            var i = list.indexOf(document.activeElement);
            if (i < 0) return;
            var to = -1;
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') to = (i + 1) % list.length;
            else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') to = (i - 1 + list.length) % list.length;
            else if (e.key === 'Home') to = 0;
            else if (e.key === 'End') to = list.length - 1;
            if (to < 0) return;
            e.preventDefault();
            list[to].focus();
            choose(list[to].getAttribute('data-cadence'));
        });

        ajax('lg_fd_cadence_state', {}).then(function (j) {
            if (!j || !j.ok || typeof j.cadence !== 'string') { drop(); return; }
            tok = j.nonce || '';
            if (!tok) { drop(); return; }       // no nonce ⇒ every write would 403

            /* Rebuild the pills from the endpoint's list so this page can never
             * offer a cadence lg_fd_cadences() would refuse. The server-rendered
             * skeleton is only a placeholder. */
            if (Array.isArray(j.options) && j.options.length) {
                seg.textContent = '';
                j.options.forEach(function (v) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'lg-manage-sub__fol-freq-opt';
                    b.setAttribute('role', 'radio');
                    b.setAttribute('data-cadence', v);
                    b.textContent = FREQ_LABELS[v] || (v.charAt(0).toUpperCase() + v.slice(1));
                    seg.appendChild(b);
                });
            }
            paint(j.cadence);
            box.setAttribute('data-state', 'ready');
            box.hidden = false;
        }).catch(drop);
    }

    /* ── 2a. per-row 🔔/✉ toggles (LG_FOLLOWING_ROW_TOGGLES) ─────────────────
     * Ian: "they cant change the setting, just close it out, could they change
     * the toggles on that page too?" Before this, the only way to stop the EMAIL
     * from a thread you still wanted to read was to leave the thread.
     *
     * ONE BIT AT A TIME, and that is the whole point: the two channels are
     * independent (follow.php:15) and live in different databases — the bell in
     * Postgres, the envelope in bbPress's MySQL store — so this sends exactly the
     * one channel that was pressed and never touches the other.
     *
     * SAME ENDPOINT, SAME WIRE FORMAT AS THE HUB CARD. Not "an equivalent write":
     * literally POST /bb-mirror-api/v0/follow {topic_id, channel, on}, which is
     * the only writer of forums.topic_follow and the only caller of the bbPress
     * subscription writers. A second write path is how the two stores drift, and
     * drift here is the "UI lies" class — the account page saying the bell is off
     * while the card still shows it lit.
     *
     * Optimistic, then RECONCILED AGAINST THE RESPONSE rather than against what
     * we asked for: the endpoint re-reads both stores after writing and returns
     * what they now SAY, so a write that half-succeeded shows as it really is.
     */
    function paintToggle(btn, on) {
        var row = btn.closest('.lg-manage-sub__fol-row');
        var ch  = btn.getAttribute('data-toggle');
        if (row) paintMark(row, ch, on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        var noun = ch === 'notify' ? 'notifications' : 'emails';
        btn.setAttribute('aria-label', 'Turn ' + noun + ' ' + (on ? 'off' : 'on'));
    }

    function toggleBit(btn) {
        var row = btn.closest('.lg-manage-sub__fol-row');
        var id  = row && parseInt(row.getAttribute('data-topic'), 10);
        var ch  = btn.getAttribute('data-toggle');
        if (!(id > 0) || (ch !== 'notify' && ch !== 'email')) return;

        var was  = btn.getAttribute('aria-pressed') === 'true';
        var want = !was;
        btn.disabled = true;
        paintToggle(btn, want);                       // optimistic

        fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify({ topic_id: id, channel: ch, on: want })
        })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (j) {
                btn.disabled = false;
                if (!j || !j.ok) { paintToggle(btn, was); alert('Could not save that — try again.'); return; }
                // The STORE's answer, not our intent, and for BOTH bits: a write to
                // one channel can legitimately change what the other reports.
                paintToggle(btn, ch === 'notify' ? !!j.notify : !!j.email);
                var other = row && row.querySelector('[data-toggle="' + (ch === 'notify' ? 'email' : 'notify') + '"]');
                if (other) paintToggle(other, ch === 'notify' ? !!j.email : !!j.notify);
                var master = document.getElementById('lg-fol-master');
                if (master && typeof j.email_master === 'boolean') {
                    master.textContent = j.email_master
                        ? 'Emails are on for your account.'
                        : "Discussion emails are off for your account, so none of these will email you.";
                }
                refreshCounts();
            })
            .catch(function () {
                btn.disabled = false;
                paintToggle(btn, was);
                alert('Network error — try again.');
            });
    }

    /* ── 2. per-row unfollow, and the toggles ────────────────────────────── */
    sec.addEventListener('click', function (ev) {
        var tog = ev.target.closest ? ev.target.closest('[data-toggle]') : null;
        if (tog && sec.contains(tog)) { ev.preventDefault(); toggleBit(tog); return; }

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

            // "Stop all 1?" / "for all 1 discussion" is what a counter writes, not
            // what a person says. Caught by actually reading the dialog a real
            // click produced.
            var one   = count === 1;
            var head  = one ? 'Stop following this discussion?' : 'Stop all ' + count + '?';
            var scope = one ? 'this discussion' : 'all ' + count + ' discussions you follow';

            var dlg = document.createElement('dialog');
            dlg.className = 'lg-manage-sub__fol-dlg';
            dlg.innerHTML =
                '<h3>' + head + '</h3>' +
                '<p>This turns off notifications <b>and</b> email for ' + scope + '.</p>' +
                '<p>Your Weekly Digest and Event Reminders are not affected. You can follow any ' +
                'discussion again from the discussion itself.</p>' +
                '<div class="lg-manage-sub__fol-dlg-btns">' +
                '<button type="button" class="lg-manage-sub__fol-stopall is-cancel" value="no">Cancel</button>' +
                '<button type="button" class="lg-manage-sub__fol-stopall is-go" value="yes">' +
                (one ? 'Unfollow it' : 'Stop all ' + count) + '</button>' +
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
    /* Independent of hydrate() on purpose: the cadence is account-level, so it is
     * still the right setting to show a member whose follow list failed to read or
     * is empty. Coupling them would hide the control on exactly the page state
     * where "why am I getting these emails" gets asked. */
    initCadence();
}());
