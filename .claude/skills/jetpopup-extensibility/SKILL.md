---
name: jetpopup-extensibility
description: Use when extending JetPopup's own admin/editor surface — gating who can edit popups (jet-popup/access-cap, which also drives REST permission_callback and the CPT's own capabilities array), adding a REST endpoint under its jet-popup/v2 namespace, registering a custom Gutenberg data-attribute usable on any block (not just JetPopup's own), adding a new plugin-compatibility module, or registering a custom Gutenberg block for the popup editor. Captures verified behavior from JetPopup 2.2.1 source, live-verified 2026-07-16 against jackfruit.epeak.studio (7/7 tests.php assertions passing after one test-only fix — see TEST-REGIMEN.md).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetPopup Extensibility: Capabilities, REST, Block Data-Attributes, Compatibility Modules

Verified facts about JetPopup's own admin/editor extension points — as opposed to the
display-condition system (`jetpopup-conditions`) or the render/trigger pipeline
(`jetpopup-render-triggers`). Confirmed against JetPopup 2.2.1 source,
`plugins/jet-popup/`.

## `jet-popup/access-cap` — one filter gates the CPT, REST, and menu visibility together

`jet_popup()->get_admin_ui_cap()` (`jet-popup.php:428-430`):

```php
public function get_admin_ui_cap() {
    return apply_filters( 'jet-popup/access-cap', 'edit_others_posts' );
}
```

Default `'edit_others_posts'`. This single value is fanned out to **every** meta
capability in the `jet-popup` CPT's `capabilities` array (`edit_post`, `read_post`,
`delete_post`, `edit_posts`, `edit_others_posts`, `delete_posts`, `publish_posts`,
`read_private_posts`, `read`, `delete_private_posts`, `delete_published_posts`,
`delete_others_posts`, `edit_private_posts`, `edit_published_posts`, `create_posts` —
`includes/post-type.php:216-232`, all 15 keys map to the *same* filtered value, not 15
independent capabilities) **and** to every custom REST endpoint's `permission_callback`
(`Endpoints\Base::permission_callback()`, `includes/rest-api/endpoints/base.php:40-42`:
`current_user_can( jet_popup()->get_admin_ui_cap() )`). Changing this filter is the
single place to both loosen/tighten "who can create/edit popups" and "who can call the
admin REST endpoints" — they can't be split apart without overriding `permission_callback`
per-endpoint class instead.

**Gotcha**: this cap gates the *editor*, not front-end popup visibility to logged-out
visitors — the front-end AJAX content endpoint (`jet_popup_get_content`, see
`jetpopup-render-triggers`) has its own, separate, hardcoded
`current_user_can('read_post', $popup_id)` check for unpublished popups
(`includes/ajax-handlers.php:223`) that does **not** go through `get_admin_ui_cap()`.

## REST API: `jet-popup/v2` namespace, endpoint classes registered through a filter

`Jet_Popup\Rest_Api` (`includes/rest-api/rest-api.php`) — namespace `jet-popup/v2`
(`:29`). `init_endpoints()` (`:70-103`) requires 15 built-in endpoint files, `new`s each
class, calls `register_endpoint()`, then fires:

```php
do_action( 'jet-popup/rest-api/init-endpoints', $this );
```

but the actual extension point most compatibility modules use is a **filter over the
class list itself**, applied before any endpoint gets instantiated:

```php
apply_filters( 'jet-popup/rest-api/endpoint-list', [ '\Jet_Popup\Endpoints\Save_Plugin_Settings' => $path, ... ] )
```

(`:76-92`) — e.g. the WooCommerce compatibility module adds 3 endpoint classes this way
(`includes/compatibility/plugins/woocommerce/manager.php:96-104`, gated by
`class_exists('WooCommerce')` in its constructor). Every endpoint class extends
`Jet_Popup\Endpoints\Base` (`includes/rest-api/endpoints/base.php`), which supplies
working defaults for `get_method()` (`'GET'`), `get_query_params()` (`''`, an inline
regex fragment for path params like `(?P<id>[\d]+)`), and `get_args()` (`[]`) — only
`get_name()` and `callback( $request )` are actually abstract/required.

**`get_endpoints()` lazily calls `init_endpoints()` on first access if `$_endpoints` is
still `null`** (`:126-128`) — so `jet_popup()->rest_api->get_endpoints()` is safe to call
before `rest_api_init` has fired (e.g. from an `init`-hooked snippet), it just triggers
registration early rather than returning an empty/uninitialized list.

## Block-editor data-attributes: a generic system, usable on *any* registered block

