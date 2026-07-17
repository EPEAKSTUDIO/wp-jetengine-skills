# Test regimen: jetformbuilder-payment-gateways

Validates claims in `SKILL.md`. Run against the sandbox site
(`jackfruit.epeak.studio`, JetFormBuilder 3.6.3.1 active, PayPal is the only gateway
registered — no live credentials configured).

## Run log — 2026-07-17: live-verified (9/9 pass, first run clean)

Deployed `tests.php` as its own Code Snippets snippet (`AGENT-TEST-SUITE:
jetformbuilder-payment-gateways`) alongside the always-active `AGENT-TEST-CORE harness`,
then ran `GET /agent-test/v1/suite/jetformbuilder-payment-gateways`. **First run: 9/9
pass, no fixes needed** — every claim in `SKILL.md` held up against the running site
exactly as read from source. No real plugin/doc bugs found this round; no test-only
bugs either.

Notable things confirmed only by actually running code, not just reading source:

- `pg-1` confirmed the `Jet_Form_Builder\Gateways\*` → `JFB_Modules\Gateways\*`
  autoloader alias genuinely resolves at runtime (`ReflectionClass::getName()` returns
  the real class name after the alias is triggered) — this is exactly the kind of
  "looks like a missing class" trap a static read alone can assert but not prove works.
