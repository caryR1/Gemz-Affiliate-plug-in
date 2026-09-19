# GAS Status Snapshot — Solar (2026-09-19)

Authoritative-as-of snapshot for reviewers (ChatGPT, future sessions). Scope: the Solar site (solar.gemzonline.com). `ROADMAP.md` is the feature history; this file is "what is true right now and how do we know."

Plugin `GAS_VERSION` 2.21.3 / `GAS_DB_VERSION` 19, live on Solar, matches repo `main` (HEAD 251bccc when written).

**Evidence tags:** `[LIVE]` observed directly in Solar's database/site on 2026-09-19 (read-only queries). `[DOC]` recorded in ROADMAP/SWAP as tested by an earlier session, not re-verified here. `[CODE]` read in source only.

**Solar's data is test data.** Live rows: 7 affiliates (e.g. `cary-test-affiliate`, `uton-moodie`, `paypal-sandbox-test`), 8 leads (7 auto-flagged stale, 1 completed), 5 clicks, 1 payout row, 14 contacts. No evidence of real customer traffic yet. Treat counts as fixtures.

## A. Status matrix

Legend: IT = implemented + tested, INT = implemented, not fully tested, PART = partially implemented, PLAN = planned/not implemented, BRK = known broken/blocked, UNK = unknown/needs verification.

