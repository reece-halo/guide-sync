<?php
// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue Font Awesome for the FAQ components.
 */
function faq_enqueue_fontawesome() {
	wp_enqueue_style( 'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', array(), '6.4.0' );
}
add_action( 'wp_enqueue_scripts', 'faq_enqueue_fontawesome' );

/**
 * Register rewrite rules.
 */
add_action( 'init', 'faq_register_rewrite_rules' );
function faq_register_rewrite_rules() {
	// If a user visits /guides, set a query var so we can redirect.
	add_rewrite_rule( '^guides/?$', 'index.php?guides_redirect=1', 'top' );
	
	// Assumes your product pages are structured as: domain/{product}/guides/{guide_identifier}
	add_rewrite_rule( '^([^/]+)/guides/([^/]+)/?$', 'index.php?pagename=$matches[1]/guides&faq_guide=$matches[2]', 'top' );
	
	add_rewrite_tag( '%faq_guide%', '([^&]+)' );
	add_rewrite_tag( '%guides_redirect%', '([^&]+)' );
}

/**
 * Template redirect: if the URL includes guides_redirect, redirect to the homepage.
 */
add_action( 'template_redirect', 'faq_redirect_guides_to_halopsa' );
function faq_redirect_guides_to_halopsa() {
	if ( get_query_var( 'guides_redirect' ) === '1' ) {
		wp_redirect( home_url( '/' ), 301 );
		exit;
	}
}

/**
 * Disable old-style guide URLs.
 *
 * If a URL is accessed with the old query parameter (e.g. ?guide=changing-your-halo-url),
 * return a 404 page.
 */
function disable_system_guide_url() {
	if ( isset( $_GET['guide'] ) ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		
		// If Elementor's 404 template exists, output it.
		if ( function_exists( 'elementor_theme_do_location' ) && elementor_theme_do_location( '404' ) ) {
			exit;
		}
		
		// Fallback: try to get the default 404 template.
		$template = get_query_template( '404' );
		if ( $template ) {
			include( $template );
			exit;
		} else {
			wp_die( '404 Not Found', '404', array( 'response' => 404 ) );
		}
	}
}
add_action( 'template_redirect', 'disable_system_guide_url', 5 );

/**
 * AJAX handler for loading a guide’s content.
 */
add_action( 'wp_ajax_load_guide_content', 'faq_load_guide_content' );
add_action( 'wp_ajax_nopriv_load_guide_content', 'faq_load_guide_content' );
function faq_load_guide_content() {
	$guide_identifier = isset( $_POST['guide_slug'] ) ? sanitize_text_field( $_POST['guide_slug'] ) : '';
	if ( empty( $guide_identifier ) ) {
		wp_send_json_error( 'No guide provided.' );
	}

	// First, try to get the guide by its meta field "external_article_id".
	$args = array(
		'post_type'      => 'guide',
		'posts_per_page' => 1,
		'meta_query'     => array(
			array(
				'key'     => 'external_article_id',
				'value'   => $guide_identifier,
				'compare' => '=',
			),
		),
	);
	$guides = get_posts( $args );
	if ( ! empty( $guides ) ) {
		$guide = $guides[0];
	} else {
		// Fallback: try to load the guide by its post name.
		$guide = get_page_by_path( $guide_identifier, OBJECT, 'guide' );
	}

	if ( ! $guide ) {
		wp_send_json_error( 'Guide not found.' );
	}
	$content = apply_filters( 'the_content', $guide->post_content );
	wp_send_json_success( array(
		'title'   => get_the_title( $guide ),
		'content' => $content,
	) );
}

function get_faq_term_path( $term, $taxonomy ) {
    // Get all ancestor term IDs.
    $ancestors = get_ancestors( $term->term_id, $taxonomy, 'taxonomy' );
    // Reverse to get the top-most first.
    $ancestors = array_reverse( $ancestors );
    $path = array();
    // Get each ancestor term's name.
    foreach ( $ancestors as $ancestor_id ) {
        $ancestor = get_term( $ancestor_id, $taxonomy );
        if ( ! is_wp_error( $ancestor ) ) {
            $path[] = $ancestor->name;
        }
    }
    // Finally, add the term itself.
    $path[] = $term->name;
    
    // Remove the top-level department if it matches one of the specified values.
    $departments = array('HaloPSA Website', 'HaloITSM Guides', 'HaloCRM Guides');
    if ( ! empty( $path ) && in_array( $path[0], $departments ) ) {
        array_shift( $path );
    }
    
    return implode( ' > ', $path );
}

/**
 * AJAX handler for server-side searching of guides by full content.
 */
