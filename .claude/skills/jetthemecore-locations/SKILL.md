---
name: jetthemecore-locations
description: Use when a JetThemeCore Header/Footer/Single/Archive/Section template isn't rendering where expected, when adding a brand-new theme "location" (structure) beyond the 6 built-in ones, or when hooking into the actual template-render pipeline (Elementor vs. Block Editor content, before/after render actions). Captures verified behavior of `Jet_Theme_Core\Structures`/`Jet_Theme_Core\Locations\Manager` from JetThemeCore 2.3.1.2 source, live-verified 2026-07-16 against jackfruit.epeak.studio (4/4 tests.php assertions passing after fixes — see TEST-REGIMEN.md; a WooCommerce-active site registers 6 more structures/locations beyond the 6/4 "core" counts).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetThemeCore Structures & Locations (the render pipeline)

Verified facts about how a template id that already **matched** a page (see
`jetthemecore-template-conditions`) actually gets **rendered** — the Structures registry
(what kinds of templates exist), the Locations registry (which structures are
frontend-swappable "locations" like header/footer), and the content-type dispatch that
picks Elementor vs. Block-Editor rendering. Confirmed against JetThemeCore 2.3.1.2 source
(`plugins/jet-theme-core/`).

## Two registries, one feeds the other: `Structures` → `Locations\Manager`

`jet_theme_core()->structures` (`Jet_Theme_Core\Structures`, `includes/template-structures/manager.php`)
registers 6 core built-in **structure** types on construction (`register_structures()`,
`:28-50`): `Page` (`jet_page`), `Header` (`jet_header`), `Footer` (`jet_footer`),
`Section` (`jet_section`), `Archive` (`jet_archive`), `Single` (`jet_single`) — these ids
are exactly the `$type` values `Template_Conditions\Manager::find_matched_conditions()`
matches against.

**Live-verified correction (2026-07-16): this is the floor, not the ceiling, when
WooCommerce is active.** On a site with WooCommerce running, 6 more WooCommerce-specific
structures register alongside these core 6: `jet_products_archive`, `jet_single_product`,
`jet_products_card`, `jet_products_checkout`, `jet_products_checkout_endpoint`,
`jet_account_page` — with matching `is_location()`-true entries in
`jet_theme_core()->locations->get_locations()` (`products-archive`, `single-product`,
`products-card`, `products-checkout`, `products-checkout-endpoint`, `account-page`). Don't
assume "6 structures / 4 locations" is the complete, fixed set on every site — it's
conditional on which compatible plugins (WooCommerce here) are active.

Extend the list:

```php
add_filter( 'jet-theme-core/template-structures/structures-list', function( $structures ) {
    $structures['\\My_Plugin\\My_Structure'] = __DIR__ . '/my-structure.php';
    return $structures;
} );
```

(`:34`, same "class-name-key → file-path-value, `require`d then `new`'d" convention as
the conditions-list filter in `jetthemecore-template-conditions`). Class must `extend
\Jet_Theme_Core\Structures\Base` (abstract, `structures/base.php:12`) — required:
`get_id()`, `get_single_label()`, `get_plural_label()`, `get_sources()`,
`get_elementor_document_type()`; optional overrides: `is_location()` (default `false`),
`has_conditions()` (default `true`), `location_name()` (default `''`),
`pro_location_mapping()` (default `false`), `library_settings()`, `get_admin_bar_priority()`,
`before_render()`/`after_render()` (both no-op hooks a structure can use to e.g.
`the_post()`/`wp_reset_postdata()` around its own render — `Single` does exactly this,
`structures/single.php:69-81`, because rendering a Single template needs the loop
primed).

A second hook fires per-structure right after registration, **only for structures where
`is_location()` returns true**:

```php
do_action( 'jet-theme-core/locations/register', $id, $structure_instance );
```

