<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Hook to add a manual sync option in the Tools menu
add_action( 'admin_menu', function () {
    add_management_page(
        'Guide Article Sync',
        'Guide Article Sync',
        'manage_options',
        'guide-article-sync',
        'render_guide_sync_page'
    );
} );

// Render the sync page
function render_guide_sync_page() {
    ?>
    <div class="wrap">
        <h1>Sync Guide Articles</h1>
        <p>Click the button below to sync guide articles from the external API.</p>
        <form method="post" action="">
            <?php
            wp_nonce_field( 'guide_sync_action', 'guide_sync_nonce' );
            submit_button( 'Sync Articles' );
            ?>
        </form>
        <?php
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['guide_sync_nonce'] ) ) {
            if ( ! wp_verify_nonce( $_POST['guide_sync_nonce'], 'guide_sync_action' ) ) {
                wp_die( esc_html__( 'Nonce verification failed.', 'text-domain' ) );
            }

            sync_guide_articles();
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Guide articles synced successfully!', 'text-domain' ) . '</p></div>';
        }
        ?>
    </div>
    <?php
}

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
        $term = get_term_by( 'name', $faqlist['name'], 'faq_list' );

        if ( ! $term ) {
            $term_result = wp_insert_term( $faqlist['name'], 'faq_list', [
                'slug' => sanitize_title( $faqlist['name'] ),
            ] );

            if ( ! is_wp_error( $term_result ) ) {
                $term = get_term( $term_result['term_id'], 'faq_list' );
            } else {
                error_log( 'Error inserting term: ' . $term_result->get_error_message() );
                continue;
            }
        }

        $faq_ids[] = $term->term_id;
    }

    wp_set_object_terms( $post_id, $faq_ids, 'faq_list' );
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
    $htmlContent = '<h1>Description</h1>';
    $htmlContent .= ! empty( $details['description_html'] ) ? $details['description_html'] : esc_html( $details['description'] );
    $htmlContent .= '<h1>Resolution</h1>';
    $htmlContent .= ! empty( $details['resolution_html'] ) ? $details['resolution_html'] : esc_html( $details['resolution'] );

    return $htmlContent;
}