add_action( 'wp_ajax_faq_search_guides', 'faq_search_guides' );
add_action( 'wp_ajax_nopriv_faq_search_guides', 'faq_search_guides' );
function faq_search_guides() {
    $search_query = isset( $_POST['search_term'] ) ? sanitize_text_field( $_POST['search_term'] ) : '';
    if ( empty( $search_query ) ) {
        wp_send_json_error( 'No search term provided.' );
    }

    $api_url  = 'https://halo.haloservicedesk.com/api/KBArticle?isportal=true&search=' . $search_query . '&pageinate=true&page_size=100&page_no=1';
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
    $results  = array();
    global $faq_current_permalink;

    foreach ( $articles as $article ) {
        // Attempt to find a matching guide post using the API article id.
        $guide_posts = get_posts( array(
            'post_type'      => 'guide',
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'     => 'external_article_id',
                    'value'   => $article['id'],
                    'compare' => '='
                )
            )
        ) );

        // Skip this article if no matching guide is found.
        if ( empty( $guide_posts ) ) {
            continue;
        }

        $guide       = $guide_posts[0];
        $permalink   = get_permalink( $guide->ID );
        $guide_slug  = $guide->post_name;
        $full_path   = '';

        // Try to retrieve the guide's term in the faq_list taxonomy.
        $terms = get_the_terms( $guide->ID, 'faq_list' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            // If the guide belongs to multiple terms, choose one (here we pick the first).
            $term      = reset( $terms );
            $full_path = get_faq_term_path( $term, 'faq_list' );
        }

        $results[] = array(
            'title'         => $article['name'],
            'excerpt'       => $article['name'],
            'permalink'     => $permalink,
            'guide_slug'    => $article['id'],
            'full_path'     => $full_path,
            'view_count'    => $article['view_count'],
            'useful_count'  => $article['useful_count'],
            'notuseful_count'=> $article['notuseful_count'],
            'url'           => $permalink,
        );
    }

    wp_reset_postdata();
    wp_send_json_success( $results );
}

/**
 * Shortcode: [faq_list_hierarchy_ajax]
 *
 * Outputs a two-column layout with the FAQ hierarchy.
 */
