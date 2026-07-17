---
name: jetformbuilder-payment-gateways
description: Use when working with JetFormBuilder's Payment Gateways module (`modules/gateways/`) — extending `Base_Gateway`/`Base_Scenario_Gateway` to add a custom gateway, reading/writing the custom-DB-table `Payment_Model`/`Payment_Meta_Model`/`Payer_Model` payment records, hooking the `GATEWAY.SUCCESS`/`GATEWAY.FAILED` Action Events a form author can attach extra Action steps to, or tracing the PayPal reference implementation. Captures verified behavior of JetFormBuilder 3.6.3.1 source (`modules/gateways/`), cross-referencing `jetformbuilder-actions`/`jetformbuilder-hooks` for the shared Action/Event system gateways plug into, live-verified 2026-07-17 against jackfruit.epeak.studio (9/9 tests.php assertions passing).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetFormBuilder Payment Gateways module

Verified facts about `modules/gateways/` — PayPal/Stripe-style checkout wired into a
JetFormBuilder form as a special Action Event executor, backed by its own custom DB
tables (not post meta, not a JetEngine CCT). Confirmed against JetFormBuilder 3.6.3.1
source (`plugins/jetformbuilder/modules/gateways/`, `includes/actions/events/`).
Distinct from — but plugs directly into — the Action/Event system covered in
`jetformbuilder-actions` (custom action classes) and `jetformbuilder-hooks` (the
executors filter, Call Hook). Read those two first if the Action Event vocabulary below
(`Base_Event`, `Base_Executor`, `Events_Manager`) is unfamiliar.

## The namespace-alias landmine: `Jet_Form_Builder\Gateways\*` vs `JFB_Modules\Gateways\*`

Every real gateway class in the source **uses** the public-looking namespace
`Jet_Form_Builder\Gateways\*` (e.g. `Base_Scenario_Gateway extends
\Jet_Form_Builder\Gateways\Base_Gateway`, `modules/gateways/base-scenario-gateway.php:19`;
`Controller extends \Jet_Form_Builder\Gateways\Base_Scenario_Gateway`,
`modules/gateways/paypal/controller.php:16`) — but **no class is ever defined in that
namespace**. Grepping the source for `namespace Jet_Form_Builder\Gateways` returns
nothing. The real classes all live under `JFB_Modules\Gateways\*`
(`modules/gateways/base-gateway.php:4`, `modules/gateways/base-scenario-gateway.php:4`,
etc.) and are wired to the public-looking names by a `DEPRECATED_CLASSMAP` entry in the
custom autoloader (`includes/autoloader.php:44-82`) — ~40 gateway-related class-alias
pairs, e.g.:

```php
'Jet_Form_Builder\\Gateways\\Base_Gateway'          => 'JFB_Modules\\Gateways\\Base_Gateway',
'Jet_Form_Builder\\Gateways\\Base_Scenario_Gateway' => 'JFB_Modules\\Gateways\\Base_Scenario_Gateway',
'Jet_Form_Builder\\Gateways\\Db_Models\\Payment_Model' => 'JFB_Modules\\Gateways\\Db_Models\\Payment_Model',
// ...
```

