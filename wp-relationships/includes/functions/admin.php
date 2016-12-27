<?php

/**
 * Relationships Admin
 *
 * @package Plugins/Relationships/Admin
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Add menus in network and site dashboards
 *
 * @since 0.1.0
 */
function wp_relationships_add_menu_item() {

	// Bail if not the blog admin
	if ( ! is_blog_admin() ) {
		return;
	}

	// Add pages
	$list = add_menu_page( esc_html__( 'Relationships', 'wp-relationships' ), esc_html__( 'Relationships', 'wp-relationships' ), 'manage_relationships', 'manage_relationships', 'wp_relationships_output_list_page', 'dashicons-networking', 30 );
	$edit = add_submenu_page( 'manage_relationships', esc_html__( 'Add New', 'wp-relationships' ), esc_html__( 'Add New', 'wp-relationships' ), 'edit_relationships', 'relationship_edit', 'wp_relationships_output_edit_page' );

	// Additional per-page actions
	add_action( "load-{$edit}", 'wp_relationships_add_meta_boxes'       );
	add_action( "load-{$list}", 'wp_relationships_handle_actions'       );
	add_action( "load-{$list}", 'wp_relationships_load_site_list_table' );

	// Assets
	add_action( "admin_head-{$edit}", 'wp_relationships_admin_enqueue_scripts' );
	add_action( "admin_head-{$list}", 'wp_relationships_admin_enqueue_scripts' );
}

/**
 * Get any admin actions
 *
 * @since 0.1.0
 *
 * @return string
 */
function wp_relationships_get_admin_action() {

	$action = false;

	// Regular action
	if ( ! empty( $_REQUEST['action'] ) ) {
		$action = sanitize_key( $_REQUEST['action'] );

	// Bulk action (top)
	} elseif ( ! empty( $_REQUEST['bulk_action'] ) ) {
		$action = sanitize_key( $_REQUEST['bulk_action'] );

	// Bulk action (bottom)
	} elseif ( ! empty( $_REQUEST['bulk_action2'] ) ) {
		$action = sanitize_key( $_REQUEST['bulk_action2'] );
	}

	return $action;
}

/**
 * Load the list table and populate some essentials
 *
 * @since 0.1.0
 */
function wp_relationships_load_site_list_table() {
	global $wp_list_table;

	// Include the list table class
	require_once wp_relationships_get_plugin_path() . 'includes/classes/class-wp-relationships-list-table.php';

	// Create a new list table object
	$wp_list_table = new WP_Relationships_List_Table();

	$wp_list_table->prepare_items();
}

/**
 * Output the admin page header
 *
 * @since 0.1.0
 */
function wp_relationships_output_page_header() {

	?><div class="wrap">
		<h1 id="edit-relationship"><?php esc_html_e( 'Relationships', 'wp-relationships' ); ?></h1><?php

	// Admin notices
	do_action( 'wp_relationships_admin_notices' );
}

/**
 * Close the .wrap div
 *
 * @since 0.1.0
 */
function wp_relationships_output_page_footer() {
	?></div><?php
}

/**
 * Handle submission of the list page
 *
 * Handles bulk actions for the list page. Redirects back to itself after
 * processing, and exits.
 *
 * @since 0.1.0
 *
 * @param  string  $action  Action to perform
 */
