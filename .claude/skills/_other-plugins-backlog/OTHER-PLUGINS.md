# Other Crocoblock plugins — backlog for future skills

Not a Claude Code Skill itself (no `SKILL.md`/frontmatter) — this is a research log for
whoever picks up the next plugin. This repo (`jetengine-skills`) only covers JetEngine,
JetFormBuilder, and JetSmartFilters. While auditing Crocoblock's public GitHub Gists
account (`https://gist.github.com/Crocoblock`, 305 gists total, fetched 2026-07-16) for
JetEngine/JFB/JSF material, we also triaged every gist that turned out to be about a
*different* Crocoblock plugin. Rather than throw that research away, it's logged here so
a future session doesn't have to re-fetch and re-classify all 305 gists from scratch —
just grep this file for the plugin you're about to start on, pull the gist URLs, and go.

Each entry: `[gist url]` — one-line description — key hook/class names seen in the code
(verbatim strings, useful for grepping the plugin's source once you have it checked out).

None of this has been verified against the plugins' actual PHP source — that's the next
step for whoever builds out these skills, per this repo's core principle (see
`docs/principles.md`): verify against source, don't just trust a distributed snippet.

## JetBooking / JetAppointments (JET_APB / jet_abaf — naming is inconsistent across gists)

By far the largest of the "other" plugins by gist count — a strong first candidate for a
new skill. Note the plugin appears to have been renamed at least once; code/hooks use
`jet_apb()`/`JET_APB`/`jet-apb/...` in some gists and `jet_abaf()`/`jet-booking/...`/
`jet-appointment/...` in others — reconcile this before naming hooks in a new skill.

- https://gist.github.com/Crocoblock/9e38f625881fcbaa079cfc363114bd3d — excludes calendar slots shorter than service default duration — `jet_apb()`, `jet-apb/time-slots/slots-html/slots-list`
- https://gist.github.com/Crocoblock/74f2564b9a2db6bb93da4f39aa06080f — GTranslate compatibility fix — `.jet-apb-calendar-body`, `jet-engine/booking-form/init`
- https://gist.github.com/Crocoblock/88754050bc3840b7d9bb098ed492256a — sticky calendar months — `jet-booking/init`, `window.jetBookingState.filters`
- https://gist.github.com/Crocoblock/8ae3015c0537242e116d633eca098edf — buffer/lock/slot settings from custom schedule — `jet-apb/calendar/custom-schedule`, `\JET_APB\Plugin`
- https://gist.github.com/Crocoblock/f5456fe6b1b3231008705ce7670390f1 — hides empty rows in WC order details — `jet-appointment/wc-integration/pre-cart-info`
- https://gist.github.com/Crocoblock/617a145c09a427c3781a1691186c0878 — strtotime-compatible default check-in/out value — `jet-booking/form-fields/check-in-out/default-value`
- https://gist.github.com/Crocoblock/b0797f1011bdae579e2a4893e12d6ce2 — REST endpoints `jet-engine/v2/appointment-refresh-date`, `jet-engine/v2/appointment-add-appointment` (legacy namespace, JetAppointments-specific)
- https://gist.github.com/Crocoblock/23d018ccd92c19aa8270b80774eab4d6 — set calendar view month — `jet-booking/init-field`, `JetABAFData`
- https://gist.github.com/Crocoblock/0927be75f728256de28f0b7e5416e6cb — redirect on cancel/confirm — `jet-apb/public-actions/custom-action-page-content`
- https://gist.github.com/Crocoblock/0515d5e71d1fde2773ce52a2127396f9 — set appointment status "completed" on insert — `jet-apb/form-action/insert-appointment`
- https://gist.github.com/Crocoblock/15fb1d8a4a467977ba058623891a4acc — week-number display — `jet-booking.input.config`/`jet-booking.calendar.config` JS filters, `jet-booking/init`
- https://gist.github.com/Crocoblock/b7bb212271d591f4458cf3edb09e66df — working hours from global settings — `jet-apb/calendar/custom-schedule`
- https://gist.github.com/Crocoblock/9bfa5f3a7baf35b58f8e7b6e8e21ddd2 — Google Calendar URL args — `jet-booking/google-calendar-url/args`
- https://gist.github.com/Crocoblock/451cf6e526b9ec926928681c5a47520f — seasonal price periods shift forward a year — `jet-booking/assets/config`
- https://gist.github.com/Crocoblock/700d3e04366a84edb9cd41ac9f2ec8b7 — weekly-only date-range selection — `jet-booking/init-field`, `jet-booking/init-calendar`
- https://gist.github.com/Crocoblock/4559535195cf7b03d970971bace5a512 — `show_ab_prices` shortcode — meta keys `jet_abaf_price`, `_seasonal_prices`
- https://gist.github.com/Crocoblock/417ae221717b9f479a22e709d79a6aae — blocks same-day booking after cutoff — `jet-booking/init` JS event, `jetBookingState.filters`
- https://gist.github.com/Crocoblock/470ca61c99e58b8c20d2ede6d56636c1 — restricts bookable range to next 60 days — `jet-booking/init` JS event
- https://gist.github.com/Crocoblock/027256b0f15ac20860675af3a9329a3d — multiple units per purchase — `\JET_ABAF\Plugin`, `jet-booking/form-action/pre-process`
- https://gist.github.com/Crocoblock/e7ec644ed118fcf99116ffa88e4f1724 — first-day-of-week = Sunday — `window.jetBookingState.filters.add('jet-booking/input/config'|'jet-booking/calendar/config', ...)` (deprecated JS hook form)
- https://gist.github.com/Crocoblock/b90e9b9887da95963388121896aad6c1 — blocks next N days — `jet-apb/calendar/custom-schedule`, meta_key `days_off`
- https://gist.github.com/Crocoblock/85108218cce5dce2ffa6a3dec5fb9fe3 — lock time >24h — `jet-apb/calendar/custom-schedule`, meta_key `locked_time`
- https://gist.github.com/Crocoblock/a1f896396b2596c5f070c91a2a94d4ad — skip WC payment for specific services — `jet-apb/jet-fb/action/success` (a JFB integration point worth cross-referencing from `jetformbuilder-hooks` once this skill exists), `jet_apb()->db`, `jet_apb()->wc->process_wc_notification`
- https://gist.github.com/Crocoblock/adb52df1069629b2e922ec4b5bc73f5b — force provider to service's weekly schedule — `jet-apb/calendar/custom-schedule`, meta_key `working_hours`
- https://gist.github.com/Crocoblock/0d73be0a11b07fa24c1490f5ad7d0d3a — **full JS API reference doc** for the booking calendar: filters `jet-booking.input.config`, `jet-booking.calendar.config`, `jet-booking.apartment-price`; triggers `jet-booking/init`, `jet-booking/init-field`, `jet-booking/init-calendar`; `window.JetPlugins.hooks.addFilter`. Start here for a JS-API skill section.

