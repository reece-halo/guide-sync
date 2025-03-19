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
		's'              => '" ' . $search_query . ' "',
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
	$sidebar = build_faq_sidebar_ajax( $parent_id, $taxonomy, $atts );

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
				$children = build_faq_sidebar_ajax( $term->term_id, $taxonomy, $atts, 2 );
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
	#faq-search {
		width: 100%;
		padding: 10px 15px;
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
				}
			});
			clearButton.addEventListener('click', function() {
				searchInput.value = '';
				clearButton.style.display = 'none';
				sidebarListContainer.innerHTML = originalSidebar;
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
						} else {
							childrenContainer.style.display = 'none';
							var chevron = header.querySelector('.chevron');
							if(chevron) { chevron.classList.remove('expanded'); }
						}
					}
				}
			});
			document.addEventListener('click', function(e) {
				if(e.target && e.target.classList.contains('faq-guide-link')) {
					e.preventDefault();
					var guideIdentifier = e.target.getAttribute('data-guide-slug');
					loadGuideContent(guideIdentifier);
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
                                if (window.location.hash) {
                                    setTimeout(function(){
                                        var targetId = window.location.hash.substring(1);
                                        var targetElem = document.getElementById(targetId);
                                        if (targetElem) {
                                            var headerOffset = 120;
                                            var elementPosition = targetElem.getBoundingClientRect().top;
                                            var offsetPosition = elementPosition + window.pageYOffset - headerOffset;
                                            window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
                                        }
                                    }, 100);
                                }
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
			document.getElementById('faq-search-button').addEventListener('click', function() {
				var query = searchInput.value.trim();
				if(query === '') {
					sidebarListContainer.innerHTML = originalSidebar;
				} else {
					searchGuides(query);
				}
			});
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
