<?php
/**
 * Main file for Imclarity plugin.
 *
 * This file includes the core of Imclarity and the top-level image handler.
 *
 * @link https://wordpress.org/plugins/imclarity/
 * @package Imclarity
 */

/*
Plugin Name: Imclarity
Plugin URI: https://wordpress.org/plugins/imclarity/
Description: Imclarity stops insanely huge image uploads
Author: Netura
Domain Path: /languages
Version: 3.0.0
Requires at least: 6.5
Requires PHP: 7.4
Author URI: https://netura.fi
License: GPLv3
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IMCLARITY_VERSION', '3.0.0' );
define( 'IMCLARITY_SCHEMA_VERSION', '1.1' );

define( 'IMCLARITY_DEFAULT_MAX_WIDTH', 1920 );
define( 'IMCLARITY_DEFAULT_MAX_HEIGHT', 1920 );
define( 'IMCLARITY_DEFAULT_BMP_TO_JPG', true );
define( 'IMCLARITY_DEFAULT_PNG_TO_JPG', false );
define( 'IMCLARITY_DEFAULT_QUALITY', 82 );

define( 'IMCLARITY_SOURCE_POST', 1 );
define( 'IMCLARITY_SOURCE_LIBRARY', 2 );
define( 'IMCLARITY_SOURCE_OTHER', 4 );

/**
 * The full path of the main plugin file.
 *
 * @var string IMCLARITY_PLUGIN_FILE
 */
define( 'IMCLARITY_PLUGIN_FILE', __FILE__ );
/**
 * The path of the main plugin file, relative to the plugins/ folder.
 *
 * @var string IMCLARITY_PLUGIN_FILE_REL
 */
define( 'IMCLARITY_PLUGIN_FILE_REL', plugin_basename( __FILE__ ) );

/**
 * Load translations for Imclarity.
 */
function imclarity_init() {
	load_plugin_textdomain( 'imclarity', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

/**
 * Import supporting libraries.
 */
require_once plugin_dir_path( __FILE__ ) . 'libs/utils.php';
require_once plugin_dir_path( __FILE__ ) . 'settings.php';
require_once plugin_dir_path( __FILE__ ) . 'ajax.php';
require_once plugin_dir_path( __FILE__ ) . 'media.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once plugin_dir_path( __FILE__ ) . 'class-imclarity-cli.php';
}

/**
 * Use the EWWW IO debugging functions (if available).
 *
 * @param string $message A message to send to the debugger.
 */
function imclarity_debug( $message ) {
	if ( function_exists( 'ewwwio_debug_message' ) ) {
		if ( ! is_string( $message ) ) {
			if ( function_exists( 'print_r' ) ) {
				$message = print_r( $message, true );
			} else {
				$message = 'not a string, print_r disabled';
			}
		}
		ewwwio_debug_message( $message );
		if ( function_exists( 'ewww_image_optimizer_debug_log' ) ) {
			ewww_image_optimizer_debug_log();
		}
	}
}

/**
 * Inspects the request and determines where the upload came from.
 *
 * @return IMCLARITY_SOURCE_POST | IMCLARITY_SOURCE_LIBRARY | IMCLARITY_SOURCE_OTHER
 */
function imclarity_get_source() {
	imclarity_debug( __FUNCTION__ );
	$id     = array_key_exists( 'post_id', $_REQUEST ) ? (int) $_REQUEST['post_id'] : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$action = ! empty( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	
	// Only debug in development environments to prevent information disclosure
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
		imclarity_debug( "getting source for id=$id and action=$action" );
	}

	// Uncomment this (and remove the trailing .) to temporarily check the full $_SERVER vars.
	// imsanity_debug( $_SERVER );.
	$referer = '';
	if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
		$referer = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
			imclarity_debug( "http_referer: $referer" );
		}
	}

	$request_uri = wp_referer_field( false );
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
		imclarity_debug( "request URI: $request_uri" );
	}

	// A post_id indicates image is attached to a post.
	if ( $id > 0 ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
			imclarity_debug( 'from a post (id)' );
		}
		return IMCLARITY_SOURCE_POST;
	}

	// If the referrer is the post editor, that's a good indication the image is attached to a post.
	if ( false !== strpos( $referer, '/post.php' ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
			imclarity_debug( 'from a post.php' );
		}
		return IMCLARITY_SOURCE_POST;
	}
	// If the referrer is the (new) post editor, that's a good indication the image is attached to a post.
	if ( false !== strpos( $referer, '/post-new.php' ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
			imclarity_debug( 'from a new post' );
		}
		return IMCLARITY_SOURCE_POST;
	}

	// Post_id of 0 is 3.x otherwise use the action parameter.
	if ( 0 === $id || 'upload-attachment' === $action ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
			imclarity_debug( 'from the library' );
		}
		return IMCLARITY_SOURCE_LIBRARY;
	}

	// We don't know where this one came from but $_REQUEST['_wp_http_referer'] may contain info.
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
		imclarity_debug( 'unknown source' );
	}
	return IMCLARITY_SOURCE_OTHER;
}

