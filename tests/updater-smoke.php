<?php
/**
 * Standalone smoke test for the reusable updater.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['sobelz_test_filters']    = array();
$GLOBALS['sobelz_test_actions']    = array();
$GLOBALS['sobelz_test_transients'] = array();
$GLOBALS['sobelz_test_requests']   = 0;
$GLOBALS['sobelz_test_manifest']   = array(
	'name'            => 'Example Plugin',
	'slug'            => 'example-plugin',
	'version'         => '2.0.0',
	'download_url'    => 'https://plugins.sobelz.ir/example-plugin/example-plugin.zip',
	'homepage'        => 'https://plugins.sobelz.ir/example-plugin',
	'author'          => 'Sobelz',
	'author_homepage' => 'https://sobelz.ir',
	'requires'        => '6.2',
	'requires_php'    => '7.4',
	'sections'        => array(
		'description' => '<p>Example description.</p>',
		'changelog'   => '<h4>2.0.0</h4>',
	),
);

class WP_Error {
	public $code;
	public $message;

	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function plugin_basename( $file ) {
	return 'example-plugin/' . basename( $file );
}

function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}

function trailingslashit( $value ) {
	return untrailingslashit( $value ) . '/';
}

function esc_url_raw( $value ) {
	return filter_var( $value, FILTER_VALIDATE_URL ) ? $value : '';
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( $value ) );
}

function wp_kses_post( $value ) {
	return $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['sobelz_test_filters'][ $hook ][] = array( $callback, $priority, $accepted_args );
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['sobelz_test_actions'][ $hook ][] = array( $callback, $priority, $accepted_args );
}

function apply_filters( $hook, $value ) {
	return $value;
}

function get_site_transient( $key ) {
	return isset( $GLOBALS['sobelz_test_transients'][ $key ] ) ? $GLOBALS['sobelz_test_transients'][ $key ] : false;
}

function set_site_transient( $key, $value, $expiration ) {
	$GLOBALS['sobelz_test_transients'][ $key ] = $value;
	return true;
}

function delete_site_transient( $key ) {
	unset( $GLOBALS['sobelz_test_transients'][ $key ] );
	return true;
}

function get_bloginfo( $field ) {
	return '6.2';
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function wp_remote_get( $url, $args ) {
	++$GLOBALS['sobelz_test_requests'];

	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( $GLOBALS['sobelz_test_manifest'] ),
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

function sobelz_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

require dirname( __DIR__ ) . '/includes/updater/class-sobelz-plugin-updater.php';

$updater = \Sobelz\PluginUpdater\V1\Updater::register(
	array(
		'plugin_file' => '/var/www/wp-content/plugins/example-plugin/example-plugin.php',
		'slug'        => 'example-plugin',
		'update_uri'  => 'https://plugins.sobelz.ir/example-plugin',
	)
);

sobelz_test_assert( ! is_wp_error( $updater ), 'Updater registration failed.' );
sobelz_test_assert( isset( $GLOBALS['sobelz_test_filters']['update_plugins_plugins.sobelz.ir'] ), 'Hostname update filter was not registered.' );

$update_callback = $GLOBALS['sobelz_test_filters']['update_plugins_plugins.sobelz.ir'][0][0];
$update          = call_user_func( $update_callback, false, array( 'Version' => '1.0.0' ), 'example-plugin/example-plugin.php', array( 'en_US' ) );

sobelz_test_assert( '2.0.0' === $update['version'], 'Update version was not mapped.' );
sobelz_test_assert( $GLOBALS['sobelz_test_manifest']['download_url'] === $update['package'], 'Package URL was not mapped.' );
sobelz_test_assert( 1 === $GLOBALS['sobelz_test_requests'], 'Manifest should have been requested exactly once.' );

$details_callback = $GLOBALS['sobelz_test_filters']['plugins_api'][0][0];
$details          = call_user_func( $details_callback, false, 'plugin_information', (object) array( 'slug' => 'example-plugin' ) );

sobelz_test_assert( is_object( $details ) && '2.0.0' === $details->version, 'Plugin information was not mapped.' );
sobelz_test_assert( 1 === $GLOBALS['sobelz_test_requests'], 'Request-level manifest cache was not reused.' );

$unchanged = call_user_func( $update_callback, 'existing', array(), 'another-plugin/plugin.php', array() );
sobelz_test_assert( 'existing' === $unchanged, 'An unrelated plugin response was changed.' );

$updater->clear_cache();
call_user_func( $update_callback, false, array( 'Version' => '1.0.0' ), 'example-plugin/example-plugin.php', array() );
sobelz_test_assert( 2 === $GLOBALS['sobelz_test_requests'], 'Clearing the cache did not force a new request.' );

fwrite( STDOUT, "Updater smoke test passed.\n" );
