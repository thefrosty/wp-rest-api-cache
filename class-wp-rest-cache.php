<?php
/**
 * Plugin Name: WP REST API Cache
 * Description: Enable caching for WordPress REST API and increase speed of your application
 * Author: Aires Gonçalves
 * Author URI: http://github.com/airesvsg
 * Version: 2.0.3
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

		const CACHE_DELETE        = 'rest_cache_delete';
		const CACHE_FORCE_DELETE  = 'rest_force_delete';
		const CACHE_GROUP         = 'rest_api';
		const CACHE_HEADER        = 'X-WP-API-Cache';
		const CACHE_HEADER_DELETE = 'X-WP-API-Cache-Delete';
		const CACHE_REFRESH       = 'rest_cache_refresh';

		const VERSION = '2.0.3';

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
			add_filter( 'rest_post_dispatch', array( __CLASS__, 'post_dispatch' ), 10, 3 );
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
			$request_uri = self::get_request_uri();
			$key         = self::get_cache_key( $request_uri, $server, $request );
			$group       = self::get_cache_group();

			self::maybe_send_headers( $request_uri, $server, $request );

			// Delete the cache.
			$delete = filter_var( $request->get_param( self::CACHE_DELETE ), FILTER_VALIDATE_BOOLEAN );
			$force  = filter_var( $request->get_param( self::CACHE_FORCE_DELETE ), FILTER_VALIDATE_BOOLEAN );
			if ( $delete ) {
				if ( $force ) {
					if ( self::delete_cache_by_key( $key ) ) {
						$server->send_header( self::CACHE_HEADER_DELETE, 'true' );
						$request->set_param( self::CACHE_DELETE, false );

						return self::get_cached_result( $server, $request, $key, $group );
					}
				} else {
					$server->send_header( self::CACHE_HEADER_DELETE, 'soft' );
					add_action( 'shutdown', function() use ( $key ) {
						call_user_func( array( __CLASS__, 'delete_cache_by_key' ), $key );
					} );
				}
			}

			// Cache is refreshed (cached below).
			$refresh = filter_var( $request->get_param( self::CACHE_REFRESH ), FILTER_VALIDATE_BOOLEAN );
			if ( $refresh ) {
				$server->send_header(
					self::CACHE_HEADER,
					esc_attr_x(
						'refreshed',
						'When the wp-api cache is skipped. This is the header value.',
						'wp-rest-api-cache'
					)
				);

				return $result;
			} else {
				$server->send_header(
					self::CACHE_HEADER,
					esc_attr_x(
						'cached',
						'When rest_cache is cached. This is the header value.',
						'wp-rest-api-cache'
					)
				);
			}

			$skip = filter_var(
				apply_filters( 'rest_cache_skip', WP_DEBUG, $request_uri, $server, $request ),
				FILTER_VALIDATE_BOOLEAN
			);
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

				return $result;
			}

			return self::get_cached_result( $server, $request, $key, $group );
		}

		/**
		 * Filters the post-calculated result of a REST dispatch request.
		 *
		 * @todo Implement cache on this method over 'pre'.
		 *
		 * @param WP_REST_Response $response
		 * @param WP_REST_Server   $server
		 * @param WP_REST_Request  $request
		 * @return WP_HTTP_Response
		 */
		public static function post_dispatch( WP_REST_Response $response, WP_REST_Server $server, WP_REST_Request $request ) {
			$request_uri = self::get_request_uri();
			$allowed_cache_status = apply_filters( 'allowed_rest_cache_status', array( WP_Http::OK ) );
			if ( ! in_array( $response->get_status(), $allowed_cache_status, true ) ) {
				$key = self::get_cache_key( $request_uri, $server, $request );
				$server->send_header(
					self::CACHE_HEADER,
					esc_attr_x(
						'skipped',
						'When rest_cache is skipped. This is the header value.',
						'wp-rest-api-cache'
					)
				);
				add_action( 'shutdown', function() use ( $key ) {
					call_user_func( array( __CLASS__, 'delete_cache_by_key' ), $key );
				} );
			}
			self::maybe_send_headers( $request_uri, $server, $request, $response );

			return $response;
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

			// Be sure to remove our added cache refresh & cache delete queries.
			$uri = remove_query_arg( array( self::CACHE_DELETE, self::CACHE_REFRESH ), $request_uri );

			return filter_var(
				apply_filters( 'rest_cache_key', $uri, $server, $request ),
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

		/**
		 * Return the current REQUEST_URI from the global server variable.
		 *
		 * @return string
		 */
		private static function get_request_uri() {
			return filter_var( $_SERVER['REQUEST_URI'], FILTER_SANITIZE_URL );
		}

		/**
		 * Send server headers if we have headers to send.
		 *
		 * @param string                $request_uri
		 * @param WP_REST_Server        $server
		 * @param WP_REST_Request       $request
		 * @param null|WP_REST_Response $response
		 */
		private static function maybe_send_headers( $request_uri, WP_REST_Server $server, WP_REST_Request $request, WP_REST_Response $response = null ) {
			$headers = apply_filters( 'rest_cache_headers', array(), $request_uri, $server, $request, $response );
			if ( ! empty( $headers ) ) {
				$server->send_headers( $headers );
			}
		}

		/**
		 * Get the result from cache.
		 *
		 * @param WP_REST_Server  $server
		 * @param WP_REST_Request $request
		 * @param string          $key
		 * @param string          $group
		 * @return bool|mixed|WP_REST_Response
		 */
		private static function get_cached_result( WP_REST_Server $server, WP_REST_Request $request, $key, $group ) {
			$result = wp_cache_get( $key, $group );
			if ( false === $result ) {
				$result  = self::dispatch_request( $server, $request );
				$timeout = WP_REST_Cache_Admin::get_options( 'timeout' );
				$timeout = apply_filters( 'rest_cache_timeout', $timeout['length'] * $timeout['period'], $timeout['length'], $timeout['period'] );

				wp_cache_set( $key, $result, $group, $timeout );

				return $result;
			}

			return $result;
		}

		/**
		 * Dispatch the REST request.
		 *
		 * @param WP_REST_Server  $server
		 * @param WP_REST_Request $request
		 * @return WP_REST_Response
		 */
		private static function dispatch_request( WP_REST_Server $server, WP_REST_Request $request ) {
			$request->set_param( self::CACHE_REFRESH, true );

			return $server->dispatch( $request );
		}
	}

	add_action( 'init', array( 'WP_REST_Cache', 'init' ) );

	register_uninstall_hook( __FILE__, array( 'WP_REST_Cache', 'empty_cache' ) );
}
