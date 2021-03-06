WP REST API Cache
====

[![Latest release](https://img.shields.io/github/release/thefrosty/wp-rest-api-cache.svg)](https://github.com/thefrosty/wp-rest-api-cache/releases)

Enable caching for the WordPress REST API and the increase speed of your application.

- [Installation](#installation)
- [Actions](#actions)
- [How to use actions](#how-to-use-actions)
- [Filters](#filters)
- [How to use filters](#how-to-use-filters)

Installation
====
1. Copy the `wp-rest-api-cache` folder into your `wp-content/plugins` folder
2. Activate the `WP REST API Cache` plugin via the plugin admin page

Actions
====
| Action    | Argument(s) |
|-----------|-----------|
| wp_rest_cache_skipped | mixed **$result**<br>WP_REST_Server **$server**<br>WP_REST_Request **$request** |
| rest_cache_request_flush_cache | string **$message**<br>string **$type**<br>WP_User **$user** |

How to use actions
----

```PHP
add_action( 'wp_rest_cache_skipped', function( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
	// Do something here, like create a log entry using Wonolog.
	do_action( 'wonolog.log', new Log(
		sprintf( 'The `%s` REST route cache was skipped.', $request->get_route() ),
		\Monolog\Logger::NOTICE,
		\Inpsyde\Wonolog\Channels::DEBUG,
		[
			$result,
			$server,
			$request,
		]
	) );
}, 10, 3 );
```

```PHP
add_action( 'rest_cache_request_flush_cache', function( $message, $type, WP_User $user ) {
	// Do something here, like create a log entry using Wonolog.
	do_action( 'wonolog.log', new Log(
		sprintf( 'The `%s` user just flushed the object cache.', $user->user_login ),
		\Monolog\Monolog\Logger::NOTICE,
		\Inpsyde\Wonolog\Channels::DEBUG,
		[
			$message,
			$type,
			$user
		]
	) );
}, 10, 3 );
```

Filters
====
| Filter    | Argument(s) |
|-----------|-----------|
| rest_cache_headers | array **$headers**<br>string **$request_uri**<br>WP_REST_Server **$server**<br>WP_REST_Request **$request**<br>WP_REST_Response **$response (`rest_post_dispatch` only)** |
| rest_cache_skip | boolean **$skip** ( default: WP_DEBUG )<br>string **$request_uri**<br>WP_REST_Server **$server**<br>WP_REST_Request **$request** |
| rest_cache_key | string **$request_uri**<br>WP_REST_Server **$server**<br>WP_REST_Request **$request** |
| rest_cache_group | string **$cache_group** |
| rest_cache_timeout | int **$timeout**<br>int **$length**<br>int **$period** |
| rest_cache_update_options | array **$options** |
| rest_cache_get_options | array **$options** |
| rest_cache_show_admin | boolean **$show** |
| rest_cache_show_admin_menu | boolean **$show** |
| rest_cache_show_admin_bar_menu | boolean **$show** |
| allowed_rest_cache_status | array **$status** HTTP Header statuses (defaults to `array( 200 )` |
| rest_cache_control_no_cache_value | array **$cache_control** Cache-Control header to **not** cache request. (defaults to `array( 'private', 'no-cache', 'no-store', 'must-revalidate' )` |

How to use filters
----
- **sending headers**

```PHP
add_filter( 'rest_cache_headers', function( $headers ) {
	$headers['Cache-Control'] = 'public, max-age=3600';
	
	return $headers;
} );
```

- **changing the cache timeout**

```PHP
add_filter( 'rest_cache_timeout', function() {
	// https://codex.wordpress.org/Transients_API#Using_Time_Constants
	return 15 * DAY_IN_SECONDS;
} );
```
or
```PHP
add_filter( 'rest_cache_get_options', function( $options ) {
	if ( ! isset( $options['timeout'] ) ) {
		$options['timeout'] = array();
	}

	// https://codex.wordpress.org/Transients_API#Using_Time_Constants
	$options['timeout']['length'] = 15;
	$options['timeout']['period'] = DAY_IN_SECONDS;
	
	return $options;
} );
```

- **skipping cache**

```PHP
add_filter( 'rest_cache_skip', function( $skip, $request_uri ) {
	if ( ! $skip && false !== stripos( 'wp-json/acf/v2', $request_uri ) ) {
		return true;
	}

	return $skip;
}, 10, 2 );
```

If `rest_cache_skip` is true, this action is called: `wp_rest_cache_skipped`.

```PHP
add_action( 'wp_rest_cache_skipped', function( $result, WP_REST_Server $server, WP_REST_Request $request ) {
	// Do something here
}, 10, 3 );
```

- **deleting cache**

**Soft delete**:
Append `rest_cache_delete` to your query param; `&rest_cache_delete=1`.  
_soft delete will delete the cache after the current request completes (on WordPress shutdown)._ 

**Hard delete**: Append `rest_cache_delete` && `rest_force_delete` to your query param; `&rest_cache_delete=1&rest_force_delete=1`.  
_hard delete will delete the cache before the request, forcing it to repopulate._
- **show / hide admin links**

![WP REST API Cache](http://airesgoncalves.com.br/screenshot/wp-rest-api-cache/readme/filter-admin-show.gif)

- **empty ALL cache on post-save** _this is not ideal_

You can use WordPress' default filter "save_post" if you would like to empty **ALL** the cache on save of a post,
page or custom post type.

```PHP
add_action( 'save_post', function( $post_id ) {
  if ( class_exists( 'WP_REST_Cache' ) ) {
    WP_REST_Cache::flush_all_cache();
  }
} );
```

- **clear endpoint cache on transition**

You can use WordPress' default filter "save_post" if you would like to empty **ALL** the cache on save of a post,
page or custom post type. THIS IS A WORK IN PROGRESS.

```PHP
add_action( 'transition_post_status', function( $new_status, $old_status, WP_Post $post ) {
  if ( 'publish' === $new_status && 'publish' !== $old_status ) {
    if ( class_exists( 'WP_REST_Cache' ) ) {
        //$url = get_permalink( $post->ID );
        //$key = WP_REST_Cache::get_cache_key();
        //WP_REST_Cache::delete_cache_by_key( $key );
    }
  }
} );
```
