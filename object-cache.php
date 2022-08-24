<?php

/*
wp_using_ext_object_cache( true );
wp_cache_add_global_groups
wp_cache_add_non_persistent_groups
$this->start_time  = isset($_SERVER['REQUEST_TIME']) ? $_SERVER['REQUEST_TIME'] : time();
*/

// wp_cache_setup();
function wp_cache_setup() {
	global $wpdb;
	$sql = "
	  CREATE TABLE {$wpdb->prefix}cache (
			`group` varchar(200) NOT NULL,
			`key` varchar(200) NOT NULL,
			value longtext NOT NULL default '',
			expires_gmt datetime,
			created_gmt datetime DEFAULT CURRENT_TIMESTAMP,
			hits bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (`group`(31), `key`(160))
		)
		{$wpdb->get_charset_collate()}";
	require_once ABSPATH . 'wp-includes/l10n.php';
	wp_cache_init();
	require_once ABSPATH . 'wp-includes/theme.php'; // is_customize_preview()
	require_once ABSPATH . 'wp-includes/class-wp-walker.php'; // Class 'Walker' not found in /Users/sun/sites/nest/haur/htdocs/wp-admin/includes/class-walker-category-checklist.php:19
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);
}

function wp_cache_init() {
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add( $key, $data, $group, (int) $expire );
}

function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add_multiple( $data, $group, $expire );
}

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->replace( $key, $data, $group, (int) $expire );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set( $key, $data, $group, (int) $expire );
}

function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set_multiple( $data, $group, $expire );
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	global $wp_object_cache;
	return $wp_object_cache->get( $key, $group, $force, $found );
}

function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
	global $wp_object_cache;
	return $wp_object_cache->get_multiple( $keys, $group, $force );
}

function wp_cache_delete( $key, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->delete( $key, $group );
}

function wp_cache_delete_multiple( array $keys, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->delete_multiple( $keys, $group );
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->incr( $key, $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->decr( $key, $offset, $group );
}

function wp_cache_flush() {
	global $wp_object_cache;
	return $wp_object_cache->flush();
}

function wp_cache_flush_runtime() {
	return wp_cache_flush();
}

function wp_cache_close() {
	return true;
}

function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
	// Default cache doesn't persist so nothing to do here.
}

function wp_cache_switch_to_blog( $blog_id ) {
	global $wp_object_cache;
	$wp_object_cache->switch_to_blog( $blog_id );
}

function wp_cache_reset() {
	_deprecated_function( __FUNCTION__, '3.5.0', 'wp_cache_switch_to_blog()' );
	global $wp_object_cache;
	$wp_object_cache->reset();
}

/**
 * Core class that implements an object cache.
 *
 * The WordPress Object Cache is used to save on trips to the database. The
 * Object Cache stores all of the cache data to memory and makes the cache
 * contents available by using a key, which is used to name and later retrieve
 * the cache contents.
 *
 * The Object Cache can be replaced by other caching mechanisms by placing files
 * in the wp-content folder which is looked at in wp-settings. If that file
 * exists, then this file will not be included.
 *
 * @since 2.0.0
 */
class WP_Object_Cache {

	/**
	 * Holds the cached objects.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private $cache = array();

	/**
	 * The amount of times the cache data was already stored in the cache.
	 *
	 * @since 2.5.0
	 * @var int
	 */
	public $cache_hits = 0;

	/**
	 * Amount of times the cache did not have the request in cache.
	 *
	 * @since 2.0.0
	 * @var int
	 */
	public $cache_misses = 0;

	/**
	 * List of global cache groups.
	 *
	 * @since 3.0.0
	 * @var array
	 */
	protected $global_groups = array();

	/**
	 * The blog prefix to prepend to keys in non-global groups.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	private $blog_prefix;

	/**
	 * Holds the value of is_multisite().
	 *
	 * @since 3.5.0
	 * @var bool
	 */
	private $multisite;

	/**
	 * Sets up object properties; PHP 5 style constructor.
	 *
	 * @since 2.0.8
	 */
	public function __construct() {
		$this->multisite   = is_multisite();
		$this->blog_prefix = $this->multisite ? get_current_blog_id() . ':' : '';
	}

	public function __get( $name ) {
		return $this->$name;
	}

	public function __set( $name, $value ) {
		return $this->$name = $value;
	}

	public function __isset( $name ) {
		return isset( $this->$name );
	}

