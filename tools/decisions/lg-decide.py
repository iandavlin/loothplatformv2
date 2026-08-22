#!/usr/bin/env python3
"""lg-decide — the pending-question store behind the decision box (#202).

Ian, 8/22: "I want a button that opens up the decision box that we use here and
have it communicate with you." This is the store both channels read: the box he
answers in chat, and the box the lanes page draws.

WHERE IT LIVES, AND WHY EXACTLY THERE (measured, not assumed)

    ~/.lg-decisions/            0755 ubuntu:ubuntu
    ~/.lg-decisions/<id>.json   0644   the question
    ~/.lg-decisions/<id>.claim  0644   the first-answer-wins token

`/srv/lg-shared-state` was the obvious home and is the WRONG one: it is
looth-dev:looth-dev 755, so keeper (ubuntu) cannot write it at all, and it is
where the tester-unlock hash lives — loosening it to make this feature work
would be trading a real secret for a convenience. Measured instead:

    /home/ubuntu is drwxr-x--x  → the web user can TRAVERSE but not list/write
    sudo -u looth-dev cat  ~/.lg-decisions/x.json   → succeeded
    sudo -u looth-dev touch ~/.lg-decisions/nope    → Permission denied

which is the exact asymmetry this needs: keeper writes, the endpoints read,
nothing else can do either.

⚠️ A QUESTION IS READABLE BY THE WEB USER. It is behind the dev gate, but a
question is not a private channel — never put a secret, a token or a member's
personal data in one. The options are labels for a button, not a payload.

FIRST ANSWER WINS, ACROSS BOTH CHANNELS. Chat and page race through this one
function. The token is an O_EXCL create of `<id>.claim`, which is atomic on a
local filesystem and needs no lock, no daemon and no database. The claim is
AUTHORITATIVE: if a crash lands between the claim and the json rewrite, `load()`
adopts the claim and the store self-heals, because the alternative is a question
that was answered and does not know it.

A QUESTION IS NEVER DELETED AND NEVER EXPIRES. It leaves the pending set by
being answered and by nothing else. #172's ruling on this page stands — a wrong
quiet line is recoverable and a wrong disappearance is not.

    lg-decide ask --question T --option K:Label:Description … [--recommend K]
    lg-decide ask --json -            read the whole object from stdin
    lg-decide list [--json]           pending only
    lg-decide show <id> [--json]      pending or answered
    lg-decide answer <id> <key> --via chat|page
    lg-decide pending-count
"""
import argparse
import errno
import json
import os
import pathlib
import re
import sys
import time

# Overridable so the gate can drive a scratch store. Deliberately an env var and
# NOT a constant-with-define: this is a CLI run by ubuntu, never a web endpoint.
# The two PHP servants take the opposite choice, for the opposite reason — a
# lane-preview fastcgi_param lands in $_SERVER and not getenv(), and a web
# endpoint whose store can be moved by the environment is a worse thing than an
# untestable one.
STORE = pathlib.Path(os.environ.get(
    "LG_DECISIONS_DIR", os.path.expanduser("~/.lg-decisions")))

ID_RE = re.compile(r"^[0-9a-z][0-9a-z-]{2,39}$")
KEY_RE = re.compile(r"^[a-z0-9][a-z0-9_-]{0,15}$")
VIA = ("chat", "page")

MAX_Q, MAX_DETAIL, MAX_LABEL, MAX_DESC = 400, 600, 80, 240
MIN_OPTIONS, MAX_OPTIONS = 2, 4          # the shape of the box he already uses


class Refused(Exception):
    """A refusal with a sentence in it. Every exit path prints WHY."""


def _valid_id(i):
    return bool(i and ID_RE.match(i)) and ".." not in i


def qpath(i):
    if not _valid_id(i):
        raise Refused(f"not a question id: {i!r}")
    return STORE / f"{i}.json"


def cpath(i):
    if not _valid_id(i):
        raise Refused(f"not a question id: {i!r}")
    return STORE / f"{i}.claim"


