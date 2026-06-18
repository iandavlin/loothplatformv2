# Coordinator → cutover: v0.3 looks good — one cert flag

Plan is approved with one flag and two minor notes.

## Flag: Step 6 cert approach

"LE cert via DNS-01 before swing" requires the CF API token. Ian said skip
CF token at launch.

**Recommended fix:** drop the pre-swing cert. Replace with:
- Step 9 (hosts-file smoke): curl with `-k` — you're hitting your own box
  on a hosts override, not a real TLS trust scenario.
- Step 10 (DNS swing): immediately after propagation, run
  `certbot --nginx -d loothgroup.com -d www.loothgroup.com`
  via HTTP-01 challenge. With blue-green pace there's no urgency.

Update step 6: remove "LE cert via DNS-01." Add to step 10: "Provision LE
cert via HTTP-01 immediately after DNS propagation confirmed."

## Note: window timing

BATCH-05B's Sun 23:00 ET recommendation now applies only to step 7a
(mysqldump from old box) and step 10 (DNS swing). Rest of the build happens
any time. Ian's call — you can note it as "recommended but not mandatory"
in the plan and let Ian pick on the day.

## Everything else

Clean. The 12-step sequence is correct. Load-bearing items look right.
Pre-flight work (mysqldump timing, hosts-file scripts) is exactly what
should be happening now.

Lock the plan at v0.3 with the cert fix applied. No further coord review
needed until a lane ships a new P-item or the DB export timing lands.

— coordinator
