<?php
/**
 * Imclarity utility functions.
 *
 * @package Imclarity
 */

/**
 * Retrieves the path of an attachment via the $id and the $meta.
 *
 * @param array  $meta The attachment metadata.
 * @param int    $id The attachment ID number.
 * @param string $file Optional. Path relative to the uploads folder. Default ''.
 * @param bool   $refresh_cache Optional. True to flush cache prior to fetching path. Default true.
 * @return string The full path to the image.
 */
function imclarity_attachment_path( $meta, $id, $file = '', $refresh_cache = true ) {
	// Retrieve the location of the WordPress upload folder.
	$upload_dir  = wp_upload_dir( null, false, $refresh_cache );
	$upload_path = trailingslashit( $upload_dir['basedir'] );
	if ( is_array( $meta ) && ! empty( $meta['file'] ) ) {
		$file_path = $meta['file'];
		if ( strpos( $file_path, 's3' ) === 0 ) {
			return '';
		}
		if ( is_file( $file_path ) ) {
			return $file_path;
		}
		$file_path = $upload_path . $file_path;
		if ( is_file( $file_path ) ) {
			return $file_path;
		}
		$upload_path = trailingslashit( WP_CONTENT_DIR ) . 'uploads/';
		$file_path   = $upload_path . $meta['file'];
		if ( is_file( $file_path ) ) {
			return $file_path;
		}
	}
	if ( ! $file ) {
		$file = get_post_meta( $id, '_wp_attached_file', true );
	}
	$file_path          = ( 0 !== strpos( $file, '/' ) && ! preg_match( '|^.:\\\|', $file ) ? $upload_path . $file : $file );
	$filtered_file_path = apply_filters( 'get_attached_file', $file_path, $id );
	if ( strpos( $filtered_file_path, 's3' ) === false && is_file( $filtered_file_path ) ) {
		return str_replace( '//_imsgalleries/', '/_imsgalleries/', $filtered_file_path );
	}
	if ( strpos( $file_path, 's3' ) === false && is_file( $file_path ) ) {
		return str_replace( '//_imsgalleries/', '/_imsgalleries/', $file_path );
	}
	return '';
}

/**
 * Get mimetype based on file extension instead of file contents when speed outweighs accuracy.
 *
 * @param string $path The name of the file.
 * @return string|bool The mime type based on the extension or false.
 */
function imclarity_quick_mimetype( $path ) {
	$pathextension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	switch ( $pathextension ) {
		case 'jpg':
		case 'jpeg':
		case 'jpe':
			return 'image/jpeg';
		case 'png':
			return 'image/png';
		case 'gif':
			return 'image/gif';
		case 'pdf':
			return 'application/pdf';
		case 'webp':
			return 'image/webp';
		default:
			return false;
	}
}

/**
 * Check if an image needs thumbnail updates.
 * 
 * @param array  $meta The attachment metadata.
 * @param string $main_ftype The main file type.
 * @return bool True if thumbnails need updating.
 */
function imclarity_needs_thumbnail_update( $meta, $main_ftype ) {
	// Only check if WebP conversion is enabled and main image is WebP
	if ( ! imclarity_get_option( 'imclarity_convert_to_webp', false ) || 'image/webp' !== $main_ftype ) {
		return false;
	}
	
	// Check if there are thumbnails to update
	if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
		return false;
	}
	
	// Check if any thumbnails are not WebP
	foreach ( $meta['sizes'] as $size ) {
		if ( ! empty( $size['file'] ) ) {
			$thumb_extension = strtolower( pathinfo( $size['file'], PATHINFO_EXTENSION ) );
			// If any thumbnail is not WebP, we need to update
			if ( 'webp' !== $thumb_extension ) {
				return true;
			}
		}
	}
	
	return false;
}

/**
 * Regenerate thumbnails in WebP format after converting main image.
 *
 * @param int    $id The attachment ID.
 * @param string $webp_path The path to the new WebP file.
 * @param array  $meta The current attachment metadata.
 * @return array Updated metadata with new thumbnail information.
 */