def _write_atomic(path, obj, mode=0o644):
    """Temp + rename in the SAME directory, so a reader never sees half a
    question. A reader here is a PHP endpoint answering Ian; a partial read
    would render a box with no options in it."""
    tmp = path.with_name(path.name + f".tmp{os.getpid()}")
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(obj, fh, indent=1, sort_keys=True)
        fh.write("\n")
        fh.flush()
        os.fsync(fh.fileno())
    os.chmod(tmp, mode)
    os.replace(tmp, path)


def ensure_store():
    STORE.mkdir(parents=True, exist_ok=True)
    # 0755 and not 0700: the web user must be able to read the questions it is
    # asked to render. It still cannot write — that is the directory's owner.
    os.chmod(STORE, 0o755)


def validate(q):
    """The whole shape, in one place, so `ask` and a hand-written --json file
    are held to the same rules."""
    if not isinstance(q, dict):
        raise Refused("a question must be a JSON object")
    text = (q.get("question") or "").strip()
    if not text:
        raise Refused("a question needs a question")
    if len(text) > MAX_Q:
        raise Refused(f"question is over {MAX_Q} characters")
    if len(q.get("detail") or "") > MAX_DETAIL:
        raise Refused(f"detail is over {MAX_DETAIL} characters")

    opts = q.get("options")
    if not isinstance(opts, list):
        raise Refused("options must be a list")
    # ⚠ The 2..4 bound is not decoration: it is the shape of the box Ian already
    # answers in chat, and a one-option box is not a decision.
    if not (MIN_OPTIONS <= len(opts) <= MAX_OPTIONS):
        raise Refused(f"a decision box takes {MIN_OPTIONS}-{MAX_OPTIONS} "
                      f"options, got {len(opts)}")
    seen = set()
    for o in opts:
        if not isinstance(o, dict):
            raise Refused("each option must be an object")
        k = o.get("key") or ""
        if not KEY_RE.match(k):
            raise Refused(f"not an option key: {k!r}")
        if k in seen:
            raise Refused(f"duplicate option key: {k}")
        seen.add(k)
        if not (o.get("label") or "").strip():
            raise Refused(f"option {k} has no label")
        if len(o["label"]) > MAX_LABEL:
            raise Refused(f"option {k} label is over {MAX_LABEL} characters")
        if len(o.get("description") or "") > MAX_DESC:
            raise Refused(f"option {k} description is over {MAX_DESC} characters")
        if o.get("recommended") not in (None, True, False):
            raise Refused(f"option {k}: recommended must be true or false")
    if sum(1 for o in opts if o.get("recommended")) > 1:
        raise Refused("at most one option may be recommended")
    if q.get("issue") is not None and not isinstance(q["issue"], int):
        raise Refused("issue must be a number")
    return q


def new_id():
    return "d%s-%s" % (time.strftime("%Y%m%d", time.gmtime()),
                       os.urandom(3).hex())


def load(i):
    """Read one question, reconciling the claim. THE CLAIM IS AUTHORITATIVE:
    if it exists and the json still says unanswered, the json is the stale one —
    a crash landed between the two writes — and we adopt the claim rather than
    re-offering a question that has already been answered."""
    p = qpath(i)
    try:
        q = json.loads(p.read_text(encoding="utf-8"))
    except FileNotFoundError:
        raise Refused(f"no such question: {i}")
    except (OSError, ValueError) as e:
        raise Refused(f"question {i} is unreadable: {e}")
    if not q.get("answered"):
        c = cpath(i)
        if c.exists():
            try:
                q["answered"] = json.loads(c.read_text(encoding="utf-8"))
                _write_atomic(p, q)
            except (OSError, ValueError):
                pass
    return q


def all_questions():
    """Every question, newest first. A file that will not parse is REPORTED, not
    skipped silently — an unreadable store must never render as an empty one."""
    out, bad = [], []
    try:
        names = sorted(STORE.glob("*.json"))
    except OSError as e:
        raise Refused(f"store unreadable: {e}")
    for p in names:
        i = p.name[:-5]
        if not _valid_id(i):
            bad.append(p.name)
            continue
        try:
            out.append(load(i))
        except Refused:
            bad.append(p.name)
    out.sort(key=lambda q: q.get("created", 0), reverse=True)
    return out, bad


