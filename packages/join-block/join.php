<?php
/**
 * Plugin Name:     Join
 * Description:     Example block written with ESNext standard and JSX support – build step required.
 * Version:         0.1.0
 * Author:          The WordPress Contributors
 * License:         GPL-2.0-or-later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:     uk-greens
 *
 * @package         uk-greens
 */

require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

require 'lib/settings.php';
require 'lib/services/join_service.php';
require 'lib/services/gocardless_service.php';

add_action( 'init', 'uk_greens_join_block_init');
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

	$bundle_js = 'dist/join-flow/bundle.js';
	wp_enqueue_script(
		'uk-greens-join-block-js',
		plugins_url($bundle_js, __FILE__),
		array(),
		filemtime( "$dir/$bundle_js" ),
		true
	);

	register_block_type( 'uk-greens/join', array(
		'editor_script' => 'uk-greens-join-block-editor',
		'editor_style'  => 'uk-greens-join-block-editor',
		'style'         => 'uk-greens-join-block',
		'script'		=> 'uk-greens-join-block-js'
	));
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'join/v1', '/join', array(
		'methods' => 'POST',
		'permission_callback' => function ($req) {
			return true;
		},
		'callback' => function ($req) {
			return rest_ensure_response(handle_join($req->get_json_params()));
		},
	));
	register_rest_route( 'join/v1', '/gocardless', array(
		'methods' => 'POST',
		'permission_callback' => function ($req) {
			return true;
		},
		'callback' => function ($req) {
			return rest_ensure_response(gocardless_create_redirect($req->get_json_params()));
		},
	));
} );
