---
name: jetengine-listings-macros
description: Use when working with JetEngine listing grid/item dynamic field macros (%macro% tokens) — registering a custom macro, understanding what context/object data a macro callback can access, or debugging why a macro prints literally instead of resolving. Captures verified behavior from JetEngine 3.8.12 source.
license: MIT
metadata:
  author: project
  version: "0.2.0"
---

# JetEngine Listings Macros

**Live-verified (2026-07-16):** this skill now has a runnable suite (`tests.php`, 5
tests, `macros-1` through `macros-5`) per `docs/test-harness-guide.md` — 5/5 pass on
first live run. **One real finding** beyond what was previously documented: pipe-arg
syntax (`%macro|foo,bar%`) is silently dropped unless the macro class declares a matching
`macros_args()` schema — see the new callout under "Registering a custom macro" and
`TEST-REGIMEN.md`.

Verified facts about JetEngine's `%macro%` token parser, the registry that resolves a
macro tag to a callback, and how to register a custom macro. Confirmed against
JetEngine 3.8.12 source.

## Syntax

The parser (`Crocoblock\Macros_Handler::do_macros()`,
`framework/macros/macros-handler.php`) uses this regex:

```
/%([a-z_-]+)(\|(?:\[.*?\]|[a-zA-Z0-9_\-\,\.\+\:\/\s\(\)|\[\]\'\"=\{\}&\p{Sc}]+))?%(\{.*?\})?/u
```

Confirmed real syntax variants:
- `%macro_name%` — plain, no args.
- `%macro_name|arg1,arg2%` — pipe-delimited raw argument string.
- `%macro_name%{"fallback":"N/A","before":"$","after":"","filter":"..."}` — a trailing
  JSON config block, `json_decode`d, supporting `context`, `fallback`, `before`,
  `after`, `filter` keys.

**The macro tag itself must be lowercase letters/underscore/hyphen only** (`[a-z_-]+`)
— no digits, no uppercase. A tag containing either will never match the regex at all,
so it's not a registry-miss, it's not even attempted.

## Registry and resolution

The registry is a flat array inside `Macros_Handler`. The wiring layer for listings is
`Jet_Engine_Listings_Macros` (`includes/components/listings/macros.php`):

1. `init()` auto-loads core macros from `includes/components/listings/macros/*.php`.
2. Fires `do_action( 'jet-engine/register-macros' )` — instantiate a custom
   `Jet_Engine_Base_Macros` subclass here; its constructor self-registers.
3. Calls `$handler->register_macros_list( apply_filters( 'jet-engine/listings/macros-list', array() ) )`
   — alternatively, append your own `['tag' => ['label'=>.., 'cb'=>..]]` entry directly
   via this filter.

`Jet_Engine_Base_Macros::__construct()` (`includes/base/base-macros.php`) does the
self-registration for you:
```php
public function __construct() {
    add_filter( 'jet-engine/listings/macros-list', array( $this, 'register_macros' ) );
}
```

## Registering a custom macro — real worked example

`includes/compatibility/packages/woocommerce/inc/macros/products-in-cart.php`:

```php
class Products_In_Cart extends \Jet_Engine_Base_Macros {
    public function macros_tag()  { return 'wc_get_products_in_cart'; }
    public function macros_name() { return __( 'WC Products In Cart', 'jet-engine' ); }
    public function macros_callback( $args = [] ) {
        if ( ! function_exists( 'WC' ) ) return false;
        if ( ! WC()->cart ) wc_load_cart();
        $result = [];
        foreach ( WC()->cart->get_cart() as $product ) $result[] = $product['product_id'];
        return implode( ',', array_unique( $result ) );
    }
}
```

Required methods (abstract on `Crocoblock\Base_Macros`): `macros_tag()`,
`macros_name()`, `macros_callback( $args = [] )`. Optional: `macros_args()` (arg
definitions for the editor UI, defaults to `[]`).