function display_faq_list_hierarchy_ajax( $atts ) {
	$atts = shortcode_atts( array(
		'taxonomy'        => 'faq_list', // Your FAQ taxonomy.
		'posts_per_term'  => -1,         // Number of guides per term (-1 for all).
		'root'            => '',         // Specify a root FAQ list via its halo_id.
		'additional_root' => '',         // Specify an additional FAQ list via its halo_id.
	), $atts, 'faq_list_hierarchy_ajax' );

	$taxonomy = sanitize_text_field( $atts['taxonomy'] );
	$root_term = null;
	if ( ! empty( $atts['root'] ) ) {
		$root_term = get_faq_term_by_halo_id( $atts['root'], $taxonomy );
		if ( ! $root_term ) {
			return '<p class="faq-error">Invalid root FAQ list halo_id provided.</p>';
		}
	}
	// Determine the parent based on the root.
	$parent_id = ( $root_term ) ? $root_term->term_id : 0;

	// Set the base URL for guide links.
	$current_permalink = trailingslashit( get_permalink() );
	// Make it available globally.
	global $faq_current_permalink;
	$faq_current_permalink = $current_permalink;

	// Build the sidebar markup using children of the root.
	$sidebar = build_faq_sidebar_ajax( $parent_id, $taxonomy, $atts )[0];

	// If an additional FAQ list is provided, merge it with the root's children and sort by sequence.
	if ( $root_term && ! empty( $atts['additional_root'] ) ) {
		$additional_term = get_faq_term_by_halo_id( sanitize_text_field( $atts['additional_root'] ), $taxonomy );
		if ( $additional_term ) {
			$child_terms = get_terms( array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'parent'     => $root_term->term_id,
			) );
			$child_terms[] = $additional_term;
			usort( $child_terms, function( $a, $b ) {
				$a_seq = get_term_meta( $a->term_id, 'sequence', true );
				$b_seq = get_term_meta( $b->term_id, 'sequence', true );
				$a_seq = is_numeric( $a_seq ) ? (int)$a_seq : 99999;
				$b_seq = is_numeric( $b_seq ) ? (int)$b_seq : 99999;
				if ( $a_seq === $b_seq ) {
					return strcmp( $a->name, $b->name );
				}
				return ($a_seq < $b_seq) ? -1 : 1;
			} );
			$sidebar = '<ul class="faq-sidebar-list level-1">';
			foreach ( $child_terms as $term ) {
				$children = build_faq_sidebar_ajax( $term->term_id, $taxonomy, $atts, 2 )[0];
				$guides   = build_guides_list_ajax( $term->term_id, $atts );
				$has_children = ( $children || $guides ) ? true : false;
				$sidebar .= '<li class="faq-sidebar-item" data-term-id="' . esc_attr( $term->term_id ) . '">';
				if ( $has_children ) {
					$sidebar .= '<a href="#" class="faq-term-header"><i class="fa fa-chevron-right chevron"></i><span class="term-title">' . esc_html( $term->name ) . '</span></a>';
				} else {
					$sidebar .= '<a href="#" class="faq-term-header"><span class="term-title">' . esc_html( $term->name ) . '</span></a>';
				}
				if ( $has_children ) {
					$sidebar .= '<div class="faq-children" style="display:none;">';
					if ( $children ) {
						$sidebar .= $children;
					}
					if ( $guides ) {
						$sidebar .= '<ul class="faq-guides-list">' . $guides . '</ul>';
					}
					$sidebar .= '</div>';
				}
				$sidebar .= '</li>';
			}
			$sidebar .= '</ul>';
		}
	}

	$active_guide_slug = get_query_var( 'faq_guide', '' );
	
	ob_start();
	?>
	<style>
	body {
	    overflow-x: unset;
	}
	.faq-component {
		display: flex;
		align-items: flex-start;
		/* gap: 50px; */
		max-width: 1360px;
		width: 100%;
		margin: 30px auto;
		background: #fff;
		color: black;
		position: relative;
	}
	.faq-sidebar {
        flex: 0 0 305px;
        padding: 10px 10px 0 10px;
        overflow-y: auto;
        position: sticky;
        top: 100px;
        max-height: calc(100vh - 200px);
        align-self: flex-start;

		margin-left: 20px;

		border-radius: 10px;
		border: 1px solid #f1f5fb;
	    box-shadow: 0 4px 5px 0 rgba(36, 50, 66, .1);

		scrollbar-width: thin; /* For Firefox */
  		-ms-overflow-style: auto; /* For IE and Edge */
    }
	.faq-sidebar::-webkit-scrollbar {
		width: 5px;   /* Adjust the width as desired */
		height: 5px;  /* Adjust the height for horizontal scrollbar, if needed */
	}

	.faq-sidebar::-webkit-scrollbar-track {
		background: #f1f1f1; /* Track color */
	}

	.faq-sidebar::-webkit-scrollbar-thumb {
		background-color: #888; /* Thumb color */
		border-radius: 5px;     /* Rounded corners for the thumb */
		border: 1px solid #f1f1f1; /* Optional: creates a small padding effect */
	}

	.faq-sidebar::-webkit-scrollbar-thumb:hover {
		background-color: #555; /* Change thumb color on hover */
	}
	.faq-main {
	  margin: 0 20px 0 20px;
	  max-width: 1000px;
	  width: 100%;
      flex: 1;
      padding: 0 30px 30px 30px;
      background: #fff;
	  /* border-radius: 10px; */
	  /* border: 1px solid #f1f5fb; */
	  /* box-shadow: 0 4px 5px 0 rgba(36, 50, 66, .1); */
    }
	.faq-main h1 {
      font-size: 2rem;
      margin: 0 0 10px;
      border-bottom: 1px solid #e0e0e0;
      padding-bottom: 15px;
    }
	.faq-main h2,
	.faq-main h3,
	.faq-main h4,
	.faq-main h5,
	.faq-main h6 {
      margin-top: 20px;
      margin-bottom: 10px;
    }
	.faq-main p {
      margin: 1em 0;
      line-height: 1.6;
    }
	.faq-main ul,
	.faq-main ol {
      margin: 1em 0;
      padding-left: 20px;
    }
	.faq-main img {
      max-width: 100% !important;
      height: auto;
      object-fit: contain;
      margin: 20px 0;
	  cursor: zoom-in;
    }
	body.zoomed-overlay::before {
		content: "";
		position: fixed;
		top: 0;
		left: 0;
		width: 100vw;
		height: 100vh;
		background: rgba(0, 0, 0, 0.8);
		z-index: 9998;
	}
	.faq-main img.zoomed {
		position: fixed;
		top: 50%;
		left: 50%;
		transform: translate(-50%, -50%) scale(1.3);
		transition: transform 0.3s ease;
		max-width: 80%;
		max-height: 80%;
		object-fit: contain;
		z-index: 9999;
		cursor: zoom-out;
	}
	.faq-search-container {
		margin-bottom: 15px;
		position: relative;
	}
	#faq-search {
		width: 100%;
		padding: 6px 12px;
		padding-right: 70px;
		border: none;
		border-radius: 25px;
		box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
		font-size: 16px;
	}
	#faq-search-button {
	    padding: 2px;
		background: none;
		border: none;
		cursor: pointer;
		font-size: 18px;
		color: black;
	}
	#faq-clear-button {
	    padding: 2px;
		background: none;
		border: none;
		cursor: pointer;
		font-size: 18px;
		display: none;
		color: black;
	}
	.search-button-container {
	    position: absolute;
	    right: 10px;
	    top: 50%;
	    transform: translateY(-50%);
	    display: flex;
	    align-items: center;
	    justify-content: right;
	    gap: 6px;
	}
	#faq-sidebar-list-container {
		margin-top: 20px;
	}
	.faq-sidebar-list {
		list-style: none;
		padding-left: 0;
		margin: 0;
	}
	.faq-sidebar-list li {
		margin-bottom: 5px;
	}
	.level-0 > .faq-sidebar-item {
		padding-top: 8px !important;
		padding-bottom: 8px !important;
	}
	.level-0 > .faq-sidebar-item:not(:last-child) {
		border-bottom-width: 1px !important;
		border-bottom-style: solid !important;
		border-bottom-color: rgb(241, 245, 251) !important;
	}
	.guide-count {
		font-weight: light !important;
		font-size: 11px;
	}
	.faq-term-header {
		display: flex;
		align-items: center;
		justify-content: flex-start;
		padding: 2px 4px;
		/* background: #ffffff; */
		border-radius: 4px;
		color: #000 !important;
		text-decoration: none !important;
		font-size: 15px;
		transition: background 0.3s ease, transform 0.2s ease;
		/* box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); */
		cursor: pointer;
	}
	.level-0 > .faq-sidebar-item > .faq-term-header {
		font-weight: 500;
	}
	.faq-term-header .chevron {
		margin-right: 10px;
		font-size: 12px;
		transition: transform 0.3s ease;
	}
	.faq-term-header .search-icon {
		font-size: 12px;
		transition: transform 0.3s ease;
	}
	.faq-term-header .chevron.expanded {
		transform: rotate(90deg);
	}
	.search-icon {
		display: none;
	}
	.faq-term-header .search-icon.expanded {
		display: block !important;
	}
	.faq-children {
		margin-left: 12px;
		margin-top: 4px;
		/* padding-left: 5px; */
	}
	.faq-guides-list {
		list-style: none;
		padding-left: 0;
		margin-top: 10px;
	}
	.faq-guide-link {
		display: block;
		padding: 4px 4px;
		/* background: #fff; */
		border-radius: 4px;
		color: #000 !important;
		text-decoration: none !important;
		font-size: 14px;
		transition: background 0.3s ease, color 0.3s ease, transform 0.2s ease;
		/* box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); */
	}
	.faq-guide-link:hover {
		background: #e1f5fe;
		transform: translateX(3px);
	}
	.faq-guide-link.active {
		background: #0073aa;
		color: white !important;
	}
	.styled-table td {
	    color: black;
	}
	.faq-search-results {
		list-style-type: none;
		padding-left: 0;
	}
	.faq-search-results li {
		padding-bottom: 5px;
	}
	.faq-sidebar-item.highlight {
		border-radius: 5px;
		background-color: #f0f8ff !important;
		transition: background-color 0.3s ease, border-color 0.3s ease;
	}
	.search-result-item a {
		cursor: pointer;
	}
	.search-result-item .title {
		font-weight: 500;
	}
	.search-result-item .faq-path {
		font-weight: light;
		font-size: 12px;
		margin: 0 0 2px 0;
	}
	.search-result-item .additional-stats {
		display: flex;
		align-items: center;
		justify-content: space-between;
		font-weight: light;
		font-size: 11px;
	}
	.search-result-item .p-feedback {
		color: #5bb450;
	}
	.search-result-item .n-feedback {
		color: red;
	}
	tr:has(td.hash-highlighted) {
		border: 2px solid blue !important;
    	transition: border 0.3s ease;
	}
	[id] {
        scroll-margin-top: 110px;
    }
	@media (max-width: 768px) {
		.faq-component {
			flex-direction: column;
		}
		.faq-sidebar {
			width: 100%;
			border-right: none;
			border-bottom: 1px solid #e0e0e0;
			position: static;
            top: auto;
            max-height: 25vh;
            overflow-y: scroll;
		}
	}
	</style>
	<div class="faq-component" data-baseurl="<?php echo esc_url( $current_permalink ); ?>">
		<div class="faq-sidebar">
			<div class="faq-search-container">
				<input type="text" id="faq-search" placeholder="Search guides...">
				<div class="search-button-container">
				    <button id="faq-clear-button" type="button"><i class="fa fa-times"></i></button>
    				<button id="faq-search-button" type="button"><i class="fa fa-arrow-right"></i></button>
    			</div>
			</div>
			<div id="faq-sidebar-list-container">
				<?php echo $sidebar; ?>
			</div>
		</div>
		<div class="faq-main">
			<div id="faq-guide-content">
				<?php
				if ( ! empty( $active_guide_slug ) ) :
					echo '<p style="text-align: center;"><i class="fa fa-spinner fa-spin"></i> Loading guide...</p>';
				else :
					echo '<p style="text-align: center;">Select a guide to view its content.</p>';
				endif;
				?>
			</div>
		</div>
	</div>
	<script>
		(function(){
			var sidebarListContainer = document.getElementById('faq-sidebar-list-container');
			var originalSidebar = sidebarListContainer.innerHTML;
			var searchInput = document.getElementById('faq-search');
			var clearButton = document.getElementById('faq-clear-button');
			var parentForm = searchInput.closest('form');

			if (parentForm) {
				parentForm.addEventListener('submit', function(e) {
					e.preventDefault();
					return false;
				});
			}
			searchInput.addEventListener('input', function(e) {
				if(e.target.value.trim() !== '') {
					clearButton.style.display = 'block';
				} else {
					clearButton.style.display = 'none';
					sidebarListContainer.innerHTML = originalSidebar;
					bindSearchIcons();
				}
			});
			clearButton.addEventListener('click', function() {
				searchInput.value = '';
				clearButton.style.display = 'none';
				sidebarListContainer.innerHTML = originalSidebar;
				bindSearchIcons();
			});

			function bindSearchIcons() {
				document.querySelectorAll('.search-icon').forEach(function(icon) {
					icon.addEventListener('click', function(e) {
						e.stopPropagation(); // Prevent other click events

						var sidebarItem = e.target.closest('.faq-sidebar-item');
						if (!sidebarItem) return;

						// Check if this item is already highlighted.
						if (sidebarItem.classList.contains('highlight')) {
							// Deselect it and reset the FAQ list
							sidebarItem.classList.remove('highlight');
							sidebarListContainer.innerHTML = originalSidebar;
							bindSearchIcons(); // Rebind the events on the restored DOM elements
						} else {
							// Remove highlight from any other highlighted sidebar items.
							document.querySelectorAll('.faq-sidebar-item.highlight').forEach(function(item) {
								if (item !== sidebarItem) {
									item.classList.remove('highlight');
								}
							});
							// Highlight the clicked sidebar item.
							sidebarItem.classList.add('highlight');
							// Move this highlighted item to the top of its container.
							var parentList = sidebarItem.parentElement;
							parentList.insertBefore(sidebarItem, parentList.firstChild);
						}

						// Optionally, scroll the search container into view and focus the input.
						var searchContainer = document.querySelector('.faq-search-container');
						if (searchContainer) {
							searchContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
						}
						var searchInput = document.getElementById('faq-search');
						if (searchInput) {
							searchInput.focus();
						}
					});
				});
			}

			// Bind search icon events initially.
			bindSearchIcons();

			document.addEventListener('click', function(e) {
				var link = e.target.closest('.faq-guide-link');
				if ( link ) {
					e.preventDefault();
					var guideIdentifier = link.getAttribute('data-guide-slug');
					loadGuideContent( guideIdentifier );
					// Remove 'active' class from all guide links.
					document.querySelectorAll('.faq-guide-link').forEach(function(l) {
						l.classList.remove('active');
					});
					// Mark this link as active.
					link.classList.add('active');
					var baseUrl = document.querySelector('.faq-component').getAttribute('data-baseurl');
					history.pushState(null, '', baseUrl + guideIdentifier);
					var faqComponent = document.querySelector('.faq-component');
					var offset = faqComponent.getBoundingClientRect().top + window.pageYOffset - 120;
					window.scrollTo({ top: offset, behavior: 'smooth' });
				}
			});

			document.addEventListener('click', function(e) {
				var header = e.target.closest('.faq-term-header');
				if (header && header.parentElement && header.parentElement.classList.contains('faq-sidebar-item')) {
					e.preventDefault();
					var parentItem = header.parentElement;
					var childrenContainer = parentItem.querySelector('.faq-children');
					if (childrenContainer) {
						if (childrenContainer.style.display === 'none' || childrenContainer.style.display === '') {
							childrenContainer.style.display = 'block';
							var chevron = header.querySelector('.chevron');
							if(chevron) { chevron.classList.add('expanded'); }

							var searchIcon = header.querySelector('.search-icon');
							if(searchIcon) { searchIcon.classList.add('expanded'); }
						} else {
							childrenContainer.style.display = 'none';
							var chevron = header.querySelector('.chevron');
							if(chevron) { chevron.classList.remove('expanded'); }

							var searchIcon = header.querySelector('.search-icon');
							if(searchIcon) { searchIcon.classList.remove('expanded'); }
						}
					}
				}
			});

			function loadGuideContent( guideIdentifier ) {
                var contentDiv = document.getElementById('faq-guide-content');
                contentDiv.innerHTML = '<p style="text-align: center;"><i class="fa fa-spinner fa-spin"></i> Loading guide...</p>';
                var data = 'action=load_guide_content&guide_slug=' + encodeURIComponent( guideIdentifier );
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                xhr.onload = function(){
				if ( xhr.status === 200 ) {
					try {
						var response = JSON.parse( xhr.responseText );
						if ( response.success ) {
							contentDiv.innerHTML = '<h2>' + response.data.title + '</h2>' + response.data.content;
							if ( window.location.hash ) {
								setTimeout(function(){
									var targetId = window.location.hash.substring(1);
									var targetElem = document.getElementById(targetId);
									if ( targetElem ) {
										var headerOffset = 120;
										var elementPosition = targetElem.getBoundingClientRect().top;
										var offsetPosition = elementPosition + window.pageYOffset - headerOffset;
										window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
										

										targetElem.classList.add('hash-highlighted');
										setTimeout(function(){
											targetElem.classList.remove('hash-highlighted');
										}, 3000);
									}
								}, 100);
							} else {
								// If no hash is provided, scroll to the top of the page.
								window.scrollTo({ top: 0, behavior: 'smooth' });
							}
							document.querySelectorAll('.faq-main img').forEach(function(img) {
								img.addEventListener('click', function(e) {
									e.target.classList.toggle('zoomed');
									document.body.classList.toggle('zoomed-overlay');
								});
							});
						} else {
							contentDiv.innerHTML = '<p>Error: ' + response.data + '</p>';
						}
					} catch( e ) {
						contentDiv.innerHTML = '<p>Error parsing response.</p>';
					}
				} else {
					contentDiv.innerHTML = '<p>Error loading guide content.</p>';
				}
			};
                xhr.send( data );
            }
			function filterSidebarByResults(matchingSlugs) {
				sidebarListContainer.innerHTML = originalSidebar;
				bindSearchIcons();
				var guideLinks = sidebarListContainer.querySelectorAll('.faq-guide-link');
				guideLinks.forEach(function(link) {
					var slug = link.getAttribute('data-guide-slug');
					if ( matchingSlugs.indexOf(slug) === -1 ) {
						var guideItem = link.closest('.faq-guide-item');
						if (guideItem) {
							guideItem.style.display = 'none';
						}
					}
				});
				var faqItems = sidebarListContainer.querySelectorAll('.faq-sidebar-item');
				var faqItemsArray = Array.from(faqItems).reverse();
				faqItemsArray.forEach(function(item) {
					var visibleGuide = item.querySelector('.faq-guide-item:not([style*="display: none"])');
					if (!visibleGuide) {
						item.style.display = 'none';
					} else {
						var childrenContainer = item.querySelector('.faq-children');
						if (childrenContainer) {
							childrenContainer.style.display = 'block';
							var chevron = item.querySelector('.faq-term-header .chevron');
							if (chevron) {
								chevron.classList.add('expanded');
							}
						}
					}
				});
			}
			function searchGuides(query) {
				// Get the search button element
				var searchButton = document.getElementById('faq-search-button');
				// Save its original HTML so we can restore it later
				var originalButtonHTML = searchButton.innerHTML;
				
				// Put the search bar into a loading state:
				searchInput.disabled = true;
				searchButton.disabled = true;
				// Replace the search icon with a spinner (Font Awesome spinner)
				searchButton.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
				
				var xhr = new XMLHttpRequest();
				xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xhr.onload = function(){
					// Remove loading state after the request finishes.
					searchInput.disabled = false;
					searchButton.disabled = false;
					searchButton.innerHTML = originalButtonHTML;
					
					if(xhr.status === 200) {
						try {
							var response = JSON.parse(xhr.responseText);
							if(response.success) {
								displaySearchResults(response.data);
							} else {
								console.error('Search error:', response.data);
							}
						} catch(e) {
							console.error('Error parsing search response');
						}
					} else {
						console.error('Error in AJAX request');
					}
				};
				xhr.send('action=faq_search_guides&search_term=' + encodeURIComponent(query));
			}
			function displaySearchResults(results) {
				var html = '<ul class="faq-search-results">';
				results.forEach(function(result) {
					html += '<li class="search-result-item"><a class="faq-guide-link" data-guide-slug="' + result.guide_slug + '"><span class="title">' + result.title + '</span>';
					html += '<p class="faq-path"><em>' + result.full_path + '</em></p>'

					html += '<div class="additional-stats">';
					html += '<div class="views"><i class="fa-regular fa-eye"></i> ' + result.view_count + '</div>';
					html += '<div><span class="p-feedback"><i class="fa-regular fa-thumbs-up"></i></i> ' + result.useful_count + '</span> ';
					// html += '<span class="n-feedback"><i class="fa-regular fa-thumbs-down"></i> ' + result.notuseful_count + '</span>';
					html += '</div>';
					html += '</div>';

					html += '</a></li>';
				});
				html += '</ul>';
				sidebarListContainer.innerHTML = html;
			}

			searchInput.addEventListener('keydown', function(e) {
				if (e.key === 'Enter') {
					var query = e.target.value.trim();
					e.preventDefault();
					processSearch(query);
				}
			});
			document.getElementById('faq-search-button').addEventListener('click', function() {
				var query = searchInput.value.trim();
				processSearch(query);
			});
			function processSearch(query) {
				if (query === '') {
					sidebarListContainer.innerHTML = originalSidebar;
					bindSearchIcons();
				} else {
					var highlightedItem = document.querySelector('.faq-sidebar-item.highlight');
					if (highlightedItem) {
						filterHighlightedSidebarItem(highlightedItem, query);
					} else {
						searchGuides(query);
					}
				}
			};

			function filterHighlightedSidebarItem(item, query) {
				var guideLinks = item.querySelectorAll('.faq-guide-link');
				guideLinks.forEach(function(link) {
					// Hide guide items whose text doesn't match the query (case-insensitive)
					if (link.textContent.toLowerCase().indexOf(query.toLowerCase()) === -1) {
						var guideItem = link.closest('.faq-guide-item');
						if (guideItem) {
							guideItem.style.display = 'none';
						}
					} else {
						var guideItem = link.closest('.faq-guide-item');
						if (guideItem) {
							guideItem.style.display = '';
						}
					}
				});
				
				// Loop through all child FAQ sidebar items (child FAQ lists) and hide them if no guide items are visible.
				var childFaqItems = item.querySelectorAll('.faq-sidebar-item');
				childFaqItems.forEach(function(childItem) {
					// Check if any guide items in this child are visible.
					var anyVisible = Array.from(childItem.querySelectorAll('.faq-guide-item')).some(function(guideItem) {
						return guideItem.style.display !== 'none';
					});
					// If none are visible, hide the child FAQ list.
					if (!anyVisible) {
						childItem.style.display = 'none';
					} else {
						childItem.style.display = '';
					}
				});
				
				// Expand all child lists within the highlighted item.
				var childLists = item.querySelectorAll('.faq-children');
				childLists.forEach(function(childList) {
					childList.style.display = 'block';
					// Add the expanded class to the chevron in the parent's header.
					var headerChevron = childList.parentElement.querySelector('.faq-term-header .chevron');
					if (headerChevron) {
						headerChevron.classList.add('expanded');
					}
				});
			}
			<?php if ( ! empty( $active_guide_slug ) ) : ?>
				document.addEventListener('DOMContentLoaded', function(){
					var activeLink = document.querySelector('.faq-guide-link[data-guide-slug="<?php echo esc_js( $active_guide_slug ); ?>"]');
					if ( activeLink ) {
						activeLink.classList.add('active');
						var parent = activeLink.parentElement;
						while( parent && parent.classList.contains('faq-sidebar-item') ) {
							var childrenContainer = parent.querySelector('.faq-children');
							if ( childrenContainer ) {
								childrenContainer.style.display = 'block';
								var chevron = parent.querySelector('.faq-term-header .chevron');
								if(chevron){
									chevron.classList.add('expanded');
								}
							}
							parent = parent.parentElement.closest('.faq-sidebar-item');
						}
					}
					loadGuideContent("<?php echo esc_js( $active_guide_slug ); ?>");
				});
			<?php endif; ?>
		})();
	</script>
	<?php
	return ob_get_clean();
}
add_shortcode( 'faq_list_hierarchy_ajax', 'display_faq_list_hierarchy_ajax' );

