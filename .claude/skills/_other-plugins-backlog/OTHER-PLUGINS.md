# Other Crocoblock plugins — backlog for future skills

Not a Claude Code Skill itself (no `SKILL.md`/frontmatter) — this is a research log for
whoever picks up the next plugin. While auditing Crocoblock's public GitHub Gists account
(`https://gist.github.com/Crocoblock`, 305 gists total, fetched 2026-07-16) for
JetEngine/JFB/JSF material, we also triaged every gist that turned out to be about a
*different* Crocoblock plugin. Rather than throw that research away, it was logged here so
future sessions didn't have to re-fetch and re-classify all 305 gists from scratch.

**Status as of 2026-07-16 (fifth round): every plugin that had real source checked out has
now been built into a full skill set** — Jet Appointments Booking, JetBooking, JetElements,
JetMenu, JetReviews, JetWooBuilder (fourth round), plus JetBlog, JetCompareWishlist,
JetPopup, JetTabs, and JetThemeCore (fifth round). Each of those plugins' gist-research
sections have been removed from this file since the corresponding skills (built and
verified directly against real plugin source, not the gists) are now the authoritative
reference — see each skill's own `SKILL.md` "How this was verified" section, not this file,
for citations. The only plugin below with an unresolved gist-research section is **JetSearch**,
which does not have real source checked out yet (see "Suggested next step").

Each entry: `[gist url]` — one-line description — key hook/class names seen in the code
(verbatim strings, useful for grepping the plugin's source once you have it checked out).
None of the JetSearch entries below have been verified against real source yet — that's the
next step, per this repo's core principle (see `docs/principles.md`): verify against
source, don't just trust a distributed snippet.

## JetSearch

- https://gist.github.com/Crocoblock/902235b7beb68058a2efd175b159170a — restrict AJAX search to post_title — `jet-search/ajax-search/search-query`
- https://gist.github.com/Crocoblock/57708a1ce484bee9ba794a4316626005 — adds Media as searchable post type + custom thumbnail HTML — `jet-search/tools/get-post-types`, `jet-search/ajax-search/query-args`, `jet-search/ajax-search/thumbnail-html`
- https://gist.github.com/Crocoblock/44f6d792299a03bdd1e5ca05ce60bb41 — modify AJAX search results — JS trigger `jet-ajax-search/show-results`

## Shared "JetPlugins" framework (not product-specific)

- https://gist.github.com/Crocoblock/e7d67a698d270f078c5cc74519e591d6 — disables "Edit with JetPlugins" admin-bar item — class `Jet_Admin_Bar`. Worth noting if a skill ever covers cross-product infrastructure (the `window.JetPlugins.hooks` JS event bus, used pervasively by JFB and JetBooking JS snippets, is this same shared framework on the frontend side).

## Generic / not Crocoblock at all (no backlog value, listed only so they aren't re-triaged)

- https://gist.github.com/Crocoblock/ada1a5c8520a964b624993eabf19eb48 — generic WooCommerce order line-item cleanup
- https://gist.github.com/Crocoblock/62a9c331304fc44f5d7192d50cbb9c06 — generic WP taxonomy admin column
- https://gist.github.com/Crocoblock/f585e1d8e0907585f0ccf387406d2ef8 — third-party Slim SEO/Bricks Builder hook (JetEngine referenced only as a string literal)
- https://gist.github.com/Crocoblock/4bf84add9b7d6ac22ba2843533519b9f — pure CSS, no hooks

## Suggested next step

**JetSearch is the only plugin left in this backlog without real source checked out.**
JetSearch (v3.6.1.3) is active on the sandbox (jackfruit.epeak.studio), but its plugin
source was never added to `plugins/jet-search/` this round — the site owner added source
for the other five plugins (JetBlog, JetCompareWishlist, JetPopup, JetTabs, JetThemeCore)
but not this one. Once `plugins/jet-search/` exists locally, the 3 gists above are a
starting point, but per this repo's principle they must be verified (or corrected) against
the real source before becoming a skill, the same way every other plugin in this repo was.
