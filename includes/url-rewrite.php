<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'rewrite-rules.php';

// Add rewrite rules for custom URL structure
add_action( 'init', function () {
    add_rewrite_tag( '%external_article_id%', '([^/]+)' );
    add_rewrite_tag( '%product%', '([^/]+)' );

    add_rewrite_rule(
        '^([^/]+)/guides/([^/]+)/?$',
        'index.php?post_type=guide&external_article_id=$matches[2]&product=$matches[1]',
        'top'
    );
} );

// Determine all applicable products based on root FAQ lists
function get_products_from_faq_lists( $post_id ) {
    $faq_lists = wp_get_object_terms( $post_id, 'faq_list' );

    if ( empty( $faq_lists ) ) {
        return [ 'default-product' ]; // Fallback product
    }

    $products = [];

    foreach ( $faq_lists as $faq ) {
        // Get the root FAQ list
        $root_faq = get_root_faq_list( $faq );

        if ( $root_faq ) {
            $root_faq_name = strtolower( sanitize_title( $root_faq->name ) );

            // Map root FAQ names to products
            if ( faqListsForHaloCRM( $root_faq_name ) ) {
                $products[] = 'halocrm';
            }
            if ( faqListsForHaloPSA( $root_faq_name ) ) {
                $products[] = 'halopsa';
            }
            if ( faqListsForHaloITSM( $root_faq_name ) ) {
                $products[] = 'haloitsm';
            }
        }
    }

    return array_unique( $products ); // Ensure no duplicates
}


// Modify the permalink structure for 'guide' post type
add_filter( 'post_type_link', function ( $post_link, $post ) {
    if ( 'guide' === $post->post_type ) {
        $external_article_id = get_post_meta( $post->ID, 'external_article_id', true );
        $products = get_products_from_faq_lists( $post->ID );

        // Generate multiple permalinks if needed
        if ( $external_article_id && ! empty( $products ) ) {
            $links = array_map( function ( $product ) use ( $external_article_id ) {
                return home_url( sprintf( '/%s/guides/%s/', $product, $external_article_id ) );
            }, $products );

            // Return the first permalink as the canonical one
            return reset( $links );
        }
    }

    return $post_link;
}, 10, 2 );

// Adjust the main query to fetch the correct post
add_action( 'pre_get_posts', function ( $query ) {
    if ( $query->is_main_query() && ! is_admin() && $query->get( 'external_article_id' ) ) {
        $external_article_id = $query->get( 'external_article_id' );

        $meta_query = [
            [
                'key'   => 'external_article_id',
                'value' => $external_article_id,
                'compare' => '='
            ],
        ];

        $query->set( 'post_type', 'guide' );
        $query->set( 'meta_query', $meta_query );
    }
} );

// Flush rewrite rules on activation
register_activation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );

// Flush rewrite rules on deactivation
register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );
