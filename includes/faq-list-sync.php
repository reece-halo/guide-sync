<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Add the manual sync option to the Tools menu
add_action( 'admin_menu', 'add_faq_sync_tool_menu' );

function add_faq_sync_tool_menu() {
    add_management_page(
        'FAQ Sync',
        'FAQ Sync',
        'manage_options',
        'faq-sync',
        'faq_sync_tool_page'
    );
}

// Render the sync tool page
function faq_sync_tool_page() {
    ?>
    <div class="wrap">
        <h1>Sync FAQ List</h1>
        <p>Click the button below to sync the FAQ list from the API.</p>
        <form method="post" action="">
            <?php submit_button( 'Sync FAQ List' ); ?>
        </form>
        <?php
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            sync_faq_lists_from_api();
            echo '<div class="notice notice-success"><p>FAQ List synced successfully!</p></div>';
        }
        ?>
    </div>
    <?php
}

// Sync FAQ lists from the API
function sync_faq_lists_from_api() {
    $api_url = 'https://halo.haloservicedesk.com/api/FAQLists?isportal=true';
    $response = wp_remote_get( $api_url );

    if ( is_wp_error( $response ) ) {
        error_log( 'API Error: ' . $response->get_error_message() );
        echo '<div class="notice notice-error"><p>Failed to sync FAQ lists: ' . esc_html( $response->get_error_message() ) . '</p></div>';
        return;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $data ) || ! is_array( $data ) ) {
        error_log( 'Invalid API response' );
        echo '<div class="notice notice-error"><p>Failed to sync FAQ lists: Invalid API response.</p></div>';
        return;
    }

    // Sync the FAQ List
    sync_faq_terms( $data );
}

// Sync terms with the FAQ taxonomy
function sync_faq_terms( $faq_data ) {
    // Ensure $faq_data is an array
    if ( ! is_array( $faq_data ) ) {
        error_log( 'Error: $faq_data is not an array.' );
        return;
    }

    // Get existing terms in the taxonomy
    $existing_terms = get_terms( [
        'taxonomy'   => 'faq_list',
        'hide_empty' => false,
        'fields'     => 'all',
    ] );

    // Check for errors in get_terms()
    if ( is_wp_error( $existing_terms ) ) {
        error_log( 'Error fetching terms: ' . $existing_terms->get_error_message() );
        $existing_terms = []; // Fallback to an empty array
    }

    // Map existing terms by name for easy lookup
    $existing_terms_by_name = [];
    foreach ( $existing_terms as $term ) {
        if ( isset( $term->name ) ) {
            $existing_terms_by_name[ $term->name ] = $term;
        }
    }

    foreach ( $faq_data as $faq ) {
        // Ensure $faq is an array
        if ( ! is_array( $faq ) ) {
            error_log( 'Skipping invalid FAQ item: not an array.' );
            continue;
        }

        // Extract FAQ details
        $faq_name = $faq['name'] ?? null;
        $group_name = $faq['group_name'] ?? null;

        if ( ! $faq_name ) {
            error_log( 'Skipping FAQ item: missing name.' );
            continue;
        }

        // Find or insert parent term
        $parent_id = 0;
        if ( $group_name && isset( $existing_terms_by_name[ $group_name ] ) ) {
            $parent_id = $existing_terms_by_name[ $group_name ]->term_id;
        } elseif ( $group_name ) {
            $inserted_parent = wp_insert_term( $group_name, 'faq_list' );
            if ( ! is_wp_error( $inserted_parent ) ) {
                $parent_id = $inserted_parent['term_id'];
                $existing_terms_by_name[ $group_name ] = get_term( $parent_id, 'faq_list' );
            } else {
                error_log( 'Error inserting parent term: ' . $inserted_parent->get_error_message() );
            }
        }

        // Find or insert the term
        if ( isset( $existing_terms_by_name[ $faq_name ] ) ) {
            $term_id = $existing_terms_by_name[ $faq_name ]->term_id;

            // Update the term if necessary
            wp_update_term( $term_id, 'faq_list', [
                'parent' => $parent_id,
                'name'   => $faq_name,
            ] );
        } else {
            // Insert new term
            $inserted_term = wp_insert_term( $faq_name, 'faq_list', [
                'parent' => $parent_id,
            ] );

            if ( ! is_wp_error( $inserted_term ) ) {
                $existing_terms_by_name[ $faq_name ] = get_term( $inserted_term['term_id'], 'faq_list' );
            } else {
                error_log( 'Error inserting term: ' . $inserted_term->get_error_message() );
            }
        }
    }
}