def pending():
    qs, bad = all_questions()
    return [q for q in qs if not q.get("answered")], bad


def cmd_ask(a):
    ensure_store()
    if a.json:
        raw = sys.stdin.read() if a.json == "-" else \
            pathlib.Path(a.json).read_text(encoding="utf-8")
        try:
            q = json.loads(raw)
        except ValueError as e:
            raise Refused(f"that is not JSON: {e}")
    else:
        opts = []
        for spec in a.option or []:
            # maxsplit=2 so a description may contain colons — it is prose, and
            # a parser that forbids a colon in prose is a parser that will
            # silently truncate somebody's sentence.
            parts = spec.split(":", 2)
            if len(parts) < 2:
                raise Refused(f"--option wants KEY:LABEL[:DESCRIPTION], got {spec!r}")
            o = {"key": parts[0].strip(), "label": parts[1].strip()}
            if len(parts) == 3 and parts[2].strip():
                o["description"] = parts[2].strip()
            opts.append(o)
        q = {"question": a.question or "", "options": opts}
        if a.detail:
            q["detail"] = a.detail
        if a.issue:
            q["issue"] = a.issue
    if a.recommend:
        keys = [o.get("key") for o in q.get("options") or []]
        if a.recommend not in keys:
            raise Refused(f"--recommend {a.recommend} is not one of the options")
        for o in q["options"]:
            o["recommended"] = (o.get("key") == a.recommend)

    validate(q)
    q["id"] = q.get("id") or new_id()
    if not _valid_id(q["id"]):
        raise Refused(f"not a question id: {q['id']!r}")
    q.setdefault("created", int(time.time()))
    q.setdefault("asked_by", a.asked_by)
    q["answered"] = None
    p = qpath(q["id"])
    if p.exists():
        raise Refused(f"question {q['id']} already exists")
    _write_atomic(p, q)
    print(q["id"])
    return 0


def answer(i, key, via, by="ian"):
    """The ONLY mutation, and the whole of first-answer-wins.

    Returns the answer record. Raises Refused when the question is unknown, the
    key is not one keeper offered, or somebody else got there first — and in
    that last case the sentence names WHO and THROUGH WHICH CHANNEL, because
    "already answered" with no attribution is not an answer to anybody.
    """
    if via not in VIA:
        raise Refused(f"--via must be one of {', '.join(VIA)}")
    q = load(i)
    keys = [o.get("key") for o in q.get("options") or []]
    # ⚠ Checked BEFORE the claim. A key keeper never posed must not be able to
    # burn the one claim a real answer needs.
    if key not in keys:
        raise Refused(f"{key!r} is not an option on {i} (offered: "
                      f"{', '.join(keys) or 'none'})")
    if q.get("answered"):
        a = q["answered"]
        raise Refused(f"{i} was already answered — {a.get('label') or a.get('key')} "
                      f"via {a.get('via')} at "
                      f"{time.strftime('%H:%M', time.localtime(a.get('at', 0)))}")

    label = next((o.get("label") for o in q["options"] if o.get("key") == key), key)
    rec = {"at": int(time.time()), "key": key, "label": label,
           "via": via, "by": by}
    try:
        fd = os.open(cpath(i), os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o644)
    except OSError as e:
        if e.errno != errno.EEXIST:
            raise Refused(f"cannot claim {i}: {e}")
        # Lost the race in the window above. Re-read and report the winner.
        q = load(i)
        a = q.get("answered") or {}
        raise Refused(f"{i} was already answered — {a.get('label') or a.get('key')} "
                      f"via {a.get('via')}")
    with os.fdopen(fd, "w", encoding="utf-8") as fh:
        json.dump(rec, fh, sort_keys=True)
        fh.flush()
        os.fsync(fh.fileno())
    q["answered"] = rec
    _write_atomic(qpath(i), q)
    return rec


