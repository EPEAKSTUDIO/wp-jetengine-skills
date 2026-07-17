---
name: jetappointments-core
description: Use when writing PHP against Jet Appointments Booking's own internals — the jet_apb() accessor and its component properties, how an appointment is actually stored (custom DB tables, not a CPT or JetEngine CCT), the calendar/time-slots customization hooks (jet-apb/calendar/custom-schedule, jet-apb/time-slots/slots-html/slots-list), the form-action appointment-insert pipeline, or the public confirm/cancel action-link pages. Captures verified behavior of Jet Appointments Booking 2.5.1 source (plugins/jet-appointments-booking/), live-verified 2026-07-16 against jackfruit.epeak.studio (5/5 tests.php assertions passing).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# Jet Appointments Booking — Core Internals

Verified facts about Jet Appointments Booking's own data model and extension points,
independent of its JetFormBuilder/WooCommerce integrations (see
`jetappointments-integrations` for those). Confirmed against Jet Appointments Booking
2.5.1 source, `plugins/jet-appointments-booking/`.

## The accessor: `jet_apb()` is a private-constructor singleton

`jet_apb()` (`jet-appointments-booking.php:40-42`) returns `JET_APB\Plugin::instance()`.
`Plugin` (`includes/plugin.php:21-398`) is a classic single-instance-static-property
singleton: `__construct()` is `private` (`:80`), `instance()` lazily creates
`self::$instance` (`:388-397`), and the file itself calls `Plugin::instance()` once at
the bottom (`:400`) so the singleton always exists once the plugin has loaded (guarded
by a hard dependency on JetEngine — the constructor bails early with an admin notice if
`jet_engine()` isn't available, `:82-91`).

**Never call `new JET_APB\Plugin()` or reinstantiate any of its component classes
constructed inside `init_components()` yourself** — same landmine class as this repo's
`jetsmartfilters-query` skill already documents for JetSmartFilters' `Storage\Controller`
(a second `new` of an already-`require`d/registered class can crash the request or,
milder, silently double-register hooks). Read a component off `jet_apb()->x`, don't
build your own.

Real component properties, all assigned in `Plugin::init_components()`
(`includes/plugin.php:205-254`), confirmed either by that assignment or by the class
docblock's `@property` list (`:9-18`):

| Property | Class | What it is |
|---|---|---|
| `jet_apb()->db` | `JET_APB\DB\Manager` | custom-table CRUD for appointments (below) |
| `jet_apb()->settings` | `JET_APB\Admin\Settings` | plugin settings store, `get( $key )` (`admin/settings.php:590`) |
| `jet_apb()->calendar` | `JET_APB\Calendar` | working-hours/schedule resolution, incl. `custom-schedule` filter |
| `jet_apb()->form` | `JET_APB\Form` | shortcode/Elementor/Gutenberg booking form renderer |
| `jet_apb()->rest_api` | `JET_APB\Rest_API\Manager` | registers this plugin's endpoints into JetEngine's REST layer |
| `jet_apb()->wc` | `JET_APB\WC_Integration` | WooCommerce integration (see `jetappointments-integrations`) |
| `jet_apb()->statuses` | `JET_APB\Statuses` | appointment status taxonomy (`pending`/`processing`/`on-hold`/`completed`/`cancelled`/`refunded`/`failed`) and status-group helpers (`valid_statuses()`, `invalid_statuses()`, `in_progress_statuses()`, `exclude_statuses()`, `finished_statuses()` — `includes/statuses.php:60-127`) |
| `jet_apb()->workflows` | `JET_APB\Workflows\Manager` | notification/automation workflow engine |
| `jet_apb()->tools` | `JET_APB\Tools` | misc helpers |
| `jet_apb()->service_provider_relations_map` | `JET_APB\Service_Provider_Relations_Map` | resolves which providers offer which services |
| `jet_apb()->providers_meta` | `JET_APB\Admin\Cpt_Meta_Box\Providers_Meta` | **only set if `settings->get('providers_cpt')` is truthy** (`:232-234`) — providers are an optional feature; guard before using |
| `jet_apb()->elementor` / `->bricks` / `->blocks` | respective `*_Integration\Manager` | page-builder widget/block registration |
| `jet_apb()->macros` | `JET_APB\Macros` | assigned later, on `init` via `register_macros()` (`:256-258`), not in `init_components()` — don't read it before `init` has fired |

**Gotcha:** `jet_apb()->providers_meta` is `null` on any site where the optional
"Providers" CPT feature is off (the default) — same "check the gating setting, don't
just `class_exists()`" lesson this repo's `jetengine-modules` skill already documents
for Dynamic Visibility/Data Stores.

## Data model: custom `wp_jet_*` DB tables, not a CPT or CCT

Appointments are **not** stored as a post type or a JetEngine CCT — they live in five
dedicated tables, all subclassing the abstract `JET_APB\DB\Base` (`includes/db/base.php`).
`Base::table()` (`:64-66`) returns `$wpdb->prefix . 'jet_' . $this->table_slug()`; each
subclass's `table_slug()` gives the real table name:

| `jet_apb()->db->...` property | `table_slug()` | Real table |
|---|---|---|
| `->appointments` | `appointments` (`appointments.php:40-42`) | `wp_jet_appointments` |
| `->appointments_meta` | `appointments_meta` (`appointments-meta.php:23-25`) | `wp_jet_appointments_meta` (EAV-style, `appointment_id`/`meta_key`/`meta_value`) |
| `->excluded_dates` | `appointments_excluded` (`excluded-dates.php:36-38`) | `wp_jet_appointments_excluded` |
| `->appointments_external` | `appointments_external` (`appointments-external.php:25-27`) | `wp_jet_appointments_external` (synced-from-Google-Calendar events) |
| `->external_meta` | `external_meta` (`external-meta.php:23-25`) | `wp_jet_external_meta` |

Tables are created via `dbDelta()` from `Base::install_table()`
(`includes/db/base.php:198-206`), hooked on `init` for admin, non-AJAX requests
(`:54-58`) — they self-heal/recreate on every admin page load if missing, so a fresh
install doesn't need a manual migration step.

**The real CRUD/read API is `JET_APB\Resources\Appointment_Model`**
(`includes/resources/appointment-model.php`), not raw `$wpdb` calls:
- `new Appointment_Model( $data = [], $ID = false )` (`:13-37`) — passing `$ID` loads the
  existing row via `jet_apb()->db->get_appointment_by('ID', $ID)` first (`:16`), then
  layers `$data` on top; passing only `$data` builds a brand-new in-memory model. Safe to
  instantiate directly (a plain data object, no singleton/hook-registration side effects).
- `->get( $key )` / `->set( $key, $value )` (`:218`, `:269`) — typed field accessors.
- `->set_meta( $array )` — appointment meta (backed by `appointments_meta` table).
- `->save()` (`:318`) — the actual `INSERT`/`UPDATE`, called after mutation.
- `->to_array()` (`:549`).

`JET_APB\DB\Manager` (`jet_apb()->db`, `includes/db/manager.php`) wraps this with
higher-level helpers: `get_appointment_by( $field, $value )` (`:207-213`, returns one row
+ meta merged in), `get_appointments_by( $field, $value )` (`:190-200`, no meta),
`add_appointment( $data )` (`:162-165`, thin wrapper around
`(new Appointment_Model($data))->save()`), `delete_appointment( $id )` (`:128-154`).

**Two useful hooks fire on every raw DB write**, from `Base::update()`
(`includes/db/base.php:119-149`), independent of which table:
- `do_action( "jet-apb/db/update/{$table_slug}", $new_data, $where, $old_data )` (`:137`)
  — e.g. `jet-apb/db/update/appointments`.
- `do_action( "jet-apb/db/update/{$table_slug}/status", $new_data )` (`:144`) — fires
  additionally, only when the row's `status` column value actually changed.

## Calendar customization: `jet-apb/calendar/custom-schedule` — 5 args, not 1

The single real hook for overriding buffer time/lock time/working-hours/days-off from
custom PHP, confirmed at `includes/calendar.php:646`:

```php
apply_filters( 'jet-apb/calendar/custom-schedule', $value, $meta_key, $default_value, $provider, $service )
```

Fired from `Calendar::get_schedule_settings( $provider, $service, $default_value, $meta_key )`
(`:615-647`) — the single resolver every schedule-ish setting (`working_hours`,
`days_off`, `locked_time`, `min_slot_count`, `max_slot_count`, buffer settings, etc.)
goes through, whether it ultimately came from the global settings page or a
per-provider/per-service "custom schedule" meta-box override. **Real snippets hook this
one filter for every different `$meta_key`** — check `$meta_key` inside the callback
rather than assuming a dedicated hook exists per setting:

```php
add_filter( 'jet-apb/calendar/custom-schedule', function( $value, $meta_key, $default_value, $provider, $service ) {
    if ( 'days_off' === $meta_key ) {
        // e.g. block the next N days as a rolling buffer
    }
    return $value;
}, 10, 5 );
```

## Time-slot rendering: `jet-apb/time-slots/slots-html/slots-list` — 6 args

`JET_APB\Time_Slots::generate_slots_html( $slots, $format, $dataset, $date, $service, $provider )`
(`includes/time-slots.php:293-...`) is a **static** method that turns a raw slots array
into the front-end HTML. Right after receiving its args it fires:

```php
apply_filters( 'jet-apb/time-slots/slots-html/slots-list', $slots, $format, $dataset, $date, $service, $provider )
```

(`:307-310`) — the hook to remove/reorder specific slot entries (e.g. "exclude slots
shorter than the service's default duration") before HTML is built. A sibling filter,
`jet-apb/time-slots/slots-html/capacity-format` (`:325`), only affects the
capacity-counter label text, not the slot list itself. `Time_Slots::get_max_slots_number()`
(`:282-284`) is a separate, single-value filter (`jet-apb/time-slots/max-slots`, default
`250`) capping how many slots get generated per day to avoid runaway loops.

## Appointment-insert pipeline: `jet-apb/form-action/insert-appointment`

Both real entry points that create an appointment (the classic form-handler action,
`includes/insert-appointment.php`, and the REST "add appointment" endpoint,
`includes/rest-api/endpoint-add-appointment.php:91`) share one trait,
`JET_APB\Insert_Appointment::run_action()` (`includes/insert-appointment.php:58-181`).
For **each individual appointment** in the batch it builds an `Appointment_Model`, then:

```php
do_action_ref_array( 'jet-apb/form-action/insert-appointment', [
    &$appointment,  // Appointment_Model, passed BY REFERENCE — mutate it in-place
    $this,          // the action/handler instance (has getSettings()/getRequest())
] );

$appointment->save(); // <-- save() happens AFTER the hook, so hook mutations persist
```

(`:153-158`). **Because it's `do_action_ref_array` with `&$appointment`, a callback can
mutate the model directly** (e.g. `$appointment->set('status', 'completed')` or
`$appointment->set_meta([...])`) and that change is what actually gets written by the
`->save()` call right after — this is the correct place for "force a default status on
insert" or "stamp custom meta" logic, not a `jet-apb/db/update/appointments` post-save
hook (which fires per-write, not per-logical-appointment, and is too late to affect the
initial insert's own row).

**A second, batch-level hook** fires once per *group* of appointments (multi-slot
booking), not per individual row:

```php
do_action( 'jet-apb/form-action/insert-appointments-group', array_values( $appointments ), $collection );
```

(`:174`) — only fires when either a `group_ID` was assigned or the `multi_booking`
setting is on (`:173`); `$appointments` here is the array of already-saved
`Appointment_Model` instances, `$collection` is the `Appointment_Collection` that built
them.

## Public confirm/cancel action-link pages

`JET_APB\Public_Actions\Manager` (`includes/public-actions/manager.php`) implements the
"click a link in a confirmation email to confirm/cancel an appointment" flow, gated by
the `allow_action_links` setting (`is_enabled()`, `:71-73`). Two built-in actions
(`Confirm`, `Cancel`, both extending `Public_Actions\Actions\Base`) register via
`do_action( 'jet-apb/public-actions/register', $this )` (`:44`) — the hook for adding a
third custom action type.

The result page's content is filterable — this is what the backlog's
`jet-apb/public-actions/custom-action-page-content` lead refers to, confirmed at
`render_action_result_page( $key = 'action', $message = '' )`
(`includes/public-actions/manager.php:115-131`):

```php
apply_filters( 'jet-apb/public-actions/custom-' . $key . '-page-content', false, $this )
```

`$key` is the literal string `'action'` on the success path (`render_result_page()`,
`:200-204`, called after a successful `confirm`/`cancel`) and `'error'` on the failure
path (`render_error_page()`, `:191-198`, e.g. invalid/already-used token) — so the two
real, distinct hook names are `jet-apb/public-actions/custom-action-page-content` and
`jet-apb/public-actions/custom-error-page-content`, both filtering `(false, $manager)` →
return an HTML string to fully override the default template/message page, or `false`
to fall through to the plugin's own rendering. `$this->get_action()` (`:206-208`) on the
passed `$manager` returns which action ran (`'confirm'`/`'cancel'`), for branching inside
a shared callback.

## REST API surface

Jet Appointments Booking does **not** register its own REST namespace — it hooks
JetEngine's REST layer instead: `JET_APB\Rest_API\Manager::__construct()`
(`includes/rest-api/manager.php:14-16`) adds an action on
`jet-engine/rest-api/init-endpoints`, then `init_rest( $api_manager )` (`:23-40`) calls
`$api_manager->register_endpoint( new Endpoint_* )` for 13 endpoint classes (date slots,
refresh dates, service providers, provider services, appointments list,
delete/update/add appointment, update workflows/integrations, generate Zoom token,
appointment meta, get appointment, external meta). Actual route slugs/URLs are resolved
through JetEngine's own `jet_engine()->api->get_route( $slug, $full )`
(`get_urls()`, `:50-64`) — e.g. `'add_appointment' => get_route('appointment-add-appointment')`
— so the final URL prefix is whatever JetEngine's REST manager uses, not a
plugin-specific namespace. **Not yet traced further** (which JetEngine REST version/
namespace these land under, and full request/response shapes for each endpoint) — flagged
as an open gap, see `TEST-REGIMEN.md`.

## How this was verified

Read `jet-appointments-booking.php`, `includes/plugin.php`, `includes/calendar.php`,
`includes/time-slots.php`, `includes/insert-appointment.php`, `includes/statuses.php`,
`includes/admin/settings.php`, `includes/db/{manager,base,appointments,appointments-meta,
excluded-dates,appointments-external,external-meta}.php`,
`includes/resources/appointment-model.php`, `includes/public-actions/manager.php`,
`includes/public-actions/actions/base.php`, and `includes/rest-api/manager.php` directly
in Jet Appointments Booking 2.5.1 source (`plugins/jet-appointments-booking/`), confirming
every hook's exact arg count/order by reading the `apply_filters()`/`do_action()` call
site itself (not inferring from a gist), and confirming the `jet_apb()` singleton's safe
call pattern per `HANDOFF.md`'s "Adding a new Crocoblock plugin" lesson (grepped for
`::instance()`/`new self()` before ever documenting a constructor call). Not yet verified
against a running site — see `TEST-REGIMEN.md`; `tests.php` has reachability/source-presence
smoke tests ready to deploy.
