# dev2 wake path

An auto-off box nobody can wake is worse than no auto-off. dev2 stops itself
(see `../README.md`); this file is how it comes back.

Today dev2 is woken by hand from dev1 (`devgbox-cli start`, or the AWS
console). Both items below need IAM changes that **neither dev1 nor dev2 can
make** — the `devgbox-cli` key that both boxes share has no `scheduler:*`,
no `events:*`, and no `iam:*`. Verified 2026-07-09:

```
$ aws scheduler list-schedules --max-results 1
AccessDeniedException: User: arn:aws:iam::577668279429:user/devgbox-cli is not
authorized to perform: scheduler:ListSchedules ... because no identity-based
policy allows the scheduler:ListSchedules action

$ aws events list-rules --limit 1
AccessDeniedException: ... not authorized to perform: events:ListRules ...
```

So both are **handoffs to Ian**, to run with an admin key. Nothing below has
been executed.

---

## (i) Weekday-morning auto-start — 08:00 US-Eastern, Mon–Fri

EventBridge **Scheduler** (not classic EventBridge rules): it takes a real
IANA timezone, so 08:00 ET stays 08:00 ET across DST. Classic `events` rules
are UTC-only and would drift an hour twice a year.

Cost: a one-off schedule is free-tier (14M invocations/mo free).

```bash
ACCT=577668279429
REGION=us-east-1
DEV2_ID=i-0811803ce91ecd6d3     # verify first; this changes on every rebuild

# 1. role EventBridge Scheduler assumes
aws iam create-role \
  --role-name dev2-morning-wake \
  --assume-role-policy-document file://wake-scheduler-trust-policy.json

# 2. what that role may do: start dev2*, nothing else
aws iam put-role-policy \
  --role-name dev2-morning-wake \
  --policy-name start-dev2-only \
  --policy-document file://wake-scheduler-permission-policy.json

# 3. the schedule itself — 08:00 America/New_York, Mon-Fri
aws scheduler create-schedule \
  --name dev2-morning-wake \
  --schedule-expression 'cron(0 8 ? * MON-FRI *)' \
  --schedule-expression-timezone 'America/New_York' \
  --flexible-time-window '{"Mode":"OFF"}' \
  --target "{
      \"Arn\": \"arn:aws:scheduler:::aws-sdk:ec2:startInstances\",
      \"RoleArn\": \"arn:aws:iam::${ACCT}:role/dev2-morning-wake\",
      \"Input\": \"{\\\"InstanceIds\\\":[\\\"${DEV2_ID}\\\"]}\"
  }"
```

Verify without waiting for morning:

```bash
aws scheduler get-schedule --name dev2-morning-wake
# and a one-shot 2 minutes out, then delete it:
aws scheduler create-schedule --name dev2-wake-smoke \
  --schedule-expression "at($(date -u -d '+2 min' +%Y-%m-%dT%H:%M:%S))" \
  --flexible-time-window '{"Mode":"OFF"}' --target '...same...'
aws scheduler delete-schedule --name dev2-wake-smoke
```

**To change the hour** (Ian's decision — 08:00 ET is only a default):

```bash
aws scheduler update-schedule --name dev2-morning-wake \
  --schedule-expression 'cron(0 9 ? * MON-FRI *)' ...   # 09:00 ET
```

### The instance-id trap

`DEV2_ID` is baked into the schedule's `Input`. dev2's id **changes on every
rebuild** (it is already on its third: `i-0811803ce91ecd6d3` today). A stale id
makes the schedule fail silently every morning — the box just never wakes.

Two ways out, pick one:

- **Simple (recommended now):** keep the explicit id, and add this line to the
  dev2 rebuild runbook, right after the `Name=dev2*` tag is applied:
  ```bash
  aws scheduler update-schedule --name dev2-morning-wake --target "...\"Input\": \"{\\\"InstanceIds\\\":[\\\"$NEW_ID\\\"]}\"..."
  ```
- **Durable:** point the schedule at a 6-line Lambda that resolves the box by
  tag instead. Survives rebuilds untouched, at the cost of a Lambda + its role.
  ```python
  import boto3
  def handler(event, context):
      ec2 = boto3.client("ec2")
      r = ec2.describe_instances(Filters=[
          {"Name": "tag:Name", "Values": ["dev2*"]},
          {"Name": "instance-state-name", "Values": ["stopped"]}])
      ids = [i["InstanceId"] for x in r["Reservations"] for i in x["Instances"]]
      if ids:
          ec2.start_instances(InstanceIds=ids)
      return ids
  ```

The daemon that stops the box has no such problem: it resolves its own id from
IMDSv2 at stop time, precisely because ids churn.

---

## (ii) Start-only key so buck's desktop Claude can wake dev2 off-hours

**IAN DECISION — prepared, not created.** `wake-buck-start-only-policy.json`
is ready to attach; no IAM user has been made.

```bash
aws iam create-user --user-name buck-dev2-wake
aws iam put-user-policy --user-name buck-dev2-wake \
  --policy-name start-dev2-only \
  --policy-document file://wake-buck-start-only-policy.json
aws iam create-access-key --user-name buck-dev2-wake     # key rides a buck-pack update
```

Then from buck's desktop:

```bash
aws ec2 start-instances --instance-ids "$(aws ec2 describe-instances \
  --filters 'Name=tag:Name,Values=dev2*' 'Name=instance-state-name,Values=stopped' \
  --query 'Reservations[].Instances[].InstanceId' --output text)"
```

What the key can and cannot do:

| | |
|---|---|
| start dev2* | yes — `StringLike aws:ResourceTag/Name: dev2*` |
| stop / terminate / reboot **anything** | no — those actions are not in the policy |
| start any non-dev2 box | no — tag condition fails |
| **list every instance in the account** | **yes, unavoidably** |

That last row is the one to weigh. `ec2:DescribeInstances` does not support
resource-level permissions or tag conditions — AWS only accepts it on `"*"`.
So the key leaks the shape of the account (ids, IPs, tags, AMIs) to whoever
holds it, and it is a **long-lived static key sitting on a laptop**. It cannot
destroy anything, but it can enumerate everything.

Alternatives, if that trade is unattractive:

- **Do nothing.** Buck asks Ian to start the box, or waits for 08:00 ET. Zero
  new credentials. The morning schedule in (i) already covers the normal case.
- **A URL instead of a key.** Lambda function URL with a shared secret; buck's
  Claude `curl`s it. No AWS credential leaves the account; the secret only
  starts dev2. More moving parts, strictly less blast radius.