/**
 * Recursive function to build the FAQ sidebar.
 */
function build_faq_sidebar_ajax( $parent_id, $taxonomy, $atts, $level = 0 ) {
	$terms = get_terms( array(
		'taxonomy'   => $taxonomy,
		'hide_empty' => true,
		'parent'     => $parent_id,
	) );

	// Adding in the administrator guides
	if ($level == 0) {
		$admin_guides = get_faq_term_by_halo_id( 47, $taxonomy );
		array_push($terms, $admin_guides);
	}

	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return '';
	}
	usort( $terms, function( $a, $b ) {
		$a_seq = get_term_meta( $a->term_id, 'sequence', true );
		$b_seq = get_term_meta( $b->term_id, 'sequence', true );
		$a_seq = is_numeric($a_seq) ? (int)$a_seq : 99999;
		$b_seq = is_numeric($b_seq) ? (int)$b_seq : 99999;
		if ( $a_seq === $b_seq ) {
			return strcmp( $a->name, $b->name );
		}
		return ($a_seq < $b_seq) ? -1 : 1;
	} );
	$output = '<ul class="faq-sidebar-list level-' . $level . '">';
	foreach ( $terms as $term ) {
		$children_build = build_faq_sidebar_ajax( $term->term_id, $taxonomy, $atts, $level + 1 );
		$children = $children_build[0];
		$children_count = $children_build[1];

		$guides_build   = build_guides_list_ajax( $term->term_id, $atts );
		$guides = $guides_build[0];
		$guides_count = $guides_build[1];

		$has_children = ( $children || $guides ) ? true : false;

		$output .= '<li class="faq-sidebar-item" data-term-id="' . esc_attr( $term->term_id ) . '">';

		$collapsed = ($children_count <= 10 && $guides_count <= 10 && $level == 0) ? 'block' : 'none';
		$set_collapsed = ($children_count <= 10 && $guides_count <= 10 && $level == 0) ? true : false;

		$search_icon = ($level == 0) ? ('<i class="fa-solid fa-magnifying-glass search-icon' . (($set_collapsed == true) ? ' expanded' : '') . '"></i>') : '';

		if ( $has_children && $set_collapsed ) {
			$output .= '<a href="#" class="faq-term-header" style="display: flex; justify-content: space-between;"><span class="term-title">' . esc_html( $term->name ) . '</span><div style="display: flex; align-items: right; gap: 7px;">' . $search_icon . '<i class="fa fa-chevron-right chevron expanded"></i></div></a>';
		} else if ( $has_children ) {
			$output .= '<a href="#" class="faq-term-header" style="display: flex; justify-content: space-between;"><span class="term-title">' . esc_html( $term->name ) . '</span><div style="display: flex; align-items: center; gap: 7px;"><span class="guide-count"></span> ' . $search_icon . '<i class="fa fa-chevron-right chevron"></i></div></a>';
		}
		else {
			$output .= '<a href="#" class="faq-term-header"><span class="term-title">' . esc_html( $term->name ) . '</span></a>';
		}
		if ( $has_children ) {
			$output .= '<div class="faq-children" style="display: ' . $collapsed . ';">';
			if ( $children ) {
				$output .= $children;
			}
			if ( $guides ) {
				$output .= '<ul class="faq-guides-list">' . $guides . '</ul>';
			}
			$output .= '</div>';
		}
		$output .= '</li>';
	}
	$output .= '</ul>';
	return [$output, count($terms)];
}

