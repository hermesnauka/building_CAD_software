<?php
if (!defined('ABSPATH')) {
    exit;
}

function cad_edu_theme_setup(): void
{
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['comment-form', 'comment-list', 'search-form', 'gallery', 'caption']);
}
add_action('after_setup_theme', 'cad_edu_theme_setup');

function cad_edu_theme_assets(): void
{
    wp_enqueue_style('cad-edu-theme-style', get_stylesheet_uri(), [], '0.1.0');
}
add_action('wp_enqueue_scripts', 'cad_edu_theme_assets');
