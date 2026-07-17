# Other Crocoblock plugins — backlog for future skills

Not a Claude Code Skill itself (no `SKILL.md`/frontmatter) — this was a research log for
whoever picked up the next plugin. While auditing Crocoblock's public GitHub Gists account
(`https://gist.github.com/Crocoblock`, 305 gists total, fetched 2026-07-16) for
JetEngine/JFB/JSF material, we also triaged every gist that turned out to be about a
*different* Crocoblock plugin. Rather than throw that research away, it was logged here so
future sessions didn't have to re-fetch and re-classify all 305 gists from scratch.

**Status as of 2026-07-17: every plugin this backlog ever tracked now has real source
checked out and a full skill set built against it** — Jet Appointments Booking, JetBooking,
JetElements, JetMenu, JetReviews, JetWooBuilder (fourth round), JetBlog,
JetCompareWishlist, JetPopup, JetTabs, JetThemeCore (fifth round), and finally JetSearch
(seventh round — `jetsearch-query-pipeline`, `jetsearch-suggestions`,
`jetsearch-widgets-extensibility`). Every plugin's gist-research section has been removed
from this file since the corresponding skills (built and verified directly against real
plugin source, not the gists) are now the authoritative reference — see each skill's own
`SKILL.md` "How this was verified" section, not this file, for citations.

This file is kept around as a template/precedent for triaging a *future* Crocoblock plugin
this repo doesn't cover yet (JetStyleManager, JetProductGallery, etc.), not because it
currently tracks anything open.

## Shared "JetPlugins" framework (not product-specific)

- https://gist.github.com/Crocoblock/e7d67a698d270f078c5cc74519e591d6 — disables "Edit with JetPlugins" admin-bar item — class `Jet_Admin_Bar`. Worth noting if a skill ever covers cross-product infrastructure (the `window.JetPlugins.hooks` JS event bus, used pervasively by JFB and JetBooking JS snippets, is this same shared framework on the frontend side).

## Generic / not Crocoblock at all (no backlog value, listed only so they aren't re-triaged)

- https://gist.github.com/Crocoblock/ada1a5c8520a964b624993eabf19eb48 — generic WooCommerce order line-item cleanup
- https://gist.github.com/Crocoblock/62a9c331304fc44f5d7192d50cbb9c06 — generic WP taxonomy admin column
- https://gist.github.com/Crocoblock/f585e1d8e0907585f0ccf387406d2ef8 — third-party Slim SEO/Bricks Builder hook (JetEngine referenced only as a string literal)
- https://gist.github.com/Crocoblock/4bf84add9b7d6ac22ba2843533519b9f — pure CSS, no hooks

## If a new plugin shows up

1. Get its real source checked out to `plugins/<slug>/` (gitignored, same as every other
   plugin here) — this repo's core principle (`docs/principles.md`) is verify-against-source,
   not against gists/docs/marketing copy.
2. Confirm it's active on the sandbox (or activate it) before writing any `tests.php`.
3. Follow `docs/authoring-guide.md`'s draft → verify → ship workflow, same as every
   skill in `skills/`.
4. Split by capability, not by plugin name alone, if the plugin has more than one
   clearly distinct subsystem (see `docs/principles.md`) — most plugins in this repo
   ended up as 2-3 skills, not one.
