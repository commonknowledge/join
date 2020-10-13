<?php

function init_assets()
{
	if (WP_DEBUG === true) {
		wp_enqueue_script('style', 'http://localhost:3001/index.js');
	} else {
		wp_enqueue_style('style', get_stylesheet_directory_uri() .'/style.css');
	}
}

add_action('init', 'init_assets');
