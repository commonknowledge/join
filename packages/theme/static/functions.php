<?php

function init_assets()
{

    wp_enqueue_style('typekit-fonts', '//use.typekit.net/ejc1mhh.css');

    if (WP_DEBUG === true) {
        wp_enqueue_script('style', 'http://localhost:3001/index.js');
    } else {
        wp_enqueue_style('style', get_stylesheet_directory_uri() . '/style.css');
    }
}

add_action('init', 'init_assets');

add_theme_support('title-tag');
