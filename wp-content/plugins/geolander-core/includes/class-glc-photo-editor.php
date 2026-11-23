<?php
/**
 * WordPress's own picture editor, with one thing added: taking out what a
 * camera writes beside the pixels.
 *
 * A phone photo carries where and when it was taken, the phone's model and
 * sometimes its owner's name. WordPress keeps all of that when it saves a
 * picture again. A partner's car photo goes onto a public page, so before it is
 * saved everything but the colour profile is removed (without the profile the
 * colours of a modern phone's photos would shift).
 *
 * This file is loaded only at the moment a car photo is being stored (see
 * GLC_Partner_Media), because the class it extends is not loaded with every
 * request. On a server without Imagick the other editor, GD, is used instead:
 * it writes pixels and nothing else, so there is nothing to remove.
 */

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';

class GLC_Photo_Editor extends WP_Image_Editor_Imagick {

	/** What stays: the two names a colour profile goes by. */
	private const KEPT = [ 'icc', 'icm' ];

	/** Call after the picture has been turned upright: "which way is up" is one of the things removed. */
	public function drop_camera_data(): bool {
		try {
			$kept = [];
			foreach ( self::KEPT as $name ) {
				try {
					$kept[ $name ] = $this->image->getImageProfile( $name );
				} catch ( Exception $e ) {
					continue; // the picture has no such profile
				}
			}
			$this->image->stripImage();
			foreach ( $kept as $name => $profile ) {
				$this->image->profileImage( $name, $profile );
			}
			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}
}