(`manager.php:67`, called from `register_structure()`, `:55-69`) — this is what
auto-registers a location into `jet_theme_core()->locations` (see next section) *and*
what JetThemeCore's own Elementor-Pro-location bridge (`Elementor\Locations::define_pro_locations()`,
hooked on this exact action) uses to mirror a structure into Elementor Pro's native
Theme Builder locations when `pro_location_mapping()` returns non-false.

Of the 6 built-ins, only **`Header`, `Footer`, and `Single`** return `is_location() === true`
(`Archive` also returns true — confirmed by grep, all 4 non-Page/Section structures are
locations); `Page` and `Section` are structure types (they get their own Elementor
document type + condition support) but are **not** swappable frontend "locations" —
there is no `do_location( 'page' )` call anywhere, `Page`/`Section` templates are only
ever inserted directly (e.g. via a shortcode/block), not resolved through the
matched-condition → location pipeline below.

## `Locations\Manager::do_location( $location )` — the actual dispatch

`jet_theme_core()->locations` (`Jet_Theme_Core\Locations\Manager`,
`includes/locations/manager.php`). `do_location( $location = 'header' )` (`:61-106`) is
the entry point a theme template file calls (e.g. `get_header()`-equivalent markup calls
`jet_theme_core()->locations->do_location( 'header' )`):

1. **Bails immediately if the Theme Builder's own Page-Template override is active**
   (`jet_theme_core()->theme_builder->frontend_manager->is_theme_builder_render`,
   `:62-66`) — see `jetthemecore-theme-builder`: when a Theme Builder "Page Template"
   layout matches the current request, it takes over header/footer rendering entirely
   and this classic per-structure `do_location()` path is skipped, not layered underneath
   it.
2. `apply_filters( 'jet-theme-core/location/do-location/active-location', $location )`
   (`:68`) — lets a callback swap which location actually resolves (e.g. force `'header'`
   requests to resolve as `'header-alt'` under some condition) before structure lookup.
3. Looks up the structure via `get_structure_for_location( $location )`, then calls
   `Template_Conditions\Manager::find_matched_conditions( $structure->get_id() )` — the
   exact bridge point between the two registries documented in this skill and
   `jetthemecore-template-conditions`.
4. Resolves `$content_type` via `jet_theme_core()->templates->get_template_content_type( $template_id )`
   — **defaults to `'elementor'` if no template matched at all** (`:86-88`), so the
   render-content filter below still fires even with `$template_id === false`, letting a
   fallback/default-content callback handle the no-match case explicitly rather than
   `do_location()` silently printing nothing.
5. Three dynamic hooks, name built from `$content_type` (currently either `'default'`
   [Block Editor] or `'elementor'`):
   ```php
   do_action( "jet-theme-core/location/before-render/{$content_type}-location-content", $template_id, $location );
   $render_status = apply_filters( "jet-theme-core/location/render/{$content_type}-location-content", false, $template_id, $location );
   do_action( "jet-theme-core/location/after-render/{$content_type}-location-content", $template_id, $location );
   ```
   (`:93-103`) — **the actual template output happens inside the filter callback as a
   side effect (it echoes/prints), not via the filter's return value** — `$render_status`
   is a bool the callback returns for a "did I render" signal, not the HTML itself. Both
   built-in content-type renderers work this way:
   `Frontend_Manager::render_default_template_content()` (`includes/frontend.php:15-36`,
   hooked on `jet-theme-core/location/render/default-location-content`) and
   `Elementor\Locations::render_elementor_template_content()`
   (`includes/elementor/locations.php:67-78`, hooked on
   `.../render/elementor-location-content`) both construct a `Render` object and call
   `->render()`, which prints directly.

A custom third content type (e.g. a `'my-custom-builder'` template source) needs to hook
`jet-theme-core/location/render/my-custom-builder-location-content` and make
`get_template_content_type()` (see `Templates` class, not covered in depth here) return
that string for its own template ids.

## Elementor document-type registration — one per Structure, plus the fallback

