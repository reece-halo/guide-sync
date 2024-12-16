<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'rewrite-rules.php';

// Add rewrite rules for custom URL structure
add_action( 'init', function () {
    // Add rewrite tags
    add_rewrite_tag( '%product%', '([^/]+)' );
    add_rewrite_tag( '%external_article_id%', '([0-9]+)' );

    // Add custom rewrite rule for guide URLs
    add_rewrite_rule(
        '^([^/]+)/guides/([0-9]+)/?$',
        'index.php?product=$matches[1]&external_article_id=$matches[2]',
        'top'
    );
} );

// Register query variables
add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'product';
    $vars[] = 'external_article_id';
    return $vars;
} );

// Validate the product based on FAQ lists
function validate_product_for_article( $product, $post_id ) {
    // Get FAQ lists associated with the post
    $faq_lists = wp_get_object_terms( $post_id, 'faq_list' );

    if ( empty( $faq_lists ) ) {
        return false; // No FAQ list means no valid product
    }

    foreach ( $faq_lists as $faq ) {
        // Traverse up to the root FAQ list
        $root_faq = get_root_faq_list( $faq );

        if ( $root_faq ) {
            $root_faq_name = strtolower( sanitize_title( $root_faq->name ) );
            error_log($root_faq_name);

            // Define your product-to-FAQ list rules
            if ( faqListsForHaloCRM( $root_faq_name ) && $product === 'halocrm' ) {
                return true;
            }
            if ( faqListsForHaloPSA( $root_faq_name ) && $product === 'halopsa' ) {
                return true;
            }
            if ( faqListsForHaloITSM( $root_faq_name ) && $product === 'haloitsm' ) {
                return true;
            }
        }
    }

    return false; // No matching rule
}

// Modify the main query to validate product and ID
add_action( 'pre_get_posts', function ( $query ) {
    if ( $query->is_main_query() && ! is_admin() ) {
        $product = get_query_var( 'product' );
        $external_article_id = get_query_var( 'external_article_id' );

        // If both product and article ID are specified, validate them
        if ( $product && $external_article_id ) {
            // Get the post with the given external_article_id
            $post = get_posts( [
                'post_type'  => 'guide',
                'meta_key'   => 'external_article_id',
                'meta_value' => $external_article_id,
                'numberposts' => 1,
            ] );

            if ( empty( $post ) || ! validate_product_for_article( $product, $post[0]->ID ) ) {
                // Product does not match or post not found, display 404
                $query->set_404();
                status_header( 404 );
                return;
            }

            // Otherwise, adjust the query to fetch the correct post
            $query->set( 'p', $post[0]->ID );
            $query->set( 'post_type', 'guide' );
        }
    }
} );

// Flush rewrite rules on plugin activation
register_activation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );

// Flush rewrite rules on plugin deactivation
register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );
