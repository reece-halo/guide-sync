<?php
/**
 * Plugin Name: Custom Guide and FAQ List Plugin
 * Description: Adds a custom post type "Guide" and a hierarchical taxonomy "FAQ List".
 * Version: 1.0
 * Author: Reece English
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include the file containing post type and taxonomy registration
require_once plugin_dir_path( __FILE__ ) . 'includes/register-posts-and-taxonomy.php';

// Hook to register custom post type and taxonomy
add_action( 'init', 'register_guide_and_faq_list' );

require_once plugin_dir_path( __FILE__ ) . 'includes/faq-list-sync.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/kb-article-sync.php';