`Jet_Popup\Data_Attributes` (`includes/block-editor/data-attributes.php`) is not
JetPopup-block-specific — it's a small registry any block can opt into.
`register_attributes()` → `do_action('jet-popup/data-attributes/register', $this)` (`:18`)
is where 3rd-party code adds its own attribute definitions (shape documented inline in
the file's own docblock, `:22-38`: `name`, `type`, `dataType`, `dataAttr`, optional
`options` array-or-callable, `default`, `label`, `description`, optional `condition`
map for the editor UI's conditional field display).

Once registered, `Block_Editor_Manager` wires the attribute onto **every** block
(except an explicit exclusion list) via two separate WP-core filters that both need to
agree:
- `block_type_metadata` → `add_block_attrs()` (`includes/block-editor/manager.php:294-304`)
  merges `to_block_attrs()` into a block's `attributes` schema at metadata-parse time.
- `register_block_type_args` → `register_block_type_arg()` (`:327-354`) does the same at
  registration time for dynamically-registered blocks (metadata-less `register_block_type()`
  calls), **and** additionally wraps the block's `render_callback` so
  `add_attributes()` injects the resolved `data-*` HTML attributes onto the rendered
  block's root element via a `DOMDocument` parse/re-serialize (`:363-399`) — this is how
  a plain server-rendered block ends up with e.g. `data-popup-instance="42"` in its
  actual output HTML, not just in the editor's saved attributes.

`get_not_supported_blocks()` (`:423-431`, filterable via
`jet-popup/block-manager/not-supported-blocks`) excludes both from the block-attrs
injection: built-in list is `core/freeform`, `core/html`, `core/shortcode`,
`core/legacy-widget`, `jet-popup/action-button` (its own block, deliberately excluded —
attaching a popup-open-trigger data attribute to a button whose whole purpose *is*
triggering popups doesn't make sense). The JetFormBuilder compatibility module extends
this list with 23 `jet-forms/*` block names (`includes/compatibility/plugins/jet-form-builder/manager.php:56-85`)
— attaching popup-trigger attributes to individual form-field blocks isn't meaningful
either.

## Registering a custom block (for the popup content editor) or compatibility module

Two more filter-based registries, same shape as conditions/endpoints:

```php
// A block class for JetPopup's own block list (extends Jet_Popup\Blocks\Base, includes/block-editor/blocks/base.php):
add_filter( 'jet-popup/block-manager/blocks-list', function( $blocks ) {
    $blocks['\My\Namespace\My_Block'] = __DIR__ . '/my-block.php';
    return $blocks;
} );

// A whole compatibility module (instantiated unconditionally — gate 3rd-party dependency checks in your own constructor, same as every built-in module does):
add_filter( 'jet-popup/compatibility-manager/registered-plugins', function( $modules ) {
    $modules['my-plugin'] = [
        'class'    => '\\My\\Namespace\\Manager',
        'instance' => false,
        'path'     => __DIR__ . '/my-plugin/manager.php',
    ];
    return $modules;
} );
```

(`includes/block-editor/manager.php:145-147`, `includes/compatibility/manager.php:21-52`).
`Jet_Popup\Compatibility\Manager::load_compatibility_modules()` (`:61-77`) `require`s
each `path` and `new`s each `class` **unconditionally** — every built-in module (`Woocommerce`,
`Jet_Engine`, `Jet_Form_Builder`, `WPML`, `Polylang`, `Jet_Style_Manager`) does its own
`class_exists()`/`defined()` guard-and-`return false` at the top of its constructor
(e.g. `Jet_Form_Builder::__construct()`, `includes/compatibility/plugins/jet-form-builder/manager.php:94-96`,
checks `defined('JET_FORM_BUILDER_VERSION')`) — a custom module must do the same, since
the manager itself performs no dependency check before instantiating.

## Correction to the gist-research backlog: the `lodash` script-dependency bug is already fixed

The backlog (`.claude/skills/_other-plugins-backlog/OTHER-PLUGINS.md`, "## JetPopup")
lists a gist "missing `lodash` script dependency fix for `jet-popup-block-editor`." In
the currently-checked-out 2.2.1 source, `'lodash'` **is already present** in that
script's dependency array:

```php
wp_enqueue_script(
    'jet-popup-block-editor',
    jet_popup()->plugin_url( 'assets/js/jet-popup-block-editor.js' ),
    [ 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-block-editor', 'lodash' ],
    jet_popup()->get_version()
);
```

(`includes/block-editor/manager.php:238-243`). The gist almost certainly documents a fix
for an older JetPopup release that's since been merged upstream — don't apply that gist
against 2.2.1+, and don't cite it as a currently-open bug.

## How this was verified

Read `jet-popup.php`, `includes/post-type.php`, `includes/rest-api/rest-api.php`,
`includes/rest-api/endpoints/base.php`, `includes/block-editor/manager.php`,
`includes/block-editor/data-attributes.php`, `includes/compatibility/manager.php`, and
`includes/compatibility/plugins/{woocommerce,jet-form-builder}/manager.php` directly in
JetPopup 2.2.1 source (`plugins/jet-popup/`), confirming the shared-capability fan-out,
the endpoint-list filter, the dual block_type_metadata/register_block_type_args wiring,
and the current `lodash` dependency by direct file:line citation. Cross-checked against
Crocoblock's public `developer-documentation` GitHub repo (`05-jet-popup/`) — its
`01-hooks/01-php-hooks/{admin,frontend}-hooks.md` files exist but are **empty (0
bytes)**, so there is no official documentation to corroborate or contradict any claim
here; the plugin source is the only source of truth. Not yet verified against a running
site — see `TEST-REGIMEN.md`; `tests.php` has reachability/source-presence smoke tests
ready to deploy.
