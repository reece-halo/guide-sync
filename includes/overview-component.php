<?php
// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register a rewrite rule so that guide URLs like:
 * /{product}/guides/{guide_identifier}
 * load the guides page and set the query var 'faq_guide'.
 */
add_action( 'init', 'faq_register_rewrite_rules' );
function faq_register_rewrite_rules() {
	// Assumes your product pages are structured as: domain/{product}/guides
	add_rewrite_rule( '^([^/]+)/guides/([^/]+)/?$', 'index.php?pagename=$matches[1]/guides&faq_guide=$matches[2]', 'top' );
	add_rewrite_tag( '%faq_guide%', '([^&]+)' );
}

/**
 * AJAX handler for loading a guide’s content.
 *
 * This function first attempts to load the guide using its meta field "external_article_id".
 * If no guide is found by meta, it falls back to looking it up by its post name.
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
 * Shortcode: [faq_list_hierarchy_ajax]
 *
 * Outputs a two-column layout:
 *  - Left sidebar: a nested, collapsible FAQ hierarchy.
 *  - Right area: guide content loaded via AJAX.
 *
 * Deep linking is supported on product pages so that when a guide is selected
 * the URL becomes /{product}/guides/{guide_identifier} (where guide_identifier is ideally the meta "external_article_id").
 */
