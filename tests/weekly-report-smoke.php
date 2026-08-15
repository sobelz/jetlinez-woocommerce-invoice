<?php
/**
 * Standalone smoke test for weekly report metrics, selection, delivery, and scheduling.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'JLWI_OPTION', 'jlwi_settings' );
define( 'JLWI_WEEKLY_REPORT_STATE_OPTION', 'jlwi_weekly_report_state' );
define( 'JLWI_TEXT_DOMAIN', 'jetlinez-woocommerce-invoice' );
define( 'JLWI_VERSION', '1.7.0-test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1048576 );

$GLOBALS['jlwi_weekly_options']    = array();
$GLOBALS['jlwi_weekly_transients'] = array();
$GLOBALS['jlwi_weekly_messages']   = array();
$GLOBALS['jlwi_weekly_cron']       = false;
$GLOBALS['jlwi_weekly_now']        = '2026-08-15 20:00:00';
$GLOBALS['jlwi_quickchart_mode']    = 'success';
$GLOBALS['jlwi_quickchart_requests'] = array();
$GLOBALS['jlwi_weekly_uploads']    = array();
$GLOBALS['jlwi_weekly_upload_mode'] = 'success';

function get_option( $key, $default = false ) {
	if ( JLWI_OPTION === $key || JLWI_WEEKLY_REPORT_STATE_OPTION === $key ) {
		return isset( $GLOBALS['jlwi_weekly_options'][ $key ] ) ? $GLOBALS['jlwi_weekly_options'][ $key ] : $default;
	}

	$values = array(
		'date_format' => 'Y-m-d',
		'time_format' => 'H:i',
	);
	return array_key_exists( $key, $values ) ? $values[ $key ] : $default;
}

function update_option( $key, $value ) {
	$GLOBALS['jlwi_weekly_options'][ $key ] = $value;
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

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
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

function current_datetime() {
	return new DateTimeImmutable( $GLOBALS['jlwi_weekly_now'], wp_timezone() );
}

function wp_timezone() {
	return new DateTimeZone( 'Asia/Tehran' );
}

function wp_date( $format, $timestamp, $timezone = null ) {
	$date = new DateTimeImmutable( '@' . (int) $timestamp );
	return $date->setTimezone( $timezone ?: wp_timezone() )->format( $format );
}

function wp_next_scheduled() {
	return $GLOBALS['jlwi_weekly_cron'];
}

function wp_clear_scheduled_hook() {
	$GLOBALS['jlwi_weekly_cron'] = false;
	return 1;
}

function wp_schedule_single_event( $timestamp ) {
	$GLOBALS['jlwi_weekly_cron'] = (int) $timestamp;
	return true;
}

function get_transient( $key ) {
	return isset( $GLOBALS['jlwi_weekly_transients'][ $key ] ) ? $GLOBALS['jlwi_weekly_transients'][ $key ] : false;
}

function set_transient( $key, $value ) {
	$GLOBALS['jlwi_weekly_transients'][ $key ] = $value;
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['jlwi_weekly_transients'][ $key ] );
	return true;
}

function wp_remote_post( $url, $args ) {
	$GLOBALS['jlwi_quickchart_requests'][] = array( 'url' => $url, 'args' => $args );
	if ( 'transport_error' === $GLOBALS['jlwi_quickchart_mode'] ) {
		return new WP_Error( 'http_request_failed', 'QuickChart unavailable.' );
	}
	if ( 'invalid' === $GLOBALS['jlwi_quickchart_mode'] ) {
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'text/plain' ),
			'body'     => 'not an image',
		);
	}

	return array(
		'response' => array( 'code' => 200 ),
		'headers'  => array( 'content-type' => 'image/jpeg' ),
		'body'     => "\xFF\xD8\xFF\xE0weekly-chart\xFF\xD9",
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_header( $response, $header ) {
	$header = strtolower( (string) $header );
	return isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function wp_tempnam() {
	return tempnam( sys_get_temp_dir(), 'jlwi-weekly-chart-' );
}

function wp_delete_file( $path ) {
	if ( is_file( $path ) ) {
		unlink( $path );
	}
}

function get_woocommerce_currency() {
	return 'IRR';
}

function wc_get_is_paid_statuses() {
	return array( 'processing', 'completed' );
}

function wc_get_orders( $args ) {
	if ( isset( $args['return'] ) && 'ids' === $args['return'] ) {
		return isset( $args['customer_id'] ) && 1 === (int) $args['customer_id'] ? array( 99 ) : array();
	}

	list( $start ) = explode( '...', $args['date_created'] );
	$start_date = wp_date( 'Y-m-d', (int) $start, wp_timezone() );
	if ( '2026-08-08' === $start_date ) {
		$orders = array(
			new JLWI_Weekly_Test_Order( 1, 'processing', 1000, 0, 100, '2026-08-08 10:00:00', array(
				new JLWI_Weekly_Test_Item( 11, 10, 'Product A', 2, 600 ),
				new JLWI_Weekly_Test_Item( 12, 20, 'Product B', 1, 200 ),
			) ),
			new JLWI_Weekly_Test_Order( 2, 'completed', 3000, 500, 200, '2026-08-09 11:00:00', array(
				new JLWI_Weekly_Test_Item( 21, 10, 'Product A', 3, 2000 ),
				new JLWI_Weekly_Test_Item( 22, 30, 'Product C', 1, 800 ),
			), array( 21 => -1 ), array( 21 => -500 ) ),
			new JLWI_Weekly_Test_Order( 3, 'cancelled', 500, 0, 0, '2026-08-10 12:00:00' ),
			new JLWI_Weekly_Test_Order( 4, 'failed', 700, 0, 0, '2026-08-11 13:00:00' ),
			new JLWI_Weekly_Test_Order( 5, 'refunded', 1500, 1500, 0, '2026-08-12 14:00:00' ),
			new JLWI_Weekly_Test_Order( 1, 'processing', 2000, 0, 0, '2026-08-14 15:00:00', array(
				new JLWI_Weekly_Test_Item( 61, 40, 'Product D', 1, 1800 ),
			) ),
		);
	} elseif ( '2026-08-01' === $start_date ) {
		$orders = array(
			new JLWI_Weekly_Test_Order( 1, 'processing', 4000, 0, 0, '2026-08-02 10:00:00', array(
				new JLWI_Weekly_Test_Item( 71, 10, 'Product A', 1, 1000 ),
				new JLWI_Weekly_Test_Item( 72, 20, 'Product B', 3, 3000 ),
			) ),
			new JLWI_Weekly_Test_Order( 8, 'completed', 2000, 0, 0, '2026-08-05 10:00:00', array(
				new JLWI_Weekly_Test_Item( 81, 20, 'Product B', 2, 2000 ),
			) ),
		);
	} else {
		$orders = array();
	}

	return (object) array(
		'orders'        => $orders,
		'max_num_pages' => 1,
	);
}

function wc_price( $amount, $args ) {
	return number_format( (float) $amount, 0, '.', ',' ) . ' ' . $args['currency'];
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals, '.', ',' );
}

function wp_strip_all_tags( $value ) {
	return strip_tags( $value );
}

function wp_specialchars_decode( $value ) {
	return html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
}

function get_bloginfo( $field ) {
	return 'charset' === $field ? 'UTF-8' : 'Weekly Test Shop';
}

function jlwi_weekly_assert( $condition, $message ) {
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
}

class JLWI_Weekly_Test_Order {
	private $customer_id;
	private $status;
	private $total;
	private $refunded;
	private $discount;
	private $created;
	private $items;
	private $refunded_quantities;
	private $refunded_totals;

	public function __construct( $customer_id, $status, $total, $refunded, $discount, $created, $items = array(), $refunded_quantities = array(), $refunded_totals = array() ) {
		$this->customer_id          = $customer_id;
		$this->status               = $status;
		$this->total                = $total;
		$this->refunded             = $refunded;
		$this->discount             = $discount;
		$this->created              = new DateTimeImmutable( $created, wp_timezone() );
		$this->items                = $items;
		$this->refunded_quantities  = $refunded_quantities;
		$this->refunded_totals      = $refunded_totals;
	}

	public function get_id() {
		return $this->customer_id * 100;
	}

	public function get_customer_id() {
		return $this->customer_id;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_total() {
		return $this->total;
	}

	public function get_total_refunded() {
		return $this->refunded;
	}

	public function get_discount_total() {
		return $this->discount;
	}

	public function get_discount_tax() {
		return 0;
	}

	public function get_currency() {
		return 'IRR';
	}

	public function get_date_created() {
		return $this->created;
	}

	public function get_items() {
		return $this->items;
	}

	public function get_qty_refunded_for_item( $item_id ) {
		return isset( $this->refunded_quantities[ $item_id ] ) ? $this->refunded_quantities[ $item_id ] : 0;
	}

	public function get_total_refunded_for_item( $item_id ) {
		return isset( $this->refunded_totals[ $item_id ] ) ? $this->refunded_totals[ $item_id ] : 0;
	}
}

class JLWI_Weekly_Test_Item {
	private $id;
	private $product_id;
	private $name;
	private $quantity;
	private $total;

	public function __construct( $id, $product_id, $name, $quantity, $total ) {
		$this->id         = $id;
		$this->product_id = $product_id;
		$this->name       = $name;
		$this->quantity   = $quantity;
		$this->total      = $total;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_product_id() {
		return $this->product_id;
	}

	public function get_variation_id() {
		return 0;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_quantity() {
		return $this->quantity;
	}

	public function get_total() {
		return $this->total;
	}
}

class JLWI_Sender {
	public static function normalize_phone( $raw, $country_code = '98' ) {
		$number = preg_replace( '/\D+/', '', JLWI_Settings::ascii_digits( $raw ) );
		if ( 0 === strpos( $number, '0' ) ) {
			$number = $country_code . ltrim( $number, '0' );
		}
		return preg_match( '/^[0-9]{8,15}$/', $number ) ? $number : '';
	}
}

class JLWI_API_Client {
	public function validate_configuration() {
		return true;
	}

	public function upload_media( $path ) {
		$GLOBALS['jlwi_weekly_uploads'][] = array(
			'path'      => $path,
			'exists'    => is_file( $path ),
			'signature' => is_file( $path ) ? file_get_contents( $path, false, null, 0, 3 ) : '',
		);
		if ( 'failure' === $GLOBALS['jlwi_weekly_upload_mode'] ) {
			return new WP_Error( 'jlwi_test_upload_failed', 'Upload failed.' );
		}
		return array( 'id' => 'weekly-chart-media-id' );
	}

	public function send_message( $phone, $message = '', $media_id = '' ) {
		$GLOBALS['jlwi_weekly_messages'][] = array(
			'phone'    => $phone,
			'text'     => $message,
			'media_id' => $media_id,
		);
		return array( 'success' => true );
	}

	public function delete_media() {
		return array( 'success' => true );
	}
}

require dirname( __DIR__ ) . '/includes/class-jlwi-settings.php';
require dirname( __DIR__ ) . '/includes/class-jlwi-weekly-report.php';

$settings = array_merge(
	JLWI_Settings::defaults(),
	array(
		'api_key'               => 'test-key',
		'device_id'             => 'test-device',
		'fixed_recipients'      => "09121234567\n989121234567\n09351234567",
		'weekly_report_enabled' => 'yes',
	)
);
$GLOBALS['jlwi_weekly_options'][ JLWI_OPTION ] = $settings;

jlwi_weekly_assert( 9 === count( JLWI_Settings::sanitize_weekly_report_sections( $settings['weekly_report_sections'] ) ), 'All weekly sections should be enabled by default.' );
jlwi_weekly_assert( 6 === JLWI_Settings::sanitize_weekly_report_day( '۶' ), 'Persian weekday was not normalized.' );

$report  = new JLWI_Weekly_Report();
$message = $report->build_message();
jlwi_weekly_assert( ! is_wp_error( $message ), 'Weekly report generation returned an error.' );
jlwi_weekly_assert( false !== strpos( $message, '5,500 IRR' ), 'Weekly net sales are incorrect.' );
jlwi_weekly_assert( false !== strpos( $message, '-8.3%' ), 'Previous-week sales comparison is incorrect.' );
jlwi_weekly_assert( false !== strpos( $message, 'تعداد سفارش‌ها: 6' ), 'Weekly order count is incorrect.' );
jlwi_weekly_assert( false !== strpos( $message, '1,833 IRR' ), 'Weekly average order value is incorrect.' );
jlwi_weekly_assert( false !== strpos( $message, '2026-08-08: 1,000 IRR' ), 'Daily sales breakdown is incorrect.' );
jlwi_weekly_assert( false !== strpos( $message, 'مشتری جدید: 1' ), 'New customer count is incorrect.' );
jlwi_weekly_assert( false !== strpos( $message, 'مشتری تکراری: 1' ), 'Returning customer count is incorrect.' );
jlwi_weekly_assert( false !== strpos( $message, 'Product A — 4 عدد — 2,100 IRR' ), 'Top-selling product metrics are incorrect.' );
jlwi_weekly_assert( false !== strpos( $message, 'Product B — 1 عدد — 200 IRR' ), 'Low-selling product metrics are incorrect.' );
jlwi_weekly_assert( false !== strpos( $message, 'Product D — رشد جدید' ), 'Largest product growth is missing.' );
jlwi_weekly_assert( false !== strpos( $message, 'Product B — -96.0%' ), 'Largest product decline is missing.' );
jlwi_weekly_assert( false !== strpos( $message, 'لغوشده: 1' ), 'Cancelled order count is incorrect.' );
jlwi_weekly_assert( false !== strpos( $message, 'مرجوع/بازپرداخت‌شده: 2' ), 'Refunded order count is incorrect.' );
jlwi_weekly_assert( false !== strpos( $message, 'پرداخت ناموفق: 1' ), 'Failed payment count is incorrect.' );
jlwi_weekly_assert( false !== strpos( $message, '300 IRR' ), 'Weekly discount total is incorrect.' );

$GLOBALS['jlwi_weekly_options'][ JLWI_OPTION ]['weekly_report_sections'] = array( 'orders_average' );
$orders_only = $report->build_message();
jlwi_weekly_assert( false !== strpos( $orders_only, 'تعداد سفارش‌ها: 6' ), 'Enabled weekly section was omitted.' );
jlwi_weekly_assert( false === strpos( $orders_only, 'مجموع فروش هفته' ), 'Disabled weekly sales section was rendered.' );
jlwi_weekly_assert( false === strpos( $orders_only, 'فروش روزبه‌روز' ), 'Disabled daily breakdown was rendered.' );

$GLOBALS['jlwi_weekly_options'][ JLWI_OPTION ]['weekly_report_sections'] = array( 'sales_change' );
$change_only = $report->build_message();
jlwi_weekly_assert( false !== strpos( $change_only, '-8.3%' ), 'Enabled weekly change section was omitted.' );
jlwi_weekly_assert( false === strpos( $change_only, '5,500 IRR' ), 'Disabled total-sales value leaked into the change-only section.' );

$GLOBALS['jlwi_weekly_options'][ JLWI_OPTION ]['weekly_report_sections'] = $settings['weekly_report_sections'];
$delivery = $report->send_now();
jlwi_weekly_assert( ! is_wp_error( $delivery ), 'Weekly report delivery returned an error.' );
jlwi_weekly_assert( 2 === $delivery['sent'], 'Weekly report recipients were not normalized and deduplicated.' );
jlwi_weekly_assert( 2 === $delivery['chart_sent'], 'Weekly change chart was not delivered to every text recipient.' );
jlwi_weekly_assert( 4 === count( $GLOBALS['jlwi_weekly_messages'] ), 'Expected two text messages and two chart messages.' );
jlwi_weekly_assert( 1 === count( $GLOBALS['jlwi_quickchart_requests'] ), 'QuickChart should be requested once per report.' );
jlwi_weekly_assert( 1 === count( $GLOBALS['jlwi_weekly_uploads'] ), 'The weekly chart should be uploaded once.' );
jlwi_weekly_assert( "\xFF\xD8\xFF" === $GLOBALS['jlwi_weekly_uploads'][0]['signature'], 'The uploaded weekly chart is not JPEG data.' );
jlwi_weekly_assert( ! is_file( $GLOBALS['jlwi_weekly_uploads'][0]['path'] ), 'The temporary weekly chart was not deleted.' );

$quickchart_request = $GLOBALS['jlwi_quickchart_requests'][0];
$quickchart_payload = json_decode( $quickchart_request['args']['body'], true );
jlwi_weekly_assert( 'https://quickchart.io/chart' === $quickchart_request['url'], 'The chart request used an unexpected endpoint.' );
jlwi_weekly_assert( 'jpg' === $quickchart_payload['format'], 'QuickChart output format should be JPG.' );
jlwi_weekly_assert( 'bar' === $quickchart_payload['chart']['type'], 'The weekly change chart should be a bar chart.' );
jlwi_weekly_assert( array( -8.3 ) === $quickchart_payload['chart']['data']['datasets'][0]['data'], 'Only the calculated weekly percentage change should be charted.' );
jlwi_weekly_assert( false === strpos( $quickchart_request['args']['body'], '5500' ), 'Current sales total leaked to QuickChart.' );
jlwi_weekly_assert( false === strpos( $quickchart_request['args']['body'], '6000' ), 'Previous sales total leaked to QuickChart.' );
jlwi_weekly_assert( false === strpos( $quickchart_request['args']['body'], 'Product A' ), 'Product data leaked to QuickChart.' );
jlwi_weekly_assert( false === strpos( $quickchart_request['args']['body'], 'Weekly Test Shop' ), 'Store identity leaked to QuickChart.' );
jlwi_weekly_assert( false === strpos( $quickchart_request['args']['body'], '989121234567' ), 'Recipient data leaked to QuickChart.' );
jlwi_weekly_assert( 'success' === $GLOBALS['jlwi_weekly_options'][ JLWI_WEEKLY_REPORT_STATE_OPTION ]['status'], 'Weekly report state was not stored.' );

$GLOBALS['jlwi_quickchart_mode']     = 'transport_error';
$GLOBALS['jlwi_weekly_messages']    = array();
$GLOBALS['jlwi_quickchart_requests'] = array();
$GLOBALS['jlwi_weekly_uploads']     = array();
$fallback_delivery = $report->send_now();
jlwi_weekly_assert( ! is_wp_error( $fallback_delivery ), 'QuickChart failure should not fail text delivery.' );
jlwi_weekly_assert( 2 === $fallback_delivery['sent'], 'Text fallback was not delivered after QuickChart failure.' );
jlwi_weekly_assert( 0 === $fallback_delivery['chart_sent'], 'A chart was reported sent despite QuickChart failure.' );
jlwi_weekly_assert( 2 === count( $GLOBALS['jlwi_weekly_messages'] ), 'QuickChart failure should result in text-only messages.' );
jlwi_weekly_assert( 0 === count( $GLOBALS['jlwi_weekly_uploads'] ), 'An invalid QuickChart response should not be uploaded.' );

$GLOBALS['jlwi_quickchart_mode']      = 'success';
$GLOBALS['jlwi_weekly_upload_mode']   = 'failure';
$GLOBALS['jlwi_weekly_messages']      = array();
$GLOBALS['jlwi_quickchart_requests']  = array();
$GLOBALS['jlwi_weekly_uploads']       = array();
$upload_fallback = $report->send_now();
jlwi_weekly_assert( ! is_wp_error( $upload_fallback ), 'Chart upload failure should not fail text delivery.' );
jlwi_weekly_assert( 2 === $upload_fallback['sent'], 'Text fallback was not delivered after chart upload failure.' );
jlwi_weekly_assert( 0 === $upload_fallback['chart_sent'], 'A chart was reported sent despite upload failure.' );
jlwi_weekly_assert( 2 === count( $GLOBALS['jlwi_weekly_messages'] ), 'Chart upload failure should result in text-only messages.' );
jlwi_weekly_assert( 1 === count( $GLOBALS['jlwi_weekly_uploads'] ), 'The valid chart should have reached the media upload path.' );
jlwi_weekly_assert( ! is_file( $GLOBALS['jlwi_weekly_uploads'][0]['path'] ), 'The chart temp file survived an upload failure.' );
$GLOBALS['jlwi_weekly_upload_mode'] = 'success';

JLWI_Weekly_Report::reschedule();
jlwi_weekly_assert( false !== $GLOBALS['jlwi_weekly_cron'], 'Weekly report cron event was not scheduled.' );
jlwi_weekly_assert( '6' === wp_date( 'w', $GLOBALS['jlwi_weekly_cron'], wp_timezone() ), 'Weekly cron did not preserve the selected weekday.' );
jlwi_weekly_assert( '20:00' === wp_date( 'H:i', $GLOBALS['jlwi_weekly_cron'], wp_timezone() ), 'Weekly cron did not preserve the configured time.' );
jlwi_weekly_assert( '2026-08-22' === wp_date( 'Y-m-d', $GLOBALS['jlwi_weekly_cron'], wp_timezone() ), 'Weekly cron should schedule the next week when today\'s time has passed.' );

$GLOBALS['jlwi_weekly_now'] = '2026-08-16 09:00:00';
$delayed_scheduled = $report->build_message( 'scheduled' );
$manual_on_sunday  = $report->build_message();
jlwi_weekly_assert( false !== strpos( $delayed_scheduled, '2026-08-08 تا 2026-08-14' ), 'A delayed cron run should retain the configured Saturday-to-Friday period.' );
jlwi_weekly_assert( false !== strpos( $manual_on_sunday, '2026-08-09 تا 2026-08-15' ), 'A manual report should use the last seven complete calendar days.' );

fwrite( STDOUT, "Weekly report smoke test passed.\n" );
