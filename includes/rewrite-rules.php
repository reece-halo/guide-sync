<?php

function faqListsForHaloITSM( $faq_name ) {
    return (
        $faq_name === 'administrator-guides' ||
        $faq_name === 'user-guides' ||
        $faq_name === 'security'
    );
}

function faqListsForHaloPSA( $faq_name ) {
    return (
        $faq_name === 'administrator-guides' ||
        $faq_name === 'halopsa-guides' ||
        $faq_name === 'user-guides' ||
        $faq_name === 'security'
    );
}

function faqListsForHaloCRM( $faq_name ) {
    return (
        $faq_name === 'administrator-guides' ||
        $faq_name === 'halocrm-user-guides' ||
        $faq_name === 'user-guides' ||
        $faq_name === 'security'
    );
}

function get_root_faq_list( $faq ) {
    while ( $faq->parent && $faq->parent > 0 ) {
        $faq = get_term( $faq->parent, 'faq_list' );
        if ( is_wp_error( $faq ) || ! $faq ) {
            return null; // Error or invalid term
        }
    }

    return $faq;
}

?>
