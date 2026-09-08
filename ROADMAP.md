# Gemz Affiliate Suite — Roadmap

Living status doc for the shared `gemz-affiliate-suite` plugin (powers both
solar.gemzonline.com and homes.gemzonline.com). Unlike `SWAP-with-HOMES.md`
(a running conversation log between the two sessions), this is meant to stay
current — update it in place as features land or plans change, rather than
appending entries.

Last written: 2026-09-08, by the Solar Referral session. Current
`GAS_VERSION`: 2.5.0 / `GAS_DB_VERSION`: 13.

**Since this was first written (2026-09-06)**: shipped self-signup
affiliates auto-matched to every partner marked "Open to self-signup"
(multiple pre-matched codes per affiliate instead of always landing
unmatched), a self-serve "get a link" button on the affiliate dashboard for
partners that opened up after signup, and richer per-link dashboard cards
(coverage line, blurb popover, spotlight link, capability icons) — see
`SWAP-with-HOMES.md` 2026-09-07 for full detail. Also built the pure-logic
PHPUnit suite this doc has been calling the #1 fragility item — see its own
section below.

## What it does today (verified against the actual code, not memory)

**Signup & referral**
- Self-service affiliate signup (`[gas_affiliate_signup]`) and a merged
  signup-or-refer toggle (`[gas_signup_or_refer]`) that lets one page do
  both jobs.
- Recruiting links (`/join/{code}`) set a sponsor cookie distinct from the
  customer-referral cookie (`/go/{code}`), so a visitor can carry both at
  once.
- Existing-email reuse on the merged form deliberately never auto-logs
  someone in or touches their password — it attaches the referral and
  emails the real account holder instead (anti-account-takeover by design).
- Email verification (`generate_verify_token` / `handle_verify_email`).