function imclarity_regenerate_webp_thumbnails( $id, $webp_path, $meta ) {
	// Remove old thumbnails
	if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
		$upload_dir = wp_upload_dir();
		$base_dir = trailingslashit( dirname( $webp_path ) );
		
		foreach ( $meta['sizes'] as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$old_thumb = $base_dir . $size['file'];
				if ( file_exists( $old_thumb ) ) {
					unlink( $old_thumb );
				}
			}
		}
	}
	
	// Force WordPress to regenerate thumbnails
	require_once( ABSPATH . 'wp-admin/includes/image.php' );
	$new_meta = wp_generate_attachment_metadata( $id, $webp_path );
	
	if ( $new_meta && is_array( $new_meta ) ) {
		// Preserve any existing metadata that wp_generate_attachment_metadata doesn't handle
		$new_meta['width'] = ! empty( $meta['width'] ) ? $meta['width'] : $new_meta['width'];
		$new_meta['height'] = ! empty( $meta['height'] ) ? $meta['height'] : $new_meta['height'];
		return $new_meta;
	}
	
	// Fallback: just clear the sizes array if regeneration failed
	$meta['sizes'] = array();
	return $meta;
}

/**
 * Check for WebP support in the image editor and add to the list of allowed mimes.
 *
 * @param array $mimes A list of allowed mime types.
 * @return array The updated list of mimes after checking WebP support.
 */
function imclarity_add_webp_support( $mimes ) {
	if ( ! in_array( 'image/webp', $mimes, true ) ) {
		if ( class_exists( 'Imagick' ) ) {
			$imagick = new Imagick();
			$formats = $imagick->queryFormats();
			if ( in_array( 'WEBP', $formats, true ) ) {
				$mimes[] = 'image/webp';
			}
		}
	}
	return $mimes;
}

/**
 * Gets the orientation/rotation of a JPG image using the EXIF data.
 *
 * @param string $file Name of the file.
 * @param string $type Mime type of the file.
 * @return int|bool The orientation value or false.
 */
function imclarity_get_orientation( $file, $type ) {
	if ( function_exists( 'exif_read_data' ) && 'image/jpeg' === $type ) {
		$exif = @exif_read_data( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $exif ) && array_key_exists( 'Orientation', $exif ) ) {
			return (int) $exif['Orientation'];
		}
	}
	return false;
}

/**
 * Check an image to see if it has transparency.
 *
 * @param string $filename The name of the image file.
 * @return bool True if transparency is found.
 */
function imclarity_has_alpha( $filename ) {
	if ( ! is_file( $filename ) ) {
		return false;
	}
	
	// Enhanced path traversal protection
	$filename = realpath( $filename );
	if ( ! $filename ) {
		return false;
	}
	
	// Ensure the file is within WordPress upload directory
	$upload_dir = wp_upload_dir();
	$allowed_path = realpath( $upload_dir['basedir'] );
	if ( ! $allowed_path || strpos( $filename, $allowed_path ) !== 0 ) {
		return false;
	}
	
	// Additional security checks for path traversal attempts
	$filename_normalized = wp_normalize_path( $filename );
	if ( strpos( $filename_normalized, '../' ) !== false || 
		 strpos( $filename_normalized, '..' . DIRECTORY_SEPARATOR ) !== false ||
		 strpos( $filename_normalized, '~' ) !== false ||
		 preg_match( '/\x00/', $filename_normalized ) ) {
		return false;
	}
	
	$file_contents = file_get_contents( $filename );
	// Determine what color type is stored in the file.
	$color_type = ord( substr( $file_contents, 25, 1 ) );
	// If we do not have GD and the PNG color type is RGB alpha or Grayscale alpha.
	if ( ! imclarity_gd_support() && ( 4 === $color_type || 6 === $color_type ) ) {
		return true;
	} elseif ( imclarity_gd_support() ) {
		$image = imagecreatefrompng( $filename );
		if ( imagecolortransparent( $image ) >= 0 ) {
			return true;
		}
		list( $width, $height ) = getimagesize( $filename );
		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				$color = imagecolorat( $image, $x, $y );
				$rgb   = imagecolorsforindex( $image, $color );
				if ( $rgb['alpha'] > 0 ) {
					return true;
				}
			}
		}
	}
	return false;
}

/**
 * Check for GD support of both PNG and JPG.
 *
 * @return bool True if full GD support is detected.
 */