/**
 * Given the source, returns the max width/height.
 *
 * @example:  list( $w, $h ) = imclarity_get_max_width_height( IMCLARITY_SOURCE_LIBRARY );
 * @param int $source One of IMCLARITY_SOURCE_POST | IMCLARITY_SOURCE_LIBRARY | IMCLARITY_SOURCE_OTHER.
 */
function imclarity_get_max_width_height( $source ) {
	$w = (int) imclarity_get_option( 'imclarity_max_width', IMCLARITY_DEFAULT_MAX_WIDTH );
	$h = (int) imclarity_get_option( 'imclarity_max_height', IMCLARITY_DEFAULT_MAX_HEIGHT );

	switch ( $source ) {
		case IMCLARITY_SOURCE_POST:
			break;
		case IMCLARITY_SOURCE_LIBRARY:
			$w = (int) imclarity_get_option( 'imclarity_max_width_library', $w );
			$h = (int) imclarity_get_option( 'imclarity_max_height_library', $h );
			break;
		default:
			$w = (int) imclarity_get_option( 'imclarity_max_width_other', $w );
			$h = (int) imclarity_get_option( 'imclarity_max_height_other', $h );
			break;
	}

	// NOTE: filters MUST return an array of 2 items, or the defaults will be used.
	return apply_filters( 'imclarity_get_max_width_height', array( $w, $h ), $source );
}

/**
 * Handler after a file has been uploaded.  If the file is an image, check the size
 * to see if it is too big and, if so, resize and overwrite the original.
 *
 * @param Array $params The parameters submitted with the upload.
 */
