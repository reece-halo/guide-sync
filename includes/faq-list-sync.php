<?php
// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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

    // Sync FAQ Lists
    foreach ($data as $faq_list) {
        sync_faq_list($faq_list['id'], $faq_list['name'], $faq_list['group_id'], $faq_list['sequence'], $data);
    }
}

/**
 * Recursively syncs FAQ lists and ensures hierarchy is maintained.
 */
function sync_faq_list($halo_id, $name, $group_id, $sequence, $faq_data, $processed = []) {
    // Avoid infinite recursion if there are circular dependencies
    if (in_array($halo_id, $processed)) {
        return null;
    }

    $processed[] = $halo_id;

    // If there is a group_id, we need to ensure the parent exists first
    $parent_id = 0;
    if (!empty($group_id)) {
        $parent_id = get_faq_term_id_by_halo_id($group_id);

        // If the parent doesn't exist, find it in the API data and create it
        if (!$parent_id) {
            foreach ($faq_data as $faq) {
                if ($faq['id'] === $group_id) {
                    $parent_id = sync_faq_list($faq['id'], $faq['name'], $faq['group_id'], $faq_data, $processed);
                    break;
                }
            }
        }
    }

    // Check if the FAQ list already exists
    $existing_term = get_faq_term_id_by_halo_id($halo_id);
    
    if ($existing_term) {
        // Update existing term
        wp_update_term($existing_term, 'faq_list', ['name' => $name, 'parent' => $parent_id]);
        update_term_meta($existing_term, 'group_id', $group_id);
        update_term_meta($existing_term, 'sequence', $sequence);
        return $existing_term;
    } else {
        // Insert new term
        $new_term = wp_insert_term($name, 'faq_list', ['parent' => $parent_id]);
        
        if (!is_wp_error($new_term)) {
            $new_term_id = $new_term['term_id'];
            update_term_meta($new_term_id, 'halo_id', $halo_id);
            update_term_meta($new_term_id, 'group_id', $group_id);
            update_term_meta($new_term_id, 'sequence', $sequence);
            return $new_term_id;
        } else {
            return null;
        }
    }
}

/**
 * Helper function to retrieve the term ID based on halo_id
 */
function get_faq_term_id_by_halo_id($halo_id) {
    $existing_terms = get_terms([
        'taxonomy' => 'faq_list',
        'hide_empty' => false,
        'meta_query' => [
            [
                'key'   => 'halo_id',
                'value' => $halo_id,
                'compare' => '='
            ]
        ]
    ]);

    return (!empty($existing_terms) && !is_wp_error($existing_terms)) ? $existing_terms[0]->term_id : null;
}