function wp_relationships_handle_actions() {

	// Look for actions
	$action = wp_relationships_get_admin_action();

	// Bail if no action
	if ( false === $action ) {
		return;
	}

	// Get action
	$redirect_to = remove_query_arg( array( 'did_action', 'processed', 'relationship_ids', 'referrer', '_wpnonce' ), wp_get_referer() );

	// Maybe fallback redirect
	if ( empty( $redirect_to ) ) {
		$redirect_to = wp_relationships_admin_url();
	}

	// Get Relationships being bulk actioned
	$processed        = array();
	$relationship_ids = wp_relationships_sanitize_relationship_ids();

	// Redirect args
	$args = array(
		'relationship_ids' => $relationship_ids,
		'did_action'       => $action,
		'page'             => 'manage_relationships'
	);

	// What's the action?
	switch ( $action ) {

		// Bulk activate
		case 'activate':
			check_admin_referer( 'relationships-bulk' );

			foreach ( $relationship_ids as $relationship_id ) {
				$relationship = WP_Relationship::get_instance( $relationship_id );

				// Skip erroneous relationships
				if ( is_wp_error( $relationship ) ) {
					$args['did_action'] = $relationship->get_error_code();
					continue;
				}

				// Process switch
				$result = $relationship->set_status( 'active' );
				if ( is_wp_error( $result ) ) {
					$args['did_action'] = $result->get_error_code();
					continue;
				}

				// Success
				$processed[] = $relationship_id;
			}
			break;

		// Bulk deactivate
		case 'deactivate':
			check_admin_referer( 'relationships-bulk' );

			foreach ( $relationship_ids as $relationship_id ) {
				$relationship = WP_Relationship::get_instance( $relationship_id );

				// Skip erroneous relationships
				if ( is_wp_error( $relationship ) ) {
					$args['did_action'] = $relationship->get_error_code();
					continue;
				}

				// Process switch
				$result = $relationship->set_status( 'inactive' );
				if ( is_wp_error( $result ) ) {
					$args['did_action'] = $result->get_error_code();
					continue;
				}

				// Success
				$processed[] = $relationship_id;
			}
			break;

		// Single/Bulk Delete
		case 'delete':
			check_admin_referer( 'relationships-bulk' );

			$args['domains'] = array();

			foreach ( $relationship_ids as $relationship_id ) {
				$relationship = WP_Relationship::get_instance( $relationship_id );

				// Skip erroneous relationships
				if ( is_wp_error( $relationship ) ) {
					$args['did_action'] = $relationship->get_error_code();
					continue;
				}

				// Try to delete
				$result = $relationship->delete();
				if ( is_wp_error( $result ) ) {
					$args['did_action'] = $result->get_error_code();
					continue;
				}

				// Success
				$processed[] = $relationship_id;
			}

			break;

		// Single Add
		case 'add' :
			check_admin_referer( 'relationship_add' );

			// Add
			$relationship = WP_Relationship::create( wp_unslash( $_POST ) );

			// Bail if an error occurred
			if ( is_wp_error( $relationship ) ) {
				$args['did_action'] = $relationship->get_error_code();
				continue;
			}

			$processed[] = $relationship->id;

			break;

		// Single Edit
		case 'edit' :
			check_admin_referer( 'relationship_edit' );

			// Check that the parameters are correct first
			$relationship_id = $relationship_ids[0];
			$relationship    = WP_Relationship::get_instance( $relationship_id );

			// Error messages
			if ( is_wp_error( $relationship ) ) {
				$args['did_action'] = $relationship->get_error_code();
				continue;
			}

			// Update
			$result = $relationship->update( wp_unslash( $_POST ) );

			// Error messages
			if ( is_wp_error( $result ) ) {
				$args['did_action'] = $result->get_error_code();
				continue;
			}

			$processed[] = $relationship_id;

			break;

		// Any other bingos
		default:
			check_admin_referer( 'relationships-bulk' );
			do_action_ref_array( "relationships_bulk_action-{$action}", array( $relationship_ids, &$processed, $action ) );

			break;
	}

	// Add processed Relationships to redirection
	$args['processed'] = $processed;
	$redirect_to = add_query_arg( $args, $redirect_to );

	// Redirect
	wp_safe_redirect( $redirect_to );
	exit();
}

/**
 * Output relationship editing page
 *
 * @since 0.1.0
 */
function wp_relationships_output_list_page() {
	global $wp_list_table;

	// Get site ID being requested
	$search  = isset( $_GET['s']    ) ? $_GET['s']                    : '';
	$page    = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'manage_relationships';

	// Action URLs
	$form_url = $action_url = wp_relationships_admin_url();

	// Output header, maybe with tabs
	wp_relationships_output_page_header(); ?>

	<form class="search-form wp-clearfix" method="get" action="<?php echo esc_url( $form_url ); ?>">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>" />
		<input type="hidden" name="relationship_id" value="<?php echo esc_attr( '' ); ?>" />
		<p class="search-box">
			<label class="screen-reader-text" for="relationship-search-input"><?php esc_html_e( 'Search Relationships:', 'wp-relationships' ); ?></label>
			<input type="search" id="relationship-search-input" name="s" value="<?php echo esc_attr( $search ); ?>">
			<input type="submit" id="search-submit" class="button" value="<?php esc_html_e( 'Search Relationships', 'wp-relationships' ); ?>">
		</p>
	</form>

	<div class="form-wrap">
		<form method="post" action="<?php echo esc_url( $form_url ); ?>">
			<?php $wp_list_table->display(); ?>
		</form>
	</div><?php

	// Footer
	wp_relationships_output_page_footer();
}