function imclarity_handle_upload( $params ) {

	// If "noresize" is included in the filename then we will bypass imsanity scaling.
	if ( strpos( $params['file'], 'noresize' ) !== false ) {
		return $params;
	}

	if ( apply_filters( 'imclarity_skip_image', false, $params['file'] ) ) {
		return $params;
	}

	// Check if WebP conversion is enabled
	$convert_to_webp = imclarity_get_option( 'imclarity_convert_to_webp', false );

	// If preferences specify so then we can convert an original bmp or png file into jpg or webp.
	if ( ( 'image/bmp' === $params['type'] || 'image/x-ms-bmp' === $params['type'] ) && imclarity_get_option( 'imclarity_bmp_to_jpg', IMCLARITY_DEFAULT_BMP_TO_JPG ) ) {
		if ( $convert_to_webp ) {
			$params = imclarity_convert_to_webp( 'bmp', $params );
		} else {
			$params = imclarity_convert_to_jpg( 'bmp', $params );
		}
	}

	if ( 'image/png' === $params['type'] && imclarity_get_option( 'imclarity_png_to_jpg', IMCLARITY_DEFAULT_PNG_TO_JPG ) ) {
		if ( $convert_to_webp ) {
			$params = imclarity_convert_to_webp( 'png', $params );
		} else {
			$params = imclarity_convert_to_jpg( 'png', $params );
		}
	}

	// Make sure this is a type of image that we want to convert and that it exists.
	$oldpath = $params['file'];

	// Let folks filter the allowed mime-types for resizing.
	$allowed_types = apply_filters( 'imclarity_allowed_mimes', array( 'image/png', 'image/gif', 'image/jpeg' ), $oldpath );
	if ( is_string( $allowed_types ) ) {
		$allowed_types = array( $allowed_types );
	} elseif ( ! is_array( $allowed_types ) ) {
		$allowed_types = array();
	}

	if (
		( ! is_wp_error( $params ) ) &&
		is_file( $oldpath ) &&
		is_readable( $oldpath ) &&
		is_writable( $oldpath ) &&
		filesize( $oldpath ) > 0 &&
		in_array( $params['type'], $allowed_types, true )
	) {

		// figure out where the upload is coming from.
		$source = imclarity_get_source();

		$maxw             = IMCLARITY_DEFAULT_MAX_WIDTH;
		$maxh             = IMCLARITY_DEFAULT_MAX_HEIGHT;
		$max_width_height = imclarity_get_max_width_height( $source );
		if ( is_array( $max_width_height ) && 2 === count( $max_width_height ) ) {
			list( $maxw, $maxh ) = $max_width_height;
		}
		$maxw = (int) $maxw;
		$maxh = (int) $maxh;

		list( $oldw, $oldh ) = getimagesize( $oldpath );

		if ( ( $oldw > $maxw + 1 && $maxw > 0 ) || ( $oldh > $maxh + 1 && $maxh > 0 ) ) {
			$quality = imclarity_get_option( 'imclarity_quality', IMCLARITY_DEFAULT_QUALITY );

			$ftype       = imclarity_quick_mimetype( $oldpath );
			$orientation = imclarity_get_orientation( $oldpath, $ftype );
			// If we are going to rotate the image 90 degrees during the resize, swap the existing image dimensions.
			if ( 6 === (int) $orientation || 8 === (int) $orientation ) {
				$old_oldw = $oldw;
				$oldw     = $oldh;
				$oldh     = $old_oldw;
			}

			if ( $maxw > 0 && $maxh > 0 && $oldw >= $maxw && $oldh >= $maxh && ( $oldh > $maxh || $oldw > $maxw ) && apply_filters( 'imclarity_crop_image', false ) ) {
				$neww = $maxw;
				$newh = $maxh;
			} else {
				list( $neww, $newh ) = wp_constrain_dimensions( $oldw, $oldh, $maxw, $maxh );
			}

			global $ewww_preempt_editor;
			if ( ! isset( $ewww_preempt_editor ) ) {
				$ewww_preempt_editor = false;
			}
			$original_preempt    = $ewww_preempt_editor;
			$ewww_preempt_editor = true;
			
			// Determine output format
			$output_format = null;
			if ( $convert_to_webp && 'image/webp' !== $params['type'] ) {
				$output_format = 'webp';
			}
			
			$resizeresult        = imclarity_image_resize( $oldpath, $neww, $newh, apply_filters( 'imclarity_crop_image', false ), null, null, $quality, $output_format );
			$ewww_preempt_editor = $original_preempt;

			if ( $resizeresult && ! is_wp_error( $resizeresult ) ) {
				$newpath = $resizeresult;

				if ( is_file( $newpath ) && filesize( $newpath ) < filesize( $oldpath ) ) {
					// We saved some file space. remove original and replace with resized image.
					unlink( $oldpath );
					
					// Update params if we converted to WebP
					if ( $output_format === 'webp' ) {
						// Generate the WebP filename without dimension suffix
						$pathinfo = pathinfo( $oldpath );
						$webp_path = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '.webp';
						
						// Rename the resized file to remove dimension suffix
						rename( $newpath, $webp_path );
						
						// Keep the WebP file with its proper extension
						$params['type'] = 'image/webp';
						$params['file'] = $webp_path;
						// Update URL to reflect new file extension
						$uploads = wp_upload_dir();
						$newfilename = wp_basename( $webp_path );
						$params['url'] = $uploads['url'] . '/' . $newfilename;
						
						// Note: Thumbnails will be generated later by WordPress after upload
						// No need to regenerate them here as they haven't been created yet
					} else {
						// For non-WebP conversions, rename back to original path
						rename( $newpath, $oldpath );
					}
				} elseif ( is_file( $newpath ) ) {
					// The resized image is actually bigger in filesize (most likely due to jpg quality).
					// Keep the old one and just get rid of the resized image.
					unlink( $newpath );
				}
			} elseif ( false === $resizeresult ) {
				return $params;
			} elseif ( is_wp_error( $resizeresult ) ) {
				// resize didn't work, likely because the image processing libraries are missing.
				// remove the old image so we don't leave orphan files hanging around.
				unlink( $oldpath );

				$params = wp_handle_upload_error(
					$oldpath,
					sprintf(
						/* translators: 1: error message 2: link to support forums */
						esc_html__( 'Imclarity was unable to resize this image for the following reason: %1$s. If you continue to see this error message, you may need to install missing server components. If you think you have discovered a bug, please report it on the Imclarity support forum: %2$s', 'imclarity' ),
						$resizeresult->get_error_message(),
						'https://wordpress.org/support/plugin/imclarity'
					)
				);
			} else {
				return $params;
			}
		}
	}
	clearstatcache();
	return $params;
}


