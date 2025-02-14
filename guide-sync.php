<?php
/**
 * Plugin Name: Halo Guide Sync
 * Description: Syncs Guides and FAQ Lists from Halo and creates rewrite URL rules to display for the correct products.
 * Version: 1.0
 * Author: Reece English
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include the file containing post type and taxonomy registration
require_once plugin_dir_path( __FILE__ ) . 'includes/register-posts-and-taxonomy.php';

// Hook to register custom post type and taxonomy
add_action( 'init', 'register_guide_and_faq_list' );

require_once plugin_dir_path( __FILE__ ) . 'includes/faq-list-sync.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/kb-article-sync.php';

// require_once plugin_dir_path( __FILE__ ) . 'includes/url-rewrite.php';

// require_once plugin_dir_path( __FILE__ ) . 'includes/global-rewrite.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/overview-component.php';




// CRON Jobs for Automated Sync
function sync_faqs_and_articles() {
    $log_file = WP_CONTENT_DIR . '/guide-sync-log.txt'; // Path to log file

    // Start logging
    $start_time = date( 'Y-m-d H:i:s' );
    $log_message = "Sync started at: $start_time\n";

    // Sync FAQ Lists
    try {
        sync_faq_lists_from_api();
        $log_message .= "FAQ lists synced successfully.\n";
    } catch ( Exception $e ) {
        $log_message .= "Error syncing FAQ lists: " . $e->getMessage() . "\n";
    }

    // Sync Guide Articles
    try {
        sync_guide_articles();
        $log_message .= "Guide articles synced successfully.\n";
    } catch ( Exception $e ) {
        $log_message .= "Error syncing guide articles: " . $e->getMessage() . "\n";
    }

    // Complete logging
    $end_time = date( 'Y-m-d H:i:s' );
    $log_message .= "Sync completed at: $end_time\n\n";

    // Write to log file
    file_put_contents( $log_file, $log_message, FILE_APPEND );
}


// Schedule the cron job on theme/plugin activation
function setup_faq_and_article_sync_cron() {
    if ( ! wp_next_scheduled( 'sync_faq_and_articles_cron' ) ) {
        wp_schedule_event( time(), 'two_hours', 'sync_faq_and_articles_cron' );
    }
}
add_action( 'init', 'setup_faq_and_article_sync_cron' );

// Clear the scheduled cron job on theme/plugin deactivation
function clear_faq_and_article_sync_cron() {
    $timestamp = wp_next_scheduled( 'sync_faq_and_articles_cron' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'sync_faq_and_articles_cron' );
    }
}
register_deactivation_hook( __FILE__, 'clear_faq_and_article_sync_cron' );

// Register a custom interval for every 2 hours
function add_two_hours_cron_interval( $schedules ) {
    $schedules['two_hours'] = array(
        'interval' => 2 * HOUR_IN_SECONDS, // 2 hours in seconds
        'display'  => __( 'Every 2 Hours' ),
    );
    return $schedules;
}
add_filter( 'cron_schedules', 'add_two_hours_cron_interval' );

// Hook the cron event to the sync function
add_action( 'sync_faq_and_articles_cron', 'sync_faqs_and_articles' );

// Add a manual sync option in the Tools menu
add_action( 'admin_menu', function () {
    add_management_page(
        'Halo Guide and FAQ Sync',
        'Halo Guide and FAQ Sync',
        'manage_options',
        'halo-guide-faq-sync',
        'render_manual_sync_page'
    );
});

// Render the manual sync page
function render_manual_sync_page() {
    ?>
    <div class="wrap">
        <h1>Manual Sync: Guides and FAQ Lists</h1>
        <p>Click the button below to manually sync FAQ lists and guide articles from the Halo API.</p>
        <form method="post" action="">
            <?php
            wp_nonce_field( 'manual_sync_action', 'manual_sync_nonce' );
            submit_button( 'Run Manual Sync' );
            ?>
        </form>
        <?php
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['manual_sync_nonce'] ) ) {
            if ( ! wp_verify_nonce( $_POST['manual_sync_nonce'], 'manual_sync_action' ) ) {
                wp_die( esc_html__( 'Nonce verification failed.', 'text-domain' ) );
            }

            // Trigger the sync process
            sync_faqs_and_articles();

            echo '<div class="notice notice-success"><p>' . esc_html__( 'Manual sync completed successfully!', 'text-domain' ) . '</p></div>';
        }
        ?>
    </div>
    <?php
}
