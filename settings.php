<?php
/**
 * Imclarity settings and admin UI.
 *
 * @package Imclarity
 */

// Setup custom $wpdb attribute for our image-tracking table.
global $wpdb;
if ( ! isset( $wpdb->imclarity_ms ) ) {
	$wpdb->imclarity_ms = $wpdb->get_blog_prefix( 0 ) . 'imclarity';
}

// Register the plugin settings menu.
add_action( 'admin_menu', 'imclarity_create_menu' );
add_action( 'network_admin_menu', 'imclarity_register_network' );
add_filter( 'plugin_action_links_' . IMCLARITY_PLUGIN_FILE_REL, 'imclarity_settings_link' );
add_filter( 'network_admin_plugin_action_links_' . IMCLARITY_PLUGIN_FILE_REL, 'imclarity_settings_link' );
add_action( 'admin_enqueue_scripts', 'imclarity_queue_script' );
add_action( 'admin_init', 'imclarity_register_settings' );
add_filter( 'big_image_size_threshold', 'imclarity_adjust_default_threshold', 10, 3 );

register_activation_hook( IMCLARITY_PLUGIN_FILE_REL, 'imclarity_maybe_created_custom_table' );

// settings cache.
$_imclarity_multisite_settings = null;

/**
 * Create the settings menu item in the WordPress admin navigation and
 * link it to the plugin settings page
 */
function imclarity_create_menu() {
	$permissions = apply_filters( 'imclarity_admin_permissions', 'manage_options' );
	// Create new menu for site configuration.
	add_options_page(
		esc_html__( 'Imclarity Plugin Settings', 'imclarity' ), // Page Title.
		esc_html__( 'Imclarity', 'imclarity' ),                 // Menu Title.
		$permissions,                                         // Required permissions.
		IMCLARITY_PLUGIN_FILE_REL,                             // Slug.
		'imclarity_settings_page'                              // Function to call.
	);
}

/**
 * Register the network settings page
 */
function imclarity_register_network() {
	if ( ! function_exists( 'is_plugin_active_for_network' ) && is_multisite() ) {
		// Need to include the plugin library for the is_plugin_active function.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( is_multisite() ) {
		$permissions = apply_filters( 'imclarity_superadmin_permissions', 'manage_network_options' );
		add_submenu_page(
			'settings.php',
			esc_html__( 'Imclarity Network Settings', 'imclarity' ),
			esc_html__( 'Imclarity', 'imclarity' ),
			$permissions,
			IMCLARITY_PLUGIN_FILE_REL,
			'imclarity_network_settings'
		);
	}
}

/**
 * Settings link that appears on the plugins overview page
 *
 * @param array $links The plugin action links.
 * @return array The action links, with a settings link pre-pended.
 */
function imclarity_settings_link( $links ) {
	if ( ! is_array( $links ) ) {
		$links = array();
	}
	if ( is_multisite() && is_network_admin() ) {
		$settings_link = '<a href="' . network_admin_url( 'settings.php?page=' . IMCLARITY_PLUGIN_FILE_REL ) . '">' . esc_html__( 'Settings', 'imclarity' ) . '</a>';
	} else {
		$settings_link = '<a href="' . admin_url( 'options-general.php?page=' . IMCLARITY_PLUGIN_FILE_REL ) . '">' . esc_html__( 'Settings', 'imclarity' ) . '</a>';
	}
	array_unshift( $links, $settings_link );
	return $links;
}

/**
 * Queues up the AJAX script and any localized JS vars we need.
 *
 * @param string $hook The hook name for the current page.
 */