/**
 * Read in the image file from the params and then save as a new jpg file.
 * if successful, remove the original image and alter the return
 * parameters to return the new jpg instead of the original
 *
 * @param string $type Type of the image to be converted: 'bmp' or 'png'.
 * @param array  $params The upload parameters.
 * @return array altered params
 */
function imsanity_convert_to_jpg( $type, $params ) {

	if ( apply_filters( 'imsanity_disable_convert', false, $type, $params ) ) {
		return $params;
	}

	$img = null;

	if ( 'bmp' === $type ) {
		if ( ! function_exists( 'imagecreatefrombmp' ) ) {
			return $params;
		}
		$img = imagecreatefrombmp( $params['file'] );
	} elseif ( 'png' === $type ) {
		// Prevent converting PNG images with alpha/transparency, unless overridden by the user.
		if ( apply_filters( 'imsanity_skip_alpha', imsanity_has_alpha( $params['file'] ), $params['file'] ) ) {
			return $params;
		}
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return wp_handle_upload_error( $params['file'], esc_html__( 'Imsanity requires the GD library to convert PNG images to JPG', 'imsanity' ) );
		}

		$input = imagecreatefrompng( $params['file'] );
		// convert png transparency to white.
		$img = imagecreatetruecolor( imagesx( $input ), imagesy( $input ) );
		imagefill( $img, 0, 0, imagecolorallocate( $img, 255, 255, 255 ) );
		imagealphablending( $img, true );
		imagecopy( $img, $input, 0, 0, 0, 0, imagesx( $input ), imagesy( $input ) );
	} else {
		return wp_handle_upload_error( $params['file'], esc_html__( 'Unknown image type specified in imsanity_convert_to_jpg', 'imsanity' ) );
	}

	// We need to change the extension from the original to .jpg so we have to ensure it will be a unique filename.
	$uploads     = wp_upload_dir();
	$oldfilename = wp_basename( $params['file'] );
	$newfilename = wp_basename( str_ireplace( '.' . $type, '.jpg', $oldfilename ) );
	$newfilename = wp_unique_filename( $uploads['path'], $newfilename );

	$quality = imclarity_get_option( 'imclarity_quality', IMCLARITY_DEFAULT_QUALITY );

	if ( imagejpeg( $img, $uploads['path'] . '/' . $newfilename, $quality ) ) {
		// Conversion succeeded: remove the original bmp & remap the params.
		unlink( $params['file'] );

		$params['file'] = $uploads['path'] . '/' . $newfilename;
		$params['url']  = $uploads['url'] . '/' . $newfilename;
		$params['type'] = 'image/jpeg';
	} else {
		unlink( $params['file'] );

		return wp_handle_upload_error(
			$oldfilename,
			/* translators: %s: the image mime type */
			sprintf( esc_html__( 'Imclarity was unable to process the %s file. If you continue to see this error you may need to disable the conversion option in the Imclarity settings.', 'imclarity' ), $type )
		);
	}

	return $params;
}

