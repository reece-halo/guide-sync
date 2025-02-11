<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function display_faq_list_hierarchy_with_root( $atts ) {
    global $wp;

    // Shortcode attributes
    $atts = shortcode_atts( array(
        'taxonomy'        => 'faq_list', // The taxonomy to query
        'posts_per_term'  => -1,         // Number of guides per FAQ list (-1 for all)
        'orderby'         => 'title',    // Order guides by field (e.g., title, date)
        'order'           => 'ASC',      // Order direction (ASC or DESC)
        'root'            => '',         // Root FAQ list halo_id
    ), $atts, 'faq_list_hierarchy_root' );

    $taxonomy = sanitize_text_field( $atts['taxonomy'] );
    $posts_per_term = intval( $atts['posts_per_term'] );
    $orderby = sanitize_text_field( $atts['orderby'] );
    $order = sanitize_text_field( $atts['order'] );
    $root_halo_id = sanitize_text_field( $atts['root'] );

    // Fetch the root term by `halo_id`
    $root_term = null;
    if ( ! empty( $root_halo_id ) ) {
        $root_term = get_faq_term_by_halo_id( $root_halo_id, $taxonomy );
        if ( ! $root_term ) {
            return '<p class="faq-error">Invalid root FAQ list halo_id provided.</p>';
        }
    }

    // Output the search bar
    $output = '<div class="faq-search-container">';
    $output .= '<input type="text" id="faq-search" placeholder="Search FAQs or guides..." onkeyup="filterFAQ()">';
    $output .= '</div>';

    // Array to track processed terms and prevent duplicates
    $processed_terms = [];

    // Recursive function to build the FAQ hierarchy
    function get_faq_hierarchy( $parent_id, $taxonomy, $posts_per_term, $orderby, $order, &$processed_terms, $level = 2 ) {
        global $wp;
        $output = '';

        // Get child terms
        $child_terms = get_terms( array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
            'parent'     => $parent_id,
        ) );

        if ( ! empty( $child_terms ) && ! is_wp_error( $child_terms ) ) {
            $output .= '<div class="faq-child-columns">';

            foreach ( $child_terms as $child_term ) {
                if ( in_array( $child_term->term_id, $processed_terms ) ) {
                    continue; // Skip duplicates
                }
                $processed_terms[] = $child_term->term_id; // Mark as processed

                // Get the halo_id for reference
                $halo_id = get_term_meta( $child_term->term_id, 'halo_id', true );

                // Adjust heading size based on hierarchy level
                $heading_tag = 'h' . min( 6, $level );
                $output .= '<div class="faq-card" data-title="' . strtolower( esc_attr( $child_term->name ) ) . '">';
                $output .= '<' . $heading_tag . ' class="faq-title">' . esc_html( $child_term->name ) . '</' . $heading_tag . '>';

                // Recursively fetch child terms
                $child_output = get_faq_hierarchy( $child_term->term_id, $taxonomy, $posts_per_term, $orderby, $order, $processed_terms, $level + 1 );

                if ( empty( $child_output ) ) {
                    $query = new WP_Query( array(
                        'post_type'      => 'guide',
                        'posts_per_page' => $posts_per_term,
                        'orderby'        => $orderby,
                        'order'          => $order,
                        'tax_query'      => array(
                            array(
                                'taxonomy' => $taxonomy,
                                'field'    => 'term_id',
                                'terms'    => $child_term->term_id,
                                'include_children' => false,
                            ),
                        ),
                    ) );

                    if ( $query->have_posts() ) {
                        $output .= '<ul class="guide-list">';
                        while ( $query->have_posts() ) {
                            $query->the_post();
                            $external_id = get_post_meta( get_the_ID(), 'external_article_id', true );
                            $current_path = $wp->request;
                            $path_segments = explode( '/', $current_path );
                            $product_slug = isset( $path_segments[0] ) ? $path_segments[0] : 'default-product';
                            $base_url = home_url();
                            $link = trailingslashit( $base_url ) . $product_slug . '/guides/' . esc_attr( $external_id );

                            $output .= '<li class="guide-item" data-title="' . strtolower( get_the_title() ) . '">';
                            $output .= '<a href="' . esc_url( $link ) . '">' . get_the_title() . '</a>';
                            $output .= '</li>';
                        }
                        $output .= '</ul>';
                    }

                    wp_reset_postdata();
                } else {
                    $output .= $child_output;
                }

                $output .= '</div>';
            }

            $output .= '</div>';
        }

        return $output;
    }

    if ( $root_term ) {
        $output .= get_faq_hierarchy( $root_term->term_id, $taxonomy, $posts_per_term, $orderby, $order, $processed_terms );
    }

    $output .= '</div>';

    return $output;
}
add_shortcode( 'faq_list_hierarchy_root', 'display_faq_list_hierarchy_with_root' );

/**
 * Retrieve the term by `halo_id`
 */
function get_faq_term_by_halo_id( $halo_id, $taxonomy ) {
    $existing_terms = get_terms( array(
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'meta_query' => array(
            array(
                'key'     => 'halo_id',
                'value'   => $halo_id,
                'compare' => '=',
            ),
        ),
    ) );

    return ( ! empty( $existing_terms ) && ! is_wp_error( $existing_terms ) ) ? $existing_terms[0] : null;
}
