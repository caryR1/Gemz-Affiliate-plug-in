# Gemz Affiliate Suite — Roadmap

Living status doc for the shared `gemz-affiliate-suite` plugin (powers both
solar.gemzonline.com and homes.gemzonline.com). Unlike `SWAP-with-HOMES.md`
(a running conversation log between the two sessions), this is meant to stay
current — update it in place as features land or plans change, rather than
appending entries.

Last written: 2026-09-10, by the Solar Referral session. Current
`GAS_VERSION`: 2.9.0 / `GAS_DB_VERSION`: 16.

**Same day, later**: shipped a three-part batch from a real discussion
Cary had with Homes — folded the Codes screen into Affiliates (its
link-provisioning job is gone now that campaigns handle that), added
held-affiliate notifications with deliberately NO dollar figures (an
FTC/income-claim caution Cary agreed with), and a genuinely automated
monthly payout run via real server cron (not WP-Cron). Building the
automated run surfaced and fixed a real, serious pre-existing bug:
`affiliates_with_unpaid_balance()` had zero date awareness and could have
swept a same-month, still-open sale into a batch payout. Also did a real
documentation pass across both Help shortcodes (`GAS_Help::render()`/
`render_partner_help()`) and the admin Help screen, closing a gap Cary
asked about directly — tax compliance, buyer cash back, self-referral,
marketing materials, the $50 threshold, and unsubscribing were all
genuinely undocumented until now. One item on the ask list — "a
notifications system (WhatsApp, custom SMTP, editable templates)" — does
NOT exist in GAS as far as this session can find (grepped the whole
plugin, zero matches beyond an unrelated "whatsapp" bot-UA string in the
fraud filter); flagged rather than documented, since it can't be real
without a Homes-side change this session hasn't seen. See "Automated
monthly payout run" below for the full fix and how it was verified.

**Since this was first written (2026-09-06)**: shipped self-signup
affiliates auto-matched to every partner marked "Open to self-signup" with
richer per-link dashboard cards (coverage line, blurb popover, spotlight
link, capability icons) on 2026-09-07 — then, at Cary's request after
comparing GAS against the more mature `gemz-referral-crm` (GRC), **replaced
that whole link model** with GRC's architecture: campaigns are now a real,
first-class, admin-managed entity (see "Campaigns" below), an affiliate has
exactly ONE stable code instead of one per partner, and a link is that code
+ a campaign's tracking slug combined at share time. The richer dashboard
cards carried over unchanged, now sourced per-campaign instead of per-code.
Also built the pure-logic PHPUnit suite this doc has been calling the #1
fragility item — see its own section below.

Same day, following a "step back and gap-check this against systems of its
type" review, shipped a second batch closing the gaps that review surfaced:
**tax compliance** (W-9/W-8BEN collection gating every payout, calendar-year
paid tracking, accountant-ready CSV export), a **$50 minimum payout
threshold**, **fraud filtering** (disposable-email/bot-UA/IP-rate-limit
checks, no paid API), a **marketing-assets facility** for affiliates
(reusing WP's media library), and a **compliance footer** on every
customer- and affiliate-facing email. Also added, later the same day: a
real **unsubscribe mechanism** across all three contact types ahead of
Cary hooking up an external ESP (Kit/ConvertKit free tier), and the
Segments CSV export now respects it.

**2026-09-09**: Cary reasoned through a real policy question — should
self-referral (one person as both affiliate and customer on their own
sale) be allowed — and reached a considered "yes, with a narrower guard
elsewhere" answer rather than a blanket "never" (see "Self-referral &
tier-stacking" below for the reasoning and what shipped: self-referral is
now allowed at click time, a non-blocking tier-stacking identity flag
covers the actual risk, and a **customer cashback claim flow** was built
from scratch — previously only a math placeholder existed, `cashback_paid`
was never actually set anywhere). Full detail in `SWAP-with-HOMES.md`
2026-09-09.

