<?php
/**
 * Plugin Name: WP REST API Cache
 * Description: Enable caching for WordPress REST API and increase speed of your application
 * Author: Aires Gonçalves
 * Author URI: http://github.com/airesvsg
 * Version: 2.0.0
 * Plugin URI: https://github.com/airesvsg/wp-rest-api-cache
 * License: GPL2+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_REST_Cache' ) ) {

	/**
	 * Class WP_REST_Cache
	 */
	class WP_REST_Cache {

		const CACHE_HEADER = 'X-WP-API-Cache';
		const CACHE_GROUP  = 'rest_api';
		const VERSION      = '2.0.0';

		/**
		 * Initiate the class.
		 */
		public static function init() {
			self::includes();
			self::hooks();
		}

		/**
		 * Include class files.
		 */
		private static function includes() {
			require_once dirname( __FILE__ ) . '/includes/admin/classes/class-wp-rest-cache-admin.php';
		}

		/**
		 * Add class hooks.
		 */
		private static function hooks() {
			add_filter( 'rest_pre_dispatch', array( __CLASS__, 'pre_dispatch' ), 10, 3 );
		}

		/**
		 * Filters the pre-calculated result of a REST dispatch request.
		 *
		 * @param mixed           $result Response to replace the requested version with. Can be anything
		 *                                 a normal endpoint can return, or null to not hijack the request.
		 * @param WP_REST_Server  $server Server instance.
		 * @param WP_REST_Request $request Request used to generate the response.
		 *
		 * @return mixed Response
		 */
		public static function pre_dispatch( $result, WP_REST_Server $server, WP_REST_Request $request ) {
			$request_uri = filter_input( 'INPUT_SERVER', 'REQUEST_URI', FILTER_SANITIZE_URL );

			if ( method_exists( $server, 'send_headers' ) ) {
				$headers = apply_filters( 'rest_cache_headers', array(), $request_uri, $server, $request );
				if ( ! empty( $headers ) ) {
					$server->send_headers( $headers );
				}
			}

			if ( true === $request->get_param( 'refresh-cache' ) ) {
				$server->send_header( self::CACHE_HEADER, 'refreshed' );
				$server->send_header(
					self::CACHE_HEADER,
					esc_attr_x(
						'refreshed',
						'When the wp-api cache is skipped. This is the header value.',
						'wp-rest-api-cache'
					)
				);

				return $result;
			}

			$skip = apply_filters( 'rest_cache_skip', WP_DEBUG, $request_uri, $server, $request );
			if ( $skip ) {
				$server->send_header(
					self::CACHE_HEADER,
					esc_attr_x(
						'skipped',
						'When rest_cache is skipped. This is the header value.',
						'wp-rest-api-cache'
					)
				);
				/**
				 * Action hook when the cache is skipped.
				 *
				 * @param mixed           $result Response to replace the requested version with. Can be anything
				 *                                 a normal endpoint can return, or null to not hijack the request.
				 * @param WP_REST_Server  $server Server instance.
				 * @param WP_REST_Request $request Request used to generate the response.
				 */
				do_action( 'wp_rest_cache_skipped', $result, $server, $request );
			} else {
				$key   = self::get_cache_key( $request, $server, $request );
				$group = self::get_cache_group();
				if ( false === ( $result = wp_cache_get( $key, $group ) ) ) {
					$request->set_param( 'refresh-cache', true );
					$result  = $server->dispatch( $request );
					$timeout = WP_REST_Cache_Admin::get_options( 'timeout' );
					$timeout = apply_filters( 'rest_cache_timeout', $timeout['length'] * $timeout['period'], $timeout['length'], $timeout['period'] );
					
					wp_cache_set( $key, $result, $group, $timeout );
				}
			}

			return $result;
		}

		/**
		 * Get the cache key value.
		 *
		 * @param string               $request_uri The REQUEST_URI
		 * @param WP_REST_Server|null  $server An instance of WP_REST_Server
		 * @param WP_REST_Request|null $request An instance of WP_REST_Request
		 * @param string|null          $url Full URL to pass to WP_REST_Request
		 * @return string
		 */
		public static function get_cache_key( $request_uri, WP_REST_Server $server = null, WP_REST_Request $request = null, $url = null ) {
			if ( ! ( $server instanceof WP_REST_Server ) ) {
				$server = rest_get_server();
			}

			if ( ! ( $request instanceof WP_REST_Request ) ) {
				if ( is_string( $url ) ) {
					$request = WP_REST_Request::from_url( $url );
				} else {
					$request = new WP_REST_Request();
				}
			}

			return filter_var(
				apply_filters( 'rest_cache_key', $request_uri, $server, $request ),
				FILTER_SANITIZE_STRING
			);
		}

		/**
		 * Get the cache group value.
		 *
		 * @return string
		 */
		public static function get_cache_group() {
			return filter_var( apply_filters( 'rest_cache_group', self::CACHE_GROUP ), FILTER_SANITIZE_STRING );
		}

		/**
		 * Empty all cache.
		 *
		 * @deprecated 2.0
		 */
		public static function empty_cache() {
			_deprecated_function( __FUNCTION__, '2.0', array( __CLASS__, 'flush_all_cache' ) );

			return self::flush_all_cache();
		}

		/**
		 * Empty all cache.
		 *
		 * @uses wp_cache_flush()
		 */
		public static function flush_all_cache() {
			return wp_cache_flush();
		}

		/**
		 * Empty all cache.
		 *
		 * @uses wp_cache_delete()
		 * @param string $key The key under which to store the value.
		 * @return bool Returns TRUE on success or FALSE on failure.
		 */
		public static function delete_cache_by_key( $key ) {
			return wp_cache_delete( $key, self::get_cache_group() );
		}
	}

	add_action( 'init', array( 'WP_REST_Cache', 'init' ) );

	register_uninstall_hook( __FILE__, array( 'WP_REST_Cache', 'empty_cache' ) );
}