`Autoloader::autoload()` (`includes/autoloader.php:158-183`) resolves the requested
alias class to its real target, loads the real file if not already loaded, then calls
`class_alias()` so both names refer to the same class. **A custom gateway can `extend
\Jet_Form_Builder\Gateways\Base_Gateway` (the "public" name real code uses) and it will
work** — just don't be surprised when `grep`ing for `class Base_Gateway` only turns up
`JFB_Modules\Gateways\Base_Gateway`. One exception: `Jet_Form_Builder\Gateways\Gateway_Manager`
is a **real**, separately-defined class (`modules/gateways/legacy/gateway-manager.php:17`,
just `class Gateway_Manager extends Module {}`, kept "Required for
`\Jet_FB_Stripe_Gateway\Compatibility\...`" per its own file comment) — not in the alias
map, explicitly `require_once`'d from `modules/modules-controller.php:22`, not
autoloaded via the namespace-prefix convention at all.

## Registering a gateway: extend `Base_Gateway` (or `Base_Scenario_Gateway`) and hook `jet-form-builder/gateways/register`

`JFB_Modules\Gateways\Base_Gateway` (`modules/gateways/base-gateway.php:27`) extends
`Legacy_Base_Gateway` (`legacy-base-gateway.php:21`, mostly `@deprecated 2.0.0` methods
kept for backward compat — e.g. `on_success_payment()`, `try_do_actions()`,
`set_gateway_from_post_meta()`). Two abstract methods every gateway must implement:
`get_id()` and `get_name()` (`base-gateway.php:52-68`), plus
`retrieve_gateway_meta()` (abstract, `:74`). Registration happens on WP `init` via
`Module::register_gateways()` → `do_action( 'jet-form-builder/gateways/register', $this )`
(`module.php:251-256`):

```php
add_action( 'jet-form-builder/gateways/register', function( $module ) {
    $module->register_gateway( new My_Gateway() );
} );
```

`Module::register_gateway( Base_Gateway $gateway )` (`module.php:263-265`) delegates to
the same repository pattern (`rep_install_item()`) `jetformbuilder-actions` documents for
custom action types — a real, current registration is
`Module::rep_instances()` returning `[ new Paypal\Controller() ]` (`module.php:169-173`).

**The real PayPal implementation (`Controller`, `modules/gateways/paypal/controller.php:16`)
doesn't extend `Base_Gateway` directly — it extends `Base_Scenario_Gateway`**
(`modules/gateways/base-scenario-gateway.php:19`, itself extending `Base_Gateway`), a
second layer that adds a **scenario** concept (`get_scenario()` /
`Scenarios_Manager::instance()->get_logic( $this )`, `:25-27`) — PayPal ships one
concrete scenario, `Pay_Now` (`paypal/scenarios-logic/pay-now.php`,
`Scenarios_Logic\Pay_Now::scenario_id()` is the default when a form's `scenario.id`
setting is empty, `base-gateway.php:286-292`). Whether to extend `Base_Gateway` directly
or go through `Base_Scenario_Gateway`'s scenario layer is a real design choice — a
single-flow gateway (no "one-time payment vs. subscription vs. checkout-then-capture"
branching) has no reason to take on the scenario abstraction; PayPal needs it because it
supports multiple checkout flows behind one gateway id.

## Required credentials and the gateways-editor-data trait

`Gateways_Editor_Data` (`gateways-editor-data.php`, mixed into `Module`) builds the admin
editor's config for the "Payments Gateways" tab. `required_credentials_fields()`
(`base-gateway.php:381-383`, default empty array, override to declare required option
keys) feeds `required_fields_map()`
(`gateways-editor-data.php:111-129`), which **hardcodes PayPal's and Stripe's field
names as a fallback** (`'paypal' => ['client_id','secret'], 'stripe' => ['public','secret']`,
`:113-116`) even though only PayPal ships in this source tree — Stripe support exists as
a documented but not-locally-present integration (see `modules/gateways/legacy/gateway-manager.php`'s
comment about `Jet_FB_Stripe_Gateway`, a separate add-on plugin). A gateway that declares
`required_credentials_fields()` overrides that hardcoded map entry
(`gateways-editor-data.php:118-125`); `PayPal\Controller::required_credentials_fields()`
returns `['client_id', 'secret']` (`controller.php:194-196`), matching the hardcode
(redundant but harmless — the override wins either way).

## The payments data model: 6 custom DB tables, not post meta or a CCT

Every gateway-related DB model extends `Jet_Form_Builder\Db_Queries\Base_Db_Model`
(`includes/db-queries/base-db-model.php:15`) — the **same** custom-table framework
JetFormBuilder uses for form records (`jetformbuilder-fields`' storage layer), not
WordPress post meta and not a JetEngine CCT. `Base_Db_Model::table()`
(`base-db-model.php:323-325`) computes the real table name as
`$wpdb->prefix . 'jet_fb_' . static::table_name()` — every gateway table is prefixed
`wp_jet_fb_` on a default install.

| Model class (`modules/gateways/db-models/`) | `table_name()` | Real columns (`schema()`) |
|---|---|---|
| `Payment_Model` | `payments` | `id, transaction_id, initial_transaction_id, form_id, user_id, gateway_id, scenario, amount_value, amount_code, type, status, created_at, updated_at` (`payment-model.php:26-40`) |
| `Payment_Meta_Model` | `payments_meta` | `id, payment_id, meta_key, meta_value, created_at, updated_at` (`payment-meta-model.php:27-35`) — free-form per-payment key/value, the escape hatch for gateway-specific data that doesn't warrant its own `Payment_Model` column |
| `Payer_Model` | `payers` | `id, user_id, payer_id, first_name, last_name, email, created_at, updated_at` (`payer-model.php:28-38`) |
| `Payer_Shipping_Model` | `payers_shipping` | `id, payer_id, full_name, address_line_1, address_line_2, admin_area_2, admin_area_1, postal_code, country_code, created_at, updated_at` (`payer-shipping-model.php:26-39`) — `admin_area_1`/`admin_area_2` are PayPal's own naming for state/city, carried straight through |
| `Payment_To_Record` | `payments_to_records` | `id, payment_id, record_id` (`payment-to-record.php:25-30`) — join table linking a payment row to a JetFormBuilder form-record row (`JFB_Modules\Form_Record\Models\Record_Model`) |
| `Payment_To_Payer_Shipping_Model` | `payment_to_payer_shipping` | `id, payment_id, payer_shipping_id` (`payment-to-payer-shipping-model.php:26-32`) |

A "payment" is therefore normalized across up to 5 tables (`payments` +
`payments_meta` + `payers`/`payers_shipping` joined via the two `payment_to_*` tables) —
there is no single row that has everything; `Query_Views\Payment_View` (below) is what
reassembles the common shape.

**CRUD**: `Base_Db_Model::insert()`/`update()`/`delete()` (`base-db-model.php:155-225`)
delegate to `Execution_Builder::instance()`. `insert()` fires two filter/action pairs
worth knowing (`:156-162`): `apply_filters('jet-form-builder/db/before-insert', $columns, $this)`
then a **table-scoped** variant `apply_filters("jet-form-builder/db/{$this::table_name()}/before-insert", $columns)`
(2-arg vs 1-arg — don't assume both take the model instance), and symmetric
`do_action('jet-form-builder/db/after-insert', $id, $columns, $this)` /
`do_action("jet-form-builder/db/{$table_name}/after-insert", $id, $columns)`. For
gateways specifically that means `jet-form-builder/db/payments/before-insert` and
`jet-form-builder/db/payments/after-insert` are real, addressable hooks for
intercepting/reacting to a payment row write, confirmed by the generic mechanism
(no gateway-specific override of `insert()` was found — the hooks fire from the base
class for every model, gateway tables included). `create()` (`:93-97`) is schema
creation (`CREATE TABLE IF NOT EXISTS`, via `Execution_Builder::safe_create()`), not row
insertion — a model must `->create()` its table (and, transitively, any
`foreign_relations()` tables — see `Payment_View::get_prepared_join()` calling
`(new Payment_To_Payer_Shipping_Model())->create()` before referencing its table name,
`query-views/payment-view.php:83-84`) before any `insert()`/`update()`/query against it
is guaranteed to succeed on a fresh install — `before_create()` (`:238-241`) does this
automatically as part of `insert()`'s underlying `Execution_Builder` flow, so a bare
`(new Payment_Model())->insert([...])` is safe to call without a separate `->create()`
call first in practice, but `Payment_View::get_prepared_join()`'s explicit `->create()`
calls before referencing `::table()` in raw SQL JOIN strings show the pattern to copy
when writing a **query view** (which builds SQL directly, bypassing `insert()`'s
automatic table-creation step).

## Reading payment records: the `query-views` classes

`modules/gateways/query-views/*` — read-only `View_Base` subclasses
(`includes/db-queries/views/view-base.php:19`), the same query-view abstraction used
elsewhere in JetFormBuilder's DB layer. Key ones:

- **`Payment_View`** (`payment-view.php:21`) — the "give me a payment plus its payer and
  shipping info" join: `LEFT JOIN payment_to_payer_shipping … LEFT JOIN payers_shipping …
  LEFT JOIN payers` (`:88-97`), columns aliased `ship.*`/`payer.*` via
  `schema_columns('ship')`/`schema_columns('payer')` (`:37-38`, which prefixes each
  column `` `table`.`column` as 'prefix.column' `` — `View_Base::prepare_row()`,
  `view-base.php:182-198`, then un-flattens `prefix.column` keys back into a nested
  `$row['prefix']['column']` array). `set_with_record( true )` additionally joins
  `payments_to_records` (`:99-108`) without changing `select_columns()` — the joined
  record columns only appear via `Payment_With_Record_View`, not `Payment_View` itself
  with `with_record` set (a real asymmetry: setting `with_record` alone changes the SQL
  join but not the SELECT list, so no new columns actually come back unless you use the
  subclass below).
- **`Payment_With_Record_View`** (`payment-with-record-view.php:18`) extends
  `Payment_View`, adds the `payments_to_records`+`Record_Model` join **and**
  `Record_Model::schema_columns('record')` to the select list (`:36-40`) — this is the
  one that actually returns record columns.
- **`Payment_By_Record`** (`payment-by-record.php:18`) — the reverse lookup, table is
  `payments_to_records` itself, joins back to `payments` (`:31-38`) — "given a record id,
  find its payment(s)".
- **`Payment_Count_View`** (`payment-count-view.php:18`) — `Payment_View` subclass using
  `View_Base_Count_Trait`, with `get_prepared_join()` overridden to a no-op (`:22-24`) —
  a real, deliberate optimization: counting doesn't need the payer/shipping joins.
- **`Payment_For_Export_View`** (`payment-for-export-view.php:15`) — flat, no joins,
  `select_columns()` is every `Payment_Model` schema column (`:25-27`) — used by the CSV
  export column (`meta-boxes/columns/export-csv-column.php`).

`Payment_View::AVAILABLE_STATUSES = ['COMPLETED', 'VOIDED']` (`payment-view.php:26-29`)
is the only status-filter whitelist honored by `set_filters()`'s `status` key
(`:50-77`) — any other value is silently ignored, no error, no filtering applied.

Query views are read via the shared `View_Base` static helpers (`findById()`, `all()`,
`one()`, `values()`, and the `find()`/`findOne()` builders — `view-base.php:285-330`),
same convention across every `View_Base` subclass in the plugin, not gateway-specific.

## `GATEWAY.SUCCESS`/`GATEWAY.FAILED` — the two Action Events a form author hooks extra Actions onto

This is the concrete answer to "how does gateway completion become an extension point
a form author can attach a custom Action step to" — the payoff of the Action/Event
architecture from `jetformbuilder-actions`/`jetformbuilder-hooks`. Both events are
**registered globally**, not gateway-conditional (`Events_Manager::rep_instances()`
always includes `new Gateway_Success_Event(), new Gateway_Failed_Event()`,
`includes/actions/events-manager.php` — confirmed by class list in the constructor
region), meaning both event ids are always selectable in the form editor's "run this
action on…" dropdown for any action step, gateway or no gateway — nothing gates their
*availability*, only whether they ever actually *fire* depends on a real gateway
completing.

**`Gateway_Success_Event`/`Gateway_Failed_Event`** (`includes/actions/events/gateway-success/gateway-success-event.php:15`,
`.../gateway-failed/gateway-failed-event.php:15`) both extend `Base_Gateway_Event`
(`includes/actions/events/base-gateway-event.php:11`, itself extending the generic
`Base_Event` covered in `jetformbuilder-actions`). Their ids are the literal strings
`'GATEWAY.SUCCESS'`/`'GATEWAY.FAILED'` (`get_id()`) — these are what a stored action
step's `events` array contains once a form author picks that event for an action in the
editor. **`ignored_executors()` returns `[Default_Process_Executor::class]`**
(`gateway-success-event.php:43-47`, same in the failed event) — this is the mechanism
that keeps a gateway-scoped action step from *also* running during the ordinary
DEFAULT.PROCESS event; an action assigned to `GATEWAY.SUCCESS` is excluded from the
default executor's action list.

**Firing**: `Legacy_Base_Gateway::process_status( $type )` (`legacy-base-gateway.php:62-75`,
still the real call site despite being inside a `@deprecated 2.0.0`-annotated class) is
what actually triggers them:

```php
jet_fb_events()->execute( Gateway_Success_Event::class, $id );  // or Gateway_Failed_Event::class
```

`jet_fb_events()` returns the `Events_Manager` singleton (`includes/functions.php:80-82`).
`Base_Event::execute()` (`base-event.php:34-36`) resolves to
`get_executor()->execute()` — for these two events, `executors()` returns exactly one
executor each (`Gateway_Success_Executor`/`Gateway_Failed_Executor`,
`gateway-success-event.php:37-41`), both extending the shared
**`Gateway_Base_Executor`** (`includes/actions/events/gateway-base-executor.php:11`).
`Gateway_Base_Executor::before_execute()` (`:15-22`) is the actual do_action call site an
integrator would hook against directly, independent of the whole Action/Event apparatus:

```php
do_action( "jet-form-builder/gateways/on-payment-{$this->get_gateway_type()}", jet_fb_gateway_current() );
// -> jet-form-builder/gateways/on-payment-success   (Gateway_Success_Executor::get_gateway_type() === 'success')
// -> jet-form-builder/gateways/on-payment-failed     (Gateway_Failed_Executor::get_gateway_type() === 'failed')
```

passing the current gateway controller instance (`jet_fb_gateway_current()`,
`includes/functions.php:76-78` — `Module::instance()->get_current_gateway_controller_or_die()`).
After `before_execute()`, `Base_Executor::execute_actions()`
(`includes/actions/events/base-executor.php:67-69`) runs
`jet_fb_action_handler()->soft_run_actions( $this )` — the same action-loop machinery
`jetformbuilder-actions` documents, restricted (via `validate_actions()`/`is_valid_action()`,
`base-executor.php:50-62` + `base-event.php:57-72`) to only the action steps whose stored
`events` array contains this specific event.

**Practical takeaway for hooking custom logic onto gateway completion**, two options at
different granularity:
1. **Form-editor action step** — add an Action step (custom class per
   `jetformbuilder-actions`, or a "Call Hook" step per `jetformbuilder-hooks`), assign
   its Event to "When passing through the gateway" (`GATEWAY.SUCCESS`) or "When canceling
   the passage of the gateway" (`GATEWAY.FAILED`) in the editor UI.
2. **Direct PHP hook, no editor step needed** —
   `add_action( 'jet-form-builder/gateways/on-payment-success', function( $gateway ) { ... } )`
   (and the `-failed` counterpart) fires for **every** form using **any** gateway, before
   that event's own action steps run.

## The DEFAULT.PROCESS/gateway interaction: `Default_With_Gateway_Executor`

Distinct from the two events above: **while a gateway is configured for a form**,
`Default_With_Gateway_Executor` (`includes/actions/events/default-process/default-with-gateway-executor.php:18`)
replaces the plain `Default_Process_Executor` for the DEFAULT.PROCESS event (registered
via the `jet-form-builder/default-process-event/executors` filter documented in
`jetformbuilder-hooks`, added by `Module::add_executor_to_default_process()`,
`module.php:187-194`, prepended so it's tried first). Its `is_supported()`
(`:55-65`) gates itself on `Gateway_Manager::instance()->get_current_gateway_controller()`
not throwing `Repository_Exception` — i.e. it only takes over when the current form
really has a configured, resolvable gateway; otherwise the plain executor runs. Its
`before_execute()` calls `jet_fb_gateway_current()->before_actions()` (checkout-prep,
e.g. `Base_Scenario_Gateway::before_actions()` sets form gateway meta and defers to the
scenario, `base-scenario-gateway.php:43-46`) and its `after_execute()`
(`:37-49`) calls `jet_fb_gateway_current()->after_actions( jet_fb_action_handler() )`
**after** `Save_Record::add_hidden()` — a `Gateway_Exception` thrown from `after_actions()`
is caught and rethrown as `(new Action_Exception($exception->getMessage(), $exception->get_additional()))->dynamic_error()`
(`:46-48`), meaning a gateway-layer error surfaces to the form response using the
`dynamic_error()` mechanism `jetformbuilder-actions`/`jetformbuilder-hooks` already
document for making an arbitrary message register as a failure.

## `Gateway_Exception` — the gateway-specific exception type

`Jet_Form_Builder\Exceptions\Gateway_Exception` (`includes/exceptions/gateway-exception.php:11`)
is a thin subclass of `Handler_Exception` (no new behavior of its own — same
`is_success()`/`get_form_status()`/`dynamic_success()`/`dynamic_error()` mechanics
`jetformbuilder-actions` documents for `Action_Exception`, since both share the same
parent). Thrown throughout the gateway layer for genuinely exceptional conditions —
missing/invalid credentials (`Base_Gateway::set_current_gateway_options()`,
`base-gateway.php:193-215`; `Controller::get_token_with_credits()`,
`paypal/controller.php:162-169`), a non-2xx HTTP response from the gateway's own API
(`Base_Gateway_Action::check_response_code()`, `base-gateway-action.php:316-330`), or an
unparseable/empty response body (`response_body_as_array()`, `:335-375`). Caught at
`Base_Gateway::try_run_on_catch()` (`base-gateway.php:90-109`, legacy flow) and
`Base_Scenario_Gateway::try_run_on_catch()` (`base-scenario-gateway.php:57-84`, scenario
flow) — both silently `return` on a caught `Gateway_Exception`, i.e. a mid-flow gateway
error aborts that request handler quietly rather than surfacing a form error message by
itself (the caller — `parse_request` via `Module::on_has_gateway_request()`,
`module.php:290-296` — also swallows a `Repository_Exception` the same way). Don't assume
throwing `Gateway_Exception` automatically produces a user-visible failure message the
way `Action_Exception` does inside the normal action loop — it depends on which call site
catches it.

## PayPal worked example: `Capture_Payment_Action`

`modules/gateways/paypal/api-actions/capture-payment-action.php` — a small, concrete
instance of the `Base_Gateway_Action` HTTP-request abstraction
(`base-gateway-action.php:15`, itself distinct from `Base_Gateway` — this is the "make
one authenticated HTTP call to the gateway's API" building block, not the form-facing
gateway class):

```php
class Capture_Payment_Action extends Base_Action { // Base_Action extends \Jet_Form_Builder\Gateways\Base_Gateway_Action (paypal/api-actions/base-action.php:14)
    const SLUG = 'CAPTURE_PAYMENT';
    public function action_endpoint() { return "v2/checkout/orders/{$this->order_id}/capture"; }
    public function action_headers() { return [ 'Content-Type' => 'application/json' ]; }
    public function before_make_request() {
        if ( empty( $this->order_id ) ) { throw new Gateway_Exception( 'order_id is not set.' ); }
    }
}
```

`Base_Action` (`paypal/api-actions/base-action.php:14`) supplies the PayPal-specific
`base_url()` (sandbox vs. live, gated by `Module::instance()->is_sandbox`, `:16-20`) and
a custom `user-agent` header (`:22-32`). `Base_Gateway_Action::send_request()`
(`base-gateway-action.php:380-386`) is the actual call: `request()` (fires the real
`wp_remote_post()`/`wp_remote_get()`, `:251-262`) → `response_body_as_array()`
(JSON-decodes and throws `Gateway_Exception` on any failure mode, `:335-375`). This is
the template every other PayPal API action follows (`Get_Token`, `Pay_Now_Action` —
same directory) and the pattern to copy for a from-scratch gateway's own HTTP actions:
subclass `Base_Gateway_Action` (via the `Jet_Form_Builder\Gateways\Base_Gateway_Action`
alias or the real `JFB_Modules\Gateways\Base_Gateway_Action` name), implement
`base_url()`, override `action_endpoint()`/`action_headers()`/`action_body()` as needed,
call `->send_request()`.

## Admin UI (meta-boxes, table-views, pages) — lower depth, reachability only

`modules/gateways/meta-boxes/`, `table-views/`, `pages/` back the wp-admin "Payments"
list/single/print/export screens (`Pages\Payments_Page`, `Pages\Single_Payment_Page`,
`Pages\Export_Page`, `Pages\Print_Page`, registered via the `jet-form-builder/admin/*`
filter family in `Module::init_hooks()`, `module.php:88-112`). Each meta-box/column class
is a thin wrapper around the query-views above (e.g. `Payment_Actions_Box::get_list()`
calls `Payment_For_Export_View::findById()`, `meta-boxes/payment-actions-box.php:35-45`)
— not independently verified beyond confirming the classes load and reference the
right query views; a full admin-UI walkthrough is out of scope for this skill (not
practically automatable from a single REST-triggered `tests.php` request — see "Not yet
automated" below).

## Gotchas

- **`Jet_Form_Builder\Gateways\*` classes don't exist as literal files** — they're
  autoloader class-aliases to `JFB_Modules\Gateways\*` (see the first section). Grepping
  for `class Base_Gateway` and not finding the namespace a `use` statement referenced is
  expected, not a sign the class is missing.
- **`Payment_View`'s `set_with_record( true )` changes the SQL join but not the SELECT
  list** — use `Payment_With_Record_View` if you actually need record columns back.
- **`status` filter on `Payment_View`/`Payment_For_Export_View` only recognizes
  `'COMPLETED'`/`'VOIDED'`** (`Payment_View::AVAILABLE_STATUSES`) — any other value is
  silently ignored (no filtering applied, no error).
- **A caught `Gateway_Exception` doesn't automatically produce a form error message** —
  both `try_run_on_catch()` implementations (legacy and scenario) just `return` on catch;
  only `Default_With_Gateway_Executor::after_execute()`'s specific
  `Gateway_Exception` → `Action_Exception::dynamic_error()` conversion surfaces one.
- **`GATEWAY.SUCCESS`/`GATEWAY.FAILED` are always registered**, independent of whether
  any gateway is configured or active for a given form — don't assume checking "is this
  event registered" tells you anything about whether a real payment flow exists.
- **`required_fields_map()`'s PayPal/Stripe hardcode is a fallback, not a live check** —
  a gateway with no `required_credentials_fields()` override and no hardcode entry
  reports `$result[$id] = false` from `gateways_global_valid()`
  (`gateways-editor-data.php:146-149`) even if it actually works with zero required
  fields (e.g. a webhook-only or free-tier gateway) — override
  `required_credentials_fields()` explicitly rather than relying on omission meaning
  "no credentials needed."
- **Stripe is referenced but not present in this source tree** — `Jet_FB_Stripe_Gateway`
  is a separate, not-locally-checked-out add-on plugin (see `legacy/gateway-manager.php`'s
  file comment and the hardcoded `'stripe' => ['public','secret']` entry) — nothing here
  should be read as "Stripe ships inside JetFormBuilder itself."

## Not yet automated — requires live third-party credentials/checkout or a browser

- **A real PayPal checkout round-trip** (`get_token`/`pay-now`/`capture-payment` actually
  hitting PayPal's sandbox API and coming back with a real order id) needs live PayPal
  sandbox `client_id`/`secret` credentials this sandbox site doesn't have configured —
  `Capture_Payment_Action`/`Get_Token`/`Pay_Now_Action` are documented from source only,
  not exercised live.
- **The `parse_request` webhook catch (`Module::on_has_gateway_request()`) actually
  firing from a real `?jet_form_gateway=paypal&token=...` browser redirect** — this
  requires a real front-end request cycle, not something a single `agent-test`
  REST-triggered PHP call can simulate faithfully (the whole point of the flow is a
  redirect *back* from PayPal's own domain).
- **The wp-admin Payments list/single/print/export screens' actual rendered HTML** — the
  meta-box/table-view classes are confirmed reachable and wired to the right query views
  (see "Admin UI" above), but their rendered output wasn't visually verified.
- **Stripe** — no source present in this checkout; nothing here is verified against it.

## How this was verified

Read every file under `modules/gateways/` relevant to registration
(`base-gateway.php`, `base-gateway-action.php`, `base-scenario-gateway.php`,
`legacy-base-gateway.php`, `gateways-editor-data.php`, `module.php`), the DB layer
(`db-models/*.php`, `includes/db-queries/base-db-model.php`,
`includes/db-queries/views/view-base.php`, `query-views/*.php`), the PayPal reference
implementation (`paypal/controller.php`, `paypal/api-actions/base-action.php`,
`paypal/api-actions/capture-payment-action.php`), the Action Event wiring
(`includes/actions/events/base-gateway-event.php`, `gateway-base-executor.php`,
`gateway-success/*.php`, `gateway-failed/*.php`,
`default-process/default-with-gateway-executor.php`, `includes/actions/events-manager.php`,
`includes/actions/events/base-event.php`, `base-executor.php`), the exception type
(`includes/exceptions/gateway-exception.php`), and the autoloader's class-alias map
(`includes/autoloader.php`) — all in JetFormBuilder 3.6.3.1 source, confirming every
claim above by direct file:line citation. Cross-referenced `jetformbuilder-actions`
(Action/Event vocabulary, `dynamic_error()`/`dynamic_success()`) and
`jetformbuilder-hooks` (the `default-process-event/executors` filter, which already
flagged this module as unexplored) rather than re-deriving shared mechanics. Checked
Crocoblock's public `developer-documentation` GitHub repo — no Payment Gateways/PayPal
folder exists there as of this writing, so no first-party reference exists for any of
this; everything above is source-derived. Live-verified via `tests.php` (9/9 passing) —
see `TEST-REGIMEN.md` for the run log, including which claims above are architecture/
DB-round-trip/Event-firing checks (automated) vs. real-credential checkout flows
(explicitly left unautomated, see above).
