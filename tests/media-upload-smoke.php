<?php
/**
 * Standalone smoke test for PDF and JPEG uploads to Jetlinez media storage.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'JLWI_OPTION', 'jlwi_settings' );
define( 'JLWI_TEXT_DOMAIN', 'jetlinez-woocommerce-invoice' );
define( 'JLWI_VERSION', '1.7.0-test' );
define( 'MB_IN_BYTES', 1048576 );

$GLOBALS['jlwi_media_requests'] = array();
$GLOBALS['jlwi_media_settings'] = array();

function get_option( $key, $default = false ) {
	return JLWI_OPTION === $key ? $GLOBALS['jlwi_media_settings'] : $default;
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, is_array( $args ) ? $args : array() );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_file_name( $value ) {
	return preg_replace( '/[^A-Za-z0-9._-]/', '-', basename( (string) $value ) );
}

function wp_basename( $path ) {
	return basename( $path );
}

function wp_generate_uuid4() {
	return '12345678-1234-4234-8234-123456789abc';
}

function wp_parse_url( $url ) {
	return parse_url( $url );
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function apply_filters( $hook, $value ) {
	unset( $hook );
	return $value;
}

function __( $text ) {
	return $text;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function wp_strip_all_tags( $value ) {
	return strip_tags( $value );
}

function wp_remote_request( $url, $args ) {
	$GLOBALS['jlwi_media_requests'][] = array( 'url' => $url, 'args' => $args );
	return array(
		'response' => array( 'code' => 200 ),
		'headers'  => array(),
		'body'     => '{"success":true,"data":{"id":"media-id"}}',
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function wp_remote_retrieve_header( $response, $header ) {
	$header = strtolower( (string) $header );
	return isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
}

function jlwi_media_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code, $message, $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

require dirname( __DIR__ ) . '/includes/class-jlwi-settings.php';
require dirname( __DIR__ ) . '/includes/class-jlwi-api-client.php';

$GLOBALS['jlwi_media_settings'] = array_merge(
	JLWI_Settings::defaults(),
	array(
		'api_base_url' => 'https://my.jetlinez.com/api/v1',
		'api_key'      => 'test-key',
		'device_id'    => 'test-device',
	)
);

$jpeg_path    = tempnam( sys_get_temp_dir(), 'jlwi-jpeg-' );
$pdf_path     = tempnam( sys_get_temp_dir(), 'jlwi-pdf-' );
$invalid_path = tempnam( sys_get_temp_dir(), 'jlwi-invalid-' );
file_put_contents( $jpeg_path, "\xFF\xD8\xFF\xE0jpeg-smoke\xFF\xD9" );
file_put_contents( $pdf_path, "%PDF-1.4\npdf-smoke" );
file_put_contents( $invalid_path, 'plain text' );

$client = new JLWI_API_Client();
$jpeg   = $client->upload_media( $jpeg_path );
jlwi_media_assert( ! is_wp_error( $jpeg ) && 'media-id' === $jpeg['id'], 'JPEG upload failed.' );
jlwi_media_assert( 1 === count( $GLOBALS['jlwi_media_requests'] ), 'JPEG upload did not make exactly one request.' );
$jpeg_request = $GLOBALS['jlwi_media_requests'][0]['args'];
jlwi_media_assert( false !== strpos( $jpeg_request['body'], 'Content-Type: image/jpeg' ), 'JPEG multipart MIME is incorrect.' );
jlwi_media_assert( false !== strpos( $jpeg_request['body'], '.jpg"' ), 'Temporary JPEG filename was not normalized to .jpg.' );

$pdf = $client->upload_media( $pdf_path );
jlwi_media_assert( ! is_wp_error( $pdf ), 'Existing PDF upload support regressed.' );
$pdf_request = $GLOBALS['jlwi_media_requests'][1]['args'];
jlwi_media_assert( false !== strpos( $pdf_request['body'], 'Content-Type: application/pdf' ), 'PDF multipart MIME is incorrect.' );
jlwi_media_assert( false !== strpos( $pdf_request['body'], '.pdf"' ), 'Temporary PDF filename was not normalized to .pdf.' );

$request_count = count( $GLOBALS['jlwi_media_requests'] );
$invalid       = $client->upload_media( $invalid_path );
jlwi_media_assert( is_wp_error( $invalid ) && 'jlwi_media_type_unsupported' === $invalid->get_error_code(), 'Unsupported media should be rejected.' );
jlwi_media_assert( $request_count === count( $GLOBALS['jlwi_media_requests'] ), 'Unsupported media should not be uploaded.' );

unlink( $jpeg_path );
unlink( $pdf_path );
unlink( $invalid_path );

fwrite( STDOUT, "Media upload smoke test passed.\n" );