**Finding (2026-07-16, live-verified via `tests.php` macros-2/macros-3): pipe args are
silently dropped if `macros_args()` isn't declared.** `_macros_callback()`
(`includes/base/base-macros.php`) only explodes the raw `%macro|foo,bar%` pipe string
into `$args` **inside a loop over `get_macros_args()`'s keys** — if a macro class doesn't
override `macros_args()` (the default is `[]`), `macros_callback()` receives an **empty**
`$args` array regardless of what was actually piped in, not the raw string. To receive
pipe args at all, declare `macros_args()` with one entry per expected positional arg
(e.g. `['first' => ['label' => 'First']]`); the piped values then map onto those keys in
declared order (confirmed: `%my_macro|hello-value%` → `$args['first'] === 'hello-value'`
when exactly one arg is declared).

Registration is just instantiating the class inside the `jet-engine/register-macros`
action — the base constructor handles hooking itself into the registry:

```php
add_action( 'jet-engine/register-macros', function() {
    require_once __DIR__ . '/macros/products-in-cart.php';
    new Products_In_Cart();
} );
```

## Context: what data a macro callback can access

`macros_callback( $args = [] )` does **not** receive post/CCT data directly — call
`$this->get_macros_object()` (defined on `Jet_Engine_Base_Macros`), which delegates to
`Macros_Handler::get_macros_object()`: if a context was set via
`set_macros_context()`, it resolves through
`jet_engine()->listings->data->get_object_by_context( $context )`; otherwise it falls
back to `jet_engine()->listings->data->get_current_object()` (the listing's current
render object). Outside a JetEngine listing render entirely, it falls back further to
`get_queried_object()`.

`do_macros( $string, $field_value )` also threads a second value —
the raw dynamic-field value being processed — through to the callback as its first
callback param, separate from the context object.

## Why a macro prints literally instead of resolving

Every early-return branch inside the `preg_replace_callback` in `do_macros()` returns
`$matches[0]` — the untouched literal match:

- **Macro tag not in the registry** (typo, or the registering class/hook never ran —
  e.g. its plugin/module isn't active) → literal, silently, no warning logged.
- Registry entry exists but its `cb` is empty/falsy → literal.
- `cb` exists but isn't `is_callable()` (e.g. the registering object was destructed, or
  method name/visibility is wrong) → literal.
- **Purely regex-level**: a tag with uppercase letters, digits, or unbalanced `%` never
  matches the pattern at all — the callback is never even invoked, so it stays literal
  by definition, not by any guard logic. This is the first thing to check for a
  stubborn literal macro.
- `do_macros()` is only invoked from specific render call sites (dynamic-field,
  listing-grid, dynamic-repeater, dynamic-link renderers). A macro placed in a listing
  template location that isn't routed through one of those call sites will never be
  processed. Not exhaustively verified across every render path (Elementor/Bricks
  providers weren't traced in this pass) — worth checking if a macro fails to resolve
  in a page-builder widget specifically.

## CCT vs. CPT listings

No CCT-specific branch in the macro parser or registry itself — parsing is identical
regardless of data source. CCT support is wired through the same generic hooks
JetEngine's own Woo macros use (`includes/modules/custom-content-types/inc/listings/manager.php`
hooks `jet-engine/register-macros` the same way). One real downstream branch does exist
in `get_current_meta()` (`includes/components/listings/macros.php`), which switches on
`get_class( $object )` (`WP_Post`, `WP_Term`, `WP_User`) and falls through to
`apply_filters( 'jet-engine/macros/current-meta', false, $object, $meta_key )` for
anything else — **CCT rows fall into that filter branch since they aren't any WP core
class**, distinguished by duck-typing (`isset( $object->cct_slug )`) rather than the
handler having built-in CCT awareness.

## How this was verified

Read `framework/macros/base-macros.php`, `framework/macros/macros-handler.php`,
`includes/base/base-macros.php`, `includes/components/listings/macros.php`, and the
real Woo macro implementation
`includes/compatibility/packages/woocommerce/inc/macros/products-in-cart.php` plus its
registration in `includes/compatibility/packages/woocommerce/inc/package.php`, in
JetEngine 3.8.12 source — confirming the parsing regex, registry population paths, and
literal-fallback branches by direct file:line citation. Not yet verified against a
running site — see `TEST-REGIMEN.md`, particularly around Elementor/Bricks render paths
and the full set of auto-loaded core macros, which weren't individually read in this
pass.
