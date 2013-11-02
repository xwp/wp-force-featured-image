<?php
/**
 * Plugin Name: Force Featured Image
 * Plugin URI: http://x-team.com
 * Description: Force certain post types to be published with a featured image and a certain dimension if specified
 * Version: 0.2.0
 * Author: X-Team, Jonathan Bardo
 * Author URI: http://x-team.com/wordpress/
 * Text Domain: force-featured-image
 * Domain Path: /languages
 */

/**
 * Copyright (c) 2013 X-Team (http://x-team.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

class Force_Featured_Image {

	/**
	 * Flag to indicate if dependencies are satisfied.
	 *
	 * @since 0.1.0
	 * @var bool
	 * @access private
	 */
	private static $options = array(
		'post_types' => array(),
	);

	/**
	 * Contain the error message identifier
	 * @var string
	 * @access public
	 */
	public static $admin_message;

	/**
	 * Constructor.
	 *
	 * @return \Force_Featured_Image
	 */
	public function __construct() {
		// Internationalize the text strings used.
		add_action( 'plugins_loaded', array( $this, 'i18n' ), 1 );
		add_action( 'after_setup_theme', array( $this, 'after_setup_theme' ) );
	}

	/**
	 * Loads the translation files.
	 *
	 * @access public
	 * @action plugins_loaded
	 * @return void
	 */
	public function i18n() {
		// Load the translation of the plugin
		load_plugin_textdomain( 'force-featured-image', false, 'force-featured-image/languages' );
	}

	/**
	 * Add actions after setup themes so function.php in the theme could add force_featured_image_post_type filter
	 *
	 * @action after_setup_theme
	 *
	 * @return void
	 */
	public function after_setup_theme() {
		// Let the theme override some options
		self::$options['post_types'] = apply_filters( 'force_featured_image_post_type', self::$options['post_types'] );

		// If no post-types are specified in the options, no use to go further
		if ( empty( self::$options['post_types'] ) )
			return;

		// Load components
		add_filter( 'admin_post_thumbnail_html', array( $this, 'admin_post_thumbnail_html' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'check_featured_image' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Check if a user remove the image and, if yes, set post status to draft so it
	 * doesn't affect the front-end
	 *
	 * @filter admin_post_thumbnail_html
	 * @param $content string
	 * @param $post_id int
	 *
	 * @return mixed
	 */
	public function admin_post_thumbnail_html( $content, $post_id ) {
		if ( ! defined( 'DOING_AJAX' ) )
			return $content;

		$featured_image_id = get_post_thumbnail_id( $post_id );
		$post = get_post( $post_id );

		if ( ! is_null( $post ) ) {
			if ( empty( $featured_image_id ) || ! $this->image_respect_size( $featured_image_id, $post->post_type ) ) {
				if ( $this->is_forced_for_post_type( $post->post_type ) ) {
					$post->post_status = 'draft';
					@wp_update_post( $post );
				}
			}
		}

		return $content;
	}

	/**
	 * Check if be featured image is present before saving the post
	 * Revert to draft if the post doesn't have a featured image
	 *
	 * @action wp_insert_post_data
	 * @param $data array
	 * @param $postarr array
	 *
	 * @return mixed
	 */
	public function check_featured_image( $data, $postarr ) {
		// Check if the post has transitioned to publish and has no thumbnail id
		if ( 'publish' === $data['post_status'] ) {
			$this->check_featured_image_validity( $postarr['ID'], $postarr['post_type'] );
		}

		if ( ! empty( self::$admin_message ) ) {
			add_filter( 'redirect_post_location', array( $this, 'add_notice_query_var' ), 99 );
			$data['post_status'] = 'draft';
		}

		return $data;
	}

	/**
	 * Add custom admin message if force-feature-image query var is set
	 *
	 * @action admin_notices
	 *
	 * @return string|void
	 */
	public function admin_notices() {
		global $post, $current_screen;
		$msg_id = filter_input( INPUT_GET, 'force-featured-image', FILTER_SANITIZE_STRING );

		if ( ! $msg_id && ! is_null( $post ) && 'post' === $current_screen->base ) {
			$this->check_featured_image_validity( $post->ID, $post->post_type );
			$msg_id = self::$admin_message;
		}

		switch ( $msg_id ) {
			case 'wrong-size' :
				$dimension = sprintf( '<strong>%spx X %spx</strong>', self::$options['post_types'][$post->post_type]['width'], self::$options['post_types'][$post->post_type]['height'] );
				$msg = __( "This post <strong>featured image doesn't respect the image dimention</strong>. Please add an image with the following dimension : ", 'force-featured-image' ) . $dimension;
				break;
			case 'no-image':
				$msg = __( "This post <strong>doesn't have a featured image</strong>. Please add an image before publishing.", 'force-featured-image' );
				break;
			default :
				return;
				break;
		}

		?>
			<div class="error">
				<p><?php echo $msg; //xss ok ?></p>
			</div>
		<?php
	}

	/**
	 * Add query var so we can display a custom message if the user hasn't set any featured image
	 *
	 * @param $location
	 *
	 * @return string
	 */
	public function add_notice_query_var( $location ) {
		remove_filter( 'redirect_post_location', array( $this, 'add_notice_query_var' ), 99 );
		return add_query_arg( array( 'force-featured-image' => self::$admin_message, 'message' => 10 ), $location );
	}

	/**
	 * Check image condition and put an admin message accordingly
	 *
	 * @param int $post_id
	 *
	 * @param string $post_type
	 *
	 * @return void
	 */
	private function check_featured_image_validity( $post_id, $post_type ) {
		if ( ! $this->is_forced_for_post_type( $post_type ) )
			return;

		// Get the featured image associated with this post
		$featured_image_id   = get_post_thumbnail_id( $post_id );

		// Check if featured image is present
		if ( empty( $featured_image_id ) ) {
			self::$admin_message = 'no-image';
		} else if ( ! $this->image_respect_size( $featured_image_id, $post_type )	) {
			self::$admin_message = 'wrong-size';
		}
	}

	/**
	 * Check if post type is forced to have a featured image
	 *
	 * @param string $post_type
	 * @access private
	 *
	 * @return bool
	 */
	private function is_forced_for_post_type( $post_type = 'post' ) {
		return array_key_exists( $post_type, self::$options['post_types'] );
	}

	/**
	 * Check if the an image match this plugin provided options
	 *
	 * @param $image_id
	 * @param $post_type
	 *
	 * @return bool
	 */
	private function image_respect_size( $image_id, $post_type ){
		$image_meta = wp_get_attachment_metadata( $image_id );
		$option_meta = self::$options['post_types'][$post_type];

		if ( $image_meta ) {

			// If the image is present and the self::$options['post_types'] array match the $image_meta array
			if ( count( array_diff_assoc( $option_meta, array_intersect_assoc( $option_meta, $image_meta ) ) ) === 0 )
				return true;

			$dimensions = array(
				'width'  => 0,
				'height' => 0,
			);

			$dimensions = wp_parse_args( $option_meta, $dimensions );

			// Check if width is set or if height is set and larger than the size in the option (so WordPress can crop)
			$option_width  = filter_var( $image_meta['width'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => ( int ) $dimensions['width'] ) ) );
			$option_height = filter_var( $image_meta['height'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => ( int ) $dimensions['height'] ) ) );

			if ( $option_width && $option_height )
				return true;
		}


		return false;
	}

}

$GLOBALS['force_featured_image'] = new Force_Featured_Image();
