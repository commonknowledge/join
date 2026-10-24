# Legacy settings fixtures

Raw `wp_options` rows exactly as the settings page stored them while it was
built with Carbon Fields (up to plugin version 1.4.x): one option per field,
named after the field with a leading underscore, checkboxes stored as `"yes"`
or `""`. Complex fields such as the membership plans are spread over one
option per sub-field and row (`_membership_plans|label|0|0|value`).

`membership-plans.json` also holds the copy of each plan the plugin stores
under its ID when the settings are saved (`ck_join_flow_membership_plan_<id>`).
Those are written as arrays, as WordPress stores them. "Legacy Standard", which
has no Price Point ID, is stored under its name alone
(`ck_join_flow_membership_plan_legacy-standard`), as sites last saved before
plugin version 1.4.4 have it. The plugin finds it by working out each stored
plan's ID.

The `legacy` tests in each settings spec load one of these files straight into
the database and check the plugin still reads the settings and the join form
still behaves accordingly. They stand for sites that saved their settings
before the settings page was rebuilt.

Don't regenerate these after the rebuild: they must keep the old format. If
the plugin stops reading this format on purpose (for example after a one-off
migration), change the tests to run that migration first.
