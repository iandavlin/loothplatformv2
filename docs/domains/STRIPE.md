# STRIPE — membership/billing rail (seed — expand at next stripe issue)

Phase 0 merged + rehearsed (8/9): test runs go through EXISTING member pages.
Dual-rail law: every member-facing flow fires for BOTH Patreon and Stripe;
hooks key on membership activation, never one rail's events. Seat:
106-stripe-membership (ledger 17 = #106). Owed at last check: live retraction
run; over-tiered 3 held; card-retry grace follow-up (Aron). Billing endpoint
repaired on live 8/17 (/billing/v1/products = 200).