- `pg-4`/`pg-5` confirmed the `wp_jet_fb_payments` table already exists on this sandbox
  (PayPal gateway is registered) and that `Payment_Model::create()` is a safe no-op
  against an existing table (`safe_create()`'s `is_exist()` check) — a fabricated
  `AGENT-TEST-TXN-*` row was inserted, read back, and deleted via `$wpdb->delete()` in
  the same request; no leftover rows.
- `pg-7` confirmed `jet_fb_gateway_current()` resolves to `false` (not a fatal/`wp_die`)
  when called outside a real payment-flow request, per
  `get_current_gateway_controller_or_die()`'s actual `catch ( Repository_Exception )`
  behavior (`modules/gateways/module.php:319-325`) — the "_or_die" in the method name is
  misleading; it doesn't actually die on this path. Confirming this live mattered because
  a naive read of the method name alone would suggest testing it directly risks a fatal.
- `pg-9` confirmed `Payment_Count_View::get_prepared_join()`'s override to a no-op
  produces a genuinely empty `$builder->join` string, not just an unreachable code path —
  checked by calling it directly against a fresh `Query_Builder` instance and inspecting
  the public `$join` property, no real query execution needed.

## Prerequisites

- JetFormBuilder 3.6.3.1 active on the sandbox, with the always-active `AGENT-TEST-CORE
  harness` snippet.
- This suite deployed as its own Code Snippets snippet (`AGENT-TEST-SUITE:
  jetformbuilder-payment-gateways`).
- No PayPal/Stripe API credentials required for anything in `tests.php` — every test
  either inspects class/schema shape directly or fabricates-then-cleans-up its own DB
  row, deliberately avoiding any real HTTP call to a gateway's API.

## Test 1 (`pg-1`): the `Jet_Form_Builder\Gateways\*` → `JFB_Modules\Gateways\*` autoloader alias

**Claim:** every gateway class in source is written against the
`Jet_Form_Builder\Gateways\*` namespace, but no class is actually defined there — it's a
`class_alias()` pointing at the real `JFB_Modules\Gateways\*` class, wired through
`Autoloader::DEPRECATED_CLASSMAP`.

**Trigger:** `class_exists('\Jet_Form_Builder\Gateways\Base_Gateway')`, then
`(new ReflectionClass('\Jet_Form_Builder\Gateways\Base_Gateway'))->getName()`.

**Expected observable:** the alias class exists and `getName()` returns
`JFB_Modules\Gateways\Base_Gateway` (not the alias name) — and
`Jet_Form_Builder\Gateways\Base_Scenario_Gateway` is a real subclass of
`JFB_Modules\Gateways\Base_Gateway`.

**Pass criteria:** both checks true. Automated as `pg-1`.

## Test 2 (`pg-2`): `Base_Gateway` default method shapes

**Claim:** a minimal concrete subclass (only `get_id()`/`get_name()`/
`retrieve_gateway_meta()` implemented) still gets sane defaults for
`custom_labels()`/`additional_editor_data()`/`required_credentials_fields()`/
`get_payment()`.

**Trigger:** instantiate a throwaway `Base_Gateway` subclass, call all 4 methods.

**Expected observable:** `[]`, `['version' => 0]`, `[]`, `[]` respectively.

**Pass criteria:** all 4 match. Automated as `pg-2`.

## Test 3 (`pg-3`): `Payment_Model` table name and schema

**Claim:** `Payment_Model::table()` resolves to `$wpdb->prefix . 'jet_fb_payments'`
(`Base_Db_Model::DB_TABLE_PREFIX = 'jet_fb_'`), and `schema()` has the 13 documented
columns in the documented order.

**Trigger:** call `Payment_Model::table()` and `array_keys(Payment_Model::schema())`.

**Expected observable:** exact string/array match.

**Pass criteria:** both match. Automated as `pg-3`.

## Test 4 (`pg-4`): `Payment_Model` CRUD round-trip

**Claim:** `Payment_Model::insert()` writes a real row into `wp_jet_fb_payments`
(auto-creating the table via `->create()` if it doesn't already exist), and the row
reads back with the fabricated values intact.

**Setup:** none beyond the model class itself — `->create()` is idempotent
(`Execution_Builder::safe_create()`).

**Trigger:** `(new Payment_Model())->insert([...AGENT-TEST-namespaced fields...])`, then
a direct `$wpdb->get_row()` against the returned insert id.

**Expected observable:** insert id > 0; the read-back row's `transaction_id`,
`amount_value`, `status` match what was inserted.

**Pass criteria:** all fields round-trip. **Cleanup:** the fabricated row is deleted via
`$wpdb->delete()` in a `finally` block regardless of pass/fail, so no `AGENT-TEST-TXN-*`
row is left behind even on assertion failure. Automated as `pg-4`.

## Test 5 (`pg-5`): table-scoped insert hooks

**Claim:** `Base_Db_Model::insert()` fires `jet-form-builder/db/{table_name}/before-insert`
(1-arg filter: `$columns`) and `jet-form-builder/db/{table_name}/after-insert` (2-arg
action: `$id, $columns`) in addition to the generic (non-table-scoped) pair — for
`Payment_Model` specifically, `jet-form-builder/db/payments/before-insert` and
`jet-form-builder/db/payments/after-insert`.

**Trigger:** hook both, insert a second fabricated `AGENT-TEST-TXN-HOOKS-*` row, check
what each callback received.

**Expected observable:** the before-insert filter receives the columns array containing
the fabricated `transaction_id`; the after-insert action receives the real inserted id
and the same columns.

**Pass criteria:** both callbacks fired with the expected values. **Cleanup:** same
`finally`-block delete pattern as Test 4. Automated as `pg-5`.

## Test 6 (`pg-6`): `GATEWAY.SUCCESS`/`GATEWAY.FAILED` are always-registered Events

**Claim:** both events are registered on the `Events_Manager` singleton unconditionally
(not gated on whether any gateway is configured), with ids `GATEWAY.SUCCESS`/
`GATEWAY.FAILED`, and both declare `Default_Process_Executor` in `ignored_executors()`.

**Trigger:** instantiate both event classes directly, call `get_id()`/
`ignored_executors()`; separately, resolve both from `jet_fb_events()->rep_get_item(...)`
to confirm they're really on the live singleton, not just instantiable in isolation.

**Expected observable:** ids match; `ignored_executors()` contains
`Default_Process_Executor::class`; the manager's own registry has both.

**Pass criteria:** all checks true. Automated as `pg-6`.

## Test 7 (`pg-7`): `Gateway_Base_Executor::before_execute()` fires the `on-payment-{type}` hook

**Claim:** `Gateway_Success_Executor`/`Gateway_Failed_Executor`'s inherited
`before_execute()` fires `do_action("jet-form-builder/gateways/on-payment-{type}",
jet_fb_gateway_current())`, and `jet_fb_gateway_current()` safely resolves to `false`
(not a fatal) when no gateway is configured for the current request, despite the
misleading `_or_die` suffix on the underlying method name.

**Trigger:** instantiate `Gateway_Success_Executor` directly (skip `set_event()`/the
full `execute()` loop — `Base_Executor::validate_actions()` no-ops when
`jet_fb_action_handler()->get_all()` is empty, which it is outside a real form
submission), hook `jet-form-builder/gateways/on-payment-success`, call
`->before_execute()`.

**Expected observable:** the hook fires with argument `false` (no gateway configured in
this REST-triggered context); `get_gateway_type()` returns `'success'`.

**Pass criteria:** both match, no fatal. Automated as `pg-7`.

## Test 8 (`pg-8`): `Gateway_Exception` mechanics mirror `Action_Exception`

**Claim:** `Gateway_Exception` is a thin `Handler_Exception` subclass with no
independent behavior — same `is_success()`/`get_form_status()`/`dynamic_error()`
mechanics `jetformbuilder-actions`' `act-2` already confirmed for `Action_Exception`.

**Trigger:** `is_subclass_of()` check; construct a plain `Gateway_Exception` and a
`->dynamic_error()`-wrapped one, check `is_success()`/`get_form_status()` on both.

**Expected observable:** subclass check true; plain exception's message doubles as its
form status and `is_success()` is `false`; the dynamic one's status is prefixed
`derror|` and `is_success()` is also `false`.

**Pass criteria:** all checks true. Automated as `pg-8`.

## Test 9 (`pg-9`): `Payment_View`/`Payment_Count_View` join SQL

**Claim:** `Payment_View::get_prepared_join()` builds a 3-table `LEFT JOIN` chain
(`payment_to_payer_shipping` → `payers_shipping` → `payers`) with real table names in
the generated SQL fragment; `Payment_Count_View` overrides this to a no-op (empty
`$builder->join`), the documented "counting doesn't need the payer/shipping joins"
optimization.

**Trigger:** call `get_prepared_join()` on both view classes against a fresh
`Query_Builder` instance (no query execution), inspect the public `$join` property.

**Expected observable:** `Payment_View`'s join string contains all 3 real table names;
`Payment_Count_View`'s join string is empty.

**Pass criteria:** both match. Automated as `pg-9`.

## Not yet automated — requires live third-party credentials/checkout or a browser

These are documented in `SKILL.md`'s "Not yet automated" section and are **not**
duplicated into `tests.php` — they genuinely need something this single-REST-request
suite can't fabricate:

- A real PayPal sandbox checkout round-trip (`Get_Token` → `Pay_Now_Action` →
  `Capture_Payment_Action` actually hitting PayPal's API) — needs live PayPal sandbox
  `client_id`/`secret` credentials not configured on this site.
- The `parse_request` webhook catch (`Module::on_has_gateway_request()`) firing from a
  real `?jet_form_gateway=paypal&token=...` browser redirect back from PayPal's own
  domain — needs a real front-end request cycle.
- The wp-admin Payments list/single/print/export screens' actual rendered HTML — the
  meta-box/table-view/page classes were confirmed to load and reference the right
  query-views, but visual output wasn't checked.
- Stripe — no source present in this checkout; nothing in this skill is verified against
  it (see `SKILL.md`'s Gotchas).