function display_faq_list_hierarchy_ajax( $atts ) {
	$atts = shortcode_atts( array(
		'taxonomy'        => 'faq_list', // Your FAQ taxonomy.
		'posts_per_term'  => -1,         // Number of guides per term (-1 for all).
		'orderby'         => 'title',    // Ordering for guides.
		'order'           => 'ASC',
		'root'            => '',         // Optional: specify a root FAQ list via its halo_id.
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
	
	// Get the current page URL (should be the productX/guides page).
	$current_permalink = trailingslashit( get_permalink() );
	
	ob_start();
	?>
	<style>
	.faq-component {
		display: flex;
		align-items: flex-start; /* Ensure children align from the top */
		max-width: 1200px;
		margin: 30px auto;
		font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
		background: #fff;
		color: black;
		position: relative; /* Important for sticky positioning */
	}

	.faq-sidebar {
		flex: 0 0 300px; /* Fixed width */
		padding: 20px;
		overflow-y: auto;
		position: sticky;
		top: 20px;
		height: fit-content;
		align-self: flex-start;
	}

	.faq-main {
		flex: 1;
		padding: 30px;
		background: #fff;
		border-left: 1px solid #e0e0e0;
	}

	.faq-main img {
		object-fit: contain;
	}

	.faq-search-container {
		margin-bottom: 15px;
	}

	#faq-search {
		width: 100%;
		padding: 10px 15px;
		border: none;
		border-radius: 25px;
		box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
		font-size: 16px;
	}

	.faq-sidebar-list {
		list-style: none;
		padding-left: 0;
		margin: 0;
	}

	.faq-sidebar-list li {
		margin-bottom: 10px;
	}

	.faq-term-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: 12px 15px;
		background: #ffffff;
		border-radius: 8px;
		/* Ensure FAQ term text is black */
		color: #000 !important;
		text-decoration: none;
		font-size: 16px;
		transition: background 0.3s ease, transform 0.2s ease;
		box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
		cursor: pointer;
	}

	.faq-term-header:hover {
		background: #e0f7fa;
		transform: translateX(5px);
	}

	.faq-term-header.active,
	.faq-term-header.active:hover {
		background: #00acc1;
		/* Active state still shows black text */
		color: #000 !important;
	}

	/* Chevron styles */
	.faq-term-header .chevron {
		font-size: 12px;
		transition: transform 0.3s ease;
	}
	.faq-term-header .chevron.expanded {
		transform: rotate(90deg);
	}

	.faq-children {
		margin-left: 20px;
		margin-top: 8px;
		padding-left: 10px;
		border-left: 2px solid #ddd;
	}

	.faq-guides-list {
		list-style: none;
		padding-left: 0;
		margin-top: 10px;
	}

	.faq-guide-link {
		display: block;
		padding: 10px 12px;
		background: #fff;
		border-radius: 8px;
		/* Ensure FAQ guide link text is black */
		color: #000 !important;
		text-decoration: none;
		font-size: 15px;
		transition: background 0.3s ease, color 0.3s ease, transform 0.2s ease;
		box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
	}

	.faq-guide-link:hover {
		background: #e1f5fe;
		transform: translateX(3px);
	}

	.faq-guide-link.active {
		background: #0073aa;
		/* Active state also shows black text */
		color: white !important;
	}

	@media (max-width: 768px) {
		.faq-component {
			flex-direction: column;
		}
		.faq-sidebar {
			width: 100%;
			border-right: none;
			border-bottom: 1px solid #e0e0e0;
		}
	}
	</style>

	<!-- The data-baseurl attribute is used by the JS to update the URL dynamically -->
	<div class="faq-component" data-baseurl="<?php echo esc_url( $current_permalink ); ?>">
		<div class="faq-sidebar">
			<div class="faq-search-container">
				<input type="text" id="faq-search" placeholder="Search FAQs or guides..." onkeyup="filterFAQ()">
			</div>
			<?php echo $sidebar; ?>
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
			// Toggle FAQ term expansion.
			document.querySelectorAll('.faq-term-header').forEach(function(header) {
				header.addEventListener('click', function(e) {
					e.preventDefault();
					var parentItem = this.parentElement;
					var childContainer = parentItem.querySelector('.faq-children');
					// Only toggle if child container exists.
					if ( childContainer ) {
						// Toggle display.
						childContainer.style.display = ( childContainer.style.display === 'none' || childContainer.style.display === '' ) ? 'block' : 'none';
						// Toggle chevron direction.
						var chevron = this.querySelector('.chevron');
						if ( chevron ) {
							if ( childContainer.style.display === 'block' ) {
								chevron.classList.add('expanded');
							} else {
								chevron.classList.remove('expanded');
							}
						}
					}
				});
			});

			// Guide link click: load guide content via Ajax.
			document.querySelectorAll('.faq-guide-link').forEach(function(link) {
				link.addEventListener('click', function(e) {
					e.preventDefault();
					// data-guide-slug now contains the guide identifier.
					var guideIdentifier = this.getAttribute('data-guide-slug');
					loadGuideContent(guideIdentifier);
					document.querySelectorAll('.faq-guide-link').forEach(function(l) { l.classList.remove('active'); });
					this.classList.add('active');
					// Update the URL using the base URL from the component's data attribute.
					var baseUrl = document.querySelector('.faq-component').getAttribute('data-baseurl');
					history.pushState(null, '', baseUrl + guideIdentifier);
					// Scroll to the top of the FAQ component, offset by 20px.
					var faqComponent = document.querySelector('.faq-component');
					var offset = faqComponent.getBoundingClientRect().top + window.pageYOffset - 120;
					window.scrollTo({ top: offset, behavior: 'smooth' });
				});
			});

			// Ajax function to load guide content.
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

			/**
			 * Filter the entire FAQ sidebar based on the search term.
			 * When a guide title or a category name matches, that guide (and its parents)
			 * become visible.
			 */
			window.filterFAQ = function(){
				var input = document.getElementById('faq-search');
				var filter = input.value.toLowerCase();

				// If no search term, reset everything to default
				if(filter === ''){
					document.querySelectorAll('.faq-sidebar-item').forEach(function(item){
						item.style.display = '';
					});
					document.querySelectorAll('.faq-children').forEach(function(child){
						child.style.display = 'none';
					});
					// Reset chevron direction.
					document.querySelectorAll('.faq-term-header .chevron').forEach(function(chevron){
						chevron.classList.remove('expanded');
					});
					return;
				}

				// First, hide all FAQ sidebar items and guide items
				document.querySelectorAll('.faq-sidebar-item').forEach(function(item){
					item.style.display = 'none';
				});
				document.querySelectorAll('.faq-guide-item').forEach(function(guideItem){
					guideItem.style.display = 'none';
				});

				// Check each guide link for a match against the search term.
				var allGuideLinks = document.querySelectorAll('.faq-guide-link');
				allGuideLinks.forEach(function(guideLink) {
					if( guideLink.textContent.toLowerCase().indexOf(filter) !== -1 ) {
						// Show the guide's list item.
						var guideItem = guideLink.closest('.faq-guide-item');
						if(guideItem){
							guideItem.style.display = '';
						}
						// Ensure that the parent term and any ancestors are shown and expanded.
						var parentItem = guideLink.closest('.faq-sidebar-item');
						while(parentItem) {
							parentItem.style.display = '';
							var childrenContainer = parentItem.querySelector('.faq-children');
							if(childrenContainer) {
								childrenContainer.style.display = 'block';
								var chevron = parentItem.querySelector('.faq-term-header .chevron');
								if(chevron){
									chevron.classList.add('expanded');
								}
							}
							parentItem = parentItem.parentElement.closest('.faq-sidebar-item');
						}
					}
				});

				// Also check category headers in case the term name matches.
				document.querySelectorAll('.faq-term-header').forEach(function(header){
					if(header.textContent.toLowerCase().indexOf(filter) !== -1) {
						var parentItem = header.closest('.faq-sidebar-item');
						if(parentItem) {
							parentItem.style.display = '';
							var childrenContainer = parentItem.querySelector('.faq-children');
							if(childrenContainer) {
								childrenContainer.style.display = 'block';
								var chevron = header.querySelector('.chevron');
								if(chevron){
									chevron.classList.add('expanded');
								}
							}
						}
					}
				});
			};

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
						loadGuideContent("<?php echo esc_js( $active_guide_slug ); ?>");
					}
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
			$output .= '<a href="#" class="faq-term-header"><span class="term-title">' . esc_html( $term->name ) . '</span><span class="chevron">▶</span></a>';
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
 *
 * This function uses the guide’s meta field "external_article_id" (if set)
 * to build the deep link URL. If "external_article_id" is not present, it falls back
 * to using the guide’s post name.
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
		// Retrieve the guide's identifier from its meta field "external_article_id".
		// If not set, fall back to the guide’s post name.
		$guide_identifier = get_post_meta( get_the_ID(), 'external_article_id', true );
		if ( empty( $guide_identifier ) ) {
			$guide_identifier = get_post_field( 'post_name', get_the_ID() );
		}
		// Build the guide URL based on the current page's permalink and the identifier.
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
