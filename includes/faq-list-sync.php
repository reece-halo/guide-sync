<?php

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
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

    // Sync the FAQ List and delete removed terms
    sync_faq_terms( $data );
}

// Sync terms with the FAQ taxonomy
function sync_faq_terms( $faq_data ) {
    // Get existing terms in the taxonomy
    $existing_terms = get_terms( [
        'taxonomy'   => 'faq_list',
        'hide_empty' => false,
        'fields'     => 'all',
    ] );

    if ( is_wp_error( $existing_terms ) ) {
        error_log( 'Error fetching terms: ' . $existing_terms->get_error_message() );
        return;
    }

    // Build a map of existing terms by lowercase name
    $existing_terms_by_name = [];
    foreach ( $existing_terms as $term ) {
        $existing_terms_by_name[ strtolower( $term->name ) ] = $term;
    }

    $api_faq_names = []; // To track all FAQ names in the API

    foreach ( $faq_data as $faq ) {
        // Validate data
        if ( empty( $faq['name'] ) ) {
            error_log( 'Skipping invalid FAQ item: missing name.' );
            continue;
        }

        $faq_name = sanitize_text_field( $faq['name'] );
        $group_name = isset( $faq['group_name'] ) ? sanitize_text_field( $faq['group_name'] ) : null;

        // Track FAQ names to avoid deletion
        $api_faq_names[] = $faq_name;

        // Process parent group term
        $parent_id = 0;
        if ( $group_name ) {
            $group_name_lower = strtolower( $group_name );

            if ( isset( $existing_terms_by_name[ $group_name_lower ] ) ) {
                $parent_id = $existing_terms_by_name[ $group_name_lower ]->term_id;
            } else {
                // Insert parent group term
                $inserted_parent = wp_insert_term( $group_name, 'faq_list' );
                if ( ! is_wp_error( $inserted_parent ) ) {
                    $parent_id = $inserted_parent['term_id'];
                    $existing_terms_by_name[ $group_name_lower ] = get_term( $parent_id, 'faq_list' );
                } else {
                    error_log( 'Error inserting parent term: ' . $inserted_parent->get_error_message() );
                    continue;
                }
            }
            $api_faq_names[] = $group_name; // Add group name to tracked list
        }

        // Process child term
        $faq_name_lower = strtolower( $faq_name );
        if ( isset( $existing_terms_by_name[ $faq_name_lower ] ) ) {
            // Update the term's parent if necessary
            $term_id = $existing_terms_by_name[ $faq_name_lower ]->term_id;
            wp_update_term( $term_id, 'faq_list', [ 'parent' => $parent_id ] );
        } else {
            // Insert new term
            $inserted_term = wp_insert_term( $faq_name, 'faq_list', [ 'parent' => $parent_id ] );
            if ( ! is_wp_error( $inserted_term ) ) {
                $existing_terms_by_name[ $faq_name_lower ] = get_term( $inserted_term['term_id'], 'faq_list' );
            } else {
                error_log( 'Error inserting term: ' . $inserted_term->get_error_message() );
            }
        }
    }

    // Remove terms that no longer exist in the API
    delete_removed_faq_terms( $existing_terms_by_name, $api_faq_names );
}

// Delete terms that are no longer in the API
function delete_removed_faq_terms( $existing_terms_by_name, $api_faq_names ) {
    // Collect all existing terms in the taxonomy
    $existing_terms = get_terms( [
        'taxonomy'   => 'faq_list',
        'hide_empty' => false,
        'fields'     => 'all',
    ] );

    if ( is_wp_error( $existing_terms ) ) {
        error_log( 'Error fetching terms for deletion: ' . $existing_terms->get_error_message() );
        return;
    }

    // Collect all API FAQ names (including parent names)
    $all_api_names = array_unique( $api_faq_names );

    // Check each term and delete if not in the API
    foreach ( $existing_terms as $term ) {
        if ( ! in_array( $term->name, $all_api_names, true ) ) {
            $delete_result = wp_delete_term( $term->term_id, 'faq_list' );

            if ( is_wp_error( $delete_result ) ) {
                error_log( "Error deleting term '{$term->name}': " . $delete_result->get_error_message() );
            } else {
                error_log( "Deleted term: {$term->name}" );
            }
        }
    }
}
