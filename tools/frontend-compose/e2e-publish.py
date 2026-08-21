#!/usr/bin/env python3
"""#189 end-to-end: a real member fills the real form and presses Post.

Gate 88 §I2 proves the rendered markup decodes to the right value shape and that
ACF's own update_value stores it. This closes the last gap — the HTTP layer:
the browser's actual POST, through acf_form_head() -> acf_validate_save_post ->
acf_save_post, and then the post's meta read back.

⚠️ It uploads through the real bfu_chunker first, so the ids it submits are ids
this uploader really made.
"""
import json, os, re, subprocess, sys, urllib.parse

D = "/tmp/lg189-e2e"
os.makedirs(D, exist_ok=True); os.chmod(D, 0o755)
WP = "/var/www/dev"
URL = "https://dev2.loothgroup.com/preview/189-form-uploader/compose/?type=loothprint"
TAG = str(os.getpid())
OK, BAD = [], []

def R(n, ok, why=""):
    (OK if ok else BAD).append(n)
    print("R|%s|%s|%s" % (n, "PASS" if ok else "FAIL", why), flush=True)

def wp(php, env=None):
    cmd = ["sudo", "-n", "-u", "looth-dev", "env"]
    cmd += ["%s=%s" % (k, v) for k, v in (env or {}).items()]
    cmd += ["wp", "--path=" + WP, "eval", php]
    return subprocess.run(cmd, capture_output=True, text=True)

gate = subprocess.run(["sudo","-n","grep","-oP",r'loothdev_token\s+"\K[^"]+',
                       "/etc/nginx/snippets/loothdev-tokens.conf"],
                      capture_output=True, text=True).stdout.strip().splitlines()[0]

mk = wp('$l="lg189e2e-%s";'
        '$id=wp_insert_user(["user_login"=>$l,"user_pass"=>wp_generate_password(24),'
        '"user_email"=>$l."@example.invalid","role"=>"looth1"]);'
        'if(is_wp_error($id)){fwrite(STDERR,$id->get_error_message());exit(1);}'
        '$e=time()+3600;$t=WP_Session_Tokens::get_instance($id)->create($e);'
        'echo json_encode(["id"=>$id,"roles"=>get_userdata($id)->roles,'
        '"c"=>LOGGED_IN_COOKIE,"v"=>wp_generate_auth_cookie($id,$e,"logged_in",$t)]);' % TAG)
if mk.returncode != 0:
    sys.exit("member: " + (mk.stderr or "")[:300])
M = json.loads(mk.stdout.strip().splitlines()[-1])
R("member.is.looth1", M["roles"] == ["looth1"], "uid=%s roles=%s" % (M["id"], M["roles"]))
COOKIE = "loothdev=%s; %s=%s" % (gate, M["c"], M["v"])

def curl(args, out=None):
    base = ["curl","-s","--resolve","dev2.loothgroup.com:443:127.0.0.1","-H","Cookie: "+COOKIE]
    if out: base += ["-o", out]
    return subprocess.run(base + args, capture_output=True, text=True)

