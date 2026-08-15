<?php
/**
 * Standalone smoke test for daily report metrics, selection, and scheduling.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'JLWI_OPTION', 'jlwi_settings' );
define( 'JLWI_REPORT_STATE_OPTION', 'jlwi_daily_report_state' );
define( 'JLWI_TEXT_DOMAIN', 'jetlinez-woocommerce-invoice' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['jlwi_test_options']    = array();
$GLOBALS['jlwi_test_transients'] = array();
$GLOBALS['jlwi_test_messages']   = array();
$GLOBALS['jlwi_test_cron']       = false;

function get_option( $key, $default = false ) {
	if ( JLWI_OPTION === $key ) {
		return isset( $GLOBALS['jlwi_test_options'][ $key ] ) ? $GLOBALS['jlwi_test_options'][ $key ] : array();
	}

	$values = array(
		'woocommerce_hold_stock_minutes'       => 60,
		'woocommerce_notify_low_stock_amount'  => 2,
		'date_format'                           => 'Y-m-d',
		'time_format'                           => 'H:i',
	);

	return array_key_exists( $key, $values ) ? $values[ $key ] : $default;
}

function update_option( $key, $value ) {
	$GLOBALS['jlwi_test_options'][ $key ] = $value;
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

function current_datetime() {
	return new DateTimeImmutable( '2026-08-15 20:00:00', wp_timezone() );
}

function wp_timezone() {
	return new DateTimeZone( 'Asia/Tehran' );
}

function wp_date( $format, $timestamp, $timezone = null ) {
	$date = new DateTimeImmutable( '@' . (int) $timestamp );
	return $date->setTimezone( $timezone ?: wp_timezone() )->format( $format );
}

function wp_next_scheduled() {
	return $GLOBALS['jlwi_test_cron'];
}

function wp_clear_scheduled_hook() {
	$GLOBALS['jlwi_test_cron'] = false;
	return 1;
}

function wp_schedule_single_event( $timestamp ) {
	$GLOBALS['jlwi_test_cron'] = (int) $timestamp;
	return true;
}

function get_transient( $key ) {
	return isset( $GLOBALS['jlwi_test_transients'][ $key ] ) ? $GLOBALS['jlwi_test_transients'][ $key ] : false;
}

function set_transient( $key, $value ) {
	$GLOBALS['jlwi_test_transients'][ $key ] = $value;
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['jlwi_test_transients'][ $key ] );
	return true;
}

function get_woocommerce_currency() {
	return 'IRR';
}

function wc_get_is_paid_statuses() {
	return array( 'processing', 'completed' );
}

function wc_get_orders( $args ) {
	list( $start ) = explode( '...', $args['date_created'] );
	$start_time = wp_date( 'Y-m-d H:i:s', (int) $start, wp_timezone() );
	$day        = substr( $start_time, 0, 10 );
	if ( '2026-08-15' === $day ) {
		$orders = array(
			new JLWI_Test_Report_Order( 'processing', 1000, 0, '2026-08-15 10:00:00' ),
			new JLWI_Test_Report_Order( 'completed', 2000, 500, '2026-08-15 11:00:00' ),
			new JLWI_Test_Report_Order( 'cancelled', 400, 0, '2026-08-15 12:00:00' ),
			new JLWI_Test_Report_Order( 'failed', 300, 0, '2026-08-15 13:00:00' ),
			new JLWI_Test_Report_Order( 'pending', 100, 0, '2026-08-15 14:00:00' ),
		);
	} elseif ( '2026-08-14 00:00:00' === $start_time ) {
		$orders = array(
			new JLWI_Test_Report_Order( 'processing', 2000, 0, '2026-08-14 10:00:00' ),
			new JLWI_Test_Report_Order( 'completed', 1000, 0, '2026-08-14 11:00:00' ),
		);
	} elseif ( '2026-08-14 20:00:00' === $start_time ) {
		$orders = array(
			new JLWI_Test_Report_Order( 'processing', 4000, 0, '2026-08-15 08:00:00' ),
		);
	} else {
		$orders = array(
			new JLWI_Test_Report_Order( 'processing', 2000, 0, '2026-08-14 08:00:00' ),
		);
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
	return 'charset' === $field ? 'UTF-8' : 'Test Shop';
}

function wc_get_product( $product_id ) {
	return new JLWI_Test_Product( $product_id );
}

function get_the_title( $product_id ) {
	return 'Product ' . (int) $product_id;
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
}

class WP_User_Query {
	public function __construct( $args ) {
		unset( $args );
	}

	public function get_total() {
		return 3;
	}
}

class JLWI_Test_Report_Order {
	private $status;
	private $total;
	private $refunded;
	private $created;

	public function __construct( $status, $total, $refunded, $created ) {
		$this->status   = $status;
		$this->total    = $total;
		$this->refunded = $refunded;
		$this->created  = new DateTimeImmutable( $created, wp_timezone() );
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

	public function get_currency() {
		return 'IRR';
	}

	public function get_date_created() {
		return $this->created;
	}
}

class JLWI_Test_Product {
	private $id;

	public function __construct( $id ) {
		$this->id = $id;
	}

	public function get_name() {
		return 'Product ' . $this->id;
	}
}

class JLWI_Test_WPDB {
	public $wc_product_meta_lookup = 'wp_wc_product_meta_lookup';
	public $posts                  = 'wp_posts';
	public $postmeta               = 'wp_postmeta';

	public function prepare( $query, $value ) {
		if ( false !== strpos( $query, '%d' ) ) {
			return str_replace( '%d', (string) (int) $value, $query );
		}

		return str_replace( '%f', number_format( (float) $value, 6, '.', '' ), $query );
	}

	public function get_var() {
		return 2;
	}

	public function get_results() {
		return array(
			array(
				'product_id'      => 10,
				'stock_quantity'  => null,
				'stock_status'    => 'outofstock',
			),
			array(
				'product_id'      => 11,
				'stock_quantity'  => '1',
				'stock_status'    => 'instock',
			),
		);
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

	public function send_message( $phone, $message ) {
		$GLOBALS['jlwi_test_messages'][] = array( $phone, $message );
		return array( 'success' => true );
	}
}

$wpdb = new JLWI_Test_WPDB();

require dirname( __DIR__ ) . '/includes/class-jlwi-settings.php';
require dirname( __DIR__ ) . '/includes/class-jlwi-daily-report.php';

$settings = array_merge(
	JLWI_Settings::defaults(),
	array(
		'api_key'              => 'test-key',
		'device_id'            => 'test-device',
		'fixed_recipients'     => "09121234567\n989121234567\n09351234567",
		'daily_report_enabled' => 'yes',
	)
);
$GLOBALS['jlwi_test_options'][ JLWI_OPTION ] = $settings;

jlwi_test_assert( '20:30' === JLWI_Settings::sanitize_report_time( '۲۰:۳۰' ), 'Persian report time was not normalized.' );
jlwi_test_assert( '20:00' === JLWI_Settings::sanitize_report_time( '25:10' ), 'Invalid report time should use the default.' );
jlwi_test_assert( 6 === count( JLWI_Settings::sanitize_report_sections( $settings['daily_report_sections'] ) ), 'Default report sections are incomplete.' );

$report  = new JLWI_Daily_Report();
$message = $report->build_message();
jlwi_test_assert( ! is_wp_error( $message ), 'Report message generation returned an error.' );
jlwi_test_assert( false !== strpos( $message, '2,500 IRR' ), 'Net sales were not calculated correctly.' );
jlwi_test_assert( false !== strpos( $message, '-16.7%' ), 'Yesterday comparison was not calculated correctly.' );
jlwi_test_assert( false !== strpos( $message, 'تعداد سفارش‌ها: 5' ), 'Order count is missing or incorrect.' );
jlwi_test_assert( false !== strpos( $message, '1,250 IRR' ), 'Average paid order value is incorrect.' );
jlwi_test_assert( false !== strpos( $message, 'مشتری جدید: 3' ), 'New customer count is incorrect.' );
jlwi_test_assert( false !== strpos( $message, 'لغوشده: 1' ), 'Cancelled order count is incorrect.' );
jlwi_test_assert( false !== strpos( $message, 'مرجوع/بازپرداخت‌شده: 1' ), 'Refunded order count is incorrect.' );
jlwi_test_assert( false !== strpos( $message, 'رهاشده: 2' ), 'Abandoned order count is incorrect.' );
jlwi_test_assert( false !== strpos( $message, 'Product 10 — ناموجود' ), 'Out-of-stock product is missing.' );
jlwi_test_assert( false !== strpos( $message, 'Product 11 — موجودی: 1' ), 'Low-stock product is missing.' );

$rolling_message = $report->build_message( 'last_24_hours' );
jlwi_test_assert( ! is_wp_error( $rolling_message ), 'Rolling 24-hour report returned an error.' );
jlwi_test_assert( false !== strpos( $rolling_message, 'گزارش ۲۴ ساعت گذشته فروشگاه' ), 'Rolling report title is missing.' );
jlwi_test_assert( false !== strpos( $rolling_message, 'فروش ۲۴ ساعت گذشته: 4,000 IRR' ), 'Rolling 24-hour sales are incorrect.' );
jlwi_test_assert( false !== strpos( $rolling_message, '+100.0%' ), 'Rolling report comparison is incorrect.' );
jlwi_test_assert( false !== strpos( $rolling_message, 'تعداد سفارش‌های ۲۴ ساعت گذشته: 1' ), 'Rolling report order count is incorrect.' );

$GLOBALS['jlwi_test_options'][ JLWI_OPTION ]['daily_report_sections'] = array( 'orders' );
$orders_only = $report->build_message();
jlwi_test_assert( false !== strpos( $orders_only, 'تعداد سفارش‌ها: 5' ), 'Selected order section was omitted.' );
jlwi_test_assert( false === strpos( $orders_only, 'فروش امروز:' ), 'Disabled sales section was rendered.' );
jlwi_test_assert( false === strpos( $orders_only, 'موجودی نیازمند توجه' ), 'Disabled inventory section was rendered.' );

$GLOBALS['jlwi_test_options'][ JLWI_OPTION ]['daily_report_sections'] = $settings['daily_report_sections'];
$delivery = $report->send_now( 'last_24_hours' );
jlwi_test_assert( ! is_wp_error( $delivery ), 'Daily report delivery returned an error.' );
jlwi_test_assert( 2 === $delivery['sent'], 'Daily report recipients were not normalized and deduplicated.' );
jlwi_test_assert( 2 === count( $GLOBALS['jlwi_test_messages'] ), 'Unexpected daily report message count.' );
jlwi_test_assert( false !== strpos( $delivery['message'], 'گزارش ۲۴ ساعت گذشته فروشگاه' ), 'Immediate delivery did not use the rolling report.' );
jlwi_test_assert( 'last_24_hours' === $GLOBALS['jlwi_test_options'][ JLWI_REPORT_STATE_OPTION ]['period'], 'Last report state did not retain the rolling period.' );

$GLOBALS['jlwi_test_options'][ JLWI_OPTION ]['daily_report_time'] = '21:15';
JLWI_Daily_Report::reschedule();
jlwi_test_assert( false !== $GLOBALS['jlwi_test_cron'], 'Daily report cron event was not scheduled.' );
jlwi_test_assert( '21:15' === wp_date( 'H:i', $GLOBALS['jlwi_test_cron'], wp_timezone() ), 'Cron event did not preserve the configured local time.' );

fwrite( STDOUT, "Daily report smoke test passed.\n" );
