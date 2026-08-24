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
$GLOBALS['jlwi_daily_previous_customer_queries'] = array();

function get_option( $key, $default = false ) {
	if ( JLWI_OPTION === $key ) {
		return isset( $GLOBALS['jlwi_test_options'][ $key ] ) ? $GLOBALS['jlwi_test_options'][ $key ] : array();
	}
	if ( array_key_exists( $key, $GLOBALS['jlwi_test_options'] ) ) {
		return $GLOBALS['jlwi_test_options'][ $key ];
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

function add_option( $key, $value ) {
	if ( array_key_exists( $key, $GLOBALS['jlwi_test_options'] ) ) {
		return false;
	}

	$GLOBALS['jlwi_test_options'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['jlwi_test_options'][ $key ] );
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
	if ( isset( $args['return'] ) && 'ids' === $args['return'] ) {
		$GLOBALS['jlwi_daily_previous_customer_queries'][] = $args;
		return isset( $args['customer_id'] ) && 1 === (int) $args['customer_id'] ? array( 99 ) : array();
	}

	list( $start ) = explode( '...', $args['date_created'] );
	$start_time = wp_date( 'Y-m-d H:i:s', (int) $start, wp_timezone() );
	$day        = substr( $start_time, 0, 10 );
	if ( '2026-08-15' === $day ) {
		$orders = array(
			new JLWI_Test_Report_Order(
				'processing',
				1000,
				0,
				'2026-08-15 10:00:00',
				1,
				array(
					new JLWI_Test_Report_Item( 101, 10, 2, 2 ),
					new JLWI_Test_Report_Item( 102, 11, 1, 1 ),
					new JLWI_Test_Report_Item( 103, 12, 1, 1 ),
					new JLWI_Test_Report_Item( 106, 20, 2, 2 ),
				)
			),
			new JLWI_Test_Report_Order( 'completed', 2000, 500, '2026-08-15 11:00:00', 2 ),
			new JLWI_Test_Report_Order(
				'cancelled',
				400,
				0,
				'2026-08-15 12:00:00',
				0,
				array( new JLWI_Test_Report_Item( 104, 13, 1, 1 ) )
			),
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
			new JLWI_Test_Report_Order(
				'processing',
				4000,
				0,
				'2026-08-15 08:00:00',
				2,
				array( new JLWI_Test_Report_Item( 105, 10, 2, 2 ) )
			),
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
	private static $next_id = 1;
	private $id;
	private $customer_id;
	private $status;
	private $total;
	private $refunded;
	private $created;
	private $items;

	public function __construct( $status, $total, $refunded, $created, $customer_id = 0, $items = array() ) {
		$this->id       = self::$next_id++;
		$this->customer_id = (int) $customer_id;
		$this->status   = $status;
		$this->total    = $total;
		$this->refunded = $refunded;
		$this->created  = new DateTimeImmutable( $created, wp_timezone() );
		$this->items    = $items;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_customer_id() {
		return $this->customer_id;
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

	public function get_items() {
		return $this->items;
	}
}

class JLWI_Test_Report_Item {
	private $id;
	private $product_id;
	private $quantity;
	private $reduced_stock;

	public function __construct( $id, $product_id, $quantity, $reduced_stock ) {
		$this->id            = $id;
		$this->product_id    = $product_id;
		$this->quantity      = $quantity;
		$this->reduced_stock = $reduced_stock;
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

	public function get_quantity() {
		return $this->quantity;
	}

	public function get_meta( $key ) {
		return '_reduced_stock' === $key ? $this->reduced_stock : '';
	}

	public function get_product() {
		return wc_get_product( $this->product_id );
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

	public function get_id() {
		return $this->id;
	}

	public function managing_stock() {
		return true;
	}

	public function get_stock_managed_by_id() {
		return 20 === $this->id ? 15 : $this->id;
	}

	public function get_stock_status() {
		return 11 === $this->id ? 'instock' : 'outofstock';
	}

	public function get_stock_quantity() {
		$quantities = array(
			10 => 0,
			11 => 1,
			12 => -2,
			13 => 0,
			15 => 0,
		);
		return isset( $quantities[ $this->id ] ) ? $quantities[ $this->id ] : null;
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

require dirname( __DIR__ ) . '/includes/class-jlwi-settings.php';
require dirname( __DIR__ ) . '/includes/class-jlwi-report-customers.php';
require dirname( __DIR__ ) . '/includes/class-jlwi-daily-report.php';

$settings = array_merge(
	JLWI_Settings::defaults(),
	array(
		'api_key'              => 'test-key',
		'device_id'            => 'test-device',
		'fixed_recipients'     => '09111111111',
		'report_recipients'    => "09121234567\n989121234567\n09351234567",
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
jlwi_test_assert( false !== strpos( $message, 'مشتری جدید: 1' ), 'First-time paid customer count is incorrect.' );
jlwi_test_assert( ! empty( $GLOBALS['jlwi_daily_previous_customer_queries'] ), 'Previous-customer lookup was not performed.' );
foreach ( $GLOBALS['jlwi_daily_previous_customer_queries'] as $previous_query ) {
	jlwi_test_assert( 1 === preg_match( '/^<\d+$/', $previous_query['date_created'] ), 'Daily previous-customer lookup used invalid WooCommerce date syntax.' );
}
jlwi_test_assert( false !== strpos( $message, 'لغوشده: 1' ), 'Cancelled order count is incorrect.' );
jlwi_test_assert( false !== strpos( $message, 'مرجوع/بازپرداخت‌شده: 1' ), 'Refunded order count is incorrect.' );
jlwi_test_assert( false !== strpos( $message, 'رهاشده: 2' ), 'Abandoned order count is incorrect.' );
jlwi_test_assert( false !== strpos( $message, 'محصولات ناموجودشده بر اثر فروش امروز' ), 'Sold-out section title is missing.' );
jlwi_test_assert( false !== strpos( $message, 'Product 10 — ناموجود' ), 'Product made out of stock by today\'s sales is missing.' );
jlwi_test_assert( false !== strpos( $message, 'Product 15 — ناموجود' ), 'Parent-managed variation stock was not reported against its stock owner.' );
jlwi_test_assert( false === strpos( $message, 'Product 11' ), 'A merely low-stock product should not be reported.' );
jlwi_test_assert( false === strpos( $message, 'Product 12' ), 'A product that was already out of stock should not be reported.' );
jlwi_test_assert( false === strpos( $message, 'Product 13' ), 'Stock from an unpaid order should not be reported.' );

$rolling_message = $report->build_message( 'last_24_hours' );
jlwi_test_assert( ! is_wp_error( $rolling_message ), 'Rolling 24-hour report returned an error.' );
jlwi_test_assert( false !== strpos( $rolling_message, 'گزارش ۲۴ ساعت گذشته فروشگاه' ), 'Rolling report title is missing.' );
jlwi_test_assert( false !== strpos( $rolling_message, 'فروش ۲۴ ساعت گذشته: 4,000 IRR' ), 'Rolling 24-hour sales are incorrect.' );
jlwi_test_assert( false !== strpos( $rolling_message, '+100.0%' ), 'Rolling report comparison is incorrect.' );
jlwi_test_assert( false !== strpos( $rolling_message, 'تعداد سفارش‌های ۲۴ ساعت گذشته: 1' ), 'Rolling report order count is incorrect.' );

$previous_day_message = $report->build_message( 'previous_day' );
jlwi_test_assert( ! is_wp_error( $previous_day_message ), 'Complete previous-day report returned an error.' );
jlwi_test_assert( false !== strpos( $previous_day_message, 'گزارش کامل روز گذشته فروشگاه' ), 'Previous-day report title is missing.' );
jlwi_test_assert( false !== strpos( $previous_day_message, 'بازه: 2026-08-14 00:00 تا 2026-08-14 23:59' ), 'Previous-day report did not use exact calendar-day boundaries.' );
jlwi_test_assert( false !== strpos( $previous_day_message, 'فروش روز گذشته: 3,000 IRR' ), 'Previous-day sales are incorrect.' );
jlwi_test_assert( false !== strpos( $previous_day_message, '+50.0%' ), 'Previous-day comparison is incorrect.' );
jlwi_test_assert( false !== strpos( $previous_day_message, 'تعداد سفارش‌های روز گذشته: 2' ), 'Previous-day order count is incorrect.' );

$GLOBALS['jlwi_test_options'][ JLWI_OPTION ]['daily_report_sections'] = array( 'orders' );
$orders_only = $report->build_message();
jlwi_test_assert( false !== strpos( $orders_only, 'تعداد سفارش‌ها: 5' ), 'Selected order section was omitted.' );
jlwi_test_assert( false === strpos( $orders_only, 'فروش امروز:' ), 'Disabled sales section was rendered.' );
jlwi_test_assert( false === strpos( $orders_only, 'محصولات ناموجودشده' ), 'Disabled inventory section was rendered.' );

$GLOBALS['jlwi_test_options'][ JLWI_OPTION ]['daily_report_sections'] = array( 'inventory_attention' );
$inventory_only = $report->build_message();
jlwi_test_assert( false !== strpos( $inventory_only, 'Product 10 — ناموجود' ), 'Inventory-only report did not load interval orders.' );

$GLOBALS['jlwi_test_options'][ JLWI_OPTION ]['daily_report_sections'] = array( 'new_customers' );
$customers_only = $report->build_message();
jlwi_test_assert( false !== strpos( $customers_only, 'مشتری جدید: 1' ), 'Customer-only report did not load paid orders.' );

$GLOBALS['jlwi_test_options'][ JLWI_OPTION ]['daily_report_sections'] = $settings['daily_report_sections'];
$delivery = $report->send_now( 'last_24_hours' );
jlwi_test_assert( ! is_wp_error( $delivery ), 'Daily report delivery returned an error.' );
jlwi_test_assert( 2 === $delivery['sent'], 'Daily report recipients were not normalized and deduplicated.' );
jlwi_test_assert( 2 === count( $GLOBALS['jlwi_test_messages'] ), 'Unexpected daily report message count.' );
foreach ( $GLOBALS['jlwi_test_messages'] as $sent_message ) {
	jlwi_test_assert( '989111111111' !== $sent_message[0], 'Daily report was sent to an invoice recipient.' );
}
jlwi_test_assert( false !== strpos( $delivery['message'], 'گزارش ۲۴ ساعت گذشته فروشگاه' ), 'Immediate delivery did not use the rolling report.' );
jlwi_test_assert( 'last_24_hours' === $GLOBALS['jlwi_test_options'][ JLWI_REPORT_STATE_OPTION ]['period'], 'Last report state did not retain the rolling period.' );

$GLOBALS['jlwi_test_options'][ JLWI_OPTION ]['daily_report_full_previous_day'] = 'yes';
$GLOBALS['jlwi_test_messages'] = array();
$report->run_scheduled_report();
jlwi_test_assert( 2 === count( $GLOBALS['jlwi_test_messages'] ), 'Scheduled previous-day report was not delivered.' );
jlwi_test_assert( false !== strpos( $GLOBALS['jlwi_test_messages'][0][1], 'گزارش کامل روز گذشته فروشگاه' ), 'Scheduled report ignored the complete previous-day setting.' );
jlwi_test_assert( 'previous_day' === $GLOBALS['jlwi_test_options'][ JLWI_REPORT_STATE_OPTION ]['period'], 'Scheduled report state did not retain the previous-day period.' );
jlwi_test_assert( 'success' === $GLOBALS['jlwi_test_options']['jlwi_daily_report_run_previous_20260814']['status'], 'Scheduled previous-day run marker was not completed.' );

$report->run_scheduled_report();
jlwi_test_assert( 2 === count( $GLOBALS['jlwi_test_messages'] ), 'Duplicate previous-day cron execution sent the report again.' );

$GLOBALS['jlwi_test_options'][ JLWI_OPTION ]['daily_report_full_previous_day'] = 'no';
$GLOBALS['jlwi_test_messages'] = array();
$report->run_scheduled_report();
jlwi_test_assert( false !== strpos( $GLOBALS['jlwi_test_messages'][0][1], 'گزارش ۲۴ ساعت گذشته فروشگاه' ), 'Scheduled report did not retain the rolling 24-hour mode.' );
jlwi_test_assert( 'last_24_hours' === $GLOBALS['jlwi_test_options'][ JLWI_REPORT_STATE_OPTION ]['period'], 'Scheduled rolling report state is incorrect.' );
jlwi_test_assert( 'success' === $GLOBALS['jlwi_test_options']['jlwi_daily_report_run_rolling_20260815']['status'], 'Scheduled rolling run marker was not completed.' );

$report->run_scheduled_report();
jlwi_test_assert( 2 === count( $GLOBALS['jlwi_test_messages'] ), 'Duplicate rolling cron execution sent the report again.' );

$manual_after_cron = $report->send_now( 'last_24_hours' );
jlwi_test_assert( ! is_wp_error( $manual_after_cron ), 'Manual report was blocked by the scheduled-run marker.' );
jlwi_test_assert( 4 === count( $GLOBALS['jlwi_test_messages'] ), 'Manual report did not remain available after scheduled delivery.' );

$GLOBALS['jlwi_test_options'][ JLWI_OPTION ]['daily_report_time'] = '21:15';
JLWI_Daily_Report::reschedule();
jlwi_test_assert( false !== $GLOBALS['jlwi_test_cron'], 'Daily report cron event was not scheduled.' );
jlwi_test_assert( '21:15' === wp_date( 'H:i', $GLOBALS['jlwi_test_cron'], wp_timezone() ), 'Cron event did not preserve the configured local time.' );

$GLOBALS['jlwi_test_options'][ JLWI_OPTION ]['report_recipients'] = '';
JLWI_Daily_Report::reschedule();
jlwi_test_assert( false === $GLOBALS['jlwi_test_cron'], 'Daily report cron remained scheduled without report recipients.' );

fwrite( STDOUT, "Daily report smoke test passed.\n" );