def cmd_answer(a):
    rec = answer(a.id, a.key, a.via, a.by)
    print(json.dumps(rec, sort_keys=True))
    return 0


def _line(q):
    age = max(0, int(time.time()) - int(q.get("created") or 0))
    when = (f"{age // 86400}d" if age >= 86400 else
            f"{age // 3600}h" if age >= 3600 else f"{age // 60}m")
    head = f"{q['id']}  ({when} ago)"
    if q.get("issue"):
        head += f"  #{q['issue']}"
    out = [head, f"  {q['question']}"]
    for o in q.get("options") or []:
        star = " (recommended)" if o.get("recommended") else ""
        out.append(f"    [{o['key']}] {o['label']}{star}")
        if o.get("description"):
            out.append(f"        {o['description']}")
    if q.get("answered"):
        a = q["answered"]
        out.append(f"  ANSWERED: {a.get('label')} via {a.get('via')}")
    return "\n".join(out)


def cmd_list(a):
    qs, bad = pending()
    if a.json:
        print(json.dumps({"pending": qs, "unreadable": bad}, indent=1))
    else:
        for q in qs:
            print(_line(q))
            print()
        if not qs:
            print("nothing pending")
    # An unreadable file is LOUD. "I could not read one" and "there are none"
    # must never look alike — this page's oldest law, applied to its store.
    if bad:
        print(f"⚠ {len(bad)} unreadable: {', '.join(bad)}", file=sys.stderr)
        return 1
    return 0


def cmd_show(a):
    q = load(a.id)
    print(json.dumps(q, indent=1, sort_keys=True) if a.json else _line(q))
    return 0


def cmd_count(a):
    qs, bad = pending()
    print(len(qs))
    # ⚠ The warning is NOT optional here. This verb's whole output is a number,
    # and a number printed after a file failed to parse is a count of what could
    # be read, not a count of what is waiting. It exited 1 silently for exactly
    # one revision; a silent "I could not look" is the failure this page's
    # oldest law exists to prevent, and it had reached its own store.
    if bad:
        print(f"⚠ {len(bad)} unreadable: {', '.join(bad)}", file=sys.stderr)
        return 1
    return 0


def main(argv=None):
    p = argparse.ArgumentParser(prog="lg-decide", description=__doc__.split("\n")[0])
    sub = p.add_subparsers(dest="cmd")

    s = sub.add_parser("ask", help="pose a question")
    s.add_argument("--question")
    s.add_argument("--detail")
    s.add_argument("--issue", type=int)
    s.add_argument("--option", action="append", metavar="KEY:LABEL:DESCRIPTION")
    s.add_argument("--recommend", metavar="KEY")
    s.add_argument("--asked-by", default="keeper", dest="asked_by")
    s.add_argument("--json", metavar="FILE", help="read the whole object ('-' = stdin)")
    s.set_defaults(func=cmd_ask)

    s = sub.add_parser("list", help="pending questions")
    s.add_argument("--json", action="store_true")
    s.set_defaults(func=cmd_list)

    s = sub.add_parser("show", help="one question, pending or answered")
    s.add_argument("id")
    s.add_argument("--json", action="store_true")
    s.set_defaults(func=cmd_show)

    s = sub.add_parser("answer", help="answer one question (first wins)")
    s.add_argument("id")
    s.add_argument("key")
    s.add_argument("--via", required=True, choices=VIA)
    s.add_argument("--by", default="ian")
    s.set_defaults(func=cmd_answer)

    s = sub.add_parser("pending-count", help="how many are waiting")
    s.set_defaults(func=cmd_count)

    a = p.parse_args(argv)
    if not a.cmd:
        p.print_help()
        return 2
    try:
        return a.func(a)
    except Refused as e:
        print(f"lg-decide: {e}", file=sys.stderr)
        return 3


if __name__ == "__main__":
    sys.exit(main())
