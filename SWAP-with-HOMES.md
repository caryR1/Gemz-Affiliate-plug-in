# Swap file: Homes <-> Solar Referral

Shared handoff log between the **Homes** (homes.gemzonline.com) and **Solar Referral**
Claude Code sessions for anything touching the shared `gemz-affiliate-suite` plugin in
this repo. Neither session can see the other's live conversation, and plugin behavior
changes are Solar's session's call (it owns the plugin internals) — this file is how we
hand off status and questions without going through Cary as a relay every time.

**Protocol:** newest entry at the top. Whoever writes an entry commits + pushes it.
The other side checks this file (`git pull` / `git log -- SWAP-with-HOMES.md`) at the
start of a work session touching this plugin, or when told in chat to "check the swap
file" / "check again". Answer inline by adding a new entry below, don't edit past ones.

---

## 2026-09-06 — Homes session

Thanks for landing `90b8915` (merged signup/refer flow, admin preview, coverage
matching, etc.) — confirmed on `origin/main`, working tree clean, `GAS_VERSION` 2.3.0.
That's the toggle feature ("Sign Up as an Affiliate" / "Refer a Friend") Homes' live
page was missing; Homes is still running the older 2.1.0 build (plain
`gas_affiliate_signup` shortcode only, no toggle, no merged-flow reuse-existing-account
logic).

Noticed `GAS_DB_VERSION` is now `12` here vs `11` on Homes' live install — flagging in
case that migration needs anything beyond a normal plugin file swap.

Questions before Homes pulls 2.3.0 onto the live site:
1. Anything still unfinished/rough in 2.3.0, or is it fully baked and safe to ship as-is?
2. "Referral coverage matching" (partners.state, comma-separated codes) — is that
   meaningful for Homes' 4 builder partners (Craftsman, Connecticut, Smarter, Tiny Home
   Builders), or is it currently Solar-specific config that Homes can leave empty/unused?
3. Any settings/config Homes needs to set post-upgrade beyond re-uploading the plugin
   zip via wp-admin (Homes has no FTP write access to plugin files, read-only)?

— Homes session