**Click tracking & attribution**
- `/go/{code}` redirect: last-touch attribution (180-day cookie), per-day
  per-visitor click dedup, self-referral guard (an affiliate clicking their
  own link isn't cookied or counted).
- Two fulfillment modes per partner: `lead_capture` (routes to an on-site
  form) or a direct external `destination_url`.

**Commissions (the real money logic, in `class-gas-payouts.php`)**
- `GAS_Payouts::compute()` is the single source of truth, used by both the
  admin Payout Calculator and REST payout creation.
- Gross commission (flat, percent-of-sale, or installment-based), minus
  buyer cashback, minus a 3-tier affiliate split — all computed against an
  `agent_pool` that can be less than full gross (house margin absorbs the
  rest automatically).
- Tier split is a fixed global percentage (default 70/20/10), applied only
  to tiers that actually have someone in them — payout never grows with
  chain depth.
- Affiliate-facing "estimated earnings" range is deliberately generic
  (across all approved partners, not personalized to one affiliate's real
  chain) so affiliates can't back-calculate real partner margins.

**Affiliate dashboard** (`[gas_affiliate_dashboard]`)
- Unpaid/paid totals split into direct cut vs. tier-2/3 overrides.
- Current-month pending estimate vs. prior-months' finalized exact totals.
- Downline/team view, payment-info form (PayPal / Wise / "other" with free
  text), self-service password change.

**Lead capture & partner matching**
- `[gas_lead_form]` submission → lead row, always starts unmatched.
- Admin manually assigns a partner (`GAS_Leads::assign_partner()`) with a
  proposed/backup appointment time; if the partner `requires_appointment`,
  this sends the customer a proposed-appointment email at assignment time
  (not at initial submission, since neither the partner nor the requirement
  is known until an admin matches it).
- Daily cron (`gas_daily_stale_lead_check`, re-armed on every request since
  WP-Cron's schedule doesn't survive a plain file re-upload) auto-flags
  leads untouched for 5+ days as `stale` and emails the admin.
- Out-of-area handling: `partners.state` (comma-separated codes, e.g.
  `FL,TX,GA,CA`) is matched against a referred customer's captured
  city/state; no compatible partner triggers a notification rather than
  silently dropping the lead.
- Partner-renegotiation milestone: fires once when a partner hits its 3rd
  completed payout.

**Partner Portal** (`[gas_partner_dashboard]`)
- Partner login, sees only their own leads (looked up by logged-in user id,
  never a posted id), can move a lead through
  accepted → in_progress → completed/lost.
- Admin "preview as this partner" mode is read-only (transient-based, no
  real row anywhere it could pollute).
- Partner accounts are provisioned automatically the first time a partner
  is saved with an email (`GAS_Roles::provision_partner_account()`) — links
  to an existing WP user by email if one exists, otherwise creates one and
  sends WP's standard set-password email. **There is no partner
  self-signup** — this always starts from an admin creating/editing a
  partner record first. (Don't confuse this with affiliate self-signup,
  which now auto-matches to open partners — see below. Onboarding a new
  *partner* is still always an admin action first.)

**Multi-partner self-signup & richer link cards** (shipped 2026-09-07,
`GAS_VERSION` 2.5.0 / `GAS_DB_VERSION` 13)
- New partner field `open_to_self_signup` (checkbox, defaults on for
  existing partners). A self-signup affiliate now gets one pre-matched
  code per `outreach_status='approved' AND open_to_self_signup=1` partner
  at signup, instead of always landing with a single unmatched code —
  falls back to the old unmatched-code behavior when nothing's open.
- Affiliates who joined before a partner existed/opted in get a self-serve
  "Get a link for [partner]" button on their own dashboard
  (`GAS_Frontend::handle_get_partner_link()`) rather than waiting on an
  admin to notice and manually match them.
- Each dashboard link card now shows a "Serves: FL, TX, ..." coverage line
  (from the existing `state` field), a tap-to-reveal blurb popover (new
  `blurb` field), a "See full spotlight" link (new per-site `spotlight_url`
  field), and capability icons from a curated 11-tag list (new
  `capability_tags` field, checkboxes on the Partners screen, core
  Dashicons, tap/click reveals the label — no persistent legend).
  "Appointment required" is derived from the existing
  `requires_appointment` field rather than duplicated as a 12th tag.

**Payout automation**
- PayPal Payouts: one batch per "pay now" click covering every affiliate on
  the `paypal` method with an unpaid balance.
- Wise: one transfer per affiliate (no true batch API), ABA or IBAN, each
  affiliate's failure reported independently rather than blocking the rest.
- Both are manual triggers (`gas_paypal_payout_now` / `gas_wise_payout_now`)
  — nothing runs these on a schedule.
- Admin can also just use the Payout Calculator + Ledger (with CSV export)
  and pay manually outside either API.

**Admin backend** (`class-gas-admin.php`, one submenu per screen): Affiliates,
Codes, Partners, Leads, Click Log, Reports, Payout Calculator, Payout Ledger,
Audit Log, Segments, Lead Magnets, Settings, Help.

**Reports — this already exists**, contrary to it being flagged as a gap:
Commission Summary (unpaid/paid tier-1, cashback total, tier-2/3 override
total, net-to-Cary), Partner Outcomes (leads/completed/lost/close-rate per
partner), Agent/Referrer Performance (clicks/conversions/conversion
rate/total earned, ranked by earnings). See "Known gaps" below for what it's
missing.

**Contacts / CRM**: one directory across affiliates, customers, and
partners, tagged by type on first sight and never silently reclassified;
lead magnets with a honeypot for spam, CSV export, reassignment.

**Roles & security**: `gas_affiliate` (front-end only), `gas_partner`
(own-leads only), `gas_manager` (full run of every plugin screen, explicit
deny-list for `manage_options`/user-management/plugin-and-theme caps so a
manager can never escalate past the affiliate program itself). Banking
details are only ever written by the affiliate's own dashboard form —
nothing admin-facing can write them, only read a masked summary.

**REST API** (`gas/v1`): partners (+ research-batch bulk import), settings,
codes, affiliates, leads, payouts, flush-rewrite-rules. This is Home's only
write path (no wp-admin login there), so anything not in a REST route or
missing from an allowlist is invisible to that side — see gaps below.

## Known incomplete, stubbed, or planned

- **"Reporting"** (named by Cary as a pipeline item) — partially a
  misconception: real reporting exists (see above). What's actually
  missing: no date-range filtering (everything is all-time), no CSV/export
  on the Reports screen itself (only Ledger and Contacts export), no
  trend/time-series view (month-over-month), no per-affiliate or
  per-partner drill-down page.
- **"Scheduling"** — most likely means finishing the appointment flow
  properly. What exists: a `requires_appointment` flag, a proposed/backup
  datetime captured at partner-assignment time, and a one-way email to the
  customer. What's missing: no calendar integration (no .ics attachment, no
  sync to Google/Outlook), no customer-facing confirm/decline or
  reschedule, no reminder email as the date approaches, no view of
  scheduled appointments as a calendar/list anywhere in wp-admin.