function imclarity_gd_support() {
	if ( function_exists( 'gd_info' ) ) {
		$gd_support = gd_info();
		if ( is_iterable( $gd_support ) ) {
			if ( ( ! empty( $gd_support['JPEG Support'] ) || ! empty( $gd_support['JPG Support'] ) ) && ! empty( $gd_support['PNG Support'] ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Resizes the image with the given id according to the configured max width and height settings.
 *
 * @param int $id The attachment ID of the image to process.
 * @return array The success status (bool) and a message to display.
 */
function imclarity_resize_from_id( $id = 0 ) {

	$id = (int) $id;

	if ( ! $id ) {
		return;
	}

	$meta = wp_get_attachment_metadata( $id );

	if ( $meta && is_array( $meta ) ) {
		$update_meta = false;
		// If "noresize" is included in the filename then we will bypass imclarity scaling.
		if ( ! empty( $meta['file'] ) && false !== strpos( $meta['file'], 'noresize' ) ) {
			/* translators: %s: File-name of the image */
			$msg = sprintf( esc_html__( 'SKIPPED: %s (noresize)', 'imclarity' ), $meta['file'] );
			return array(
				'success' => false,
				'message' => $msg,
			);
		}

		// $uploads = wp_upload_dir();
		$oldpath = imclarity_attachment_path( $meta, $id, '', false );

		if ( empty( $oldpath ) ) {
			/* translators: %s: File-name of the image */
			$msg = sprintf( esc_html__( 'Could not retrieve location of %s', 'imclarity' ), $meta['file'] );
			return array(
				'success' => false,
				'message' => $msg,
			);
		}

		// Let folks filter the allowed mime-types for resizing.
		$allowed_types = apply_filters( 'imclarity_allowed_mimes', array( 'image/png', 'image/gif', 'image/jpeg' ), $oldpath );
		if ( is_string( $allowed_types ) ) {
			$allowed_types = array( $allowed_types );
		} elseif ( ! is_array( $allowed_types ) ) {
			$allowed_types = array();
		}
		$ftype = imclarity_quick_mimetype( $oldpath );
		if ( ! in_array( $ftype, $allowed_types, true ) ) {
			/* translators: %s: File type of the image */
			$msg = sprintf( esc_html__( '%1$s does not have an allowed file type (%2$s)', 'imclarity' ), wp_basename( $oldpath ), $ftype );
			return array(
				'success' => false,
				'message' => $msg,
			);
		}

		if ( ! is_writable( $oldpath ) ) {
			/* translators: %s: File-name of the image */
			$msg = sprintf( esc_html__( '%s is not writable', 'imclarity' ), $meta['file'] );
			return array(
				'success' => false,
				'message' => $msg,
			);
		}

		if ( apply_filters( 'imclarity_skip_image', false, $oldpath ) ) {
			/* translators: %s: File-name of the image */
			$msg = sprintf( esc_html__( 'SKIPPED: %s (by user exclusion)', 'imclarity' ), $meta['file'] );
			return array(
				'success' => false,
				'message' => $msg,
			);
		}

		$maxw = imclarity_get_option( 'imclarity_max_width', IMCLARITY_DEFAULT_MAX_WIDTH );
		$maxh = imclarity_get_option( 'imclarity_max_height', IMCLARITY_DEFAULT_MAX_HEIGHT );

		// method one - slow but accurate, get file size from file itself.
		list( $oldw, $oldh ) = getimagesize( $oldpath );
		// method two - get file size from meta, fast but resize will fail if meta is out of sync.
		if ( ! $oldw || ! $oldh ) {
			$oldw = $meta['width'];
			$oldh = $meta['height'];
		}

		// Check if WebP conversion is enabled and needed
		$convert_to_webp = imclarity_get_option( 'imclarity_convert_to_webp', false );
		$needs_convert = ( $convert_to_webp && 'image/webp' !== $ftype );
		$needs_resize = ( ( $oldw > $maxw && $maxw > 0 ) || ( $oldh > $maxh && $maxh > 0 ) );
		$needs_thumbnail_update = imclarity_needs_thumbnail_update( $meta, $ftype );

		if ( $needs_resize || $needs_convert || $needs_thumbnail_update ) {
			$quality = imclarity_get_option( 'imclarity_quality', IMCLARITY_DEFAULT_QUALITY );

			if ( $needs_resize ) {
				if ( $maxw > 0 && $maxh > 0 && $oldw >= $maxw && $oldh >= $maxh && ( $oldh > $maxh || $oldw > $maxw ) && apply_filters( 'imclarity_crop_image', false ) ) {
					$neww = $maxw;
					$newh = $maxh;
				} else {
					list( $neww, $newh ) = wp_constrain_dimensions( $oldw, $oldh, $maxw, $maxh );
				}
			} else {
				// Only format conversion or thumbnail update needed, keep original dimensions
				$neww = $oldw;
				$newh = $oldh;
			}

			$source_image = $oldpath;
			if ( ! empty( $meta['original_image'] ) ) {
				$source_image = path_join( dirname( $oldpath ), $meta['original_image'] );
				imclarity_debug( "subbing in $source_image for resizing" );
			}
			
			// Handle thumbnail-only updates
			if ( $needs_thumbnail_update && ! $needs_resize && ! $needs_convert ) {
				// Only thumbnails need updating, no main image processing needed
				$new_meta = imclarity_regenerate_webp_thumbnails( $id, $oldpath, $meta );
				if ( $new_meta && is_array( $new_meta ) ) {
					$results = array(
						'success' => true,
						'id'      => $id,
						'message' => sprintf( esc_html__( 'OK: %1$s thumbnails updated to WebP (%2$s x %3$s)', 'imclarity' ), $meta['file'], $neww . 'w', $newh . 'h' ),
					);
					$meta = $new_meta;
					$update_meta = true;
				} else {
					$results = array(
						'success' => false,
						'id'      => $id,
						'message' => sprintf( esc_html__( 'ERROR: %1$s (Failed to update thumbnails)', 'imclarity' ), $meta['file'] ),
					);
				}
			} else {
				// Main image processing needed
				// Determine output format
				$convert_to_webp = imclarity_get_option( 'imclarity_convert_to_webp', false );
				$output_format = null;
				if ( $convert_to_webp && 'image/webp' !== $ftype ) {
					$output_format = 'webp';
				}
				
				$resizeresult = imclarity_image_resize( $source_image, $neww, $newh, apply_filters( 'imclarity_crop_image', false ), null, null, $quality, $output_format );

				if ( $resizeresult && ! is_wp_error( $resizeresult ) ) {
					$newpath = $resizeresult;

					if ( $newpath !== $oldpath && is_file( $newpath ) && filesize( $newpath ) < filesize( $oldpath ) ) {
						// we saved some file space. remove original and replace with resized image.
						unlink( $oldpath );
						
						// Update metadata if we converted to WebP
						if ( $output_format === 'webp' ) {
							// Generate the WebP filename without dimension suffix
							$pathinfo = pathinfo( $oldpath );
							$webp_path = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '.webp';
							
							// Rename the resized file to remove dimension suffix
							rename( $newpath, $webp_path );
							
							// Update the file path in metadata
							$old_file = $meta['file'];
							$file_pathinfo = pathinfo( $old_file );
							$new_basename = $pathinfo['filename'] . '.webp';
							
							// Handle both cases: with and without subdirectory
							if ( ! empty( $file_pathinfo['dirname'] ) && '.' !== $file_pathinfo['dirname'] ) {
								$new_file = $file_pathinfo['dirname'] . '/' . $new_basename;
							} else {
								$new_file = $new_basename;
							}
							$meta['file'] = $new_file;
							
							// Update attached file in database
							update_attached_file( $id, $webp_path );
							
							// Regenerate thumbnails in WebP format
							$meta = imclarity_regenerate_webp_thumbnails( $id, $webp_path, $meta );
						} else {
							rename( $newpath, $oldpath );
							// If only thumbnails need updating and main image wasn't converted
							if ( $needs_thumbnail_update ) {
								$meta = imclarity_regenerate_webp_thumbnails( $id, $oldpath, $meta );
							}
						}
						
						$meta['width']  = $neww;
						$meta['height'] = $newh;

						$update_meta = true;

						// Create appropriate success message
						if ( $needs_resize && $needs_convert ) {
							$message = sprintf( esc_html__( 'OK: %1$s resized to %2$s x %3$s and converted to WebP', 'imclarity' ), $meta['file'], $neww . 'w', $newh . 'h' );
						} elseif ( $needs_convert ) {
							$message = sprintf( esc_html__( 'OK: %1$s converted to WebP (%2$s x %3$s)', 'imclarity' ), $meta['file'], $neww . 'w', $newh . 'h' );
						} else {
							$message = sprintf( esc_html__( 'OK: %1$s resized to %2$s x %3$s', 'imclarity' ), $meta['file'], $neww . 'w', $newh . 'h' );
						}
						
						// Add thumbnail update notice if needed
						if ( $needs_thumbnail_update ) {
							$message .= esc_html__( ' (thumbnails updated)', 'imclarity' );
						}
						
						$results = array(
							'success' => true,
							'id'      => $id,
							'message' => $message,
						);
					} elseif ( $newpath !== $oldpath ) {
						// the resized image is actually bigger in filesize (most likely due to jpg quality).
						// keep the old one and just get rid of the resized image.
						if ( is_file( $newpath ) ) {
							unlink( $newpath );
						}
						$results = array(
							'success' => false,
							'id'      => $id,
							/* translators: 1: File-name of the image 2: the error message, translated elsewhere */
							'message' => sprintf( esc_html__( 'ERROR: %1$s (%2$s)', 'imclarity' ), $meta['file'], esc_html__( 'File size of resized image was larger than the original', 'imclarity' ) ),
						);
					} else {
						$results = array(
							'success' => false,
							'id'      => $id,
							/* translators: 1: File-name of the image 2: the error message, translated elsewhere */
							'message' => sprintf( esc_html__( 'ERROR: %1$s (%2$s)', 'imclarity' ), $meta['file'], esc_html__( 'Unknown error, resizing function returned the same filename', 'imclarity' ) ),
						);
					}
				} elseif ( false === $resizeresult ) {
					$results = array(
						'success' => false,
						'id'      => $id,
						/* translators: 1: File-name of the image 2: the error message, translated elsewhere */
						'message' => sprintf( esc_html__( 'ERROR: %1$s (%2$s)', 'imclarity' ), $meta['file'], esc_html__( 'wp_get_image_editor missing', 'imclarity' ) ),
					);
				} else {
					$results = array(
						'success' => false,
						'id'      => $id,
						/* translators: 1: File-name of the image 2: the error message, translated elsewhere */
						'message' => sprintf( esc_html__( 'ERROR: %1$s (%2$s)', 'imclarity' ), $meta['file'], htmlentities( $resizeresult->get_error_message() ) ),
					);
				}
			}
		} else {
			$results = array(
				'success' => true,
				'id'      => $id,
				/* translators: %s: File-name of the image */
				'message' => sprintf( esc_html__( 'SKIPPED: %s (No processing required)', 'imclarity' ), $meta['file'] ) . " -- $oldw x $oldh",
			);
			if ( empty( $meta['width'] ) || empty( $meta['height'] ) ) {
				if ( empty( $meta['width'] ) || $meta['width'] > $oldw ) {
					$meta['width'] = $oldw;
				}
				if ( empty( $meta['height'] ) || $meta['height'] > $oldh ) {
					$meta['height'] = $oldh;
				}
				$update_meta = true;
			}
		}
		$remove_original = imclarity_remove_original_image( $id, $meta );
		if ( $remove_original && is_array( $remove_original ) ) {
			$meta        = $remove_original;
			$update_meta = true;
		}
		if ( ! empty( $update_meta ) ) {
			clearstatcache();
			if ( ! empty( $oldpath ) && is_file( $oldpath ) ) {
				$meta['filesize'] = filesize( $oldpath );
			}
			wp_update_attachment_metadata( $id, $meta );
			do_action( 'imclarity_post_process_attachment', $id, $meta );
		}
	} else {
		$results = array(
			'success' => false,
			'id'      => $id,
			/* translators: %s: ID number of the image */
			'message' => sprintf( esc_html__( 'ERROR: Attachment with ID of %d not found', 'imclarity' ), intval( $id ) ),
		);
	}

	// If there is a quota we need to reset the directory size cache so it will re-calculate.
	delete_transient( 'dirsize_cache' );

	return $results;
}

/**
 * Find the path to a backed-up original (not the full-size version like the core WP function).
 *
 * @param int    $id The attachment ID number.
 * @param string $image_file The path to a scaled image file.
 * @param array  $meta The attachment metadata. Optional, default to null.
 * @return bool True on success, false on failure.
 */
function imclarity_get_original_image_path( $id, $image_file = '', $meta = null ) {
	$id = (int) $id;
	if ( empty( $id ) ) {
		return false;
	}
	if ( ! wp_attachment_is_image( $id ) ) {
		return false;
	}
	if ( is_null( $meta ) ) {
		$meta = wp_get_attachment_metadata( $id );
	}
	if ( empty( $image_file ) ) {
		$image_file = get_attached_file( $id, true );
	}
	if ( empty( $image_file ) || ! is_iterable( $meta ) || empty( $meta['original_image'] ) ) {
		return false;
	}

	return trailingslashit( dirname( $image_file ) ) . wp_basename( $meta['original_image'] );
}

/**
 * Remove the backed-up original_image stored by WP 5.3+.
 *
 * @param int   $id The attachment ID number.
 * @param array $meta The attachment metadata. Optional, default to null.
 * @return bool|array Returns meta if modified, false otherwise (even if an "unlinked" original is removed).
 */
function imclarity_remove_original_image( $id, $meta = null ) {
	$id = (int) $id;
	if ( empty( $id ) ) {
		return false;
	}
	if ( is_null( $meta ) ) {
		$meta = wp_get_attachment_metadata( $id );
	}

	if (
		$meta && is_array( $meta ) &&
		imclarity_get_option( 'imclarity_delete_originals', false ) &&
		! empty( $meta['original_image'] ) && function_exists( 'wp_get_original_image_path' )
	) {
		$original_image = imclarity_get_original_image_path( $id, '', $meta );
		if ( $original_image && is_file( $original_image ) && is_writable( $original_image ) ) {
			unlink( $original_image );
		}
		clearstatcache();
		if ( empty( $original_image ) || ! is_file( $original_image ) ) {
			unset( $meta['original_image'] );
			return $meta;
		}
	}
	return false;
}

/**
 * Resize an image using the WP_Image_Editor.
 *
 * @param string $file Image file path.
 * @param int    $max_w Maximum width to resize to.
 * @param int    $max_h Maximum height to resize to.
 * @param bool   $crop Optional. Whether to crop image or resize.
 * @param string $suffix Optional. File suffix.
 * @param string $dest_path Optional. New image file path.
 * @param int    $jpeg_quality Optional, default is 82. Image quality level (1-100).
 * @param string $format Optional. Output format ('jpg' or 'webp'). Default is null to keep original format.
 * @return mixed WP_Error on failure. String with new destination path.
 */
function imclarity_image_resize( $file, $max_w, $max_h, $crop = false, $suffix = null, $dest_path = null, $jpeg_quality = 82, $format = null ) {
	if ( function_exists( 'wp_get_image_editor' ) ) {
		imclarity_debug( "resizing $file" );
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$ftype = imclarity_quick_mimetype( $file );
		if ( 'image/webp' === $ftype ) {
			$jpeg_quality = (int) round( $jpeg_quality * .91 );
		}

		$editor->set_quality( min( 92, $jpeg_quality ) );

		// Return 1 to override auto-rotate.
		$orientation = (int) apply_filters( 'imclarity_orientation', imclarity_get_orientation( $file, $ftype ) );
		// Try to correct for auto-rotation if the info is available.
		switch ( $orientation ) {
			case 3:
				$editor->rotate( 180 );
				break;
			case 6:
				$editor->rotate( -90 );
				break;
			case 8:
				$editor->rotate( 90 );
				break;
		}

		$resized = $editor->resize( $max_w, $max_h, $crop );
		if ( is_wp_error( $resized ) ) {
			return $resized;
		}

		// Handle format conversion if specified
		if ( $format && in_array( $format, array( 'jpg', 'jpeg', 'webp' ), true ) ) {
			$mime_type = 'image/' . $format;
			if ( 'jpg' === $format ) {
				$mime_type = 'image/jpeg';
			}
			$dest_file = $editor->generate_filename( $suffix, $dest_path, $format );
		} else {
			$dest_file = $editor->generate_filename( $suffix, $dest_path );
		}

		// Make sure that the destination file does not exist.
		if ( file_exists( $dest_file ) ) {
			$dest_file = $editor->generate_filename( 'TMP', $dest_path );
		}

		if ( $format && in_array( $format, array( 'jpg', 'jpeg', 'webp' ), true ) ) {
			$saved = $editor->save( $dest_file, $mime_type );
		} else {
			$saved = $editor->save( $dest_file );
		}

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $dest_file;
	}
	return false;
}