| Subsystem | Status | Evidence / notes |
|---|---|---|
| Affiliate signup + account | IT | Full funnel verified with real (non-seeded) data 2026-09-10 [DOC]; 7 affiliate users [LIVE]. Agreement checkbox + `gas_agreement_accepted_at` on both signup forms, agreement page live (WP page "Affiliate Program Agreement") [DOC][LIVE page exists]. Email verification exists [CODE]. Existing-email reuse never auto-logs in (anti-takeover) [CODE]. |
| Sponsor / downline hierarchy | IT | `/join/{code}` sponsor cookie; `sponsor_code_id` chain. Live chain exists: code 2 → 3 → 5 → 6 (4 levels) [LIVE]. "Add a team member" direct-create with IP rate limit [DOC]. Indented team tree [DOC]. |
| Click / referral attribution | IT | `/go/{slug}?ref={code}`; last-touch, 180-day cookie, per-day visitor dedup, bot-UA skip, IP rate limits [DOC][CODE]. 5 clicks with campaign_id [LIVE]. Self-referral deliberately allowed (2026-09-09 policy) with a non-blocking tier-stacking audit flag [DOC]. |
| Lead capture | IT | Get-a-Quote form, state dropdown, TCPA consent capture (Refer-a-Friend path deliberately unconsented) [DOC]. Leads store `code_id`, `campaign_id` [LIVE]. |
| Lead routing / status | IT (appointments PART) | Manual assign, coverage-area matching, unassign/decline, out-of-area notice [DOC]. Audit log shows `coverage_matched`, `partner_assigned/unassigned`, `status_changed` [LIVE]. Daily stale check fired 2026-09-16 (`marked_stale`) [LIVE] — WP-Cron works with current traffic. 2 leads still unmatched (`partner_id` 0) [LIVE]. Appointments: one-way email only, no calendar/reminders [DOC]. |
| Partners + partner portal | IT | Portal account auto-provisioned on save [DOC]; 1 partner user [LIVE]. No partner self-signup [DOC]. Two rows named "Go Solar Power": id 1 (approved, real) and id 2 (declined accidental duplicate, note says safe to delete) [LIVE]. |
| Commission math / tier split / rounding | INT | `GAS_Payouts::compute()`: 70/20/10, each tier rounded UP to $10, tier2/3 only if a sponsor chain exists [CODE]. Verified against real partner data on 2026-09-13 via direct call [DOC, my own check]. PHPUnit 25/55 green on 2026-09-08 but **pinned to pre-rounding numbers, now stale** [CODE]. The only live payout row (id 1, code `uton-moodie`) was created 2026-09-10 **before** rounding; its tier2/tier3 are 0 [LIVE]. **A multi-tier ledger row with tier2/3 populated, post-rounding, has never been created on Solar.** |
| **Go Solar Power pool value** | **UNK — needs Cary** | Live `agent_pool_value` on partner id 1 is **$425.00** [LIVE]. My 2026-09-13 note logged the pool being set to $280 (giving $200/$60/$30), then five "updated" audit entries appear 09-13 01:17–09:03 UTC [LIVE]; the DB now says $425 (would give $300/$90/$50). Most likely Cary tuned it with the new admin calculator, but not confirmed. Anything that quoted "$280 → $200/$60/$30" is out of date until confirmed. Marketing copy says "$200+" (still a true floor). Dynamic estimate widgets follow the DB value automatically. |
| Ledger / payout runs (manual) | IT | Payout Calculator + Ledger + CSV [DOC]. Month-boundary fix (only closed prior months pay) verified on staging by DB inspection [DOC]. |
| Automated monthly payout run | INT | Token REST endpoint + `payout_run_day` (5) + pause toggle (`payout_run_paused` = false) [LIVE settings]. Verified on staging: 403 on bad token, day gate, pause, once-per-month idempotency [DOC]. On Solar `gas_last_automated_payout_run` is empty, so it has **never run** [LIVE]. Whether Cary's Hostinger hPanel cron job exists: **UNK** (no SSH crontab access). |
| PayPal payouts | IT (sandbox) / BRK (live) | Real sandbox payout succeeded on staging 2026-09-10: $490 paid, two unrelated affiliates correctly held for missing tax info [DOC]. Solar currently has **no PayPal credentials stored** (client id/secret empty; env option unset, defaults sandbox) [LIVE]. Live flip blocked by a PayPal account/config issue per Cary; live credentials exist locally, configured nowhere [DOC]. |
| Wise payouts | INT | Implemented, per-affiliate transfers with independent failure handling [CODE]. No real transfer ever exercised; Solar token empty [LIVE]. Treat as untested. |
| Tax + payment data | IT (gating) | W-9/W-8BEN collection gates every payout; masked in admin; accountant CSV export; per-person aggregation incl. cashback [DOC]. **Stored unencrypted in user meta** (known gap). No 1099 e-filing. |
| Cashback claim flow | IT (staging) | Tokenized public claim link, manual "mark paid" [DOC]. Gap: no tax gate for pure customers over $600/yr [DOC]. Go Solar's cashback config not checked here. |
| Affiliate dashboard (4 pages) + help/FAQ | IT | Overview, My Links & Earnings, My Team, Account; pages exist [LIVE]; LiteSpeed no-cache fixes [DOC]. |
| Admin backend | IT | Screens listed in ROADMAP; partner notes history (4 notes) [LIVE]; live tier-split calculator [CODE]. Reports lack date ranges/trends [DOC]. |
| REST `gas/v1` | INT | Used by the sister site's session; settings allowlist is hand-maintained (caused one real bug) [DOC]. |
| Campaigns / variants / marketing assets / lead magnets | INT | 1 campaign live; **0 variants, 0 marketing assets, 0 lead magnets** on Solar [LIVE]. Features built and staging-tested [DOC]; not exercised in production data here. |
| Fraud / security | INT | Disposable-email, bot-UA, IP rate limits [DOC]. No IP-intelligence. Public-form nonce vs page-cache audit not done (LiteSpeed caused 3 bugs on 2026-09-10 [DOC]). |
| Email (transactional) | INT | `wp_mail` only, hardcoded bodies, compliance footer + unsubscribe link on every plugin email [DOC]. Deliverability/spam placement: **UNK**. No SMS, no template editor [DOC]. Unsubscribe does not suppress transactional sends (open product question) [DOC]. |
| Mailing list / nurture | PART / PLAN | See section B. |
| Caching / deployment | INT | Two cache layers; manual per-file deploys; no CI [DOC]. SSH + WP-CLI on the server works [LIVE]. |
| Automated tests | BRK (stale) | PHPUnit suite needs updating for round-up-to-$10, then re-run on the server. |
| Non-plugin site items | IT | Yoast, Elementor pages, GA tag via mu-plugin, Search Console verified (Cary), Build a Team link row (2026-09-19). |

Corrections to earlier statements from Claude Code (2026-09-19 draft): (1) affiliate-agreement acceptance **is built and live**; the "not built" line in older ROADMAP text was stale. (2) The $280/$200-$60-$30 example is not what the live DB currently implies (see pool row).

## B. Mailing list / segmentation

**What exists (real, in code, and live on Solar):**
- `wp_gas_contacts`: one directory, columns `contact_type`, `name`, `email` (unique), `phone`, `source`, `related_table`, `related_id`, `subscribed`, timestamps.
- Segments today = the three `contact_type` values, tagged on first sight and never silently reclassified:
  - `customer` = ordinary prospects/leads. Sources: `lead_form` (Get a Quote), `referral` (an affiliate referred them).
  - `affiliate`. Sources: `signup`, `added_by_sponsor`.
  - `partner` (fulfillment/installers). Source: `partner_save` (created when an admin saves a partner with an email).
- Live counts: customer 7 (3 lead_form + 4 referral), affiliate 6 (5 signup + 1 added_by_sponsor), partner 1. All subscribed; nobody has ever unsubscribed [LIVE].
- Lead magnets: `[gas_lead_magnet]` with honeypot, download counts, CSV export. **Zero magnets defined on Solar** [LIVE].
- Admin "Segments" screen: CSV export by type, excludes unsubscribed by default (checkbox to include).
- Unsubscribe: signed HMAC link, no login, in the compliance footer of every plugin email; flips `subscribed` to 0.

**What does not exist:** any nurture/drip sequence, broadcast sending, email templates/editor, any sync to an ESP, finer segments (by state, lead status, campaign, tier, converted vs not), or any automation triggered by lead/affiliate events beyond the hardcoded transactional emails.

**What is installed but unused/unwired:** FluentCRM is active on Solar with **0 subscribers, lists, campaigns, funnels, tags** and no code link to GAS [LIVE]. Hostinger Reach is also active; its configuration is unknown. The earlier decision (2026-09-08, Cary + Homes) was an external ESP, **Kit (ConvertKit) free tier via manual CSV export/import**, with Cary creating the Kit account himself; whether that account exists is **UNK**.

**Nurture "articles":** what exists is 3 published blog posts (roof readiness, off-grid/battery, savings) and the YouTube scripts. No email nurture copy or sequence exists in the repo. Nurture was discussed, not built.

**Proposed design (for Cary to decide; nothing built):**
1. Keep the three segments as base audiences, but treat them as different programs: prospects get a short educational series (savings, roof readiness, incentives, what happens after you submit); affiliates get activation then recruiting content; partners get operational mail only, no marketing.
2. Add lightweight sub-tags rather than new types: lead status (new/matched/completed), state, source campaign, "has downline".
3. Pick one engine. Cheapest path: FluentCRM (already installed) fed by GAS via its PHP API on contact creation (tags = contact_type + source). Alternative: Kit via the existing CSV export. Either way, GAS stays the source of truth for consent/unsubscribe, and unsubscribe must sync both ways.
4. Decide the open product question first: does unsubscribe also stop transactional program notices? Recommendation: no for account/payout notices, yes for anything marketing.

## C. Authority hierarchy

1. Cary's latest explicit instruction (chat or swap) on policy/money/copy.
2. Verified live behavior/state (site render, Solar DB, settings). Note: live page content and Elementor data exist only on the server, not in git.
3. Current repo `main` code (`GAS_VERSION` constant is the real version; the plugin header still says 1.0.0).
4. `STATUS.md`, then `ROADMAP.md` (known stale spots: agreement bullet, version header).
5. `SWAP-with-HOMES.md` and Gmail swap notes = historical, dated logs.

If two levels disagree, the higher wins and the lower gets corrected.

## D. Swap-space protocol
Claude Code ACKs the 10 points, with amendments: (a) the Solar draft covers the Solar project only; other sites stay out of it; (b) durable facts live in this file, not the draft; (c) every claim carries an evidence tag or date; (d) recipient marks ACK-SAFE-TO-TRIM; the sender never trims another party's unmarked handoff.

## E. Recommended first tests (real coverage gaps)
1. **Confirm the pool value with Cary**, then run one full chain end to end: referral from code 6 → lead completed → payout created; expect tier1 code 6, tier2 code 5, tier3 code 3, code 2 nothing, each rounded up. Never done post-rounding. Do it on staging or delete the test rows after.
2. Update the PHPUnit payout tests for rounding and run them on the server.
3. Chain-depth and stacking edges: a 4-level chain (code 2 must get nothing); `tier_stacking` flag with shared payout email.
4. Automated payout run: confirm the hPanel cron exists, then dry-run in sandbox; Wise sandbox transfer (never exercised).
5. Unsubscribe end to end (link, `subscribed` flip, Segments export exclusion) and email deliverability.
6. Logged-out merged Refer-a-Friend signup end to end (open item since 2026-09-08), including the sponsor notice with and without a `/join` cookie.
7. Cache/nonce behavior on the public signup and quote forms.
