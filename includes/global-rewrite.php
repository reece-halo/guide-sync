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

// Register custom query variables
add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'product';
    $vars[] = 'external_article_id';
    return $vars;
} );

// Validate the product based on FAQ lists
function validate_product_for_article( $product, $post_id ) {
    $faq_lists = wp_get_object_terms( $post_id, 'faq_list' );

    if ( empty( $faq_lists ) ) {
        return false; // No FAQ list means no valid product
    }

    foreach ( $faq_lists as $faq ) {
        // Traverse up to the root FAQ list
        $root_faq = get_root_faq_list( $faq );

        if ( $root_faq ) {
            $root_faq_name = strtolower( sanitize_title( $root_faq->name ) );

            // Define product-to-FAQ list rules
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

        if ( $product && $external_article_id ) {
            $post = get_posts( [
                'post_type'   => 'guide',
                'meta_key'    => 'external_article_id',
                'meta_value'  => $external_article_id,
                'numberposts' => 1,
            ] );

            if ( empty( $post ) || ! validate_product_for_article( $product, $post[0]->ID ) ) {
                $query->set_404();
                status_header( 404 );
                return;
            }

            // Adjust the query to fetch the correct post
            $query->set( 'p', $post[0]->ID );
            $query->set( 'post_type', 'guide' );
        } elseif ( $product || $external_article_id ) {
            // Either product or external_article_id is missing
            $query->set_404();
            status_header( 404 );
        }
    }
} );

// Add template handling for custom guide template
add_filter( 'template_include', function ( $template ) {
    if ( get_query_var( 'product' ) && get_query_var( 'external_article_id' ) ) {
        $custom_template = locate_template( 'single-guide.php' );

        // If a custom guide template exists, use it
        if ( $custom_template ) {
            return $custom_template;
        }
    }

    return $template;
} );

// Flush rewrite rules on plugin activation
register_activation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );

// Flush rewrite rules on plugin deactivation
register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );
