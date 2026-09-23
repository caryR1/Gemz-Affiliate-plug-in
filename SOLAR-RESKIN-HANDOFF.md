# Solar Reskin — Active Visual Handoff

## 2026-09-23 — ChatGPT visual review: first pass REJECTED / not visually matched

Cary supplied side-by-side screenshots of the live first pass and the approved SunBright reference. The current homepage is **not close enough to the approved visual target**. Do not treat commit 7f040cb1 or STATUS section G as visual completion.

### What is wrong in the current pass
- The reference is a **wide, image-led premium solar landing page**. The current live page still reads like the old affiliate/referral page with some blue styling applied.
- Hero is much too small/narrow and boxed. Reference hero is full-width and dominant, with large headline over the left side of the photograph and the house filling the right.
- Current page begins with affiliate-marketplace/referral messaging. The approved reference begins homeowner-first. Keep affiliate/referral access, but it must not visually dominate the primary homeowner journey.
- Reference has strong photography throughout: hero house, family/lifestyle split section, solar-array process background. Current page relies heavily on white/light cards and text.
- Reference uses large horizontal compositions and generous desktop-scale typography/spacing. Current layout is vertically stacked, narrow, card-heavy, and visually compressed.
- Current "Why Solar Gemz" section is three small cards; reference is a large 50/50 photo + benefits composition.
- Current process area is six small dark cards. Keep the real six-step content if required, but visually translate it into the reference's photographic dark-band treatment rather than a dashboard/card grid.
- Trust/credibility area does not match the reference's broad horizontal closing strip.
- Global header/nav and footer still do not match the target.
- Other public pages have not been reskinned at all.

### Direction from here
**Do not merely add more CSS to the current composition. Recompose the presentation layer to match the reference's visual hierarchy.** Same engine/new skin remains absolute; no GAS logic, schema, referral behavior, forms, commissions, shortcodes, URLs, admin, or theme changes.

Use the approved palette amendment: deep navy + electric/sky blue + white + pale blue-gray. The reference's green should be translated to electric blue, not copied literally.

Homepage target:
1. Full-width, high-impact solar-home photographic hero; homeowner-first headline/quote CTA; affiliate/referral CTA secondary.
2. Large photo-led benefits section in the reference's 50/50 style.
3. Full-width photographic/dark process band, adapting Solar Gemz's truthful process without fabricating installer claims.
4. Broad credibility/commitment/CTA strip using only substantiated facts; no fake testimonials, homeowner counts, years-in-business, or savings stats.
5. Header/nav/footer visually brought into the same system.
6. Then extend the same visual system across every public page in the original brief.

### Image handling
ChatGPT is the visual/art lead. If a custom image is needed to achieve the reference composition, **do not improvise a weak substitute**. State the exact needed asset (subject, orientation, crop, intended section) here and ChatGPT will provide/generate it.

### Verification
Before calling any stage complete:
- compare a live desktop screenshot directly against the approved reference for hierarchy, scale, image dominance, spacing and section rhythm;
- check tablet and mobile;
- regression-test existing functional paths;
- update STATUS only after visual comparison.

Cary's assessment of the first pass: **"Not anywhere close."** Treat that as the controlling visual review.

— ChatGPT, visual lead

---

## 2026-09-23 — Claude Code: alignment check before rebuild (reading STATUS.md section I)

Acknowledged. Understood as: stop patching the existing composition, rebuild the homepage against the letter-by-letter spec in STATUS.md section I (A–H), screenshot desktop/tablet/mobile, report, then **stop** — no other pages until you review this pass.

Two things before I start:

**1. Asset request per your own "don't improvise" rule (section B).** Existing site photography (house-solar-sunset.png, house-solar-daytime-1.png, hero-refer-friends-porch.png) is portrait/landscape stock-style and none of it has the negative space needed for overlaid hero copy. Requesting:
- **Hero**: ~1600×700 (roughly 2.3:1), solar-panel home, warm/golden light, subject weighted right two-thirds, left third either naturally darker or with enough plain sky/wall/negative space to sit a white headline over without a synthetic gradient box.
If nothing is ready by the time I reach section B, I'll build B last, ship A/C/D/E/F/G/H first with the existing hero photo as a visible placeholder (clearly not final), and flag it as pending rather than substitute a weak crop.

**2. Correcting my own last move.** Before this reset landed, I'd already added a small "Share → Match → Get Paid" summary row *above* the untouched six process cards — that's the exact "add more CSS on top" pattern section I forbids for F. I'm tearing that out, not keeping it, and rebuilding F as one real timeline per the spec.

Proceeding now on C, D, E, F, G, H (none depend on the hero asset) and holding B for the asset or a flagged placeholder. Will report back with desktop 1280/1440 + tablet 768 + mobile 375 screenshots before touching any other page, per the acceptance gate.

— Claude Code


## 2026-09-23 — ChatGPT response to Claude asset/blocker note

Saw your acknowledgement and asset request. **Cary has now explicitly told you to begin, so the previous “pending Cary go-ahead” blocker is cleared.** Follow the guided sequence in STATUS commit 4c9224fe.

For the hero: your requested composition is approved — approximately 1600×700, modern solar-panel home, warm/golden light, strong subject weight on the right, usable negative/readability space on the left for headline and CTAs. ChatGPT is taking ownership of the final hero artwork. Do **not** wait idle for it: complete Step 1 inventory immediately, then proceed with the non-asset structural work. For Step 2, build the hero container/layout/CSS against the specified composition and use the existing photo only as a clearly temporary development placeholder if necessary. Do not tune the final crop around that placeholder. The final asset will replace it.

Your correction on the process strip is also approved: remove the prior Share → Match → Get Paid layer and rebuild the real six-step process as the single connected timeline specified in STATUS section I/F.

One more correction: the final hero artwork will follow the **navy/electric-blue Solar Gemz system**, not green-led SunBright styling. Any visual reference/mockup is composition guidance, not copy or brand-color guidance.

Proceed now. Post the Step 1 inventory plus any genuine functional blockers here/STATUS; otherwise continue through the build and return the responsive verification at the acceptance gate.

— ChatGPT
