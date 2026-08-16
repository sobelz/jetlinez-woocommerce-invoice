<?php
/**
 * Standalone smoke test for delivery-mode settings and recipient grouping.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'JLWI_OPTION', 'jlwi_settings' );
define( 'JLWI_TEXT_DOMAIN', 'jetlinez-woocommerce-invoice' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['jlwi_test_option'] = array();

function get_option( $key, $default = false ) {
	return JLWI_OPTION === $key ? $GLOBALS['jlwi_test_option'] : $default;
}

function add_option() {
	return true;
}

function delete_option() {
	return true;
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

function wp_parse_url( $url ) {
	return parse_url( $url );
}

function apply_filters( $hook, $value ) {
	return $value;
}

function __( $text ) {
	return $text;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function current_time() {
	return '2026-08-11 00:00:00';
}

function get_current_blog_id() {
	return 1;
}

function wc_get_order() {
	return $GLOBALS['jlwi_test_order'];
}

function wp_strip_all_tags( $value ) {
	return strip_tags( $value );
}

function wp_specialchars_decode( $value ) {
	return html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function get_bloginfo( $field ) {
	return 'charset' === $field ? 'UTF-8' : 'Test Shop';
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function wc_date_format() {
	return 'Y-m-d';
}

function wc_time_format() {
	return 'H:i';
}

function wc_get_order_status_name( $status ) {
	return $status;
}

function jlwi_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return array();
	}
}

final class JLWI_API_Client {
	public function validate_configuration() {
		return true;
	}

	public function upload_media() {
		++$GLOBALS['jlwi_test_uploads'];
		return array( 'id' => 'test-media-id' );
	}

	public function send_message( $phone, $text = '', $media_id = '' ) {
		$GLOBALS['jlwi_test_messages'][] = array(
			'phone'    => $phone,
			'text'     => $text,
			'media_id' => $media_id,
		);
		return array( 'data' => array( 'id' => 'message-id' ) );
	}

	public static function is_transient_error() {
		return false;
	}
}

require dirname( __DIR__ ) . '/includes/class-jlwi-settings.php';
require dirname( __DIR__ ) . '/includes/class-jlwi-template.php';
require dirname( __DIR__ ) . '/includes/class-jlwi-sender.php';

$defaults                     = JLWI_Settings::defaults();
$GLOBALS['jlwi_test_option']  = $defaults;

jlwi_test_assert( 'text' === JLWI_Settings::delivery_mode( 'processing', 'customer' ), 'Processing/customer should default to text.' );
jlwi_test_assert( 'file' === JLWI_Settings::delivery_mode( 'wc-processing', 'admin' ), 'Processing/admin should default to file.' );
jlwi_test_assert( 'both' === JLWI_Settings::delivery_mode( 'completed', 'customer' ), 'Completed/customer should default to both.' );
jlwi_test_assert( 'text' === JLWI_Settings::delivery_mode( 'completed', 'admin' ), 'Completed/admin should default to text.' );
jlwi_test_assert( 'none' === JLWI_Settings::delivery_mode( 'completed', 'invalid' ), 'An invalid audience should never produce a delivery route.' );

$GLOBALS['jlwi_test_option'] = array(
	'target_statuses'    => array( 'processing', 'completed' ),
	'send_pdf'           => 'yes',
	'send_text_with_pdf' => 'no',
);
$legacy = JLWI_Settings::raw();
jlwi_test_assert( 'file' === $legacy['delivery_modes']['processing']['customer'], 'Legacy file-only settings were not migrated.' );
jlwi_test_assert( 'file' === $legacy['delivery_modes']['completed']['admin'], 'Legacy mode should apply to both audiences.' );

$GLOBALS['jlwi_test_option'] = array( 'fixed_recipients' => '09121234567' );
$legacy_recipients           = JLWI_Settings::raw();
jlwi_test_assert( '09121234567' === $legacy_recipients['report_recipients'], 'Legacy invoice recipients were not migrated to the report recipient list.' );

$GLOBALS['jlwi_test_option'] = array_merge(
	$defaults,
	array(
		'fixed_recipients'  => '09121234567',
		'report_recipients' => '09351234567',
	)
);
$separate_recipients = JLWI_Settings::raw();
jlwi_test_assert( '09351234567' === $separate_recipients['report_recipients'], 'Explicit report recipients were replaced by invoice recipients.' );

$GLOBALS['jlwi_test_option'] = array_merge(
	$defaults,
	array(
		'fixed_recipients'     => '09121234567',
		'include_billing_phone' => 'yes',
	)
);

class JLWI_Test_Order {
	private $meta = array();

	public function get_billing_phone() {
		return '09121234567';
	}

	public function get_id() {
		return 100;
	}

	public function get_order_number() {
		return '100';
	}

	public function get_date_created() {
		return new JLWI_Test_Date();
	}

	public function get_formatted_billing_full_name() {
		return 'Test Customer';
	}

	public function get_billing_first_name() {
		return 'Test';
	}

	public function get_billing_last_name() {
		return 'Customer';
	}

	public function get_billing_email() {
		return 'customer@example.test';
	}

	public function get_formatted_order_total() {
		return '1000 IRR';
	}

	public function get_currency() {
		return 'IRR';
	}

	public function get_payment_method_title() {
		return 'Test payment';
	}

	public function get_shipping_method() {
		return 'Test shipping';
	}

	public function get_formatted_billing_address() {
		return 'Billing address';
	}

	public function get_formatted_shipping_address() {
		return 'Shipping address';
	}

	public function get_customer_note() {
		return '';
	}

	public function get_items() {
		return array();
	}

	public function get_item_count() {
		return 0;
	}

	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function save_meta_data() {
		return true;
	}

	public function add_order_note() {
		return true;
	}
}

class JLWI_Test_Date {
	public function date_i18n() {
		return '2026-08-11 12:00';
	}
}

class JLWI_Test_Invoice {
	public function make_pdf_file() {
		return $GLOBALS['jlwi_test_pdf'];
	}
}

$sender = new JLWI_Sender();
$method = new ReflectionMethod( 'JLWI_Sender', 'get_recipient_routes' );
$method->setAccessible( true );
$routes = $method->invoke( $sender, new JLWI_Test_Order(), 'processing' );

jlwi_test_assert( isset( $routes['admin:989121234567'] ), 'Admin route was not created.' );
jlwi_test_assert( isset( $routes['customer:989121234567'] ), 'Customer route was not created independently for the same phone.' );
jlwi_test_assert( 'file' === $routes['admin:989121234567']['delivery_mode'], 'Admin route did not retain its file mode.' );
jlwi_test_assert( 'text' === $routes['customer:989121234567']['delivery_mode'], 'Customer route did not retain its text mode.' );

$GLOBALS['jlwi_test_option']['delivery_modes']['processing']['customer'] = 'none';
$routes = $method->invoke( $sender, new JLWI_Test_Order(), 'processing' );
jlwi_test_assert( ! isset( $routes['customer:989121234567'] ), 'A disabled customer route should be omitted.' );
jlwi_test_assert( isset( $routes['admin:989121234567'] ), 'Disabling the customer route should not disable the admin route.' );

// Exercise the requested processing and completed combinations end-to-end.
$GLOBALS['jlwi_test_option'] = array_merge(
	$defaults,
	array(
		'enabled'               => 'yes',
		'fixed_recipients'      => '09121234567',
		'include_billing_phone' => 'yes',
		'prevent_duplicates'    => 'no',
		'delete_local_pdf'      => 'no',
		'add_order_notes'       => 'no',
	)
);
$GLOBALS['jlwi_test_order']    = new JLWI_Test_Order();
$GLOBALS['jlwi_test_pdf']      = sys_get_temp_dir() . '/jlwi-delivery-modes-smoke.pdf';
$GLOBALS['jlwi_test_messages'] = array();
$GLOBALS['jlwi_test_uploads']  = 0;
$GLOBALS['PeproUltimateInvoice'] = new JLWI_Test_Invoice();
file_put_contents( $GLOBALS['jlwi_test_pdf'], "%PDF-1.4\n% smoke test\n" );

$sender->process_order( 100, 'processing', 'pending', 0, false );
$processing_texts = array_filter( $GLOBALS['jlwi_test_messages'], static function ( $message ) {
	return '' !== $message['text'];
} );
$processing_files = array_filter( $GLOBALS['jlwi_test_messages'], static function ( $message ) {
	return '' !== $message['media_id'];
} );
jlwi_test_assert( 1 === count( $processing_texts ), 'Processing should send one customer text.' );
jlwi_test_assert( 1 === count( $processing_files ), 'Processing should send one admin file.' );
jlwi_test_assert( 1 === $GLOBALS['jlwi_test_uploads'], 'Processing should upload the invoice once.' );

$GLOBALS['jlwi_test_messages'] = array();
$GLOBALS['jlwi_test_uploads']  = 0;
$sender->process_order( 100, 'completed', 'processing', 0, false );
$completed_texts = array_filter( $GLOBALS['jlwi_test_messages'], static function ( $message ) {
	return '' !== $message['text'];
} );
$completed_files = array_filter( $GLOBALS['jlwi_test_messages'], static function ( $message ) {
	return '' !== $message['media_id'];
} );
jlwi_test_assert( 2 === count( $completed_texts ), 'Completed should send admin text plus customer text.' );
jlwi_test_assert( 1 === count( $completed_files ), 'Completed should send one customer file.' );
jlwi_test_assert( 1 === $GLOBALS['jlwi_test_uploads'], 'Completed should upload the invoice once.' );

unlink( $GLOBALS['jlwi_test_pdf'] );

fwrite( STDOUT, "Delivery mode smoke test passed.\n" );
