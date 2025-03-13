<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Bypass HTML sanitization for 'guide' posts created during a cron job.
function bypass_kses_for_cron( $data, $postarr ) {
    // Check if we're running as part of a cron job and the post type is 'guide'
    if ( defined( 'DOING_CRON' ) && DOING_CRON && isset( $data['post_type'] ) && 'guide' === $data['post_type'] ) {
        // Remove the filters that sanitize post content and excerpt
        remove_filter( 'content_save_pre', 'wp_filter_post_kses' );
        remove_filter( 'excerpt_save_pre', 'wp_filter_post_kses' );
    }
    return $data;
}
add_filter( 'wp_insert_post_data', 'bypass_kses_for_cron', 10, 2 );

// Sync guide articles
function sync_guide_articles() {
    $api_url = 'https://halo.haloservicedesk.com/api/KBArticle?count=5000&type=0&isportal=true';
    $response = wp_remote_get( $api_url );

    if ( is_wp_error( $response ) ) {
        error_log( 'API Error: ' . $response->get_error_message() );
        return;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $data ) || ! isset( $data['articles'] ) || ! is_array( $data['articles'] ) ) {
        error_log( 'Invalid API response' );
        return;
    }

    $articles = $data['articles'];
    $synced_ids = [];

    foreach ( $articles as $article ) {
        $synced_ids[] = sync_article( $article );
    }

    // Delete articles no longer in the API
    delete_removed_articles( $synced_ids );
}

function sync_article( $article ) {
    $date_edited = $article['date_edited'] ?? current_time( 'mysql' );

    // Check if the article already exists in WordPress
    $post_id = get_post_id_by_meta_key_and_value( 'external_article_id', $article['id'] );
    $last_synced_date = $post_id ? get_post_meta( $post_id, 'last_synced_date', true ) : null;

    if ( $post_id && $last_synced_date && $last_synced_date >= $date_edited ) {
        return $post_id;
    }

    $details_url = "https://halo.haloservicedesk.com/api/KBArticle/{$article['id']}?includedetails=true";
    $response = wp_remote_get( $details_url );

    if ( is_wp_error( $response ) ) {
        error_log( "Error fetching detailed article for ID {$article['id']}: " . $response->get_error_message() );
        return null;
    }

    $details = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $details ) || ! isset( $details['name'], $details['inactive'] ) ) {
        error_log( "Invalid detailed response for article ID {$article['id']}" );
        return null;
    }

    $post_content = build_content( $details );

    if ( empty( $post_content ) ) {
        error_log( "Failed to build content for article ID {$article['id']}" );
        return null;
    }

    $post_data = [
        'post_title'   => sanitize_text_field( $details['name'] ),
        'post_content' => $post_content,
        'post_status'  => $details['inactive'] ? 'draft' : 'publish',
        'post_type'    => 'guide',
    ];

    if ( $post_id ) {
        $post_data['ID'] = $post_id;
        $updated_post_id = wp_update_post( $post_data, true );
        if ( is_wp_error( $updated_post_id ) ) {
            error_log( "Error updating post for article ID {$article['id']}: " . $updated_post_id->get_error_message() );
            return null;
        }
    } else {
        $post_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $post_id ) ) {
            error_log( "Error inserting post for article ID {$article['id']}: " . $post_id->get_error_message() );
            return null;
        }
        add_post_meta( $post_id, 'external_article_id', $article['id'] );
    }

    $metadata_updates = [
        'last_synced_date' => $date_edited,
        'view_count'       => $details['view_count'] ?? 0,
        'useful_count'     => $details['useful_count'] ?? 0,
        'notuseful_count'  => $details['notuseful_count'] ?? 0,
        'next_review_date' => $details['next_review_date'] ?? '',
        'tags'             => $details['kb_tags'] ?? '',
    ];

    foreach ( $metadata_updates as $meta_key => $meta_value ) {
        update_post_meta( $post_id, $meta_key, $meta_value );
    }

    assign_article_taxonomies( $post_id, $details['faqlists'] ?? [] );

    return $post_id;
}

function assign_article_taxonomies( $post_id, $faqlists ) {
    $faq_ids = [];

    foreach ( $faqlists as $faqlist ) {
        // Get the term using a meta query on halo_id
        $terms = get_terms([
            'taxonomy'   => 'faq_list',
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key'     => 'halo_id',
                    'value'   => $faqlist['id'],
                    'compare' => '='
                ]
            ]
        ]);

        // If term exists, get its ID
        if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
            $term = $terms[0];
            $faq_ids[] = $term->term_id;
        } else {
            error_log( 'FAQ term not found for Halo ID: ' . $faqlist['id'] );
        }
    }

    if ( ! empty( $faq_ids ) ) {
        wp_set_object_terms( $post_id, $faq_ids, 'faq_list' );
    }
}

function get_post_id_by_meta_key_and_value( $meta_key, $meta_value ) {
    $query = new WP_Query( [
        'post_type'  => 'guide',
        'meta_key'   => $meta_key,
        'meta_value' => $meta_value,
        'fields'     => 'ids',
    ] );

    return $query->have_posts() ? $query->posts[0] : null;
}

function delete_removed_articles( $synced_ids ) {
    $existing_posts = get_posts( [
        'post_type'      => 'guide',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    foreach ( $existing_posts as $post_id ) {
        if ( ! in_array( $post_id, $synced_ids, true ) ) {
            wp_delete_post( $post_id, true );
        }
    }
}

function build_content( $details ) {
    $htmlContent = '';

    // Add the description section if it exists
    if ( ! empty( $details['description_html'] ) ) {
        $htmlContent .= '<div class="guide-description">';
        $htmlContent .= $details['description_html'];
        $htmlContent .= '</div>';
    }

    // Add the resolution section if it exists
    if ( ! empty( $details['resolution_html'] ) ) {
        $htmlContent .= '<div class="guide-resolution">';
        $htmlContent .= $details['resolution_html'];
        $htmlContent .= '</div>';
    }

    return $htmlContent;
}
