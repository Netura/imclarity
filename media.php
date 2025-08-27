<?php
/**
 * Imclarity Media Library functions.
 *
 * @package Imclarity
 */

/**
 * Add bulk action to media library dropdown.
 *
 * @param array $bulk_actions The existing bulk actions.
 * @return array The modified bulk actions.
 */
function imclarity_add_bulk_actions( $bulk_actions ) {
	$bulk_actions['imclarity_bulk_process'] = __( 'Process with Imclarity', 'imclarity' );
	return $bulk_actions;
}
add_filter( 'bulk_actions-upload', 'imclarity_add_bulk_actions' );

/**
 * Handle bulk action for processing images.
 *
 * @param string $redirect_to The redirect URL.
 * @param string $doaction The action being taken.
 * @param array  $post_ids The array of post IDs.
 * @return string The redirect URL.
 */
function imclarity_handle_bulk_actions( $redirect_to, $doaction, $post_ids ) {
	if ( 'imclarity_bulk_process' !== $doaction ) {
		return $redirect_to;
	}
	
	// Verify nonce for bulk actions to prevent CSRF
	if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'bulk-media' ) ) {
		wp_die( __( 'Security check failed', 'imclarity' ) );
	}
	
	$permissions = apply_filters( 'imclarity_admin_permissions', 'manage_options' );
	if ( ! current_user_can( $permissions ) ) {
		return $redirect_to;
	}
	
	// Store the selected IDs for processing
	set_transient( 'imclarity_bulk_process_ids', $post_ids, 600 );
	
	// Redirect to settings page with bulk process parameter
	$redirect_to = add_query_arg( 
		array(
			'page' => IMCLARITY_PLUGIN_FILE_REL,
			'bulk_process' => 1,
			'ids' => count( $post_ids )
		),
		admin_url( 'options-general.php' )
	);
	
	return $redirect_to;
}
add_filter( 'handle_bulk_actions-upload', 'imclarity_handle_bulk_actions', 10, 3 );


/**
 * Add column header for Imclarity info/actions in the media library listing.
 *
 * @param array $columns A list of columns in the media library.
 * @return array The new list of columns.
 */
function imclarity_media_columns( $columns ) {
	$columns['imclarity'] = esc_html__( 'Imclarity', 'imclarity' );
	return $columns;
}

/**
 * Print Imclarity info/actions in the media library.
 *
 * @param string $column_name The name of the column being displayed.
 * @param int    $id The attachment ID number.
 * @param array  $meta Optional. The attachment metadata. Default null.
 */
