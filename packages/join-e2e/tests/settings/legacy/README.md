# Legacy settings fixtures

Raw `wp_options` rows exactly as the settings page stored them while it was
built with Carbon Fields (up to plugin version 1.4.x): one option per field,
named after the field with a leading underscore, checkboxes stored as `"yes"`
or `""`.

The `legacy` tests in each settings spec load one of these files straight into
the database and check the plugin still reads the settings and the join form
still behaves accordingly. They stand for sites that saved their settings
before the settings page was rebuilt.

Don't regenerate these after the rebuild: they must keep the old format. If
the plugin stops reading this format on purpose (for example after a one-off
migration), change the tests to run that migration first.