/**
 * Output admin notices
 *
 * @since 0.1.0
 *
 * @global type $wp_list_table
 */
function wp_relationships_output_admin_notices() {

	// Add messages for bulk actions
	if ( empty( $_REQUEST['did_action'] ) ) {
		return;
	}

	// Vars
	$did_action = sanitize_key( $_REQUEST['did_action'] );
	$processed  = ! empty( $_REQUEST['processed'] ) ? wp_parse_id_list( (array) $_REQUEST['processed'] ) : array();
	$processed  = array_map( 'absint', $processed );
	$count      = count( $processed );
	$output     = array();
	$messages   = array(

		// Success messages
		'activate'   => _n( '%s relationship activated.',   '%s relationships activated.',   $count, 'wp-relationships' ),
		'deactivate' => _n( '%s relationship deactivated.', '%s relationships deactivated.', $count, 'wp-relationships' ),
		'delete'     => _n( '%s relationship deleted.',     '%s relationships deleted.',     $count, 'wp-relationships' ),
		'add'        => _n( '%s relationship added.',       '%s relationships added.',       $count, 'wp-relationships' ),
		'edit'       => _n( '%s relationship updated.',     '%s relationships updated.',     $count, 'wp-relationships' ),

		// Failure messages
		'create_failed' => _x( 'Create failed.', 'object relationship', 'wp-relationships' ),
		'update_failed' => _x( 'Update failed.', 'object relationship', 'wp-relationships' ),
		'delete_failed' => _x( 'Delete failed.', 'object relationship', 'wp-relationships' ),
	);

	// Insert the placeholder
	if ( ! empty( $messages[ $did_action ] ) ) {
		$output[] = sprintf( $messages[ $did_action ], number_format_i18n( $count ) );
	}

	// Bail if no messages
	if ( empty( $output ) ) {
		return;
	}

	// Get success keys
	$success = array_keys( array_slice( $messages, 0, 5 ) );

	// Which class
	$notice_class = in_array( $did_action, $success )
		? 'notice-success'
		: 'notice-warning';

	// Start a buffer
	ob_start();

	?><div id="message" class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
		<p><?php echo implode( '</p><p>', $output ); ?></p>
	</div><?php

	// Output the buffer
	ob_end_flush();
}

/**
 * Display the relationship edit page.
 *
 * @since 0.1.0
 */
function wp_relationships_output_edit_page() {

	// Vars
	$relationship_id = wp_relationships_sanitize_relationship_ids( true );
	$relationship    = WP_Relationship::get_instance( $relationship_id );
	$action          = ! empty( $relationship ) ? 'edit' : 'add';

	// Reset a bunch of global values
	wp_reset_vars( array( 'action', 'relationship_id', 'wp_http_referer' ) );

	// Remove possible query arguments
	$request_url = remove_query_arg( array( 'action', 'error', 'updated' ), $_SERVER['REQUEST_URI'] );

	// Setup form action URL
	$form_action_url = add_query_arg( array(
		'action' => $action
	), $request_url );

	// Header
	wp_relationships_output_page_header(); ?>

	<form action="<?php echo esc_url( $form_action_url ); ?>" id="wp-relationships-form" method="post" novalidate="novalidate" <?php do_action( 'relationships_form_tag' ); ?>>
		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-<?php echo 1 == get_current_screen()->get_columns() ? '1' : '2'; ?>">
				<div id="postbox-container-1" class="postbox-container">
					<?php do_meta_boxes( get_current_screen()->id, 'side', $relationship ); ?>
				</div>

				<div id="postbox-container-2" class="postbox-container">
					<?php do_meta_boxes( get_current_screen()->id, 'normal',   $relationship ); ?>
					<?php do_meta_boxes( get_current_screen()->id, 'advanced', $relationship ); ?>
				</div>
			</div>
		</div><?php

		// Nonce fields
		wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false );
		wp_nonce_field( 'meta-box-order',  'meta-box-order-nonce', false );
		wp_nonce_field( 'update-relationship_' . $relationship->relationship_id );

	?></form><?php

	// Footer
	wp_relationships_output_page_footer();
}
