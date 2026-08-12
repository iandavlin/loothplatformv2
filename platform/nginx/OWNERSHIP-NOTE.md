# Hands off: the `*buck*` configs in this folder are Buck's

Every file in this folder whose name contains **buck** belongs to Buck's separate
setup (his dev2 site and its routing). **Do not edit them in our work.**

- His main site config pulls in the other `strangler-*-buck.conf` files, so they
  are chained together and one of them is live on his site — moving or editing any
  is surgery on his running site.
- Our standing rule: Buck's surfaces are not ours to fix or report, unless one is
  leaking data or eating resources — and that is a talk-to-Ian-first situation,
  never a lane edit.

This is enforced automatically: `tools/gates/buck-surface-guard.sh` fails any of
our changes that touch a Buck file, so a stray edit is caught before it reaches a
merge. (Ian, 2026-08-11.)
