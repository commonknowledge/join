<?php
/**
 * Plugin Name:     The Green Party Join Plugin
 * Description:     Green Party join flow plugin.
 * Version:         0.0.3
 * Author:          Common Knowledge <hello@commonknowledge.coop>
 * Text Domain:     uk-greens
 *
 * @package         uk-greens
 */

require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

add_action( 'after_setup_theme', function () {
    \Carbon_Fields\Carbon_Fields::boot(); 
});

require 'lib/settings.php';
require 'lib/services/join_service.php';
require 'lib/services/gocardless_service.php';

require 'lib/blocks.php';

add_action('init', 'uk_greens_join_block_init');
function uk_greens_join_block_init() {
	$dir = dirname( __FILE__);

	$script_asset_path = "$dir/build/index.asset.php";
	if ( ! file_exists( $script_asset_path ) ) {
		throw new Error(
			'You need to run `npm start` or `npm run build` for the "uk-greens/join" block first.'
		);
	}
	$index_js     = 'build/index.js';
	$script_asset = require( $script_asset_path );
	wp_register_script(
		'uk-greens-join-block-editor',
		plugins_url( $index_js, __FILE__ ),
		$script_asset['dependencies'],
		$script_asset['version']
	);

	$bundle_js = 'build/join-flow/bundle.js';
	if (WP_DEBUG === true) {
		wp_enqueue_script(
			'uk-greens-join-block-js',
			"http://localhost:3000/bundle.js",
			array(),
			false,
			true
		);
	} else {
		wp_enqueue_script(
			'uk-greens-join-block-js',
			plugins_url($bundle_js, __FILE__),
			array(),
			filemtime( "$dir/$bundle_js" ),
			true
		);
	}

	register_block_type( 'uk-greens/join', array(
		'editor_script' => 'uk-greens-join-block-editor',
		'editor_style'  => 'uk-greens-join-block-editor',
		'style'         => 'uk-greens-join-block',
		'script'		=> 'uk-greens-join-block-js'
	));
}

add_action('rest_api_init', function () {
	register_rest_route( 'join/v1', '/join', array(
		'methods' => 'POST',
		'permission_callback' => function ($req) {
			return true;
		},
		'callback' => function ($req) {
			return rest_ensure_response(handle_join($req->get_json_params()));
		},
	));
} );
