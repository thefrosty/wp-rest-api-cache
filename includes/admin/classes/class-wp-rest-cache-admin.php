<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_REST_Cache_Admin' ) ) {

	/**
	 * Class WP_REST_Cache_Admin
	 */
	class WP_REST_Cache_Admin {

		const ACTION = 'rest_api_cache_flush';
		const NOTICE = 'rest_flush';

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
				} else {
					add_action( 'admin_action_' . self::ACTION, array( __CLASS__, 'admin_action' ) );
					add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
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
				'href'   => esc_url( self::get_empty_cache_url() ),
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
		 * Helper to check the request action.
		 */
		public static function admin_action() {
			self::request_callback();

			$url = wp_nonce_url(
				add_query_arg( array( self::NOTICE => 1 ), wp_get_referer() ),
				'rest_cache_redirect',
				'rest_cache_nonce'
			);
			wp_safe_redirect( $url );
			exit;
		}

		/**
		 * Maybe add an admin notice.
		 */
		public static function admin_notices() {
			if ( ! empty( $_REQUEST['rest_cache_nonce'] ) && wp_verify_nonce( $_REQUEST['rest_cache_nonce'], 'rest_cache_redirect' ) ) {
				if ( ! empty( $_GET[ self::NOTICE ] ) && 1 === filter_var( $_GET[ self::NOTICE ], FILTER_VALIDATE_INT ) ) {
					$message = esc_html__( 'The cache has been successfully cleared', 'wp-rest-api-cache' );
					echo "<div class='notice updated is-dismissible'><p>{$message}</p></div>"; // PHPCS: XSS OK.
				}
			}
		}

		/**
		 * Render the admin settings page.
		 */
		public static function render_page() {
			self::request_callback();

			$cache_url = self::get_empty_cache_url();
			$options   = self::get_options();

			require_once dirname( __FILE__ ) . '/../views/html-options.php';
		}

		/**
		 * Helper to check the request action.
		 */
		private static function request_callback() {
			$type    = 'warning';
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
					/**
					 * Action hook when the cache is flushed.
					 *
					 * @param string $message The message set.
					 * @param string $type The settings error code.
					 * @param WP_User The current user.
					 */
					do_action( 'rest_cache_request_flush_cache', $message, $type, wp_get_current_user() );
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
		 * @param null|string $key Option key value.
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
		private static function get_empty_cache_url() {
			if ( apply_filters( 'rest_cache_show_admin_menu', true ) ) {
				return wp_nonce_url(
					admin_url( 'options-general.php?page=rest-cache&rest_cache_empty=1' ),
					'rest_cache_options',
					'rest_cache_nonce'
				);
			} else {
				return wp_nonce_url(
					admin_url( 'admin.php?action=' . self::ACTION . '&rest_cache_empty=1' ),
					'rest_cache_options',
					'rest_cache_nonce'
				);
			}
		}
	}

	WP_REST_Cache_Admin::init();
}
