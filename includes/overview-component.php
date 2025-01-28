<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Function to generate guides list grouped by 'faq_list' taxonomy with custom links
function display_guides_grouped_by_faq_list_custom_links( $atts ) {
    // Attributes with default values
    $atts = shortcode_atts( array(
        'taxonomy'        => 'faq_list',    // Taxonomy to group by
        'posts_per_term'  => -1,            // -1 to show all guides in each term
        'orderby'         => 'title',       // Field to order guides by
        'order'           => 'ASC',         // Order direction
    ), $atts, 'guides_grouped_faq_custom' );

    $taxonomy = sanitize_text_field( $atts['taxonomy'] );
    $posts_per_term = intval( $atts['posts_per_term'] );
    $orderby = sanitize_text_field( $atts['orderby'] );
    $order = sanitize_text_field( $atts['order'] );

    // Get all terms for the specified taxonomy
    $terms = get_terms( array(
        'taxonomy'   => $taxonomy,
        'hide_empty' => true, // Only terms with guides
    ) );

    if ( empty( $terms ) || is_wp_error( $terms ) ) {
        return '<p>No categories found.</p>';
    }

    // Get the current page URL where the shortcode is used
    if ( is_singular() ) {
        $current_page_id = get_the_ID();
    } else {
        // For archive pages or other contexts
        $current_page_id = get_queried_object_id();
    }

    $current_page_url = get_permalink( $current_page_id );

    if ( ! $current_page_url ) {
        return '<p>Invalid page URL.</p>';
    }

    $output = '<div class="guides-grouped-list">';

    foreach ( $terms as $term ) {
        $output .= '<section class="guide-category-section">';

        // Taxonomy Title
        $output .= '<h2 class="guide-category-title">' . esc_html( $term->name ) . '</h2>';

        // Query guides within this term
        $query = new WP_Query( array(
            'post_type'      => 'guide',
            'posts_per_page' => $posts_per_term,
            'orderby'        => $orderby,
            'order'          => $order,
            'tax_query'      => array(
                array(
                    'taxonomy' => $taxonomy,
                    'field'    => 'term_id',
                    'terms'    => $term->term_id,
                ),
            ),
        ) );

        if ( $query->have_posts() ) {
            $output .= '<ul class="guides-list">';
            while ( $query->have_posts() ) {
                $query->the_post();

                // Retrieve 'external_article_id' from post meta
                $external_id = get_post_meta( get_the_ID(), 'external_article_id', true );

                if ( ! empty( $external_id ) ) {
                    // Construct the new href
                    $new_href = trailingslashit( $current_page_url ) . esc_attr( $external_id );

                    // Ensure proper URL format
                    $new_href = esc_url( $new_href );
                } else {
                    // Fallback to guide's permalink if 'external_article_id' is missing
                    $new_href = get_permalink();
                }

                $output .= '<li class="guide-item"><a href="' . $new_href . '">' . get_the_title() . '</a></li>';
            }
            $output .= '</ul>';
        } else {
            $output .= '<p>No guides found in this category.</p>';
        }

        wp_reset_postdata();

        $output .= '</section>';
    }

    $output .= '</div>';

    return $output;
}
add_shortcode( 'guides_grouped_faq_custom', 'display_guides_grouped_by_faq_list_custom_links' );
