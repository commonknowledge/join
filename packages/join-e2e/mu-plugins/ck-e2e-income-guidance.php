<?php
/**
 * Plugin Name: CK Join Flow E2E: income guidance switch
 * Description: Switches income guidance on, as an add-on would, unless the ck_e2e_income_guidance_enabled option is set to off.
 */

// On unless a spec has turned it off. Defaulting to on matters: the seed
// saves income guidance settings, and those fields only exist if the flag is
// on when the request starts, before the seed can set any option.
add_filter('ck_join_flow_income_guidance_enabled', function ($enabled) {
    return $enabled || (bool) get_option('ck_e2e_income_guidance_enabled', '1');
});