## JetPopup

- https://gist.github.com/Crocoblock/941a6f607ec1e190ba576ecdc8e28154 — missing `lodash` script dependency fix for `jet-popup-block-editor`
- https://gist.github.com/Crocoblock/08ae715d5f2aec7ee1815704248a15d5 — required capability for editing — `jet-popup/access-cap`
- https://gist.github.com/Crocoblock/b037cc64932b4448bc873b42dd34583a — programmatic open/close — JS events `jet-popup-open-trigger`/`jet-popup-close-trigger`
- https://gist.github.com/Crocoblock/1f1e7538e7538a52447dee869c8ccdf7 — custom "exclude" display-condition relation type — `jet-popup/popup-condition/is_excluded/and`
- https://gist.github.com/Crocoblock/8c9c4058e1ef1edff5111d1bf7841b54 — stops YouTube iframe on close — DOM only, no hook
- https://gist.github.com/Crocoblock/6db7f99e8a9353183aecb8409348cecd — scrolls a JetEngine Listing Grid slider to matching slide on popup open — JS event `jet-popup-open-trigger`, DOM only
- https://gist.github.com/Crocoblock/3b73eab179b665f5079ef3fe55006d74 — rewrites Gravity Forms action URL inside a popup — checks `$_POST['action'] === 'jet_popup_get_content'` (cross-plugin pattern also seen for JFB — see `jet-form-builder/form-refer-url` in `jetformbuilder-hooks`)

## JetWooBuilder

- https://gist.github.com/Crocoblock/1ca80a2c7950124c098bb8bb6d2fb838 — lowers min value of Zoom Magnify Elementor control — checks widget name contains `jet-woo`
- https://gist.github.com/Crocoblock/e3c24f22b0c9829fb02ab6ef2d4e7f04 — removes an Astra theme WC style filter (no Crocoblock API used)
- https://gist.github.com/Crocoblock/978f09821ecbe928d228800ee33f5d8e — disables product view-tracking cookies — `jet_woo_builder()->woocommerce`, removes `set_track_product_view` from `template_redirect`
- https://gist.github.com/Crocoblock/640ec669e84941d06ca1e360e56b3dc5 — variation swatches in archive price widget — `jet-woo-builder/template-functions/product-price`
- https://gist.github.com/Crocoblock/92698dccc093b92f37898c275f36fc2a — AJAX add-to-cart with variation swatches — `jet-woo-builder/template-functions/product-add-to-cart-settings`

## JetReviews

- https://gist.github.com/Crocoblock/f34efd4ca6957baf26ad7275f661d20f — reviewer avatar override — `jet-reviews/user-manager/raw-user-data`
- https://gist.github.com/Crocoblock/30b9cc387005815804554e5dd837e7ba — custom "can review" condition — `jet-reviews/user/conditions/register`, extends `\Jet_Reviews\User\Conditions\Base_Condition`
- https://gist.github.com/Crocoblock/197af77df362da6a26ff37c834e6b1bc — BuddyBoss compatibility — `jet-reviews/source/source-user/current-id`
- https://gist.github.com/Crocoblock/5df2fe8590a26dffd46c42ead145ec1e — "Recipe" rich-snippet type — `jet-reviews/structure-data/types`

## JetSearch