try:
    # ── the rendered form, as this member sees it ────────────────────────────
    curl([URL], out=D+"/form.html")
    html = open(D+"/form.html", encoding="utf-8", errors="replace").read()
    cfg = json.loads(re.search(r"window\.LGFC_UP=(\{.*?\});</script>", html, re.S).group(1))
    R("form.arrived", bool(cfg.get("post_id")), "composing post = %s" % cfg.get("post_id"))
    POST = cfg["post_id"]

    # ── upload two photos and a zip through the real chunker ────────────────
    def make_png(p, label):
        subprocess.run(["php","-r",
            '$im=imagecreatetruecolor(820,600);$c=imagecolorallocate($im,90,120,80);'
            'imagefilledrectangle($im,0,0,820,600,$c);$t=imagecolorallocate($im,255,255,255);'
            'imagestring($im,5,20,20,%r,$t);imagepng($im,%r);' % (label, p)], check=True)
    import zipfile
    photos = []
    for i in (1, 2):
        p = "%s/e2e-%s-%d.png" % (D, TAG, i); make_png(p, "e2e %d" % i); photos.append(p)
    zp = "%s/e2e-%s.zip" % (D, TAG)
    with zipfile.ZipFile(zp, "w") as z:
        z.writestr("model.stl", b"solid x\nendsolid x\n")

    def upload(path):
        r = curl(["-X","POST",
                  "-F","name="+os.path.basename(path),"-F","chunk=0","-F","chunks=1",
                  "-F","post_id=%d" % POST,"-F","_wpnonce="+cfg["nonce"],
                  "-F","async-upload=@%s;filename=%s" % (path, os.path.basename(path)),
                  "https://dev2.loothgroup.com/wp-admin/admin-ajax.php?action=bfu_chunker"])
        d = json.loads(r.stdout)
        assert d.get("success"), r.stdout[:200]
        return int(d["data"]["id"])

    pids = [upload(p) for p in photos]
    zid = upload(zp)
    R("uploads.made", len(pids) == 2 and zid > 0, "photos %s · print file %s" % (pids, zid))

    # ── the exact POST the form's own markup produces ───────────────────────
    fields = dict(re.findall(r'<input[^>]*type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"', html))
    keys = {}
    # ⚠️ [a-z0-9_]+, NOT [a-z_]+ — "loothprint_3d_file" has a DIGIT in it, and the
    # first version of this silently found only the gallery.
    for name, key in re.findall(r'data-name="([a-z0-9_]+)"[^>]*data-key="(field_[0-9a-f]+)"', html):
        keys[name] = key
    R("form.keys.found", "loothprint_more_images" in keys and "loothprint_3d_file" in keys,
      "gallery=%s file=%s" % (keys.get("loothprint_more_images"), keys.get("loothprint_3d_file")))

    body = []
    for n, v in fields.items():
        if n.startswith("acf["):
            continue
        body.append((n, v))
    body += [
        ("acf[_post_title]", "lg189 end to end %s" % TAG),
        ("acf[_post_content]", "<p>Made by the end-to-end check for issue 189.</p>"),
    ]
    # ⚠️ THE SENTINEL FIRST, THEN THE LIST — exactly the order the control emits.
    body.append(("acf[%s]" % keys["loothprint_more_images"], ""))
    for pid in pids:
        body.append(("acf[%s][]" % keys["loothprint_more_images"], str(pid)))
    body.append(("acf[%s]" % keys["loothprint_3d_file"], str(zid)))

    # ── the other required fields, read out of the form's own markup ────────
    # ⚠️ TAKEN FROM THE RENDER, NEVER GUESSED. The term ids and the licence
    # wording are data; a hardcoded id would make this pass on one box and fail
    # on the next, which is box trap 4 in miniature.
    for tax in ("loothprint_category", "content_topic_broad_terms"):
        k = keys.get(tax)
        if not k:
            R("form.taxonomy." + tax, False, "the field did not render")
            continue
        m = re.search(r'<input type="checkbox" name="acf\[%s\]\[\]" value="(\d+)"' % k, html)
        R("form.taxonomy." + tax, bool(m), "first term = %s" % (m.group(1) if m else "none found"))
        if m:
            body.append(("acf[%s][]" % k, m.group(1)))

    lic = keys.get("loothprint_creative_commons")
    if lic:
        m = re.search(r'<input type="radio"[^>]*name="acf\[%s\]"[^>]*value="([^"]*)"[^>]*checked' % lic, html) \
            or re.search(r'<input type="radio"[^>]*name="acf\[%s\]"[^>]*value="([^"]*)"' % lic, html)
        R("form.licence", bool(m), "licence = %r" % (m.group(1)[:40] if m else None))
        if m:
            body.append(("acf[%s]" % lic, m.group(1)))

    enc = "&".join("%s=%s" % (urllib.parse.quote_plus(k), urllib.parse.quote_plus(v)) for k, v in body)
    open(D+"/post.txt","w").write(enc)
    r = curl(["-X","POST","-H","Content-Type: application/x-www-form-urlencoded",
              "--data-binary","@"+D+"/post.txt","-o",D+"/after.html","-w","%{http_code} %{redirect_url}",
              URL])
    R("submit.accepted", "302" in r.stdout or "200" in r.stdout, "HTTP %s" % r.stdout.strip())

    # ── read the post back ──────────────────────────────────────────────────
    out = wp('$p=%d;echo json_encode(["status"=>get_post_status($p),'
             '"title"=>get_post_field("post_title",$p),'
             '"gallery"=>get_post_meta($p,"loothprint_more_images",true),'
             '"zip"=>get_post_meta($p,"loothprint_3d_file",true),'
             '"content"=>wp_strip_all_tags((string)get_post_field("post_content",$p)),'
             '"kids"=>count(get_children(["post_parent"=>$p,"post_type"=>"attachment","numberposts"=>-1]))]);' % POST)
    got = json.loads(out.stdout.strip().splitlines()[-1])
    R("saved.gallery.ids", [str(x) for x in (got["gallery"] or [])] == [str(p) for p in pids],
      "stored %r (uploaded %r)" % (got["gallery"], pids))
    R("saved.printfile.id", str(got["zip"]) == str(zid), "stored %r (uploaded %s)" % (got["zip"], zid))
    R("saved.title", TAG in (got["title"] or ""), repr(got["title"]))
    R("saved.writeup", "end-to-end" in (got["content"] or ""), repr(got["content"])[:90])
    R("saved.status", got["status"] in ("publish", "pending", "draft"),
      "post status %r — a member's new loothprint goes to review" % got["status"])
    print("Z.end|reached")
finally:
    wp('$u=%d;'
       '$posts=get_posts(["post_type"=>array_keys(lg_fc_types()),'
       '"post_status"=>["auto-draft","draft","pending","publish","private","trash"],'
       '"numberposts"=>-1,"author"=>$u,"fields"=>"ids"]);'
       'foreach($posts as $p){foreach(get_children(["post_parent"=>$p,"post_type"=>"attachment",'
       '"numberposts"=>-1,"fields"=>"ids"]) as $c){wp_delete_attachment((int)$c,true);} wp_delete_post($p,true);}'
       'require_once ABSPATH."wp-admin/includes/user.php"; wp_delete_user($u);'
       'echo "cleaned ".count($posts)." post(s)";' % M["id"])

print("\n%d passed, %d failed" % (len(OK), len(BAD)))
sys.exit(1 if BAD else 0)