**2026-09-10**: Cary looked at the affiliate dashboard on staging and
flagged it as looking unstyled — real root cause, not a deeper gap: the
"Earnings by tier" and "Your team" tables used `widefat striped`, a
WordPress **admin-only** class with zero CSS on the public-facing site, so
they rendered as bare browser-default tables regardless of theme. Fixed
with a real design pass, not a minimal patch (Cary's call) — see
"Dashboard styling" below.

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
  per-visitor click dedup. Self-referral (an affiliate clicking their own
  `/go/` link) is cookied and counted normally as of 2026-09-09 — see
  "Self-referral & tier-stacking" below for why. The separate `/join/`
  recruiting-link guard (an affiliate can't sponsor-cookie themselves via
  their own recruiting link) is untouched.
- Two fulfillment modes per partner: `lead_capture` (routes to an on-site
  form) or a direct external `destination_url`.

**Self-referral & tier-stacking** (2026-09-09) — Cary's own reasoning,
worth preserving here since it drives the design: payouts only fire on a
partner-confirmed completed sale from a FIXED commission pool, so one real
person being both the affiliate and the customer on their own real
transaction doesn't cost the partner anything or manufacture new money —
it's a reallocation within a pool that was already fixed. The actual risk
is narrower: a sockpuppet SECOND account stacking an extra sponsor tier on
top of what's really one person's one transaction, extracting more from
the pool than a single-person transaction was ever budgeted for. Judged
low-probability, not worth a blocking system.
- `GAS_Payouts::compute()` now also returns `tier_stacking`: pairwise
  compares tier1/tier2/tier3 codes belonging to different `wp_user_id`s
  for a shared identity signal (payout email, PayPal email, Wise account
  number, tax ID, or signup IP — the last of these newly recorded at
  signup, `GAS_Payouts::META_SIGNUP_IP`, purely for this check). A match
  is audit-logged as `possible_tier_stacking` (both the wp-admin Calculator
  and the REST payout-creation path) and the payout proceeds regardless —
  admin reviews the audit log and acts manually on the rare real case.

**Customer cashback claim flow** (`class-gas-cashback.php`, 2026-09-09) —
previously `cashback_amount` was computed and stored on a payout row, but
`cashback_paid`/`cashback_paid_at` were schema columns nobody ever wrote
to; there was no way to identify or pay the customer at all. Now:
- The Payout Calculator (and REST `create_payout`) accept an optional
  `customer_email`. If cashback > 0 and an email is given,
  `GAS_Cashback::send_claim_email()` emails the customer a tokenized claim
  link.
- The link is a public, no-login page (`admin-post.php?action=
  gas_cashback_claim`) bound to that ONE payout row — a customer is never
  a WP user/account. Token is a deterministic HMAC (payout id + email,
  keyed on `wp_salt('auth')`), same pattern as
  `GAS_Contacts::unsubscribe_link()` — no separate token column or expiry
  bookkeeping. The customer picks PayPal/Wise/other, stored as JSON on the
  payout row itself (`cashback_payment_details`) since there's no user
  meta to attach to.
- Admin sees a masked payment summary + claim status on the Ledger, and a
  manual "Mark cashback paid" button (`cashback_paid`/`cashback_paid_at`)
  — matches how affiliate payouts are already manually marked paid outside
  the PayPal/Wise batch runs; cashback was deliberately NOT wired into
  those automated batch runs this pass.
- **Tax aggregation is now per-PERSON, not per-payment-type**:
  `GAS_Payouts::paid_this_calendar_year()` sums an affiliate's direct +
  tier-2/3 commission AND any cashback paid to their same email (matched
  case-insensitively) — since the same person can now legitimately receive
  both. Two separate sub-$600 buckets that together cross $600 would have
  been a real tax-reporting gap, not just a theoretical one.
- **Deliberately not built**: a tax-info gate on cashback itself. A pure
  customer (never also an affiliate) has no dashboard and no W-9/W-8BEN
  collection mechanism — if they cross $600/year in cashback alone with no
  affiliate account, nothing currently catches that. Real gap, out of
  scope for what was actually asked this pass (aggregation, not a new
  collection surface) — flagged in `SWAP-with-HOMES.md`.

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
- **Dashboard styling** (2026-09-10, `gas-frontend.css`): every section
  ("Your links," "Earnings by tier," "Your team," "Change password,"
  "Payment information," "Tax information") is now wrapped in a `.gas-panel`
  — a light tinted background (`--gas-accent-tint`) with the heading
  underlined in `--gas-accent`, giving each a distinct, visually separated
  card instead of bare headings running together. Real `<table>` styling
  (`.gas-table`/`.gas-table-wrap`) replaces the two data tables that had
  been using `widefat` — WordPress's admin-only table class, invisible on
  the front end regardless of theme, which was the actual root cause Cary's
  screenshot surfaced, not a deeper styling gap. New classes deliberately
  reuse the existing `--gas-accent`/`--gas-accent-tint` CSS variables
  (already how the capability icons and per-partner cards theme themselves)
  rather than hardcoding either site's palette, so a page-level accent
  override (like Solar's blue on its merged signup page) still cascades
  correctly. Visual language adapted from — not copy-pasted from — Home's
  own proven `.thb-panel`/`.thb-spec-table`/`.thb-stat-row` patterns
  (`homes.gemzonline.com/content/style.css`); adapted rather than reused
  verbatim since those are 2-column CSS-grid "spec rows," while GAS's
  tables are real multi-column `<table>` markup. Verified live on staging
  (screenshot, plus computed-style checks confirming the exact accent/tint
  hex values are actually applied) at both desktop and mobile widths;
  deployed to Solar too but not independently re-verified there (no real
  affiliate account exists on Solar yet to preview against) since it's the
  same file already visually confirmed on staging.
- **Same styling extended to the Partner Portal and Help pages**
  (`GAS_VERSION` 2.8.3, same day) — found a real, separate, pre-existing
  bug while doing this: `enqueue_assets()`'s shortcode check that decides
  whether to load `gas-frontend.css` at all only ever listed
  `gas_affiliate_signup`/`gas_affiliate_dashboard`/`gas_signup_or_refer` —
  `gas_partner_dashboard`, `gas_help`, `gas_partner_help`, and `gas_faq`
  were never in it, so the Partner Portal and every Help page had been
  rendering with **zero plugin CSS at all** since the day each was built,
  not just missing the new panel/table styling. Refactored into a single
  `GAS_Frontend::STYLED_SHORTCODES` array checked in a loop, specifically
  so a future new shortcode can't repeat this exact class of bug a third
  time (see the code comment there — this was the second time a
  stylesheet silently failed to load on a real page, the first being the
  2026-09-06 Elementor `post_content` gap). Partner Portal's ad-hoc
  `gas-portal-table` (inline `style=` attribute, no real styling) was
  replaced with the same `.gas-table`/`.gas-table-wrap` classes; its "Your
  leads" and "Change your password" sections now use `.gas-panel` too.
  Help pages (`GAS_Help::render()`/`render_partner_help()`) wrap each
  topic in its own `.gas-panel` (extended `.gas-panel`'s heading-underline
  rule to also match `h3`, since Help content uses that level, not `h2`).
  Verified live on staging (screenshot, both pages) — going from
  completely unstyled to fully styled confirmed the CSS was never loading
  there before this fix, not just looking plain.
- **Real caching bug found and fixed in the same pass**: Cary reported
  clicking the admin "View Dashboard" preview button and being told he
  wasn't an affiliate — reproduced directly (`wp eval` confirmed the
  preview transient, capability check, and `render_dashboard()`'s own
  logic all worked correctly in isolation), then found the real cause via
  `curl -D -`: `X-LiteSpeed-Cache: hit` on a second identical request —
  Hostinger's LiteSpeed Cache plugin was full-page-caching the Affiliate
  Dashboard and Partner Portal, both entirely per-user/per-session
  content, and serving the same cached response to every visitor
  regardless of login or preview state. Plain `nocache_headers()` alone
  did NOT stop this on this specific host — confirmed via repeated `curl`
  checks that the page kept flipping back to `hit`. The actual fix needed
  LiteSpeed Cache's own explicit API: `do_action(
  'litespeed_control_set_nocache', $reason )`, added alongside
  `nocache_headers()` in both `GAS_Frontend::render_dashboard()` and
  `GAS_Partner_Portal::render_dashboard()`. Verified with repeated `curl`
  checks showing `X-LiteSpeed-Cache-Control: no-cache` /
  `x-hcdn-cache-status: DYNAMIC` consistently across 4 back-to-back
  requests to each page (previously flipped to `hit` by the second
  request). The action call is a safe no-op if LiteSpeed Cache isn't
  active, so this is portable to any future host.

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

**Campaigns** (shipped 2026-09-08, `GAS_VERSION` 2.6.0 / `GAS_DB_VERSION` 14
— replaces the 2026-09-07 "one code per partner" model described in earlier
versions of this doc)
- `gas_campaigns` (admin-managed: name, partner, `tracking_slug`,
  `outreach_status`, `open_to_self_signup`, coverage/blurb/spotlight/
  capability-tag fields carried over from the old per-partner link cards)
  and `gas_campaign_variants` (alternate landing pages per campaign — see
  "Marketing collateral" below) are now first-class tables, not derived
  from partner rows.
- An affiliate has exactly ONE stable, campaign-agnostic `code` for life
  (`partner_id` on `codes` is unused/0 for self-signup codes). A shareable
  link is that code + a campaign's `tracking_slug` combined at share time
  (`GAS_Campaigns::build_link()`), resolved at `/go/{slug}?ref={code}
  &variant={id}` (`GAS_Redirect::handle_redirect()`).
- Two independent cookies: `gas_campaign_id` is set on any valid campaign
  link even with no `ref`; `gas_affiliate_code` is only set when `ref`
  resolves to a real code — so a campaign gets attribution credit even from
  a link an affiliate didn't personalize.
- `GAS_Campaigns::ensure_default_for_partner()` auto-provisions a partner's
  first default campaign the moment it's approved + open to self-signup —
  called from both the wp-admin partner-save handler and the REST
  partner-save route, so Home (REST/FTP-only) gets the same behavior as
  wp-admin.
- Dashboard link cards (coverage line, blurb popover, spotlight link,
  capability icons — same UI as the 2026-09-07 version) now source from
  campaigns instead of per-partner codes.
- REST: `gas/v1/campaigns` (GET list, POST create) and
  `gas/v1/campaigns/{id}` (POST update).

**Tax compliance** (shipped 2026-09-08, `GAS_VERSION` 2.7.0 /
`GAS_DB_VERSION` 15)
- W-9 (US) / W-8BEN (non-US) collection via the affiliate's own dashboard
  (`GAS_Frontend::render_tax_section()` / `handle_save_tax_info()`) —
  mirrors the existing banking-info pattern: affiliate-writes-only, admin
  only ever sees a masked summary (`GAS_Payouts::masked_tax_summary()`).
  **Not encrypted at rest** (matches the existing unencrypted banking-field
  precedent) — flagged as a fragility item below, worth revisiting at
  higher volume.
- Gated at every payout, not just at the $600/year IRS threshold (simplest
  safe reading — avoids a partial-year tracking edge case): PayPal and Wise
  batch runs now split affiliates into `eligible` vs. `held`, with a
  `reason` of `no_tax_info` (checked first) or `below_threshold`.
- `GAS_Payouts::paid_this_calendar_year()` sums direct + tier-2/3 amounts
  by calendar year, using new `tier2_paid_at` / `tier3_paid_at` columns
  (added because the original `paid_at` only reflected the direct
  affiliate's payment time, not a sponsor's own).
- Admin CSV export (Ledger page, "Tax summary for your accountant," any
  year back to -4) — one row per affiliate with a nonzero year total: name,
  email, total paid, form type, legal name, tax ID, country, submitted-at.
  **Contains unmasked SSN/EIN — handle as sensitive data.** Accountant-ready
  only, not an IRS e-filer — actual 1099-NEC filing is out of scope, goes
  through Cary's accountant or a service like Track1099/Tax1099.

**Minimum payout threshold** — `min_payout_threshold` Setting (default $50).
PayPal/Wise batch runs skip anyone under it (held with reason
`below_threshold`, unless also missing tax info); their balance just
carries forward to the next run.

**Fraud filtering** (`class-gas-fraud.php`, transient-based, no paid API)
- Disposable-email domain check (~24-entry hardcoded list) on both signup
  paths.
- Bot User-Agent filtering (~20-substring hardcoded list) on click logging
  — a bot-UA click is silently skipped, not counted, but the redirect still
  happens.
- IP rate-limiting: max 5 signups/IP/day, max 20 clicks/IP/campaign/day —
  both on top of (not replacing) the existing per-visitor-per-day click
  dedup.
- Explicitly out of scope for this pass: IP-intelligence/datacenter-VPN
  detection (needs a paid API) — a possible future item.

**Marketing collateral for affiliates**
- `class-gas-marketing-assets.php`: a simple media-attachment facility
  reusing WP's native media library (`wp_enqueue_media()` +
  `wp.media()` picker, no custom uploader) — admin uploads an image scoped
  globally, to one partner, or to one campaign; affiliates see whatever
  applies to their approved partners/campaigns on their own dashboard.
- Alternate landing pages: already had a home in `gas_campaign_variants`
  (Campaigns, above) — this batch's contribution was mostly the affiliate-
  facing UI surface on top of what already existed, not new data-model
  work.

**Compliance footer** — `GAS_Settings::compliance_footer()` (business
name/address, one-line reason-for-contact, program-terms link) appended to
every customer-facing AND affiliate-facing `wp_mail()` call plugin-wide.
Added as an immediate retrofit onto existing hardcoded email bodies (GAS
has no notification-template system yet) rather than waiting for that to
be built — confirmed directly against GRC's own `default_templates()` that
it has zero compliance notice today, so this wasn't a redundant add.

**Payout automation**
- PayPal Payouts: one batch per "pay now" click covering every affiliate on
  the `paypal` method with an unpaid balance.
- Wise: one transfer per affiliate (no true batch API), ABA or IBAN, each
  affiliate's failure reported independently rather than blocking the rest.
- Both triggerable manually (`gas_paypal_payout_now` / `gas_wise_payout_now`,
  Payout Ledger screen) AND automatically — see "Automated monthly payout
  run" below.
- Admin can also just use the Payout Calculator + Ledger (with CSV export)
  and pay manually outside either API.

**Automated monthly payout run** (2026-09-10, `GAS_REST::
run_automated_payout()`) — fires both PayPal and Wise batch runs on a
schedule, per Cary's three hard requirements:
- **Pause toggle** (Settings screen, `payout_run_paused`) — a simple on/off
  that skips the run entirely, "in case we run into a problem" (Cary's own
  framing, deliberately not a more complex per-cycle workflow).
- **Real server-level cron, not WP-Cron** — WP-Cron only fires on site
  traffic and can silently slip, unacceptable for something moving real
  money on a schedule. A token-authenticated REST endpoint
  (`gas/v1/automated-payout-run?token=...`) is hit by an actual Hostinger
  cron job configured in hPanel (this host has no SSH crontab access, so
  that's Cary's own one-time setup step — the exact URL to paste is shown
  live on the Payout Ledger screen, with a "Regenerate token" action if it
  ever needs rotating). The endpoint is safe to hit daily: it only actually
  fires on/after the configured day of the month (`payout_run_day`,
  default the 5th — Cary's reasoning: the previous month closes on the
  1st, days 1-4 are the admin's window to fix any holds first, and running
  on the 5th means affiliates see their money within the first week) and
  at most once per calendar month (tracked via `gas_last_automated_payout_run`),
  so a daily cron schedule is simplest and can't double-pay.
- **Only pays out CLOSED prior months** — a real, serious pre-existing bug
  fixed as part of this: `affiliates_with_unpaid_balance()` had zero date
  awareness, so a sale entered on, say, the 3rd of a new month could have
  been swept into a batch run firing on the 5th, even though that's the
  current still-open month's earnings, not a closed prior month owed to
  the affiliate yet. Fixed with a new `GAS_Payouts::
  closed_month_unpaid_balance()` (same closed-months boundary the
  dashboard's own pending-vs-finalized split already used) feeding
  eligibility, AND — easy to miss, would have silently defeated the whole
  fix otherwise — `mark_affiliate_paid()` itself needed the identical
  boundary added to its UPDATE queries, since it was previously marking
  ALL of an affiliate's unpaid rows paid regardless of month once a batch
  API call succeeded. Applies identically whether triggered by the
  automated run or the existing manual "Pay All" buttons.
- **Held-affiliate notifications** (`GAS_Payouts::notify_held_affiliates()`)
  — a real gap before this: `affiliates_with_unpaid_balance()`'s held
  reasons (`no_tax_info`/`below_threshold`) reached the admin in the
  batch-run summary, but never told the affected affiliate anything.
  Fixed with a plain `wp_mail()` per held reason (GAS has no notification-
  templating system yet — that's still unstarted "Part 3" of the original
  GRC port, so this is a hardcoded body, same pattern as every other
  notification in the plugin, not a new templating layer). **Deliberately
  NO specific dollar figures anywhere** — not their balance, not the
  threshold amount — after Cary agreed with an FTC/income-claim caution;
  copy is encouraging-generic only ("keep sharing your link," "recruit
  your team"). A per-user-per-reason-per-day transient prevents a
  double-send if a manual "Pay Now" is clicked more than once same day.
- **A third instance of the LiteSpeed page-caching bug was found and fixed
  building this** (see the dashboard/portal fix earlier the same day): the
  automated-payout-run endpoint itself was getting cached by LiteSpeed,
  which would have made a real server cron serve back its FIRST response
  forever after — including "already ran this month," silently stopping
  the automated payout from ever running again once it hit that state
  once. Fixed with the same `nocache_headers()` +
  `litespeed_control_set_nocache` pair.
- **Verified live on staging**, not just code-reviewed: confirmed via
  direct DB inspection that the month-boundary fix correctly excludes/
  includes rows (a backdated August-entered row correctly counted, a
  same-day September row correctly didn't); confirmed `mark_affiliate_paid()`
  left a same-day unpaid row untouched while correctly marking the
  backdated one paid; hit the real REST endpoint end-to-end (wrong token
  → 403, day-gate skip, pause skip, a real un-paused execution that
  correctly found zero eligible affiliates since all live staging data was
  current-month, then correct once-per-month idempotency on a second hit);
  confirmed the held-notification copy contains no `$` anywhere via direct
  inspection. No live PayPal/Wise send was exercised (deliberately —
  staging's live data was all current-month, so nothing was eligible to
  actually pay during this test pass).

**Admin backend** (`class-gas-admin.php`, one submenu per screen): Affiliates
(now includes a "Codes" section for manually adding/auditing offline-referral
codes — dropped as its own top-level menu item 2026-09-10 since its original
job of matching a new affiliate's code to a partner is gone now that
campaigns auto-provision that), Partners, Leads, Click Log, Reports, Payout
Calculator, Payout Ledger, Audit Log, Segments, Lead Magnets, Settings, Help.

**Reports — this already exists**, contrary to it being flagged as a gap:
Commission Summary (unpaid/paid tier-1, cashback total, tier-2/3 override
total, net-to-Cary), Partner Outcomes (leads/completed/lost/close-rate per
partner), Agent/Referrer Performance (clicks/conversions/conversion
rate/total earned, ranked by earnings). See "Known gaps" below for what it's
missing.

**Contacts / CRM**: one directory across affiliates, customers, and
partners, tagged by type on first sight and never silently reclassified;
lead magnets with a honeypot for spam, CSV export, reassignment. **Real
unsubscribe mechanism** (added 2026-09-08, ahead of Cary hooking up an
external ESP — Kit/ConvertKit free tier, manual CSV export/import, no
plugin-side broadcast-sending or ESP API integration built): a public,
no-login endpoint (`GAS_Contacts::handle_unsubscribe()`, token is a
deterministic HMAC over the email via `wp_salt('auth')` — no DB storage,
no expiry) flips a contact's `subscribed` to 0; `GAS_Settings::
compliance_footer( $email )` appends the link to every customer-,
affiliate-, AND partner-facing `wp_mail()` call (the partner-facing "New
lead" email had been the one deliberate exception until Cary confirmed he
wants it broadly, not just on two of the three types). The Segments CSV
export now defaults to excluding unsubscribed contacts (an "Include
unsubscribed" checkbox opts back in) so an unsubscribed contact can't get
silently re-subscribed on import into Kit. **Deliberately not done**:
suppressing the underlying transactional sends themselves for an
unsubscribed contact (welcome email, "you've been matched," "new lead
assigned") — those are core-function program notices, not marketing
content, and blocking them could cut an active affiliate off from their
own account status; flagged as a real product question for Cary rather
than assumed either way.

**Roles & security**: `gas_affiliate` (front-end only), `gas_partner`
(own-leads only), `gas_manager` (full run of every plugin screen, explicit
deny-list for `manage_options`/user-management/plugin-and-theme caps so a
manager can never escalate past the affiliate program itself). Banking
details are only ever written by the affiliate's own dashboard form —
nothing admin-facing can write them, only read a masked summary.

**REST API** (`gas/v1`): partners (+ research-batch bulk import), settings,
codes, affiliates (includes masked `tax_summary` per affiliate), leads,
payouts, campaigns, flush-rewrite-rules. This is Home's only write path (no
wp-admin login there), so anything not in a REST route or missing from an
allowlist is invisible to that side — see gaps below. No REST CRUD for
marketing assets (deliberate — image upload is awkward over pure JSON REST,
and Cary himself has wp-admin access to supply images there).

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
- ~~No A/B testing or campaign-level tracking beyond a flat referral
  code~~ — **resolved 2026-09-08** by the Campaigns architecture (see
  above): `gas_campaign_variants` gives per-campaign alternate landing
  pages, and clicks/conversions now attribute to a campaign independent of
  which affiliate's code was used.
- **No affiliate-agreement acceptance tracking yet.** `DRAFT-affiliate-
  agreement.md` (repo root) is a generic starting draft — explicitly not
  legal advice, has bracketed placeholders, needs Cary's/an attorney's
  review before it's binding. The planned follow-up (an acceptance
  checkbox + timestamp captured at signup) is deliberately NOT built yet —
  holding until Cary confirms the actual text, so nothing gets built
  against placeholder legal language.
- **No 1099-NEC e-filing.** The new tax-summary CSV export (see "Tax
  compliance" above) is accountant-ready, not a filer — actual filing goes
  through Cary's accountant or a service like Track1099/Tax1099.
- **No IP-intelligence/datacenter-VPN detection.** The 2026-09-08 fraud
  pass covers disposable-email, bot-UA, and IP-rate-limiting, all with no
  paid API; real IP-intelligence needs one and was explicitly scoped out
  of that pass — a possible future item if fraud volume justifies the
  cost.

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
   present, unfixed, on Home.** Same failure mode is latent there. A
   second AND THIRD distinct LiteSpeed-caused bugs hit 2026-09-10, same
   day: full PAGE caching (not the object cache) served a stale,
   wrong-for-the-viewer response on the Affiliate Dashboard and Partner
   Portal, then a third time on the automated-payout-run REST endpoint
   itself (would have made a real cron serve its first response forever
   after). All three fixed via `litespeed_control_set_nocache` (see
   "Dashboard styling" and "Automated monthly payout run" above), but
   nothing systematically audits every OTHER per-user or nonce-bearing
   shortcode page in the plugin (signup forms, lead-capture, cashback
   claim) for the same risk — a signup/lead-capture page being cached
   would surface as a silently-expired nonce ("Security check failed")
   rather than wrong content, a subtler failure mode worth a deliberate
   pass rather than assuming `nocache_headers()` alone is protecting them,
   or that three is the last instance of this bug class on this host.
7. **Tax info (SSN/EIN) is stored in plaintext user-meta, unencrypted** —
   deliberately consistent with the existing (also unencrypted) banking-info
   fields rather than a new inconsistency, but disclosed here as a real gap
   worth revisiting once affiliate volume makes it a bigger target.
8. **No CI, but staging (2026-09-08) meaningfully changes this one.**
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
