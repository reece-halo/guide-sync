<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Function to register custom post type and taxonomy
function register_guide_and_faq_list() {
    // Register the 'guide' custom post type
    $labels = array(
        'name'               => _x( 'Guides', 'Post type general name', 'textdomain' ),
        'singular_name'      => _x( 'Guide', 'Post type singular name', 'textdomain' ),
        'menu_name'          => _x( 'Guides', 'Admin Menu text', 'textdomain' ),
        'name_admin_bar'     => _x( 'Guide', 'Add New on Toolbar', 'textdomain' ),
        'add_new'            => __( 'Add New', 'textdomain' ),
        'add_new_item'       => __( 'Add New Guide', 'textdomain' ),
        'new_item'           => __( 'New Guide', 'textdomain' ),
        'edit_item'          => __( 'Edit Guide', 'textdomain' ),
        'view_item'          => __( 'View Guide', 'textdomain' ),
        'all_items'          => __( 'All Guides', 'textdomain' ),
        'search_items'       => __( 'Search Guides', 'textdomain' ),
        'not_found'          => __( 'No guides found.', 'textdomain' ),
        'not_found_in_trash' => __( 'No guides found in Trash.', 'textdomain' ),
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => array( 'slug' => 'guide' ),
        'capability_type'    => 'post',
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => 20,
        'supports'           => array( 'title', 'excerpt' ),
        'show_in_rest'       => true,
    );

    register_post_type( 'guide', $args );

    // Register the 'faq_list' custom taxonomy
    $taxonomy_labels = array(
        'name'              => _x( 'FAQ Lists', 'taxonomy general name', 'textdomain' ),
        'singular_name'     => _x( 'FAQ List', 'taxonomy singular name', 'textdomain' ),
        'search_items'      => __( 'Search FAQ Lists', 'textdomain' ),
        'all_items'         => __( 'All FAQ Lists', 'textdomain' ),
        'parent_item'       => __( 'Parent FAQ List', 'textdomain' ),
        'parent_item_colon' => __( 'Parent FAQ List:', 'textdomain' ),
        'edit_item'         => __( 'Edit FAQ List', 'textdomain' ),
        'update_item'       => __( 'Update FAQ List', 'textdomain' ),
        'add_new_item'      => __( 'Add New FAQ List', 'textdomain' ),
        'new_item_name'     => __( 'New FAQ List Name', 'textdomain' ),
        'menu_name'         => __( 'FAQ Lists', 'textdomain' ),
    );

    $taxonomy_args = array(
        'hierarchical'      => true,
        'labels'            => $taxonomy_labels,
        'show_ui'           => true,
        'show_admin_column' => true,
        'query_var'         => true,
        'rewrite'           => array( 'slug' => 'faq-list' ),
        'show_in_rest'      => true,
    );

    register_taxonomy( 'faq_list', 'guide', $taxonomy_args );
}
