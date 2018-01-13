<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_REST_Cache_Admin' ) ) {

	/**
	 * Class WP_REST_Cache_Admin
	 */
	class WP_REST_Cache_Admin {

		/**
		 * Default settings.
		 *
		 * @var array $default
		 */
		private static $default = array(
			'timeout' => array(
				'length' => 1,
				'period' => WEEK_IN_SECONDS,
			),
		);

		/**
		 * Initiate the class.
		 */
		public static function init() {
			self::hooks();
		}

		/**
		 * Add class hooks.
		 */
		private static function hooks() {
			if ( apply_filters( 'rest_cache_show_admin', true ) ) {
				if ( apply_filters( 'rest_cache_show_admin_menu', true ) ) {
					add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
				}

				if ( apply_filters( 'rest_cache_show_admin_bar_menu', true ) ) {
					add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_menu' ), 999 );
				}
			}
		}

		/**
		 * Hook into the WordPress admin bar.
		 *
		 * @param WP_Admin_Bar $wp_admin_bar WP_Admin_Bar object.
		 */
		public static function admin_bar_menu( WP_Admin_Bar $wp_admin_bar ) {
			$args = array(
				'id'    => 'wp-rest-api-cache',
				'title' => __( 'REST API Cache', 'wp-rest-api-cache' ),
			);

			$wp_admin_bar->add_node( $args );
			$wp_admin_bar->add_menu( array(
				'parent' => 'wp-rest-api-cache',
				'id'     => 'wp-rest-api-cache-empty',
				'title'  => __( 'Empty all cache', 'wp-rest-api-cache' ),
				'href'   => esc_url( self::_empty_cache_url() ),
			) );
		}

		/**
		 * Hook into the WordPress admin menu.
		 */
		public static function admin_menu() {
			add_submenu_page(
				'options-general.php',
				__( 'WP REST API Cache', 'wp-rest-api-cache' ),
				__( 'REST API Cache', 'wp-rest-api-cache' ),
				'manage_options',
				'rest-cache',
				array( __CLASS__, 'render_page' )
			);
		}

		/**
		 * Render the admin settings page.
		 */
		public static function render_page() {
			$notice  = null;
			$type    = 'updated';
			$message = esc_html__( 'Nothing to see here.', 'wp-rest-api-cache' );

			if ( isset( $_REQUEST['rest_cache_nonce'] ) && wp_verify_nonce( $_REQUEST['rest_cache_nonce'], 'rest_cache_options' ) ) {
				if ( isset( $_GET['rest_cache_empty'] ) && 1 === filter_var( $_GET['rest_cache_empty'], FILTER_VALIDATE_INT ) ) {
					if ( WP_REST_Cache::flush_all_cache() ) {
						$type    = 'updated';
						$message = esc_html__( 'The cache has been successfully cleared', 'wp-rest-api-cache' );
					} else {
						$type    = 'error';
						$message = esc_html__( 'The cache is already empty', 'wp-rest-api-cache' );
					}
				} elseif ( isset( $_POST['rest_cache_options'] ) && ! empty( $_POST['rest_cache_options'] ) ) {
					if ( self::_update_options( $_POST['rest_cache_options'] ) ) {
						$type    = 'updated';
						$message = esc_html__( 'The cache time has been updated', 'wp-rest-api-cache' );
					} else {
						$type    = 'error';
						$message = esc_html__( 'The cache time has not been updated', 'wp-rest-api-cache' );
					}
				}
				add_settings_error( 'wp-rest-api-notice', esc_attr( 'settings_updated' ), $message, $type );
			}

			$options   = self::get_options();
			$cache_url = self::_empty_cache_url();

			require_once dirname( __FILE__ ) . '/../views/html-options.php';
		}

		/**
		 * Update the option settings.
		 *
		 * @param array $options Incoming POST array.
		 * @return bool
		 */
		private static function _update_options( $options ) {
			$options = apply_filters( 'rest_cache_update_options', $options );

			return update_option( 'rest_cache_options', $options, 'yes' );
		}

		/**
		 * Get an option from our options array.
		 *
		 * @param null $key Option key value.
		 * @return mixed
		 */
		public static function get_options( $key = null ) {
			$options = apply_filters( 'rest_cache_get_options', get_option( 'rest_cache_options', self::$default ) );

			if ( is_string( $key ) && array_key_exists( $key, $options ) ) {
				return $options[ $key ];
			}

			return $options;
		}

		/**
		 * Build a clear cache URL query string.
		 *
		 * @return string
		 */
		private static function _empty_cache_url() {
			return wp_nonce_url(
				admin_url( 'options-general.php?page=rest-cache&rest_cache_empty=1' ),
				'rest_cache_options',
				'rest_cache_nonce'
			);
		}
	}

	WP_REST_Cache_Admin::init();
}
