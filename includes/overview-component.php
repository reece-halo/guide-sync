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
 * Template redirect: if the URL includes guides_redirect, redirect to /halopsa/guides.
 */
add_action( 'template_redirect', 'faq_redirect_guides_to_halopsa' );
function faq_redirect_guides_to_halopsa() {
	if ( get_query_var( 'guides_redirect' ) === '1' ) {
		wp_redirect( home_url( '/' ), 301 );
		exit;
	}
}

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
	
	$args = array(
		'post_type'      => 'guide',
		'posts_per_page' => -1,
		's'              => '" ' . $search_query . '"',
	);
	
	$query = new WP_Query( $args );
	
	$results = array();
	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			$guide_identifier = get_post_meta( get_the_ID(), 'external_article_id', true );
			if ( empty( $guide_identifier ) ) {
				$guide_identifier = get_post_field( 'post_name', get_the_ID() );
			}
			$results[] = array(
				'title'      => get_the_title(),
				'excerpt'    => get_the_excerpt(),
				'permalink'  => trailingslashit( get_permalink() ) . $guide_identifier,
				'guide_slug' => $guide_identifier,
			);
		}
		wp_reset_postdata();
	}
	
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
		'orderby'         => 'title',    // Ordering for guides.
		'order'           => 'ASC',
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
	$parent_id = ( $root_term ) ? $root_term->term_id : 0;
	$sidebar   = build_faq_sidebar_ajax( $parent_id, $taxonomy, $atts );
	$active_guide_slug = get_query_var( 'faq_guide', '' );

	// If an additional FAQ list is provided, inject it as a child of the root.
	if ( $root_term && ! empty( $atts['additional_root'] ) ) {
		$additional_term = get_faq_term_by_halo_id( sanitize_text_field( $atts['additional_root'] ), $taxonomy );
		if ( $additional_term ) {
			// Build the additional term's list item.
			$additional_item = '';
			$children = build_faq_sidebar_ajax( $additional_term->term_id, $taxonomy, $atts, 1 );
			$guides   = build_guides_list_ajax( $additional_term->term_id, $atts );
			$has_children = ( $children || $guides ) ? true : false;
			$additional_item .= '<li class="faq-sidebar-item" data-term-id="' . esc_attr( $additional_term->term_id ) . '">';
			if ( $has_children ) {
				$additional_item .= '<a href="#" class="faq-term-header"><i class="fa fa-chevron-right chevron"></i><span class="term-title">' . esc_html( $additional_term->name ) . '</span></a>';
			} else {
				$additional_item .= '<a href="#" class="faq-term-header"><span class="term-title">' . esc_html( $additional_term->name ) . '</span></a>';
			}
			if ( $has_children ) {
				$additional_item .= '<div class="faq-children" style="display:none;">';
				if ( $children ) {
					$additional_item .= $children;
				}
				if ( $guides ) {
					$additional_item .= '<ul class="faq-guides-list">' . $guides . '</ul>';
				}
				$additional_item .= '</div>';
			}
			$additional_item .= '</li>';

			// Append the additional term as a child of the root.
			if ( empty( $sidebar ) ) {
				// No existing children? Create a new list.
				$sidebar = '<ul class="faq-sidebar-list level-1">' . $additional_item . '</ul>';
			} else {
				// Insert before the closing </ul> tag.
				$closing_ul = '</ul>';
				if ( substr( $sidebar, -strlen( $closing_ul ) ) === $closing_ul ) {
					$sidebar = substr( $sidebar, 0, -strlen( $closing_ul ) ) . $additional_item . $closing_ul;
				} else {
					$sidebar .= $additional_item;
				}
			}
		}
	}

	// Get the current page URL.
	$current_permalink = trailingslashit( get_permalink() );
	
	ob_start();
	?>
	<style>
	body {
	    overflow-x: unset;
	}
	.faq-component {
		display: flex;
		align-items: flex-start;
		max-width: 1360px;
		margin: 30px auto;
		background: #fff;
		color: black;
		position: relative;
	}
	.faq-sidebar {
        flex: 0 0 300px;
        padding: 20px;
        overflow-y: auto;
        position: sticky;
        top: 100px;
        max-height: calc(100vh - 100px);
        align-self: flex-start;
    }
	.faq-main {
      flex: 1;
      padding: 30px;
      background: #fff;
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
      max-width: 100%;
      height: auto;
      object-fit: contain;
      margin: 20px 0;
    }
	.faq-search-container {
		margin-bottom: 15px;
		position: relative;
	}
	/* Extra right padding so buttons remain outside the text area */
	#faq-search {
		width: 100%;
		padding: 10px 15px;
		padding-right: 70px;
		border: none;
		border-radius: 25px;
		box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
		font-size: 16px;
	}
	/* Search button styling */
	#faq-search-button {
	    padding: 2px;
		background: none;
		border: none;
		cursor: pointer;
		font-size: 18px;
		color: black;
	}
	/* Clear button styling */
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
	/* New container for the FAQ list only */
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
	.faq-term-header {
		display: flex;
		align-items: center;
		justify-content: flex-start;
		padding: 8px 10px;
		background: #ffffff;
		border-radius: 8px;
		color: #000 !important;
		text-decoration: none;
		font-size: 15px;
		transition: background 0.3s ease, transform 0.2s ease;
		box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
		cursor: pointer;
	}
	.faq-term-header .chevron {
		margin-right: 10px;
		font-size: 12px;
		transition: transform 0.3s ease;
	}
	.faq-term-header .chevron.expanded {
		transform: rotate(90deg);
	}
	.faq-children {
		margin-left: 10px;
		margin-top: 4px;
		padding-left: 5px;
		border-left: 1px solid #ddd;
	}
	.faq-guides-list {
		list-style: none;
		padding-left: 0;
		margin-top: 10px;
	}
	.faq-guide-link {
		display: block;
		padding: 8px 10px;
		background: #fff;
		border-radius: 8px;
		color: #000 !important;
		text-decoration: none;
		font-size: 14px;
		transition: background 0.3s ease, color 0.3s ease, transform 0.2s ease;
		box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
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

	<!-- The data-baseurl attribute is used by the JS to update the URL dynamically -->
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
					echo '<p>Loading guide...</p>';
				else :
					echo '<p>Select a guide to view its content.</p>';
				endif;
				?>
			</div>
		</div>
	</div>

	<script>
		(function(){
			// Store the original FAQ list markup for reverting after search.
			var sidebarListContainer = document.getElementById('faq-sidebar-list-container');
			var originalSidebar = sidebarListContainer.innerHTML;
			
			var searchInput = document.getElementById('faq-search');
			var clearButton = document.getElementById('faq-clear-button');

			// If the search input is inside a form, prevent form submission.
			var parentForm = searchInput.closest('form');
			if (parentForm) {
				parentForm.addEventListener('submit', function(e) {
					e.preventDefault();
					return false;
				});
			}

			// Toggle the clear button's visibility based on input content.
			searchInput.addEventListener('input', function(e) {
				if(e.target.value.trim() !== '') {
					clearButton.style.display = 'block';
				} else {
					clearButton.style.display = 'none';
					sidebarListContainer.innerHTML = originalSidebar;
				}
			});

			// Clear search input and restore the original FAQ list.
			clearButton.addEventListener('click', function() {
				searchInput.value = '';
				clearButton.style.display = 'none';
				sidebarListContainer.innerHTML = originalSidebar;
			});

			// Delegated event for toggling FAQ term expansion/collapse.
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
						} else {
							childrenContainer.style.display = 'none';
							var chevron = header.querySelector('.chevron');
							if(chevron) { chevron.classList.remove('expanded'); }
						}
					}
				}
			});

			// Attach click event to guide links (using event delegation).
			document.addEventListener('click', function(e) {
				if(e.target && e.target.classList.contains('faq-guide-link')) {
					e.preventDefault();
					var guideIdentifier = e.target.getAttribute('data-guide-slug');
					loadGuideContent(guideIdentifier);
					// Update active class.
					document.querySelectorAll('.faq-guide-link').forEach(function(link) {
						link.classList.remove('active');
					});
					e.target.classList.add('active');
					var baseUrl = document.querySelector('.faq-component').getAttribute('data-baseurl');
					history.pushState(null, '', baseUrl + guideIdentifier);
					var faqComponent = document.querySelector('.faq-component');
					var offset = faqComponent.getBoundingClientRect().top + window.pageYOffset - 120;
					window.scrollTo({ top: offset, behavior: 'smooth' });
				}
			});

			// Function to load guide content via Ajax.
			function loadGuideContent( guideIdentifier ) {
				var contentDiv = document.getElementById('faq-guide-content');
				contentDiv.innerHTML = '<p>Loading guide content...</p>';
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

			// Function to filter the FAQ list based on matching guide slugs.
			function filterSidebarByResults(matchingSlugs) {
				// Reset FAQ list to original markup.
				sidebarListContainer.innerHTML = originalSidebar;
				// For each guide link in the FAQ list:
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
				// Iterate over FAQ sidebar items to hide those without visible matching guides.
				var faqItems = sidebarListContainer.querySelectorAll('.faq-sidebar-item');
				var faqItemsArray = Array.from(faqItems).reverse();
				faqItemsArray.forEach(function(item) {
					var visibleGuide = item.querySelector('.faq-guide-item:not([style*="display: none"])');
					if (!visibleGuide) {
						item.style.display = 'none';
					} else {
						// Expand the item to show its children.
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

			// Server-side search function.
			function searchGuides(query) {
				var xhr = new XMLHttpRequest();
				xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xhr.onload = function(){
					if(xhr.status === 200) {
						try {
							var response = JSON.parse(xhr.responseText);
							if(response.success) {
								var matchingSlugs = response.data.map(function(item) {
									return item.guide_slug;
								});
								filterSidebarByResults(matchingSlugs);
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

			// Event listener for search input to trigger search on Enter key.
			searchInput.addEventListener('keydown', function(e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					e.stopPropagation();
					var query = e.target.value.trim();
					if(query === '') {
						sidebarListContainer.innerHTML = originalSidebar;
					} else {
						searchGuides(query);
					}
				}
			});

			// Event listener for search button click.
			document.getElementById('faq-search-button').addEventListener('click', function() {
				var query = searchInput.value.trim();
				if(query === '') {
					sidebarListContainer.innerHTML = originalSidebar;
				} else {
					searchGuides(query);
				}
			});

			// On page load: if a guide is specified in the URL, load its content and expand parent items.
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
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return '';
	}
	$output = '<ul class="faq-sidebar-list level-' . $level . '">';
	foreach ( $terms as $term ) {
		$children = build_faq_sidebar_ajax( $term->term_id, $taxonomy, $atts, $level + 1 );
		$guides   = build_guides_list_ajax( $term->term_id, $atts );
		$has_children = ( $children || $guides ) ? true : false;
		$output .= '<li class="faq-sidebar-item" data-term-id="' . esc_attr( $term->term_id ) . '">';
		if ( $has_children ) {
			$output .= '<a href="#" class="faq-term-header"><i class="fa fa-chevron-right chevron"></i><span class="term-title">' . esc_html( $term->name ) . '</span></a>';
		} else {
			$output .= '<a href="#" class="faq-term-header"><span class="term-title">' . esc_html( $term->name ) . '</span></a>';
		}
		if ( $has_children ) {
			$output .= '<div class="faq-children" style="display:none;">';
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
	return $output;
}

/**
 * Build a list of guides for a specific FAQ term.
 */
function build_guides_list_ajax( $term_id, $atts ) {
	$taxonomy       = sanitize_text_field( $atts['taxonomy'] );
	$posts_per_term = intval( $atts['posts_per_term'] );
	$orderby        = sanitize_text_field( $atts['orderby'] );
	$order          = sanitize_text_field( $atts['order'] );

	$query = new WP_Query( array(
		'post_type'      => 'guide',
		'posts_per_page' => $posts_per_term,
		'orderby'        => $orderby,
		'order'          => $order,
		'tax_query'      => array(
			array(
				'taxonomy'         => $taxonomy,
				'field'            => 'term_id',
				'terms'            => $term_id,
				'include_children' => false,
			),
		),
	) );

	if ( ! $query->have_posts() ) {
		return '';
	}
	$output = '';
	while ( $query->have_posts() ) {
		$query->the_post();
		$guide_identifier = get_post_meta( get_the_ID(), 'external_article_id', true );
		if ( empty( $guide_identifier ) ) {
			$guide_identifier = get_post_field( 'post_name', get_the_ID() );
		}
		$guide_url = trailingslashit( get_permalink() ) . $guide_identifier;
		$output   .= '<li class="faq-guide-item">';
		$output   .= '<a href="' . esc_url( $guide_url ) . '" class="faq-guide-link" data-guide-slug="' . esc_attr( $guide_identifier ) . '">' . get_the_title() . '</a>';
		$output   .= '</li>';
	}
	wp_reset_postdata();
	return $output;
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