- https://gist.github.com/Crocoblock/902235b7beb68058a2efd175b159170a — restrict AJAX search to post_title — `jet-search/ajax-search/search-query`
- https://gist.github.com/Crocoblock/57708a1ce484bee9ba794a4316626005 — adds Media as searchable post type + custom thumbnail HTML — `jet-search/tools/get-post-types`, `jet-search/ajax-search/query-args`, `jet-search/ajax-search/thumbnail-html`
- https://gist.github.com/Crocoblock/44f6d792299a03bdd1e5ca05ce60bb41 — modify AJAX search results — JS trigger `jet-ajax-search/show-results`

## JetElements

- https://gist.github.com/Crocoblock/fa94ceae20b7b2787018a8e91364fe1f — increase max posts on Posts widget — `elementor/element/jet-posts/section_general/before_section_end`
- https://gist.github.com/Crocoblock/bd4d19abe4945ae9a4f03cd4212cb3cb — adaptive height for Advanced Carousel — `jet-elements/jet-carousel/carousel-options`
- https://gist.github.com/Crocoblock/c1876b67114e944fbee9c7c36821b0f9 — (ambiguous, likely JetElements Image Comparison/Accordion) — generic `imagesloaded` script-dependency fix, no Crocoblock hook

## JetBlog

- https://gist.github.com/Crocoblock/a35b497c221d15cf652459a06dbec7b6 — Smart Posts List title trim limit — `elementor/element/jet-blog-smart-listing/section_general/before_section_end`

## JetThemeCore

- https://gist.github.com/Crocoblock/66dcaa3fdaa1824e53992992287ca6b2 — custom taxonomies for template conditions — `jet-theme-core/post-types-list/deprecated`, `jet-theme-core/custom-post-types-list/deprecated`
- https://gist.github.com/Crocoblock/b7ddd1adbd5b27c4df95d4bbe3a59cb6 — "exclude" relation type for page-template condition — `jet-theme-core/page-template-condition/is_excluded/and`
- https://gist.github.com/Crocoblock/acfe55297157dd818f76dacb2a09912d — adds GTM code — generic `wp_head`/`wp_body_open`, no distinctive hook

## JetTabs

- https://gist.github.com/Crocoblock/1e5dcd0ff588ec610dc0db3c861850d2 — opens a specific switcher state from URL params — `.jet-switcher__control-instance`, `.jet-switcher--{state}`
- https://gist.github.com/Crocoblock/c8ef1141d90c155ca55f68e1e5a1283e — CSS: show tab icon only when active — `.jet-tabs__control`, `.jet-tabs__label-icon`

## JetMenu

- Not otherwise itemized beyond one ambiguous gist seen in chunk 0 (JetMenu, mega-menu hover overlay) — `.jet-mega-menu-item` class, generic DOM/CSS, no plugin hook.

## JetCompareWishlist

- https://gist.github.com/Crocoblock/b573201182b1ea0d7056f09e614aa2d0 — formats array-valued custom field output for JCW templates — `jet-cw/template-functions/compare-custom-field/{field}` (calls JetEngine's `jet_engine_render_checkbox_values()` and `jet_engine()`, so it's a genuine cross-plugin integration point worth a "related" mention once a JCW skill exists)
- https://gist.github.com/Crocoblock/17f6f422dce4e75f43bc1fc9605a7299 — exposes JCW data as a JetEngine macro (uses external `jet_cw()` function)
- https://gist.github.com/Crocoblock/b5f9cabfa1965873c618c04f5b94c6d6 — removes product from wishlist on add-to-cart — `jet_cw()`, core WC `woocommerce_add_to_cart` hook

## Shared "JetPlugins" framework (not product-specific)

- https://gist.github.com/Crocoblock/e7d67a698d270f078c5cc74519e591d6 — disables "Edit with JetPlugins" admin-bar item — class `Jet_Admin_Bar`. Worth noting if a skill ever covers cross-product infrastructure (the `window.JetPlugins.hooks` JS event bus, used pervasively by JFB and JetBooking JS snippets, is this same shared framework on the frontend side).

## Generic / not Crocoblock at all (no backlog value, listed only so they aren't re-triaged)

- https://gist.github.com/Crocoblock/ada1a5c8520a964b624993eabf19eb48 — generic WooCommerce order line-item cleanup
- https://gist.github.com/Crocoblock/62a9c331304fc44f5d7192d50cbb9c06 — generic WP taxonomy admin column
- https://gist.github.com/Crocoblock/f585e1d8e0907585f0ccf387406d2ef8 — third-party Slim SEO/Bricks Builder hook (JetEngine referenced only as a string literal)
- https://gist.github.com/Crocoblock/4bf84add9b7d6ac22ba2843533519b9f — pure CSS, no hooks

## Suggested next-plugin priority

**JetBooking/JetAppointments first** — largest gist count by far (~25), has a full JS API
reference doc already gisted (`0d73be0a...` above), and cross-references JFB (`jet-apb/
jet-fb/action/success`) and JetEngine (`jet-engine/v2/appointment-*` REST routes,
`jet-engine/booking-form/init`), so building it out would also let `jetformbuilder-hooks`
and `jetengine-relations` document those integration points properly instead of treating
them as out-of-scope noise.
