function enqueue_faq_styles() {
    wp_enqueue_style('faq-styles', get_stylesheet_directory_uri() . '/style.css', array(), time()); 
}
add_action('wp_enqueue_scripts', 'enqueue_faq_styles');

function enqueue_custom_faq_script() {
    wp_enqueue_script('custom-faq-search', get_template_directory_uri() . '/js/global.js', array(), null, true);
}
add_action('wp_enqueue_scripts', 'enqueue_custom_faq_script');