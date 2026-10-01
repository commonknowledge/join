<?php
/**
 * Plugin Name: CK Join Flow E2E: income guidance switch
 * Description: Switches income guidance on when the ck_e2e_income_guidance_enabled option is set, as an add-on would.
 */

add_filter('ck_join_flow_income_guidance_enabled', function ($enabled) {
    return $enabled || (bool) get_option('ck_e2e_income_guidance_enabled');
});