	public function __unset( $name ) {
		unset( $this->$name );
	}

	/**
	 * Serves as a utility function to determine whether a key exists in the cache.
	 *
	 * @since 3.4.0
	 *
	 * @param int|string $key   Cache key to check for existence.
	 * @param string     $group Cache group for the key existence check.
	 * @return bool Whether the key exists in the cache for the given group.
	 */
	protected function _exists( $key, $group ) {
		global $wpdb;

		if ( $this->multisite && ! isset($this->global_groups[ $group ])) {
			$key = $this->blog_prefix . $key;
		}
		if (!isset($this->cache[$group][$key])) {
			$result = $wpdb->get_var($wpdb->prepare("SELECT value FROM {$wpdb->prefix}cache WHERE `group` = %s AND `key` = %s", $group, $key));
			if (class_exists('WP_CLI', FALSE)) var_dump(__FUNCTION__ . ":$group:$key:" . gettype($result));
			if (isset($result)) {
				$this->cache[$group][$key]['value'] = unserialize($result);
			}
		}
		return isset($this->cache[$group][$key]);
	}

	/**
	 * Adds data to the cache if it doesn't already exist.
	 *
	 * @since 2.0.0
	 *
	 * @uses WP_Object_Cache::_exists() Checks to see if the cache already has data.
	 * @uses WP_Object_Cache::set()     Sets the data after the checking the cache
	 *                                  contents existence.
	 *
	 * @param int|string $key    What to call the contents in the cache.
	 * @param mixed      $data   The contents to store in the cache.
	 * @param string     $group  Optional. Where to group the cache contents. Default 'default'.
	 * @param int        $expire Optional. When to expire the cache contents, in seconds.
	 *                           Default 0 (no expiration).
	 * @return bool True on success, false if cache key and group already exist.
	 */
	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		if ( wp_suspend_cache_addition() ) {
			return false;
		}

		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( $this->_exists( $key, $group ) ) {
			return false;
		}
		return $this->set( $key, $data, $group, (int) $expire );
	}

	/**
	 * Adds multiple values to the cache in one call.
	 *
	 * @since 6.0.0
	 *
	 * @param array  $data   Array of keys and values to be added.
	 * @param string $group  Optional. Where the cache contents are grouped. Default empty.
	 * @param int    $expire Optional. When to expire the cache contents, in seconds.
	 *                       Default 0 (no expiration).
	 * @return bool[] Array of return values, grouped by key. Each value is either
	 *                true on success, or false if cache key and group already exist.
	 */
	public function add_multiple( array $data, $group = '', $expire = 0 ) {
		$values = array();
		foreach ( $data as $key => $value ) {
			$values[ $key ] = $this->add( $key, $value, $group, $expire );
		}
		return $values;
	}

	/**
	 * Replaces the contents in the cache, if contents already exist.
	 *
	 * @since 2.0.0
	 *
	 * @see WP_Object_Cache::set()
	 *
	 * @param int|string $key    What to call the contents in the cache.
	 * @param mixed      $data   The contents to store in the cache.
	 * @param string     $group  Optional. Where to group the cache contents. Default 'default'.
	 * @param int        $expire Optional. When to expire the cache contents, in seconds.
	 *                           Default 0 (no expiration).
	 * @return bool True if contents were replaced, false if original value does not exist.
	 */
	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( ! $this->_exists( $key, $group ) ) {
			return false;
		}

		return $this->set( $key, $data, $group, (int) $expire );
	}

	/**
	 * Sets the data contents into the cache.
	 *
	 * The cache contents are grouped by the $group parameter followed by the
	 * $key. This allows for duplicate IDs in unique groups. Therefore, naming of
	 * the group should be used with care and should follow normal function
	 * naming guidelines outside of core WordPress usage.
	 *
	 * The $expire parameter is not used, because the cache will automatically
	 * expire for each time a page is accessed and PHP finishes. The method is
	 * more for cache plugins which use files.
	 *
	 * @since 2.0.0
	 *
	 * @param int|string $key    What to call the contents in the cache.
	 * @param mixed      $data   The contents to store in the cache.
	 * @param string     $group  Optional. Where to group the cache contents. Default 'default'.
	 * @param int        $expire Optional. Not used.
	 * @return true Always returns true.
	 */
	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		global $wpdb;

		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( is_object( $data ) ) {
			$data = clone $data;
		}

		if ( $this->multisite && ! isset($this->global_groups[ $group ])) {
			$key = $this->blog_prefix . $key;
		}
		$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}cache (`group`, `key`, value) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE value = VALUES(value)", $group, $key, serialize($data)));
		if (class_exists('WP_CLI', FALSE)) var_dump(__FUNCTION__ . ":$group:$key:" . gettype($data));

		$this->cache[$group][$key]['value'] = $data;
		return true;
	}

	/**
	 * Sets multiple values to the cache in one call.
	 *
	 * @since 6.0.0
	 *
	 * @param array  $data   Array of key and value to be set.
	 * @param string $group  Optional. Where the cache contents are grouped. Default empty.
	 * @param int    $expire Optional. When to expire the cache contents, in seconds.
	 *                       Default 0 (no expiration).
	 * @return bool[] Array of return values, grouped by key. Each value is always true.
	 */
	public function set_multiple( array $data, $group = '', $expire = 0 ) {
		$values = array();
		foreach ( $data as $key => $value ) {
			$values[ $key ] = $this->set( $key, $value, $group, $expire );
		}
		return $values;
	}

	/**
	 * Retrieves the cache contents, if it exists.
	 *
	 * The contents will be first attempted to be retrieved by searching by the
	 * key in the cache group. If the cache is hit (success) then the contents
	 * are returned.
	 *
	 * On failure, the number of cache misses will be incremented.
	 *
	 * @since 2.0.0
	 *
	 * @param int|string $key   The key under which the cache contents are stored.
	 * @param string     $group Optional. Where the cache contents are grouped. Default 'default'.
	 * @param bool       $force Optional. Unused. Whether to force an update of the local cache
	 *                          from the persistent cache. Default false.
	 * @param bool       $found Optional. Whether the key was found in the cache (passed by reference).
	 *                          Disambiguates a return of false, a storable value. Default null.
	 * @return mixed|false The cache contents on success, false on failure to retrieve contents.
	 */
	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( $this->_exists( $key, $group ) ) {
			$found             = true;
			$this->cache_hits += 1;

			if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
				$key = $this->blog_prefix . $key;
			}
			if (is_object($this->cache[ $group ][ $key ]['value'])) {
				return clone $this->cache[ $group ][ $key ]['value'];
			}
			else {
				return $this->cache[ $group ][ $key ]['value'];
			}
		}

		$found               = false;
		$this->cache_misses += 1;
		return false;
	}

	/**
	 * Retrieves multiple values from the cache in one call.
	 *
	 * @since 5.5.0
	 *
	 * @param array  $keys  Array of keys under which the cache contents are stored.
	 * @param string $group Optional. Where the cache contents are grouped. Default 'default'.
	 * @param bool   $force Optional. Whether to force an update of the local cache
	 *                      from the persistent cache. Default false.
	 * @return array Array of return values, grouped by key. Each value is either
	 *               the cache contents on success, or false on failure.
	 */
	public function get_multiple( $keys, $group = 'default', $force = false ) {
		$values = array();
		foreach ( $keys as $key ) {
			$values[ $key ] = $this->get( $key, $group, $force );
		}
		return $values;
	}

	/**
	 * Removes the contents of the cache key in the group.
	 *
	 * If the cache key does not exist in the group, then nothing will happen.
	 *
	 * @since 2.0.0
	 *
	 * @param int|string $key        What the contents in the cache are called.
	 * @param string     $group      Optional. Where the cache contents are grouped. Default 'default'.
	 * @param bool       $deprecated Optional. Unused. Default false.
	 * @return bool True on success, false if the contents were not deleted.
	 */
	public function delete( $key, $group = 'default', $deprecated = false ) {
		global $wpdb;

		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
			$key = $this->blog_prefix . $key;
		}

		if ( ! $this->_exists( $key, $group ) ) {
			return false;
		}

		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cache WHERE `group` = %s AND `key` = %s", $group, $key));
		if (class_exists('WP_CLI', FALSE)) var_dump(__FUNCTION__ . ":$group:$key");
		unset( $this->cache[ $group ][ $key ] );
		return true;
	}

	/**
	 * Deletes multiple values from the cache in one call.
	 *
	 * @since 6.0.0
	 *
	 * @param array  $keys  Array of keys to be deleted.
	 * @param string $group Optional. Where the cache contents are grouped. Default empty.
	 * @return bool[] Array of return values, grouped by key. Each value is either
	 *                true on success, or false if the contents were not deleted.
	 */
	public function delete_multiple( array $keys, $group = '' ) {
		$values = array();

		foreach ( $keys as $key ) {
			$values[ $key ] = $this->delete( $key, $group );
		}

		return $values;
	}

	/**
	 * Increments numeric cache item's value.
	 *
	 * @since 3.3.0
	 *
	 * @param int|string $key    The cache key to increment.
	 * @param int        $offset Optional. The amount by which to increment the item's value.
	 *                           Default 1.
	 * @param string     $group  Optional. The group the key is in. Default 'default'.
	 * @return int|false The item's new value on success, false on failure.
	 */
	public function incr( $key, $offset = 1, $group = 'default' ) {
		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( ! $this->_exists( $key, $group ) ) {
			return false;
		}
		$value = $this->get($key, $group);
		if ( ! is_numeric( $value ) ) {
			$value = 0;
		}
		$value += (int) $offset;
		if ( $value < 0 ) {
			$value = 0;
		}
		$this->set($key, $value, $group);

		return $value;
	}

	/**
	 * Decrements numeric cache item's value.
	 *
	 * @since 3.3.0
	 *
	 * @param int|string $key    The cache key to decrement.
	 * @param int        $offset Optional. The amount by which to decrement the item's value.
	 *                           Default 1.
	 * @param string     $group  Optional. The group the key is in. Default 'default'.
	 * @return int|false The item's new value on success, false on failure.
	 */
	public function decr( $key, $offset = 1, $group = 'default' ) {
		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( ! $this->_exists( $key, $group ) ) {
			return false;
		}
		$value = $this->get($key, $group);
		if ( ! is_numeric( $value ) ) {
			$value = 0;
		}
		$value -= (int) $offset;
		if ( $value < 0 ) {
			$value = 0;
		}
		$this->set($key, $value, $group);

		return $value;
	}

	/**
	 * Clears the object cache of all data.
	 *
	 * @since 2.0.0
	 *
	 * @return true Always returns true.
	 */
	public function flush() {
		$this->cache = array();

		return true;
	}

	/**
	 * Sets the list of global cache groups.
	 *
	 * @since 3.0.0
	 *
	 * @param string|string[] $groups List of groups that are global.
	 */
	public function add_global_groups( $groups ) {
		$groups = (array) $groups;

		$groups              = array_fill_keys( $groups, true );
		$this->global_groups = array_merge( $this->global_groups, $groups );
	}

	/**
	 * Switches the internal blog ID.
	 *
	 * This changes the blog ID used to create keys in blog specific groups.
	 *
	 * @since 3.5.0
	 *
	 * @param int $blog_id Blog ID.
	 */
	public function switch_to_blog( $blog_id ) {
		$blog_id           = (int) $blog_id;
		$this->blog_prefix = $this->multisite ? $blog_id . ':' : '';
	}

	/**
	 * Resets cache keys.
	 *
	 * @since 3.0.0
	 *
	 * @deprecated 3.5.0 Use WP_Object_Cache::switch_to_blog()
	 * @see switch_to_blog()
	 */
	public function reset() {
		_deprecated_function( __FUNCTION__, '3.5.0', 'WP_Object_Cache::switch_to_blog()' );

		// Clear out non-global caches since the blog ID has changed.
		foreach ( array_keys( $this->cache ) as $group ) {
			if ( ! isset( $this->global_groups[ $group ] ) ) {
				unset( $this->cache[ $group ] );
			}
		}
	}

	/**
	 * Echoes the stats of the caching.
	 *
	 * Gives the cache hits, and cache misses. Also prints every cached group,
	 * key and the data.
	 *
	 * @since 2.0.0
	 */
	public function stats() {
		echo '<p>';
		echo "<strong>Cache Hits:</strong> {$this->cache_hits}<br />";
		echo "<strong>Cache Misses:</strong> {$this->cache_misses}<br />";
		echo '</p>';
		echo '<ul>';
		foreach ( $this->cache as $group => $cache ) {
			echo '<li><strong>Group:</strong> ' . esc_html( $group ) . ' - ( ' . number_format( strlen( serialize( $cache ) ) / KB_IN_BYTES, 2 ) . 'k )</li>';
		}
		echo '</ul>';
	}
}
