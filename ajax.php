<?php
/**
 * Imclarity AJAX functions.
 *
 * @package Imclarity
 */

add_action( 'wp_ajax_imclarity_get_images', 'imclarity_get_images' );
add_action( 'wp_ajax_imclarity_resize_image', 'imclarity_ajax_resize' );
add_action( 'wp_ajax_imclarity_remove_original', 'imclarity_ajax_remove_original' );
add_action( 'wp_ajax_imclarity_update_thumbnails', 'imclarity_ajax_update_thumbnails' );
add_action( 'wp_ajax_imclarity_bulk_complete', 'imclarity_ajax_finish' );

/**
 * Searches for up to 250 images that are candidates for resize and renders them
 * to the browser as a json array, then dies.
 */
function imclarity_get_images() {
	$permissions = apply_filters( 'imclarity_admin_permissions', 'manage_options' );
	if ( ! current_user_can( $permissions ) || empty( $_REQUEST['_wpnonce'] ) ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Administrator permission is required', 'imclarity' ),
			)
		);
	}
	if ( ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imclarity-bulk' ) && ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imclarity-manual-resize' ) ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Access token has expired, please reload the page.', 'imclarity' ),
			)
		);
	}

	// Check if we're processing selected images from bulk action
	// Use transient to prevent race conditions
	$bulk_selected = get_transient( 'imclarity_bulk_selected_ids' );
	if ( $bulk_selected && is_array( $bulk_selected ) ) {
		// Remove from transient atomically and send the selected IDs
		delete_transient( 'imclarity_bulk_selected_ids' );
		array_walk( $bulk_selected, 'intval' );
		wp_send_json( $bulk_selected );
	}

	$resume_id = ! empty( $_POST['resume_id'] ) ? (int) $_POST['resume_id'] : PHP_INT_MAX;
	global $wpdb;
	// Load up all the image attachments we can find.
	$attachments = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID < %d AND post_type = %s AND post_mime_type LIKE %s ORDER BY ID DESC", $resume_id, 'attachment', '%image%' ) );
	array_walk( $attachments, 'intval' );
	wp_send_json( $attachments );
}

/**
 * Resizes the image with the given id according to the configured max width and height settings
 * renders a json response indicating success/failure and dies.
 */
function imclarity_ajax_resize() {
	$permissions = apply_filters( 'imclarity_editor_permissions', 'edit_others_posts' );
	if ( ! current_user_can( $permissions ) || empty( $_REQUEST['_wpnonce'] ) ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Editor permission is required', 'imclarity' ),
			)
		);
	}
	if ( ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imclarity-bulk' ) && ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imclarity-manual-resize' ) ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Access token has expired, please reload the page.', 'imclarity' ),
			)
		);
	}

	$id = ! empty( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	if ( ! $id ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Missing ID Parameter', 'imclarity' ),
			)
		);
	}
	$results = imclarity_resize_from_id( $id );
	if ( ! empty( $_POST['resumable'] ) ) {
		update_option( 'imclarity_resume_id', $id, false );
		sleep( 1 );
	}

	wp_send_json( $results );
}

/**
 * Removes the original image with the given id and renders a json response indicating success/failure and dies.
 */
function imclarity_ajax_remove_original() {
	$permissions = apply_filters( 'imclarity_editor_permissions', 'edit_others_posts' );
	if ( ! current_user_can( $permissions ) || empty( $_REQUEST['_wpnonce'] ) ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Editor permission is required', 'imclarity' ),
			)
		);
	}
	if ( ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imclarity-bulk' ) && ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imclarity-manual-resize' ) ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Access token has expired, please reload the page.', 'imclarity' ),
			)
		);
	}

	$id = ! empty( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	if ( ! $id ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Missing ID Parameter', 'imclarity' ),
			)
		);
	}
	$remove_original = imclarity_remove_original_image( $id );
	if ( $remove_original && is_array( $remove_original ) ) {
		wp_update_attachment_metadata( $id, $remove_original );
		wp_send_json( array( 'success' => true ) );
	}

	wp_send_json( array( 'success' => false ) );
}

/**
 * Finalizes the resizing process.
 */
function imclarity_ajax_finish() {
	$permissions = apply_filters( 'imclarity_admin_permissions', 'manage_options' );
	if ( ! current_user_can( $permissions ) || empty( $_REQUEST['_wpnonce'] ) ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Administrator permission is required', 'imclarity' ),
			)
		);
	}
	if ( ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imclarity-bulk' ) && ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imclarity-manual-resize' ) ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Access token has expired, please reload the page.', 'imclarity' ),
			)
		);
	}

	update_option( 'imclarity_resume_id', 0, false );

	die();
}

/**
 * Updates thumbnails for an image to match WebP format and renders a json response.
 */
function imclarity_ajax_update_thumbnails() {
	$permissions = apply_filters( 'imclarity_editor_permissions', 'edit_others_posts' );
	if ( ! current_user_can( $permissions ) || empty( $_REQUEST['_wpnonce'] ) ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Editor permission is required', 'imclarity' ),
			)
		);
	}
	if ( ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imclarity-bulk' ) && ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imclarity-manual-resize' ) ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Access token has expired, please reload the page.', 'imclarity' ),
			)
		);
	}

	$id = ! empty( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	if ( ! $id ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Missing ID Parameter', 'imclarity' ),
			)
		);
	}

	$meta = wp_get_attachment_metadata( $id );
	$file_path = imclarity_attachment_path( $meta, $id );

	if ( empty( $file_path ) || ! is_file( $file_path ) ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Could not retrieve file path', 'imclarity' ),
			)
		);
	}

	$ftype = imclarity_quick_mimetype( $file_path );
	
	// Check if main image is WebP and thumbnails need updating
	if ( 'image/webp' !== $ftype ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Main image is not WebP format', 'imclarity' ),
			)
		);
	}

	if ( ! imclarity_needs_thumbnail_update( $meta, $ftype ) ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Thumbnails already up to date', 'imclarity' ),
			)
		);
	}

	// Regenerate thumbnails in WebP format
	$new_meta = imclarity_regenerate_webp_thumbnails( $id, $file_path, $meta );
	
	if ( $new_meta && is_array( $new_meta ) ) {
		wp_update_attachment_metadata( $id, $new_meta );
		wp_send_json(
			array(
				'success' => true,
				'message' => esc_html__( 'Thumbnails updated to WebP format', 'imclarity' ),
			)
		);
	} else {
		wp_send_json(
			array(
				'success' => false,
				'message' => esc_html__( 'Failed to regenerate thumbnails', 'imclarity' ),
			)
		);
	}
}