function imclarity_custom_column( $column_name, $id, $meta = null ) {
	// Once we get to the EWWW IO custom column.
	if ( 'imclarity' === $column_name ) {
		$id = (int) $id;
		if ( is_null( $meta ) ) {
			// Retrieve the metadata.
			$meta = wp_get_attachment_metadata( $id );
		}
		echo '<div id="imclarity-media-status-' . (int) $id . '" class="imclarity-media-status" data-id="' . (int) $id . '">';
		if ( false && function_exists( 'print_r' ) ) {
			$print_meta = print_r( $meta, true );
			$print_meta = preg_replace( array( '/ /', '/\n+/' ), array( '&nbsp;', '<br />' ), $print_meta );
			echo "<div id='imclarity-debug-meta-" . (int) $id . "' style='font-size: 10px;padding: 10px;margin:3px -10px 10px;line-height: 1.1em;'>" . wp_kses_post( $print_meta ) . '</div>';
		}
		if ( is_array( $meta ) && ! empty( $meta['file'] ) && false !== strpos( $meta['file'], 'https://images-na.ssl-images-amazon.com' ) ) {
			echo esc_html__( 'Amazon-hosted image', 'imclarity' ) . '</div>';
			return;
		}
		if ( is_array( $meta ) && ! empty( $meta['cloudinary'] ) ) {
			echo esc_html__( 'Cloudinary image', 'imclarity' ) . '</div>';
			return;
		}
		if ( is_array( $meta ) & class_exists( 'WindowsAzureStorageUtil' ) && ! empty( $meta['url'] ) ) {
			echo '<div>' . esc_html__( 'Azure Storage image', 'imclarity' ) . '</div>';
			return;
		}
		if ( is_array( $meta ) && class_exists( 'Amazon_S3_And_CloudFront' ) && preg_match( '/^(http|s3|gs)\w*:/', get_attached_file( $id ) ) ) {
			echo '<div>' . esc_html__( 'Offloaded Media', 'imclarity' ) . '</div>';
			return;
		}
		if ( is_array( $meta ) && class_exists( 'S3_Uploads' ) && preg_match( '/^(http|s3|gs)\w*:/', get_attached_file( $id ) ) ) {
			echo '<div>' . esc_html__( 'Amazon S3 image', 'imclarity' ) . '</div>';
			return;
		}
		if ( is_array( $meta ) & class_exists( 'wpCloud\StatelessMedia' ) && ! empty( $meta['gs_link'] ) ) {
			echo '<div>' . esc_html__( 'WP Stateless image', 'imclarity' ) . '</div>';
			return;
		}
		$file_path = imclarity_attachment_path( $meta, $id );
		if ( is_array( $meta ) & function_exists( 'ilab_get_image_sizes' ) && ! empty( $meta['s3'] ) && empty( $file_path ) ) {
			echo esc_html__( 'Media Cloud image', 'imclarity' ) . '</div>';
			return;
		}
		// If the file does not exist.
		if ( empty( $file_path ) ) {
			echo esc_html__( 'Could not retrieve file path.', 'imclarity' ) . '</div>';
			return;
		}
		// Let folks filter the allowed mime-types for resizing.
		$allowed_types = apply_filters( 'imclarity_allowed_mimes', array( 'image/png', 'image/gif', 'image/jpeg' ), $file_path );
		if ( is_string( $allowed_types ) ) {
			$allowed_types = array( $allowed_types );
		} elseif ( ! is_array( $allowed_types ) ) {
			$allowed_types = array();
		}
		$ftype = imclarity_quick_mimetype( $file_path );
		if ( ! in_array( $ftype, $allowed_types, true ) ) {
			echo '</div>';
			return;
		}

		list( $imagew, $imageh ) = getimagesize( $file_path );
		if ( empty( $imagew ) || empty( $imageh ) ) {
			$imagew = $meta['width'];
			$imageh = $meta['height'];
		}

		if ( empty( $imagew ) || empty( $imageh ) ) {
			echo esc_html( 'Unknown dimensions', 'imclarity' );
			return;
		}
		// Display dimensions and format
		$format_text = '';
		if ( $ftype === 'image/webp' ) {
			$format_text = ' (WebP)';
		} elseif ( $ftype === 'image/jpeg' ) {
			$format_text = ' (JPG)';
		} elseif ( $ftype === 'image/png' ) {
			$format_text = ' (PNG)';
		}
		echo '<div>' . (int) $imagew . 'w x ' . (int) $imageh . 'h' . esc_html( $format_text ) . '</div>';

		$maxw        = imclarity_get_option( 'imclarity_max_width', IMCLARITY_DEFAULT_MAX_WIDTH );
		$maxh        = imclarity_get_option( 'imclarity_max_height', IMCLARITY_DEFAULT_MAX_HEIGHT );
		$convert_to_webp = imclarity_get_option( 'imclarity_convert_to_webp', false );
		$permissions = apply_filters( 'imclarity_editor_permissions', 'edit_others_posts' );
		
		$needs_resize = ( $imagew > $maxw || $imageh > $maxh );
		$needs_convert = ( $convert_to_webp && 'image/webp' !== $ftype );
		$needs_thumbnail_update = imclarity_needs_thumbnail_update( $meta, $ftype );
		
		if ( $needs_resize && current_user_can( $permissions ) ) {
			$manual_nonce = wp_create_nonce( 'imclarity-manual-resize' );
			// Give the user the option to resize/convert the image.
			printf(
				'<div><button class="imclarity-manual-resize button button-secondary" data-id="%1$d" data-nonce="%2$s">%3$s</button>',
				(int) $id,
				esc_attr( $manual_nonce ),
				esc_html__( 'Process Image', 'imclarity' )
			);
		} elseif ( $needs_convert && current_user_can( $permissions ) ) {
			$manual_nonce = wp_create_nonce( 'imclarity-manual-resize' );
			// Give the user the option to convert format.
			printf(
				'<div><button class="imclarity-manual-resize button button-secondary" data-id="%1$d" data-nonce="%2$s">%3$s</button>',
				(int) $id,
				esc_attr( $manual_nonce ),
				esc_html__( 'Convert to WebP', 'imclarity' )
			);
		} elseif ( $needs_thumbnail_update && current_user_can( $permissions ) ) {
			$manual_nonce = wp_create_nonce( 'imclarity-manual-resize' );
			// Give the user the option to update thumbnails.
			printf(
				'<div><button class="imclarity-manual-resize button button-secondary" data-id="%1$d" data-nonce="%2$s">%3$s</button>',
				(int) $id,
				esc_attr( $manual_nonce ),
				esc_html__( 'Update Thumbnails', 'imclarity' )
			);
		} elseif ( current_user_can( $permissions ) && imclarity_get_option( 'imclarity_delete_originals', false ) && ! empty( $meta['original_image'] ) && function_exists( 'wp_get_original_image_path' ) ) {
			$original_image = wp_get_original_image_path( $id );
			if ( empty( $original_image ) || ! is_file( $original_image ) ) {
				$original_image = wp_get_original_image_path( $id, true );
			}
			if ( ! empty( $original_image ) && is_file( $original_image ) && is_writable( $original_image ) ) {
				$link_text = __( 'Remove Original', 'imclarity' );
			} else {
				$link_text = __( 'Remove Original Link', 'imclarity' );
			}
			$manual_nonce = wp_create_nonce( 'imclarity-manual-resize' );
			// Give the user the option to optimize the image right now.
			printf(
				'<div><button class="imclarity-manual-remove-original button button-secondary" data-id="%1$d" data-nonce="%2$s">%3$s</button>',
				(int) $id,
				esc_attr( $manual_nonce ),
				esc_html( $link_text )
			);
		}
		echo '</div>';
	}
}