- **No scheduled/recurring payouts** — PayPal and Wise payout runs are
  always a manual button click; there's no "pay everyone on the 1st"
  automation.
- **No partner self-signup** — onboarding a new partner is always an admin
  action first; at higher partner volume this is a manual bottleneck. (Not
  to be confused with the affiliate-side auto-matching shipped 2026-09-07 —
  that's a different gap, still open.)
- **No SMS/text notifications** — every notification path is `wp_mail()`
  only.
- **Automated test coverage: built, run for real, currently green.**
  Resolved 2026-09-08. Cary's hosting does include SSH — Home got
  credentials, uploaded the suite to staging (it isn't part of the normal
  plugin deploy set), ran `composer install && vendor/bin/phpunit` for
  real over SSH, and got 25 tests / 51 assertions / 6 failures, all 6 of
  which were test-authoring bugs (float-precision `assertSame()` calls and
  an int-vs-float type mismatch from PHP's `min()`), not payout-math bugs —
  the actual business logic was correct on every case written. Fixed all 6
  (switched to `assertEqualsWithDelta()` for computed floats) and
  re-verified over SSH myself: **25 tests, 55 assertions, all green.**
  (55 not 51 — the 4 fixed `EstimatedPayoutRangeTest` methods each had a
  second assertion that never ran before, since the first failure halted
  the test.) This now really is a safety net for the money math, not just
  a carefully-reasoned-through one. See `tests/` (`PayoutMathTest`,
  `CoverageMatchingTest`, `EstimatedPayoutRangeTest`) — covers
  `GAS_Payouts::agent_pool_amount()` + the tier-split arithmetic in
  `compute()`, `GAS_Frontend::estimated_payout_range()`, and
  `partner_covers_state()`, with cases pinned to Go Solar Power's real
  numbers ($2,000 flat / $700 pool / $490-$140-$70 split) as a sanity
  anchor. Re-run with `composer install && composer test` (or
  `vendor/bin/phpunit`) in `wp-plugin/gemz-affiliate-suite/` on any
  environment with SSH/PHP-CLI going forward — Solar's own Claude Code
  environment still doesn't have one, so re-verifying after a future
  change to this math needs staging (or wherever Cary's SSH access
  reaches) rather than being runnable from here directly.
  
  Separately: Home also ran a REAL signup through the actual form on
  staging (2026-09-08) and confirmed Part 1/2's self-signup auto-matching
  and dashboard features work correctly end-to-end on live
  infrastructure — genuine functional verification of the shipped
  feature, complementary to but distinct from the PHPUnit run above.
- No A/B testing or campaign-level tracking beyond a flat referral code.

## What's actually fragile right now

Ranked by what would hurt most if development speed goes up:

1. **Resolved 2026-09-08 — closing this out rather than renumbering
   everything below.** The money-math tests are now built, run for real
   over SSH on staging (Cary's hosting does have it), and green: 25 tests,
   55 assertions. 6 initial failures were all test-authoring bugs (float
   precision / int-vs-float assertion mismatches), fixed and re-verified.
   No longer the top risk — kept here as a record that it was, and how it
   got closed, since the next fragility item down inherits the #1 slot in
   spirit. See the "Automated test coverage" entry above for full detail.
2. **Staging exists (2026-09-08) and is proving its worth.** Both a
   real-signup functional verification of Part 1/2 (Home, on the actual
   form) and the PHPUnit run above happened there. What it still doesn't
   change: Solar's own Claude Code environment has no PHP CLI of its own,
   so re-verifying this math after any future change to it needs staging
   (or Cary's SSH) rather than being runnable from here directly — a
   smaller, more specific gap than "no staging exists" used to be.
3. **The REST settings allowlist is hand-maintained and already caused one
   real bug** (`conversion_noun` had a wp-admin field but was missing from
   `class-gas-rest.php`'s `update_settings()` allowed array, silently
   blocking Home — who has no wp-admin login — from ever setting it). Any
   new Settings field needs its own explicit permission-checked matching
   REST entry, or the field is effectively wp-admin-only forever. Worth
   eventually deriving the allowlist from `GAS_Settings::defaults()` keys
   instead of a manually kept-in-sync list, so this class of bug can't
   recur.
4. **Deploys are per-file, manual FTP transfers with no version check.**
   Nothing on either site confirms a deploy actually landed the intended
   file set (an earlier cross-session exchange chased what looked like a
   missing hook registration — that particular case turned out to be a
   grep miss, not a bad deploy, but nothing in the current process would
   have caught it if it *had* been a partial/stale upload).
5. **WP-Cron reliance for anything scheduled.** The daily stale-lead check
   only fires on real site traffic — a quiet site delays it silently, no
   error, no notification that it didn't run. Any future cron-based feature
   (e.g. scheduled payouts) inherits the same weakness unless it's built to
   self-check.
6. **Object-cache/Redis drop-ins already caused one production bug**
   (LiteSpeed Cache's Redis object-cache.php masking page edits on Solar,
   fixed by deactivating the plugin) **and the same drop-in is confirmed
   present, unfixed, on Home.** Same failure mode is latent there.
7. **No CI, but staging (2026-09-08) meaningfully changes this one.**
   Previously a change was tested live on whichever site's session
   deployed it first — now there's a real staging tier to deploy and
   click-test on before touching Solar or Home. Doesn't eliminate the gap
   (still no automated pipeline, still relies on someone remembering to
   use staging first, and the smoke-check formalization from the
   testing-strategy decision still isn't written down as an actual
   checklist/script), but it's a real improvement over "no tier at all."

## Next concrete steps (proposed order)

1. ~~Get the PHPUnit suite actually run once~~ — **done 2026-09-08.**
   Cary's hosting does have SSH; Home ran it, found 6 test-authoring bugs
   (not math bugs), fixed and re-verified: 25 tests, 55 assertions, green.
2. Write down the ad-hoc REST/FTP smoke-check as an actual checklist or
   small script (approved, not started) — closes part of #7.
3. Decide whether "reporting" needs date-range/export/trend work now, or
   whether the existing screen is good enough for current volume.
4. Decide whether "scheduling" means finishing calendar-grade appointment
   handling now, or whether the current one-way email is good enough short
   term.
5. Now that staging exists, make it the default first stop for any new
   feature before Solar or Home — Home's real-signup verification of
   Part 1/2 is the model to repeat, not a one-off. Same for any future
   change to the payout math specifically: re-run the PHPUnit suite on
   staging (or wherever Cary's SSH reaches) before considering it done.

Everything else above is tracked but not prioritized — flag if any of it
should jump the queue.
