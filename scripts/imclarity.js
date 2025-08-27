/**
 * imclarity admin javascript functions
 */

jQuery(document).ready(function($) {
	$(".fade").fadeTo(5000,1).fadeOut(3000);
	
	// Auto-start processing if we're in bulk process mode AND on the settings page
	if (imclarity_vars.bulk_process && (window.location.href.indexOf('page=imclarity') > -1 || window.location.href.indexOf('bulk_process=1') > -1)) {
		imclarity_load_images();
	}
});

// Handle a manual resize from the media library.
jQuery(document).on('click', '.imclarity-manual-resize', function() {
	var post_id = jQuery(this).data('id');
	var imclarity_nonce = jQuery(this).data('nonce');
	var button_text = jQuery(this).text();
	
	jQuery('#imclarity-media-status-' + post_id ).html( imclarity_vars.resizing );
	
	// Determine action based on button text
	var action = 'imclarity_resize_image';
	if (button_text === 'Update Thumbnails') {
		action = 'imclarity_update_thumbnails';
	}
	
	jQuery.post(
		ajaxurl,
		{_wpnonce: imclarity_nonce, action: action, id: post_id},
		function(response) {
			var target = jQuery('#imclarity-media-status-' + post_id );
			try {
				target.html(response.message);
				// If thumbnail update was successful, hide the button
				if (action === 'imclarity_update_thumbnails' && response.success) {
					jQuery('#imclarity-media-status-' + post_id ).find('button').hide();
				}
			} catch(e) {
				target.html(imclarity_vars.invalid_response);
				if (console) {
					console.warn(post_id + ': '+ e.message);
					console.warn('Invalid JSON Response: ' + JSON.stringify(response));
				}
			}
		}
	);
	return false;
});

// Handle an original image removal request from the media library.
jQuery(document).on('click', '.imclarity-manual-remove-original', function() {
	var post_id = jQuery(this).data('id');
	var imclarity_nonce = jQuery(this).data('nonce');
	jQuery('#imclarity-media-status-' + post_id ).html( imclarity_vars.resizing );
	jQuery.post(
		ajaxurl,
		{_wpnonce: imclarity_nonce, action: 'imclarity_remove_original', id: post_id},
		function(response) {
			var target = jQuery('#imclarity-media-status-' + post_id );
			try {
				if (! response.success) {
					target.html(imclarity_vars.removal_failed);
				} else {
					target.html(imclarity_vars.removal_succeeded);
				}
			} catch(e) {
				target.html(imclarity_vars.invalid_response);
				if (console) {
					console.warn(post_id + ': '+ e.message);
					console.warn('Invalid JSON Response: ' + JSON.stringify(response));
				}
			}
		}
	);
	return false;
});

jQuery(document).on('submit', '#imclarity-bulk-stop', function() {
	jQuery(this).hide();
	imclarity_vars.stopped = true;
	imclarity_vars.attachments = [];
	jQuery('#imclarity_loading').html(imclarity_vars.operation_stopped);
	jQuery('#imclarity_loading').show();
	return false;
});

/**
 * Begin the process of re-sizing all of the checked images
 */
function imclarity_resize_images() {
	// start the recursion
	imclarity_resize_next(0);
}

/**
 * recursive function for resizing images
 */
function imclarity_resize_next(next_index) {
	if (next_index >= imclarity_vars.attachments.length) return imclarity_resize_complete();
	var total_images = imclarity_vars.attachments.length;
	var target = jQuery('#resize_results');
	target.show();

	jQuery.post(
		ajaxurl, // (defined by wordpress - points to admin-ajax.php)
		{_wpnonce: imclarity_vars._wpnonce, action: 'imclarity_resize_image', id: imclarity_vars.attachments[next_index], resumable: 1},
		function (response) {
			var result;
			jQuery('#bulk-resize-beginning').hide();

			try {
				target.append('<div>' + (next_index+1) + '/' + total_images + ' &gt;&gt; ' + response.message +'</div>');
			} catch(e) {
				target.append('<div>' + imclarity_vars.invalid_response + '</div>');
				if (console) {
					console.warn(imclarity_vars.attachments[next_index] + ': '+ e.message);
					console.warn('Invalid JSON Response: ' + JSON.stringify(response));
				}
			}
			// recurse
			imclarity_resize_next(next_index+1);
		}
	);
}

/**
 * fired when all images have been resized
 */
function imclarity_resize_complete() {
	var target = jQuery('#resize_results');
	if (! imclarity_vars.stopped) {
		jQuery('#imclarity-bulk-stop').hide();
		target.append('<div><strong>' + imclarity_vars.resizing_complete + '</strong></div>');
		jQuery.post(
			ajaxurl, // (global defined by wordpress - points to admin-ajax.php)
			{_wpnonce: imclarity_vars._wpnonce, action: 'imclarity_bulk_complete'}
		);
	}
}

/**
 * ajax post to return all images from the library
 * @param string the id of the html element into which results will be appended
 */
function imclarity_load_images() {
	// Skip confirmation prompt if processing selected images from bulk action
	if (!imclarity_vars.bulk_process) {
		var imclarity_really_resize_all = confirm(imclarity_vars.resize_all_prompt);
		if ( ! imclarity_really_resize_all ) {
			return;
		}
	}
	jQuery('#imclarity-examine-button').hide();
	jQuery('.imclarity-bulk-text').hide();
	jQuery('#imclarity-bulk-reset').hide();
	jQuery('#imclarity_loading').show();

	jQuery.post(
		ajaxurl, // (global defined by wordpress - points to admin-ajax.php)
		{_wpnonce: imclarity_vars._wpnonce, action: 'imclarity_get_images', resume_id: imclarity_vars.resume_id},
		function(response) {
			var images = response;
			if (! Array.isArray(images)) {
				console.log( response );
				return false;
			}

			jQuery('#imclarity_loading').hide();
			if (images.length > 0) {
				imclarity_vars.attachments = images;
				imclarity_vars.stopped = false;
				jQuery('#imclarity-bulk-stop').show();
				imclarity_resize_images();
			} else {
				jQuery('#imclarity_loading').html('<div>' + imclarity_vars.none_found + '</div>');
			}
		}
	);
}