/**
 * Build a list of guides for a specific FAQ term.
 */
function build_guides_list_ajax( $term_id, $atts ) {
	$taxonomy       = sanitize_text_field( $atts['taxonomy'] );
	$posts_per_term = intval( $atts['posts_per_term'] );
	$query_args = array(
		'post_type'      => 'guide',
		'posts_per_page' => $posts_per_term,
		'tax_query'      => array(
			array(
				'taxonomy'         => $taxonomy,
				'field'            => 'term_id',
				'terms'            => $term_id,
				'include_children' => false,
			),
		),
		'meta_key'       => 'sequence',
		'orderby'        => array(
			'meta_value_num' => 'ASC',
			'title'          => 'ASC'
		)
	);
	$query = new WP_Query( $query_args );
	if ( ! $query->have_posts() ) {
		return '';
	}
	$output = '';
	$guide_count = 0;
	while ( $query->have_posts() ) {
		$query->the_post();
		$guide_identifier = get_post_meta( get_the_ID(), 'external_article_id', true );
		if ( empty( $guide_identifier ) ) {
			$guide_identifier = get_post_field( 'post_name', get_the_ID() );
		}
		global $faq_current_permalink;
		$guide_url = trailingslashit( $faq_current_permalink ) . $guide_identifier;
		$output   .= '<li class="faq-guide-item">';
		$output   .= '<a href="' . esc_url( $guide_url ) . '" class="faq-guide-link" data-guide-slug="' . esc_attr( $guide_identifier ) . '">' . get_the_title() . '</a>';
		$output   .= '</li>';
		$guide_count++;
	}
	wp_reset_postdata();
	return [$output, $guide_count];
}

/**
 * Retrieve an FAQ term by its custom 'halo_id' meta value.
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
?>