function imclarity_queue_script( $hook ) {
	// Make sure we are being called from the settings page.
	if ( strpos( $hook, 'settings_page_imclarity' ) !== 0 && 'upload.php' !== $hook ) {
		return;
	}
	if ( ! empty( $_REQUEST['imclarity_reset'] ) && ! empty( $_REQUEST['imclarity_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_REQUEST['imclarity_wpnonce'] ), 'imclarity-bulk-reset' ) ) {
		update_option( 'imclarity_resume_id', 0, false );
	}
	
	// Handle bulk process from media library
	if ( ! empty( $_REQUEST['bulk_process'] ) ) {
		$bulk_ids = get_transient( 'imclarity_bulk_process_ids' );
		if ( $bulk_ids ) {
			// Store as resume IDs for processing and clean up transient
			update_option( 'imclarity_bulk_selected_ids', $bulk_ids, false );
			delete_transient( 'imclarity_bulk_process_ids' );
		}
	}
	
	$resume_id     = (int) get_option( 'imclarity_resume_id' );
	$loading_image = plugins_url( '/images/ajax-loader.gif', __FILE__ );
	// Register the scripts that are used by the bulk resizer.
	wp_enqueue_script( 'imclarity_script', plugins_url( '/scripts/imclarity.js', __FILE__ ), array( 'jquery' ), IMCLARITY_VERSION );
	wp_localize_script(
		'imclarity_script',
		'imclarity_vars',
		array(
			'_wpnonce'          => wp_create_nonce( 'imclarity-bulk' ),
			'resize_all_prompt' => esc_html__( 'You are about to resize all your existing images. Please be sure your site is backed up before proceeding. Do you wish to continue?', 'imclarity' ),
			'resizing_complete' => esc_html__( 'Processing Complete', 'imclarity' ) . ' - <a target="_blank" href="https://wordpress.org/support/plugin/imclarity/reviews/#new-post">' . esc_html__( 'Leave a Review', 'imclarity' ) . '</a>',
			'resize_selected'   => esc_html__( 'Process Selected Images', 'imclarity' ),
			'resizing'          => '<p>' . esc_html__( 'Please wait...', 'imclarity' ) . "&nbsp;<img src='$loading_image' /></p>",
			'removal_failed'    => esc_html__( 'Removal Failed', 'imclarity' ),
			'removal_succeeded' => esc_html__( 'Removal Complete', 'imclarity' ),
			'operation_stopped' => esc_html__( 'Processing stopped, reload page to resume.', 'imclarity' ),
			'image'             => esc_html__( 'Image', 'imclarity' ),
			'invalid_response'  => esc_html__( 'Received an invalid response, please check for errors in the Developer Tools console of your browser.', 'imclarity' ),
			'bulk_process'      => ( ! empty( $_REQUEST['bulk_process'] ) && strpos( $hook, 'settings_page_imclarity' ) === 0 ) ? 1 : 0,
			'none_found'        => esc_html__( 'There are no images that need to be resized.', 'imclarity' ),
			'resume_id'         => $resume_id,
		)
	);
	add_action( 'admin_notices', 'imclarity_missing_gd_admin_notice' );
	add_action( 'network_admin_notices', 'imclarity_missing_gd_admin_notice' );
	add_action( 'admin_print_scripts', 'imclarity_settings_css' );
}

/**
 * Return true if the multi-site settings table exists
 *
 * @return bool True if the Imclarity table exists.
 */
function imclarity_multisite_table_exists() {
	global $wpdb;
	return $wpdb->get_var( "SHOW TABLES LIKE '$wpdb->imclarity_ms'" ) === $wpdb->imclarity_ms;
}

/**
 * Checks the schema version for the Imclarity table.
 *
 * @return string The version identifier for the schema.
 */
function imclarity_multisite_table_schema_version() {
	// If the table doesn't exist then there is no schema to report.
	if ( ! imclarity_multisite_table_exists() ) {
		return '0';
	}

	global $wpdb;
	$version = $wpdb->get_var( "SELECT data FROM $wpdb->imclarity_ms WHERE setting = 'schema'" );

	if ( ! $version ) {
		$version = '1.0'; // This is a legacy version 1.0 installation.
	}

	return $version;
}

/**
 * Returns the default network settings in the case where they are not
 * defined in the database, or multi-site is not enabled.
 *
 * @return stdClass
 */
function imclarity_get_default_multisite_settings() {
	$data = new stdClass();

	$data->imclarity_override_site      = false;
	$data->imclarity_max_height         = IMCLARITY_DEFAULT_MAX_HEIGHT;
	$data->imclarity_max_width          = IMCLARITY_DEFAULT_MAX_WIDTH;
	$data->imclarity_max_height_library = IMCLARITY_DEFAULT_MAX_HEIGHT;
	$data->imclarity_max_width_library  = IMCLARITY_DEFAULT_MAX_WIDTH;
	$data->imclarity_max_height_other   = IMCLARITY_DEFAULT_MAX_HEIGHT;
	$data->imclarity_max_width_other    = IMCLARITY_DEFAULT_MAX_WIDTH;
	$data->imclarity_convert_to_webp    = false;
	$data->imclarity_bmp_to_jpg         = IMCLARITY_DEFAULT_BMP_TO_JPG;
	$data->imclarity_png_to_jpg         = IMCLARITY_DEFAULT_PNG_TO_JPG;
	$data->imclarity_quality            = IMCLARITY_DEFAULT_QUALITY;
	$data->imclarity_delete_originals   = false;
	return $data;
}


/**
 * On activation create the multisite database table if necessary.  this is
 * called when the plugin is activated as well as when it is automatically
 * updated.
 */
function imclarity_maybe_created_custom_table() {
	// If not a multi-site no need to do any custom table lookups.
	if ( ! function_exists( 'is_multisite' ) || ( ! is_multisite() ) ) {
		return;
	}

	global $wpdb;

	$schema = imclarity_multisite_table_schema_version();

	if ( '0' === $schema ) {
		// This is an initial database setup.
		$sql = 'CREATE TABLE IF NOT EXISTS ' . $wpdb->imclarity_ms . ' (
					  setting varchar(55),
					  data text NOT NULL,
					  PRIMARY KEY (setting)
					);';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Add the rows to the database.
		$data = imclarity_get_default_multisite_settings();
		$wpdb->insert(
			$wpdb->imclarity_ms,
			array(
				'setting' => 'multisite',
				'data'    => maybe_serialize( $data ),
			)
		);
		$wpdb->insert(
			$wpdb->imclarity_ms,
			array(
				'setting' => 'schema',
				'data'    => IMCLARITY_SCHEMA_VERSION,
			)
		);
	}

	if ( IMCLARITY_SCHEMA_VERSION !== $schema ) {
		// This is a schema update.  for the moment there is only one schema update available, from 1.0 to 1.1.
		if ( '1.0' === $schema ) {
			// Update from version 1.0 to 1.1.
			$wpdb->insert(
				$wpdb->imclarity_ms,
				array(
					'setting' => 'schema',
					'data'    => IMCLARITY_SCHEMA_VERSION,
				)
			);
			$wpdb->query( "ALTER TABLE $wpdb->imclarity_ms CHANGE COLUMN data data TEXT NOT NULL;" );
		} else {
			// @todo we don't have this yet
			$wpdb->update(
				$wpdb->imclarity_ms,
				array( 'data' => IMCLARITY_SCHEMA_VERSION ),
				array( 'setting' => 'schema' )
			);
		}
	}
}

/**
 * Display the form for the multi-site settings page.
 */
function imclarity_network_settings() {
	$settings = imclarity_get_multisite_settings(); ?>
<div class="wrap">
	<h1><?php esc_html_e( 'Imclarity Network Settings', 'imclarity' ); ?></h1>


	<form method="post" action="">
	<input type="hidden" name="update_imclarity_settings" value="1" />
	<?php wp_nonce_field( 'imclarity_network_options' ); ?>
	<table class="form-table">
		<tr>
			<th scope="row"><label for="imclarity_override_site"><?php esc_html_e( 'Global Settings Override', 'imclarity' ); ?></label></th>
			<td>
				<select name="imclarity_override_site">
					<option value="0" <?php selected( $settings->imclarity_override_site, '0' ); ?> ><?php esc_html_e( 'Allow each site to configure Imclarity settings', 'imclarity' ); ?></option>
					<option value="1" <?php selected( $settings->imclarity_override_site, '1' ); ?> ><?php esc_html_e( 'Use global Imclarity settings (below) for all sites', 'imclarity' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'If you allow per-site configuration, the settings below will be used as the defaults. Single-site defaults will be set the first time you visit the site admin after activating Imclarity.', 'imclarity' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Images uploaded within a Page/Post', 'imclarity' ); ?></th>
			<td>
				<label for="imclarity_max_width"><?php esc_html_e( 'Max Width', 'imclarity' ); ?></label> <input type="number" step="1" min="0" class='small-text' name="imclarity_max_width" value="<?php echo (int) $settings->imclarity_max_width; ?>" />
				<label for="imclarity_max_height"><?php esc_html_e( 'Max Height', 'imclarity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imclarity_max_height" value="<?php echo (int) $settings->imclarity_max_height; ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'imclarity' ); ?>
				<p class="description"><?php esc_html_e( 'These dimensions are used for Bulk Resizing also.', 'imclarity' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Images uploaded directly to the Media Library', 'imclarity' ); ?></th>
			<td>
				<label for="imclarity_max_width_library"><?php esc_html_e( 'Max Width', 'imclarity' ); ?></label> <input type="number" step="1" min="0" class='small-text' name="imclarity_max_width_library" value="<?php echo (int) $settings->imclarity_max_width_library; ?>" />
				<label for="imclarity_max_height_library"><?php esc_html_e( 'Max Height', 'imclarity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imclarity_max_height_library" value="<?php echo (int) $settings->imclarity_max_height_library; ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'imclarity' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Images uploaded elsewhere (Theme headers, backgrounds, logos, etc)', 'imclarity' ); ?></th>
			<td>
				<label for="imclarity_max_width_other"><?php esc_html_e( 'Max Width', 'imclarity' ); ?></label> <input type="number" step="1" min="0" class='small-text' name="imclarity_max_width_other" value="<?php echo (int) $settings->imclarity_max_width_other; ?>" />
				<label for="imclarity_max_height_other"><?php esc_html_e( 'Max Height', 'imclarity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imclarity_max_height_other" value="<?php echo (int) $settings->imclarity_max_height_other; ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'imclarity' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for='imclarity_quality'><?php esc_html_e( 'JPG image quality', 'imclarity' ); ?>
			</th>
			<td>
				<input type='text' id='imclarity_quality' name='imclarity_quality' class='small-text' value='<?php echo (int) $settings->imclarity_quality; ?>' />
				<?php esc_html_e( 'Usable values are 1-92.', 'imclarity' ); ?>
				<p class='description'><?php esc_html_e( 'Only used when resizing images, does not affect thumbnails.', 'imclarity' ); ?><br>
				<?php esc_html_e( 'When converting to WebP: This value is automatically reduced by 9% to account for WebP\'s better compression efficiency.', 'imclarity' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="imclarity_convert_to_webp"><?php esc_html_e( 'Convert to WebP', 'imclarity' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="imclarity_convert_to_webp" name="imclarity_convert_to_webp" value="true" <?php checked( $settings->imclarity_convert_to_webp ); ?> />
				<?php esc_html_e( 'Convert images to WebP format instead of JPG when resizing.', 'imclarity' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for"imclarity_bmp_to_jpg"><?php esc_html_e( 'Convert BMP', 'imclarity' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="imclarity_bmp_to_jpg" name="imclarity_bmp_to_jpg" value="true" <?php checked( $settings->imclarity_bmp_to_jpg ); ?> />
				<?php esc_html_e( 'Convert BMP images to JPG (or WebP if enabled above). Only applies to new uploads.', 'imclarity' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="imclarity_png_to_jpg"><?php esc_html_e( 'Convert PNG', 'imclarity' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="imclarity_png_to_jpg" name="imclarity_png_to_jpg" value="true" <?php checked( $settings->imclarity_png_to_jpg ); ?> />
				<?php esc_html_e( 'Convert PNG images to JPG (or WebP if enabled above). Only applies to new uploads.', 'imclarity' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="imclarity_delete_originals"><?php esc_html_e( 'Delete Originals', 'imclarity' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="imclarity_delete_originals" name="imclarity_delete_originals" value="true" <?php checked( $settings->imclarity_delete_originals ); ?> />
				<?php esc_html_e( 'Remove the large pre-scaled originals that WordPress retains for thumbnail generation.', 'imclarity' ); ?>
			</td>
		</tr>
	</table>

	<p class="submit"><input type="submit" class="button-primary" value="<?php esc_attr_e( 'Update Settings', 'imclarity' ); ?>" /></p>

	</form>

</div>
	<?php
}

/**
 * Process the form, update the network settings
 * and clear the cached settings
 */
function imclarity_network_settings_update() {
	if ( ! current_user_can( 'manage_options' ) || empty( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'imclarity_network_options' ) ) {
		return;
	}
	global $wpdb;
	global $_imclarity_multisite_settings;

	// ensure that the custom table is created when the user updates network settings
	// this is not ideal but it's better than checking for this table existance
	// on every page load.
	imclarity_maybe_created_custom_table();

	$data = new stdClass();

	$data->imclarity_override_site      = isset( $_POST['imclarity_override_site'] ) ? (bool) $_POST['imclarity_override_site'] : false;
	$data->imclarity_max_height         = isset( $_POST['imclarity_max_height'] ) ? (int) $_POST['imclarity_max_height'] : 0;
	$data->imclarity_max_width          = isset( $_POST['imclarity_max_width'] ) ? (int) $_POST['imclarity_max_width'] : 0;
	$data->imclarity_max_height_library = isset( $_POST['imclarity_max_height_library'] ) ? (int) $_POST['imclarity_max_height_library'] : 0;
	$data->imclarity_max_width_library  = isset( $_POST['imclarity_max_width_library'] ) ? (int) $_POST['imclarity_max_width_library'] : 0;
	$data->imclarity_max_height_other   = isset( $_POST['imclarity_max_height_other'] ) ? (int) $_POST['imclarity_max_height_other'] : 0;
	$data->imclarity_max_width_other    = isset( $_POST['imclarity_max_width_other'] ) ? (int) $_POST['imclarity_max_width_other'] : 0;
	$data->imclarity_convert_to_webp    = ! empty( $_POST['imclarity_convert_to_webp'] );
	$data->imclarity_bmp_to_jpg         = ! empty( $_POST['imclarity_bmp_to_jpg'] );
	$data->imclarity_png_to_jpg         = ! empty( $_POST['imclarity_png_to_jpg'] );
	$data->imclarity_quality            = isset( $_POST['imclarity_quality'] ) ? imclarity_jpg_quality( intval( $_POST['imclarity_quality'] ) ) : 82;
	$data->imclarity_delete_originals   = ! empty( $_POST['imclarity_delete_originals'] );

	$success = $wpdb->update(
		$wpdb->imclarity_ms,
		array( 'data' => maybe_serialize( $data ) ),
		array( 'setting' => 'multisite' )
	);

	// Clear the cache.
	$_imclarity_multisite_settings = null;
	add_action( 'network_admin_notices', 'imclarity_network_settings_saved' );
}

/**
 * Display a message to inform the user the multi-site setting have been saved.
 */
function imclarity_network_settings_saved() {
	echo "<div id='imclarity-network-settings-saved' class='updated fade'><p><strong>" . esc_html__( 'Imclarity network settings saved.', 'imclarity' ) . '</strong></p></div>';
}

/**
 * Return the multi-site settings as a standard class.  If the settings are not
 * defined in the database or multi-site is not enabled then the default settings
 * are returned.  This is cached so it only loads once per page load, unless
 * imclarity_network_settings_update is called.
 *
 * @return stdClass
 */
function imclarity_get_multisite_settings() {
	global $_imclarity_multisite_settings;
	$result = null;

	if ( ! $_imclarity_multisite_settings ) {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			global $wpdb;
			$result = $wpdb->get_var( "SELECT data FROM $wpdb->imclarity_ms WHERE setting = 'multisite'" );
		}

		// if there's no results, return the defaults instead.
		$_imclarity_multisite_settings = $result
			? unserialize( $result )
			: imclarity_get_default_multisite_settings();

		// this is for backwards compatibility.
		if ( ! isset( $_imclarity_multisite_settings->imclarity_max_height_library ) ) {
			$_imclarity_multisite_settings->imclarity_max_height_library = $_imclarity_multisite_settings->imclarity_max_height;
			$_imclarity_multisite_settings->imclarity_max_width_library  = $_imclarity_multisite_settings->imclarity_max_width;
			$_imclarity_multisite_settings->imclarity_max_height_other   = $_imclarity_multisite_settings->imclarity_max_height;
			$_imclarity_multisite_settings->imclarity_max_width_other    = $_imclarity_multisite_settings->imclarity_max_width;
		}
		$_imclarity_multisite_settings->imclarity_override_site = ! empty( $_imclarity_multisite_settings->imclarity_override_site ) ? '1' : '0';
		$_imclarity_multisite_settings->imclarity_convert_to_webp = ! empty( $_imclarity_multisite_settings->imclarity_convert_to_webp ) ? true : false;
		$_imclarity_multisite_settings->imclarity_bmp_to_jpg    = ! empty( $_imclarity_multisite_settings->imclarity_bmp_to_jpg ) ? true : false;
		$_imclarity_multisite_settings->imclarity_png_to_jpg    = ! empty( $_imclarity_multisite_settings->imclarity_png_to_jpg ) ? true : false;
		if ( ! property_exists( $_imclarity_multisite_settings, 'imclarity_delete_originals' ) ) {
			$_imclarity_multisite_settings->imclarity_delete_originals = false;
		}
	}
	return $_imclarity_multisite_settings;
}

/**
 * Gets the option setting for the given key, first checking to see if it has been
 * set globally for multi-site.  Otherwise checking the site options.
 *
 * @param string $key The name of the option to retrieve.
 * @param string $ifnull Value to use if the requested option returns null.
 */
function imclarity_get_option( $key, $ifnull ) {
	$result = null;

	$settings = imclarity_get_multisite_settings();

	if ( $settings->imclarity_override_site ) {
		$result = $settings->$key;
		if ( is_null( $result ) ) {
			$result = $ifnull;
		}
	} else {
		$result = get_option( $key, $ifnull );
	}

	return $result;
}

/**
 * Run upgrade check for new version.
 */
function imclarity_upgrade() {
	if ( is_network_admin() ) {
		return;
	}
	if ( -1 === version_compare( get_option( 'imclarity_version' ), IMCLARITY_VERSION ) ) {
		if ( wp_doing_ajax() ) {
			return;
		}
		imclarity_set_defaults();
		update_option( 'imclarity_version', IMCLARITY_VERSION );
	}
}

/**
 * Set default options on multi-site.
 */
function imclarity_set_defaults() {
	$settings = imclarity_get_multisite_settings();
	add_option( 'imclarity_max_width', $settings->imclarity_max_width, '', false );
	add_option( 'imclarity_max_height', $settings->imclarity_max_height, '', false );
	add_option( 'imclarity_max_width_library', $settings->imclarity_max_width_library, '', false );
	add_option( 'imclarity_max_height_library', $settings->imclarity_max_height_library, '', false );
	add_option( 'imclarity_max_width_other', $settings->imclarity_max_width_other, '', false );
	add_option( 'imclarity_max_height_other', $settings->imclarity_max_height_other, '', false );
	add_option( 'imclarity_convert_to_webp', $settings->imclarity_convert_to_webp, '', false );
	add_option( 'imclarity_bmp_to_jpg', $settings->imclarity_bmp_to_jpg, '', false );
	add_option( 'imclarity_png_to_jpg', $settings->imclarity_png_to_jpg, '', false );
	add_option( 'imclarity_quality', $settings->imclarity_quality, '', false );
	add_option( 'imclarity_delete_originals', $settings->imclarity_delete_originals, '', false );
	if ( ! get_option( 'imclarity_version' ) ) {
		global $wpdb;
		$wpdb->query( "UPDATE $wpdb->options SET autoload='no' WHERE option_name LIKE 'imclarity_%'" );
	}
}

/**
 * Register the configuration settings that the plugin will use
 */
function imclarity_register_settings() {
	imclarity_upgrade();
	// We only want to update if the form has been submitted.
	// Verification is done inside the imclarity_network_settings_update() function.
	if ( isset( $_POST['update_imclarity_settings'] ) && is_multisite() && is_network_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification
		imclarity_network_settings_update();
	}
	// Register our settings.
	register_setting( 'imclarity-settings-group', 'imclarity_max_height', 'intval' );
	register_setting( 'imclarity-settings-group', 'imclarity_max_width', 'intval' );
	register_setting( 'imclarity-settings-group', 'imclarity_max_height_library', 'intval' );
	register_setting( 'imclarity-settings-group', 'imclarity_max_width_library', 'intval' );
	register_setting( 'imclarity-settings-group', 'imclarity_max_height_other', 'intval' );
	register_setting( 'imclarity-settings-group', 'imclarity_max_width_other', 'intval' );
	register_setting( 'imclarity-settings-group', 'imclarity_convert_to_webp', 'boolval' );
	register_setting( 'imclarity-settings-group', 'imclarity_bmp_to_jpg', 'boolval' );
	register_setting( 'imclarity-settings-group', 'imclarity_png_to_jpg', 'boolval' );
	register_setting( 'imclarity-settings-group', 'imclarity_quality', 'imclarity_jpg_quality' );
	register_setting( 'imclarity-settings-group', 'imclarity_delete_originals', 'boolval' );
}

/**
 * Validate and return the JPG quality setting.
 *
 * @param int $quality The JPG quality currently set.
 * @return int The (potentially) adjusted quality level.
 */
function imclarity_jpg_quality( $quality = null ) {
	if ( is_null( $quality ) ) {
		$quality = get_option( 'imclarity_quality' );
	}
	if ( preg_match( '/^(100|[1-9][0-9]?)$/', $quality ) ) {
		return (int) $quality;
	} else {
		return IMCLARITY_DEFAULT_QUALITY;
	}
}

/**
 * Check default WP threshold and adjust to comply with normal Imclarity behavior.
 *
 * @param int    $size The default WP scaling size, or whatever has been filtered by other plugins.
 * @param array  $imagesize     {
 *     Indexed array of the image width and height in pixels.
 *
 *     @type int $0 The image width.
 *     @type int $1 The image height.
 * }
 * @param string $file Full path to the uploaded image file.
 * @return int The proper size to use for scaling originals.
 */
function imclarity_adjust_default_threshold( $size, $imagesize = array(), $file = '' ) {
	if ( false !== strpos( $file, 'noresize' ) ) {
		return false;
	}
	$max_size = max(
		imclarity_get_option( 'imclarity_max_width', IMCLARITY_DEFAULT_MAX_WIDTH ),
		imclarity_get_option( 'imclarity_max_height', IMCLARITY_DEFAULT_MAX_HEIGHT ),
		imclarity_get_option( 'imclarity_max_width_library', IMCLARITY_DEFAULT_MAX_WIDTH ),
		imclarity_get_option( 'imclarity_max_height_library', IMCLARITY_DEFAULT_MAX_HEIGHT ),
		imclarity_get_option( 'imclarity_max_width_other', IMCLARITY_DEFAULT_MAX_WIDTH ),
		imclarity_get_option( 'imclarity_max_height_other', IMCLARITY_DEFAULT_MAX_HEIGHT ),
		(int) $size
	);
	return $max_size;
}

/**
 * Helper function to render css styles for the settings forms
 * for both site and network settings page
 */
function imclarity_settings_css() {
	?>
<style>
	#imclarity_header {
		border: solid 1px #c6c6c6;
		margin: 10px 0px;
		padding: 0px 10px;
		background-color: #e1e1e1;
	}
	#imclarity_header p {
		margin: .5em 0;
	}
</style>
	<?php
}

/**
 * Render the settings page by writing directly to stdout.  if multi-site is enabled
 * and imclarity_override_site is true, then display a notice message that settings
 * are not editable instead of the settings form
 */
function imclarity_settings_page() {
	?>
	<div class="wrap">
	<h1><?php esc_html_e( 'Imclarity Settings', 'imclarity' ); ?></h1>
	<p>
		<a target="_blank" href="https://wordpress.org/plugins/imclarity/#faq-header"><?php esc_html_e( 'FAQ', 'imclarity' ); ?></a> |
		<a target="_blank" href="https://wordpress.org/support/plugin/imclarity/"><?php esc_html_e( 'Support', 'imclarity' ); ?></a> |
		<a target="_blank" href="https://wordpress.org/support/plugin/imclarity/reviews/#new-post"><?php esc_html_e( 'Leave a Review', 'imclarity' ); ?></a>
	</p>


	<?php

	$settings = imclarity_get_multisite_settings();

	if ( $settings->imclarity_override_site ) {
		imclarity_settings_page_notice();
	} else {
		imclarity_settings_page_form();
	}

	?>

	<?php if ( ! empty( $_REQUEST['bulk_process'] ) && ! empty( $_REQUEST['ids'] ) ) : ?>
	<h2 style="margin-top: 0px;"><?php esc_html_e( 'Process Selected Images', 'imclarity' ); ?></h2>

	<div id="imclarity_header">
		<p><?php printf( esc_html__( 'Processing %d selected images from the Media Library.', 'imclarity' ), (int) $_REQUEST['ids'] ); ?></p>
	<?php else : ?>
	<h2 style="margin-top: 0px;"><?php esc_html_e( 'Bulk Resize Images', 'imclarity' ); ?></h2>

	<div id="imclarity_header">
		<p><?php esc_html_e( 'If you have existing images that were uploaded prior to installing Imclarity, you may resize them all in bulk to recover disk space (below).', 'imclarity' ); ?></p>
	<?php endif; ?>
		<p>
			<?php
			printf(
				/* translators: 1: List View in the Media Library 2: the WP-CLI command */
				esc_html__( 'You may also use %1$s to selectively resize images or WP-CLI to resize your images in bulk: %2$s', 'imclarity' ),
				'<a href="' . esc_url( admin_url( 'upload.php?mode=list' ) ) . '">' . esc_html__( 'List View in the Media Library', 'imclarity' ) . '</a>',
				'<code>wp help imclarity resize</code>'
			);
			?>
		</p>
	</div>

	<div style="border: solid 1px #ff6666; background-color: #ffbbbb; padding: 0 10px;margin-bottom:1em;">
		<h4><?php esc_html_e( 'WARNING: Bulk Resize will alter your original images and cannot be undone!', 'imclarity' ); ?></h4>
		<p>
			<?php esc_html_e( 'It is HIGHLY recommended that you backup your images before proceeding.', 'imclarity' ); ?><br>
			<?php
			printf(
				/* translators: %s: List View in the Media Library */
				esc_html__( 'You may also resize 1 or 2 images using %s to verify that everything is working properly before processing your entire library.', 'imclarity' ),
				'<a href="' . esc_url( admin_url( 'upload.php?mode=list' ) ) . '">' . esc_html__( 'List View in the Media Library', 'imclarity' ) . '</a>'
			);
			?>
		</p>
	</div>

	<?php
	$button_text = __( 'Start Resizing All Images', 'imclarity' );
	if ( get_option( 'imclarity_resume_id' ) ) {
		$button_text = __( 'Continue Resizing', 'imclarity' );
	}
	?>

	<p class="submit" id="imclarity-examine-button">
		<button class="button-primary" onclick="imclarity_load_images();"><?php echo esc_html( $button_text ); ?></button>
	</p>
	<form id="imclarity-bulk-stop" style="display:none;margin:1em 0 1em;" method="post" action="">
		<button type="submit" class="button-secondary action"><?php esc_html_e( 'Stop Resizing', 'imclarity' ); ?></button><br>
		*<i><?php esc_html_e( 'You will be able to resume the process later.', 'imclarity' ); ?></i>
	</form>
	<?php if ( get_option( 'imclarity_resume_id' ) ) : ?>
	<p class="imclarity-bulk-text" style="margin-top:1em;"><?php esc_html_e( 'Would you like to start back at the beginning?', 'imclarity' ); ?></p>
	<form class="imclarity-bulk-form" method="post" action="">
		<?php wp_nonce_field( 'imclarity-bulk-reset', 'imclarity_wpnonce' ); ?>
		<input type="hidden" name="imclarity_reset" value="1">
		<button id="imclarity-bulk-reset" type="submit" class="button-secondary action"><?php esc_html_e( 'Clear Queue', 'imclarity' ); ?></button>
	</form>
	<?php endif; ?>
	<div id="imclarity_loading" style="display: none;margin:1em 0 1em;"><img src="<?php echo esc_url( plugins_url( 'images/ajax-loader.gif', __FILE__ ) ); ?>" style="margin-bottom: .25em; vertical-align:middle;" />
		<?php esc_html_e( 'Searching for images. This may take a moment.', 'imclarity' ); ?>
	</div>
	<div id="resize_results" style="display: none; border: solid 2px #666666; padding: 10px; height: 400px; overflow: auto;">
		<div id="bulk-resize-beginning"><?php esc_html_e( 'Resizing...', 'imclarity' ); ?> <img src="<?php echo esc_url( plugins_url( 'images/ajax-loader.gif', __FILE__ ) ); ?>" style="margin-bottom: .25em; vertical-align:middle;" /></div>
	</div>

	<?php

	echo '</div>';
}

/**
 * Multi-user config file exists so display a notice
 */
function imclarity_settings_page_notice() {
	?>
	<div class="updated settings-error">
	<p><strong><?php esc_html_e( 'Imclarity settings have been configured by the server administrator. There are no site-specific settings available.', 'imclarity' ); ?></strong></p>
	</div>
	<?php
}

/**
 * Check to see if GD is missing, and alert the user.
 */
function imclarity_missing_gd_admin_notice() {
	if ( imclarity_gd_support() ) {
		return;
	}
	echo "<div id='imclarity-missing-gd' class='notice notice-warning'><p>" . esc_html__( 'The GD extension is not enabled in PHP, Imclarity may not function correctly. Enable GD or contact your web host for assistance.', 'imclarity' ) . '</p></div>';
}

/**
 * Render the site settings form.  This is processed by
 * WordPress built-in options persistance mechanism
 */
function imclarity_settings_page_form() {
	?>
	<form method="post" action="options.php">
	<?php settings_fields( 'imclarity-settings-group' ); ?>
		<table class="form-table">

		<tr>
		<th scope="row"><?php esc_html_e( 'Images uploaded within a Page/Post', 'imclarity' ); ?></th>
		<td>
			<label for="imclarity_max_width"><?php esc_html_e( 'Max Width', 'imclarity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imclarity_max_width" value="<?php echo (int) get_option( 'imclarity_max_width', IMCLARITY_DEFAULT_MAX_WIDTH ); ?>" />
			<label for="imclarity_max_height"><?php esc_html_e( 'Max Height', 'imclarity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imclarity_max_height" value="<?php echo (int) get_option( 'imclarity_max_height', IMCLARITY_DEFAULT_MAX_HEIGHT ); ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'imclarity' ); ?>
			<p class="description"><?php esc_html_e( 'These dimensions are used for Bulk Resizing also.', 'imclarity' ); ?></p>
		</td>
		</tr>

		<tr>
		<th scope="row"><?php esc_html_e( 'Images uploaded directly to the Media Library', 'imclarity' ); ?></th>
		<td>
			<label for="imclarity_max_width_library"><?php esc_html_e( 'Max Width', 'imclarity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imclarity_max_width_library" value="<?php echo (int) get_option( 'imclarity_max_width_library', IMCLARITY_DEFAULT_MAX_WIDTH ); ?>" />
			<label for="imclarity_max_height_library"><?php esc_html_e( 'Max Height', 'imclarity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imclarity_max_height_library" value="<?php echo (int) get_option( 'imclarity_max_height_library', IMCLARITY_DEFAULT_MAX_HEIGHT ); ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'imclarity' ); ?>
		</td>
		</tr>

		<tr>
		<th scope="row"><?php esc_html_e( 'Images uploaded elsewhere (Theme headers, backgrounds, logos, etc)', 'imclarity' ); ?></th>
		<td>
			<label for="imclarity_max_width_other"><?php esc_html_e( 'Max Width', 'imclarity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imclarity_max_width_other" value="<?php echo (int) get_option( 'imclarity_max_width_other', IMCLARITY_DEFAULT_MAX_WIDTH ); ?>" />
			<label for="imclarity_max_height_other"><?php esc_html_e( 'Max Height', 'imclarity' ); ?></label> <input type="number" step="1" min="0" class="small-text" name="imclarity_max_height_other" value="<?php echo (int) get_option( 'imclarity_max_height_other', IMCLARITY_DEFAULT_MAX_HEIGHT ); ?>" /> <?php esc_html_e( 'in pixels, enter 0 to disable', 'imclarity' ); ?>
		</td>
		</tr>


		<tr>
			<th scope="row">
				<label for='imclarity_quality' ><?php esc_html_e( 'JPG image quality', 'imclarity' ); ?>
			</th>
			<td>
				<input type='text' id='imclarity_quality' name='imclarity_quality' class='small-text' value='<?php echo (int) imclarity_jpg_quality(); ?>' />
				<?php esc_html_e( 'Usable values are 1-92.', 'imclarity' ); ?>
				<p class='description'><?php esc_html_e( 'Only used when resizing images, does not affect thumbnails.', 'imclarity' ); ?><br>
				<?php esc_html_e( 'When converting to WebP: This value is automatically reduced by 9% to account for WebP\'s better compression efficiency.', 'imclarity' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="imclarity_convert_to_webp"><?php esc_html_e( 'Convert to WebP', 'imclarity' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="imclarity_convert_to_webp" name="imclarity_convert_to_webp" value="true" <?php checked( (bool) get_option( 'imclarity_convert_to_webp', false ) ); ?> />
				<?php esc_html_e( 'Convert images to WebP format instead of JPG when resizing.', 'imclarity' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="imclarity_bmp_to_jpg"><?php esc_html_e( 'Convert BMP', 'imclarity' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="imclarity_bmp_to_jpg" name="imclarity_bmp_to_jpg" value="true" <?php checked( (bool) get_option( 'imclarity_bmp_to_jpg', IMCLARITY_DEFAULT_BMP_TO_JPG ) ); ?> />
				<?php esc_html_e( 'Convert BMP images to JPG (or WebP if enabled above). Only applies to new uploads.', 'imclarity' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="imclarity_png_to_jpg"><?php esc_html_e( 'Convert PNG', 'imclarity' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="imclarity_png_to_jpg" name="imclarity_png_to_jpg" value="true" <?php checked( (bool) get_option( 'imclarity_png_to_jpg', IMCLARITY_DEFAULT_PNG_TO_JPG ) ); ?> />
				<?php esc_html_e( 'Convert PNG images to JPG (or WebP if enabled above). Only applies to new uploads.', 'imclarity' ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="imclarity_delete_originals"><?php esc_html_e( 'Delete Originals', 'imclarity' ); ?></label>
			</th>
			<td>
				<input type="checkbox" id="imclarity_delete_originals" name="imclarity_delete_originals" value="true" <?php checked( get_option( 'imclarity_delete_originals' ) ); ?> />
				<?php esc_html_e( 'Remove the large pre-scaled originals that WordPress retains for thumbnail generation.', 'imclarity' ); ?>
			</td>
		</tr>
	</table>

	<p class="submit"><input type="submit" class="button-primary" value="<?php esc_attr_e( 'Save Changes', 'imclarity' ); ?>" /></p>

	</form>
	<?php
}
