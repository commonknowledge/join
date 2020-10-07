<?php

// define( 'WP_DEBUG', true );
// define( 'WP_DEBUG_LOG', true );

ChargeBee_Environment::configure(getenv('CHARGEBEE_SITE_NAME'), getenv('CHARGEBEE_API_KEY'));
