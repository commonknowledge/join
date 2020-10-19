<?php

// define( 'WP_DEBUG', true );
// define( 'WP_DEBUG_LOG', true );

ChargeBee_Environment::configure($_ENV['CHARGEBEE_SITE_NAME'], $_ENV['CHARGEBEE_API_KEY']);