/**
 * Convert an image to WebP format.
 *
 * @param string $type Type of the image to be converted: 'bmp', 'png', 'jpg', etc.
 * @param array  $params The upload parameters.
 * @return array altered params
 */
function imclarity_convert_to_webp( $type, $params ) {

	if ( apply_filters( 'imclarity_disable_convert', false, $type, $params ) ) {
		return $params;
	}

	$img = null;

	if ( 'bmp' === $type ) {
		if ( ! function_exists( 'imagecreatefrombmp' ) ) {
			return $params;
		}
		$img = imagecreatefrombmp( $params['file'] );
	} elseif ( 'png' === $type ) {
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return wp_handle_upload_error( $params['file'], esc_html__( 'Imclarity requires the GD library to convert PNG images to WebP', 'imclarity' ) );
		}
		$img = imagecreatefrompng( $params['file'] );
	} elseif ( 'jpg' === $type || 'jpeg' === $type ) {
		if ( ! function_exists( 'imagecreatefromjpeg' ) ) {
			return wp_handle_upload_error( $params['file'], esc_html__( 'Imclarity requires the GD library to convert JPG images to WebP', 'imclarity' ) );
		}
		$img = imagecreatefromjpeg( $params['file'] );
	} else {
		return wp_handle_upload_error( $params['file'], esc_html__( 'Unknown image type specified in imclarity_convert_to_webp', 'imclarity' ) );
	}

	if ( ! function_exists( 'imagewebp' ) ) {
		return wp_handle_upload_error( $params['file'], esc_html__( 'Imclarity requires GD library with WebP support to convert images to WebP', 'imclarity' ) );
	}

	// We need to change the extension from the original to .webp so we have to ensure it will be a unique filename.
	$uploads     = wp_upload_dir();
	$oldfilename = wp_basename( $params['file'] );
	// Get the filename without extension and add .webp
	$pathinfo = pathinfo( $oldfilename );
	$newfilename = $pathinfo['filename'] . '.webp';
	$newfilename = wp_unique_filename( $uploads['path'], $newfilename );

	$quality = imclarity_get_option( 'imclarity_quality', IMCLARITY_DEFAULT_QUALITY );

	if ( imagewebp( $img, $uploads['path'] . '/' . $newfilename, $quality ) ) {
		// Conversion succeeded: remove the original image & remap the params.
		unlink( $params['file'] );

		$params['file'] = $uploads['path'] . '/' . $newfilename;
		$params['url']  = $uploads['url'] . '/' . $newfilename;
		$params['type'] = 'image/webp';
	} else {
		unlink( $params['file'] );

		return wp_handle_upload_error(
			$oldfilename,
			/* translators: %s: the image mime type */
			sprintf( esc_html__( 'Imclarity was unable to process the %s file. If you continue to see this error you may need to disable the conversion option in the Imclarity settings.', 'imclarity' ), $type )
		);
	}

	imagedestroy( $img );
	return $params;
}

// Add filter to hook into uploads.
add_filter( 'wp_handle_upload', 'imclarity_handle_upload' );
// Run necessary actions on init (loading translations mostly).
add_action( 'plugins_loaded', 'imclarity_init' );

// Adds a column to the media library list view to display optimization results.
add_filter( 'manage_media_columns', 'imclarity_media_columns' );
// Outputs the actual column information for each attachment.
add_action( 'manage_media_custom_column', 'imclarity_custom_column', 10, 2 );
// Checks for WebP support and adds it to the allowed mime types.
add_filter( 'imclarity_allowed_mimes', 'imclarity_add_webp_support' );