`Elementor\Manager::register_document_types_for_structures()`
(`includes/elementor/manager.php:93-108`, hooked on core Elementor's own
`elementor/documents/register`) loops `jet_theme_core()->structures->get_structures()`
and calls `$documents_manager->register_document_type( $id, $document_type['class'] )`
for each — `$document_type` is exactly what the structure's own
`get_elementor_document_type()` returns (`{class, file}` — the file is `require`d
separately, not shown in this snippet but present in the same loop). This is **the**
place a custom Structure's `get_elementor_document_type()` return value actually gets
consulted — skipping it (returning something falsy) leaves Elementor unable to open that
structure's templates in the editor at all. A generic fallback type,
`'jet-theme-core-not-supported'` → `Jet_Theme_Core_Not_Supported`, is always registered
first (`:97`) for template posts whose structure can't be resolved.

## Elementor Pro location bridge — only for structures with `pro_location_mapping()`

`Elementor\Locations` (`includes/elementor/locations.php`) is a thin adapter: if
Elementor Pro is active (`Utils::has_elementor_pro()`), it re-registers each
`is_location()` structure that also declares a `pro_location_mapping()` into Elementor
Pro's own native Theme Builder location system (`elementor/theme/register_locations`,
`:42-61`) so Elementor Pro's own conditions UI/`get_location_documents()` etc. see them
too — **this only matters if the site also runs Elementor Pro's native Theme Builder
side-by-side with JetThemeCore's**; a pure-JetThemeCore setup never touches this class's
registration path (though its `render_elementor_template_content()` — the actual
`elementor-location-content` renderer used by `do_location()` above — always runs
regardless of Elementor Pro).

## Style enqueuing has to independently re-discover every matched template

`Elementor\Locations::enqueue_locations_styles()` (`:85-134`, hooked
`wp_enqueue_scripts`) doesn't reuse anything cached from the render pass — it
re-computes both the Theme Builder's matched page-template layout ids **and** every
structure's `find_matched_conditions()` result independently, just to know which
Elementor template post ids need `Elementor\Core\Files\CSS\Post( $id )->enqueue()`
called. A custom Structure/content type that bypasses the standard
`get_elementor_document_type()`/Elementor CSS-file convention won't get its styles
auto-enqueued here and needs its own `wp_enqueue_scripts` hookup.

## Gotchas

- `do_location()` silently does nothing (returns `false`) if the Theme Builder's Page
  Template override is currently active for this request — don't assume every page load
  reaches the classic per-structure matching path; check
  `jetthemecore-theme-builder` first if a Header/Footer isn't rendering as its
  Template-Conditions configuration says it should.
- Only `Header`/`Footer`/`Single`/`Archive` are real "locations" callable via
  `do_location()` — `Page`/`Section` structures exist for condition/document-type
  purposes only and have no location-dispatch entry point.
- The render filters' **return value is a bare bool status flag, not markup** — a custom
  content-type renderer must `echo`/print inside its own callback, mirroring
  `Block_Editor_Render`/`Elementor_Location_Render`, not `return`-build a string.
- **Not corroborated by official docs**: as with `jetthemecore-template-conditions`,
  Crocoblock's public `developer-documentation` GitHub repo has no JetThemeCore folder at
  all (checked 2026-07-16) — everything here is sourced from plugin code directly.

## How this was verified

Read `includes/template-structures/manager.php`, `includes/template-structures/structures/{base,header,footer,single,archive,page,section}.php`,
`includes/locations/manager.php`, `includes/frontend.php`,
`includes/elementor/locations.php`, and `includes/elementor/manager.php`'s
`register_document_types_for_structures()` in JetThemeCore 2.3.1.2 source, confirming the
structures→locations bridge, the `do_location()` dispatch and its Theme-Builder-override
short-circuit, the "render filter callback prints as a side effect" pattern, and which
structures are/aren't real locations, all by direct file:line citation. Not yet verified
against a running site — see `TEST-REGIMEN.md`.
