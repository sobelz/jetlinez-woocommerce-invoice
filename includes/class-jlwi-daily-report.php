<?php
/**
 * Daily WooCommerce report generation and WhatsApp delivery.
 *
 * @package JetlinezWooCommerceInvoice
 */

defined( 'ABSPATH' ) || exit;

final class JLWI_Daily_Report {

	const CRON_HOOK = 'jlwi_send_daily_report';
	const LOCK_KEY  = 'jlwi_daily_report_lock';

	/**
	 * Register report and scheduling hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_report' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 30 );
	}

	/**
	 * Run the due report and create the next local-time event.
	 *
	 * @return void
	 */
	public function run_scheduled_report() {
		if ( ! self::schedule_enabled() ) {
			self::clear_schedule();
			return;
		}

		self::schedule_next();

		$result = $this->send_now();
		if ( is_wp_error( $result ) ) {
			$this->log(
				'error',
				'Daily WhatsApp report failed.',
				array(
					'error_code' => $result->get_error_code(),
					'error'      => $result->get_error_message(),
				)
			);
			return;
		}

		$level = empty( $result['failed'] ) ? 'info' : 'warning';
		$this->log(
			$level,
			'Daily WhatsApp report finished.',
			array(
				'sent'   => (int) $result['sent'],
				'failed' => (int) $result['failed'],
			)
		);
	}

	/**
	 * Ensure that exactly one future event exists for the configured local time.
	 *
	 * @return void
	 */
	public static function ensure_schedule() {
		if ( ! self::schedule_enabled() ) {
			if ( false !== wp_next_scheduled( self::CRON_HOOK ) ) {
				self::clear_schedule();
			}
			return;
		}

		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $next && self::scheduled_time_matches( $next ) ) {
			return;
		}

		self::clear_schedule();
		self::schedule_next();
	}

	/**
	 * Clear existing report events and schedule from current settings.
	 *
	 * @return void
	 */
	public static function reschedule() {
		self::clear_schedule();
		if ( self::schedule_enabled() ) {
			self::schedule_next();
		}
	}

	/**
	 * Remove all report cron events.
	 *
	 * @return void
	 */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Return the next report event timestamp, if one exists.
	 *
	 * @return int|false
	 */
	public static function next_scheduled() {
		return wp_next_scheduled( self::CRON_HOOK );
	}

	/**
	 * Send the current report to the configured fixed admin recipients.
	 *
	 * This method is also used by the manual test action. It intentionally does
	 * not require the schedule toggle, so an administrator can test before
	 * enabling daily delivery.
	 *
	 * @param string $period Report period: today or last_24_hours.
	 * @return array|WP_Error Delivery counters or error.
	 */
	public function send_now( $period = 'today' ) {
		$period = $this->normalize_period( $period );

		if ( get_transient( self::LOCK_KEY ) ) {
			return new WP_Error( 'jlwi_report_locked', __( 'یک گزارش روزانه دیگر در حال پردازش است.', JLWI_TEXT_DOMAIN ) );
		}

		set_transient( self::LOCK_KEY, '1', 10 * MINUTE_IN_SECONDS );

		try {
			$client = new JLWI_API_Client();
			$config = $client->validate_configuration();
			if ( is_wp_error( $config ) ) {
				$this->store_result( 'failed', 0, 0, $config->get_error_message(), $period );
				return $config;
			}

			$recipients = self::admin_recipients();
			if ( empty( $recipients ) ) {
				$error = new WP_Error( 'jlwi_report_no_recipients', __( 'برای گزارش روزانه هیچ شماره ادمین معتبری تنظیم نشده است.', JLWI_TEXT_DOMAIN ) );
				$this->store_result( 'failed', 0, 0, $error->get_error_message(), $period );
				return $error;
			}

			$message = $this->build_message( $period );
			if ( is_wp_error( $message ) ) {
				$this->store_result( 'failed', 0, 0, $message->get_error_message(), $period );
				return $message;
			}

			$sent   = 0;
			$failed = 0;
			$errors = array();
			foreach ( $recipients as $phone ) {
				$response = $client->send_message( $phone, $message );
				if ( is_wp_error( $response ) ) {
					++$failed;
					$errors[] = $response->get_error_message();
					continue;
				}

				++$sent;
			}

			$status = 0 === $failed ? 'success' : ( $sent > 0 ? 'partial' : 'failed' );
			$error  = empty( $errors ) ? '' : implode( ' | ', array_unique( $errors ) );
			$this->store_result( $status, $sent, $failed, $error, $period );

			if ( 0 === $sent ) {
				return new WP_Error(
					'jlwi_report_delivery_failed',
					'' !== $error ? $error : __( 'ارسال گزارش روزانه ناموفق بود.', JLWI_TEXT_DOMAIN )
				);
			}

			return array(
				'sent'    => $sent,
				'failed'  => $failed,
				'message' => $message,
			);
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Generate a plain-text daily report from current WooCommerce data.
	 *
	 * @param string $period Report period: today or last_24_hours.
	 * @return string|WP_Error Message or query error.
	 */
	public function build_message( $period = 'today' ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'jlwi_report_woocommerce_missing', __( 'برای ساخت گزارش روزانه، WooCommerce باید فعال باشد.', JLWI_TEXT_DOMAIN ) );
		}

		$period   = $this->normalize_period( $period );
		$sections = JLWI_Settings::sanitize_report_sections( JLWI_Settings::get( 'daily_report_sections', array() ) );
		if ( empty( $sections ) ) {
			return new WP_Error( 'jlwi_report_no_sections', __( 'حداقل یک بخش برای گزارش روزانه انتخاب کنید.', JLWI_TEXT_DOMAIN ) );
		}

		$now = current_datetime();
		if ( 'last_24_hours' === $period ) {
			$today_start     = $now->setTimestamp( $now->getTimestamp() - DAY_IN_SECONDS );
			$yesterday_start = $today_start->setTimestamp( $today_start->getTimestamp() - DAY_IN_SECONDS );
			$yesterday_end   = $today_start->modify( '-1 second' );
		} else {
			$today_start         = $now->setTime( 0, 0, 0 );
			$elapsed_seconds     = max( 0, $now->getTimestamp() - $today_start->getTimestamp() );
			$yesterday_start     = $today_start->modify( '-1 day' );
			$yesterday_end       = $yesterday_start->modify( '+' . $elapsed_seconds . ' seconds' );
		}

		$order_sections      = array( 'sales', 'orders', 'average_order', 'new_customers', 'problem_orders' );
		$needs_today_orders  = ! empty( array_intersect( $sections, $order_sections ) );
		$today_orders        = $needs_today_orders ? $this->orders_between( $today_start, $now ) : array();
		$yesterday_orders    = in_array( 'sales', $sections, true ) ? $this->orders_between( $yesterday_start, $yesterday_end ) : array();

		$today     = $this->summarize_orders( $today_orders, $now );
		$yesterday = $this->summarize_orders( $yesterday_orders, $yesterday_end );
		$data      = array(
			'period'              => $period,
			'generated_at'        => $now,
			'today_start'        => $today_start,
			'yesterday_start'    => $yesterday_start,
			'yesterday_end'      => $yesterday_end,
			'today'              => $today,
			'yesterday'          => $yesterday,
			'new_customers'      => in_array( 'new_customers', $sections, true )
				? JLWI_Report_Customers::mix( $today_orders, $today_start, 'daily' )['new']
				: 0,
			'inventory_attention' => in_array( 'inventory_attention', $sections, true ) ? $this->inventory_attention() : array(),
		);

		/**
		 * Filter calculated daily-report data before rendering.
		 *
		 * @param array $data Calculated report values.
		 */
		$filtered_data = apply_filters( 'jlwi_daily_report_data', $data );
		if ( is_array( $filtered_data ) ) {
			$data = $filtered_data;
		}

		$message = $this->render_message( $data, $sections );

		/**
		 * Filter the final daily-report text.
		 *
		 * @param string $message  Rendered report text.
		 * @param array  $data     Calculated report values.
		 * @param array  $sections Enabled section keys.
		 */
		return trim( (string) apply_filters( 'jlwi_daily_report_message', $message, $data, $sections ) );
	}

	/**
	 * Return normalized fixed admin recipients.
	 *
	 * @return string[]
	 */
	public static function admin_recipients() {
		$raw = preg_split( '/[\r\n,;،؛]+/u', (string) JLWI_Settings::get( 'fixed_recipients', '' ) );
		$raw = is_array( $raw ) ? $raw : array();

		/**
		 * Filter raw daily-report recipients before phone normalization.
		 *
		 * @param array $raw Raw fixed-recipient values.
		 */
		$raw          = apply_filters( 'jlwi_daily_report_recipients', $raw );
		$raw          = is_array( $raw ) ? $raw : array();
		$country_code = (string) JLWI_Settings::get( 'default_country_code', '98' );
		$recipients   = array();

		foreach ( $raw as $number ) {
			$phone = JLWI_Sender::normalize_phone( $number, $country_code );
			if ( '' !== $phone ) {
				$recipients[ $phone ] = $phone;
			}
		}

		$max = max( 1, (int) apply_filters( 'jlwi_max_daily_report_recipients', 100 ) );
		return array_slice( array_values( $recipients ), 0, $max );
	}

	/**
	 * Schedule the next occurrence in the WordPress site timezone.
	 *
	 * @return void
	 */
	private static function schedule_next() {
		if ( false !== wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		$time = JLWI_Settings::sanitize_report_time( JLWI_Settings::get( 'daily_report_time', '20:00' ) );
		$now  = current_datetime();
		$next = new DateTimeImmutable( $now->format( 'Y-m-d' ) . ' ' . $time . ':00', wp_timezone() );

		if ( $next->getTimestamp() <= $now->getTimestamp() ) {
			$next = $next->modify( '+1 day' );
		}

		$result = wp_schedule_single_event( $next->getTimestamp(), self::CRON_HOOK, array(), true );
		if ( is_wp_error( $result ) && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				'Daily report cron scheduling failed.',
				array(
					'source'     => 'jetlinez-invoice',
					'error_code' => $result->get_error_code(),
					'error'      => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * Whether the saved settings describe a meaningful scheduled report.
	 *
	 * @return bool
	 */
	private static function schedule_enabled() {
		$sections = JLWI_Settings::sanitize_report_sections( JLWI_Settings::get( 'daily_report_sections', array() ) );
		return JLWI_Settings::enabled( 'daily_report_enabled' ) && ! empty( $sections );
	}

	/**
	 * Check that an existing timestamp still represents the configured clock time.
	 *
	 * @param int $timestamp Event timestamp.
	 * @return bool
	 */
	private static function scheduled_time_matches( $timestamp ) {
		$expected = JLWI_Settings::sanitize_report_time( JLWI_Settings::get( 'daily_report_time', '20:00' ) );
		return $expected === wp_date( 'H:i', (int) $timestamp, wp_timezone() );
	}

	/**
	 * Load orders using the WooCommerce CRUD query for HPOS compatibility.
	 *
	 * @param DateTimeInterface $start Inclusive start.
	 * @param DateTimeInterface $end   Inclusive end.
	 * @return array
	 */
	private function orders_between( $start, $end ) {
		$orders = array();
		$page   = 1;

		do {
			$args = array(
				'type'         => 'shop_order',
				'date_created' => $start->getTimestamp() . '...' . $end->getTimestamp(),
				'limit'        => 200,
				'page'         => $page,
				'paginate'     => true,
				'orderby'      => 'date',
				'order'        => 'ASC',
				'return'       => 'objects',
			);

			/**
			 * Filter the CRUD query used to load orders for a report interval.
			 *
			 * @param array             $args  WC_Order_Query arguments.
			 * @param DateTimeInterface $start Interval start.
			 * @param DateTimeInterface $end   Interval end.
			 */
			$args   = apply_filters( 'jlwi_daily_report_order_query_args', $args, $start, $end );
			$result = wc_get_orders( is_array( $args ) ? $args : array() );

			if ( is_array( $result ) ) {
				$orders = array_merge( $orders, $result );
				break;
			}

			$batch = is_object( $result ) && isset( $result->orders ) && is_array( $result->orders ) ? $result->orders : array();
			$orders = array_merge( $orders, $batch );
			$pages  = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;
			++$page;
		} while ( $page <= $pages );

		return $orders;
	}

	/**
	 * Calculate report metrics for one collection of orders.
	 *
	 * @param array             $orders Orders.
	 * @param DateTimeInterface $now    Interval end for abandoned-order cutoff.
	 * @return array
	 */
	private function summarize_orders( $orders, $now ) {
		$currency      = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		$paid_statuses = JLWI_Report_Customers::paid_statuses();
		$hold_minutes  = (int) get_option( 'woocommerce_hold_stock_minutes', 60 );
		$hold_minutes  = $hold_minutes > 0 ? $hold_minutes : 60;
		$hold_minutes  = max( 1, (int) apply_filters( 'jlwi_daily_report_abandoned_after_minutes', $hold_minutes ) );
		$abandoned_before = $now->getTimestamp() - ( $hold_minutes * MINUTE_IN_SECONDS );
		$summary       = array(
			'order_count'       => 0,
			'sales_totals'      => array( $currency => 0.0 ),
			'paid_order_counts' => array( $currency => 0 ),
			'cancelled_count'   => 0,
			'refunded_count'    => 0,
			'abandoned_count'   => 0,
		);

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) ) {
				continue;
			}

			++$summary['order_count'];
			$status         = JLWI_Settings::normalize_status( $order->get_status() );
			$order_currency = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : $currency;
			$order_currency = '' !== $order_currency ? $order_currency : $currency;

			if ( ! isset( $summary['sales_totals'][ $order_currency ] ) ) {
				$summary['sales_totals'][ $order_currency ]      = 0.0;
				$summary['paid_order_counts'][ $order_currency ] = 0;
			}

			$refunded_total = method_exists( $order, 'get_total_refunded' ) ? (float) $order->get_total_refunded() : 0.0;
			if ( in_array( $status, $paid_statuses, true ) ) {
				$total = method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0;
				$summary['sales_totals'][ $order_currency ] += max( 0, $total - $refunded_total );
				++$summary['paid_order_counts'][ $order_currency ];
			}

			if ( 'cancelled' === $status ) {
				++$summary['cancelled_count'];
			}
			if ( 'refunded' === $status || $refunded_total > 0 ) {
				++$summary['refunded_count'];
			}

			$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
			$old_pending = 'pending' === $status && $created && $created->getTimestamp() <= $abandoned_before;
			if ( 'failed' === $status || $old_pending ) {
				++$summary['abandoned_count'];
			}
		}

		return $summary;
	}

	/**
	 * Find unavailable, backordered, and low-stock products from WooCommerce's
	 * product lookup table. Product storage is not affected by order HPOS.
	 *
	 * @return array
	 */
	private function inventory_attention() {
		global $wpdb;

		$empty = array( 'total' => 0, 'items' => array() );
		if ( ! is_object( $wpdb ) || empty( $wpdb->posts ) || empty( $wpdb->postmeta ) ) {
			$filtered_empty = apply_filters( 'jlwi_daily_report_inventory_attention', $empty );
			return is_array( $filtered_empty ) ? $filtered_empty : $empty;
		}

		$lookup_table = ! empty( $wpdb->wc_product_meta_lookup )
			? $wpdb->wc_product_meta_lookup
			: ( ! empty( $wpdb->prefix ) ? $wpdb->prefix . 'wc_product_meta_lookup' : '' );
		if ( '' === $lookup_table ) {
			$filtered_empty = apply_filters( 'jlwi_daily_report_inventory_attention', $empty );
			return is_array( $filtered_empty ) ? $filtered_empty : $empty;
		}

		$global_low = max( 0, (float) get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
		$limit      = max( 1, min( 20, (int) apply_filters( 'jlwi_daily_report_inventory_limit', 5 ) ) );
		$threshold  = $wpdb->prepare(
			"CASE WHEN low_stock.meta_value IS NOT NULL AND low_stock.meta_value <> '' THEN CAST(low_stock.meta_value AS DECIMAL(20,4)) ELSE %f END",
			$global_low
		);
		$where      = "FROM {$lookup_table} lookup
			INNER JOIN {$wpdb->posts} products ON products.ID = lookup.product_id
			LEFT JOIN {$wpdb->postmeta} low_stock ON low_stock.post_id = lookup.product_id AND low_stock.meta_key = '_low_stock_amount'
			WHERE products.post_type IN ('product', 'product_variation')
			AND products.post_status IN ('publish', 'private')
			AND (
				lookup.stock_status IN ('outofstock', 'onbackorder')
				OR (
					lookup.stock_status = 'instock'
					AND lookup.stock_quantity IS NOT NULL
					AND lookup.stock_quantity <= {$threshold}
				)
			)";

		$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT lookup.product_id) {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$sql   = $wpdb->prepare(
			"SELECT DISTINCT lookup.product_id, lookup.stock_quantity, lookup.stock_status {$where}
			ORDER BY FIELD(lookup.stock_status, 'outofstock', 'onbackorder', 'instock'), lookup.stock_quantity ASC
			LIMIT %d",
			$limit
		);
		$rows  = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$items = array();

		foreach ( (array) $rows as $row ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $row['product_id'] ) : false;
			$name    = $product && method_exists( $product, 'get_name' ) ? $product->get_name() : get_the_title( (int) $row['product_id'] );
			if ( '' === trim( (string) $name ) ) {
				continue;
			}

			$items[] = array(
				'id'             => (int) $row['product_id'],
				'name'           => wp_strip_all_tags( (string) $name ),
				'stock_status'   => sanitize_key( (string) $row['stock_status'] ),
				'stock_quantity' => null === $row['stock_quantity'] ? null : (float) $row['stock_quantity'],
			);
		}

		$attention = array( 'total' => $total, 'items' => $items );
		$filtered  = apply_filters( 'jlwi_daily_report_inventory_attention', $attention );
		return is_array( $filtered ) ? $filtered : $attention;
	}

	/**
	 * Render calculated report data.
	 *
	 * @param array $data     Report data.
	 * @param array $sections Enabled sections.
	 * @return string
	 */
	private function render_message( $data, $sections ) {
		$now       = $data['generated_at'];
		$today     = $data['today'];
		$yesterday = $data['yesterday'];
		$period    = isset( $data['period'] ) ? $this->normalize_period( $data['period'] ) : 'today';
		$is_rolling = 'last_24_hours' === $period;
		$enabled   = array_fill_keys( $sections, true );
		$lines     = array(
			'📊 *' . ( $is_rolling ? __( 'گزارش ۲۴ ساعت گذشته فروشگاه', JLWI_TEXT_DOMAIN ) : __( 'گزارش روزانه فروشگاه', JLWI_TEXT_DOMAIN ) ) . '*',
			'🏪 ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'🗓 ' . wp_date( get_option( 'date_format' ) . ' — ' . get_option( 'time_format' ), $now->getTimestamp(), wp_timezone() ),
		);
		if ( $is_rolling ) {
			$lines[] = sprintf(
				'⏱ ' . __( 'بازه: %1$s تا %2$s', JLWI_TEXT_DOMAIN ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $data['today_start']->getTimestamp(), wp_timezone() ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $now->getTimestamp(), wp_timezone() )
			);
		}
		$content_count = 0;

		if ( isset( $enabled['sales'] ) ) {
			$lines[] = '';
			$currencies = array_unique( array_merge( array_keys( $today['sales_totals'] ), array_keys( $yesterday['sales_totals'] ) ) );
			if ( 1 === count( $currencies ) ) {
				$currency = reset( $currencies );
				$current  = isset( $today['sales_totals'][ $currency ] ) ? (float) $today['sales_totals'][ $currency ] : 0.0;
				$previous = isset( $yesterday['sales_totals'][ $currency ] ) ? (float) $yesterday['sales_totals'][ $currency ] : 0.0;
				$sales_label  = $is_rolling ? __( 'فروش ۲۴ ساعت گذشته:', JLWI_TEXT_DOMAIN ) : __( 'فروش امروز:', JLWI_TEXT_DOMAIN );
				$change_label = $is_rolling ? __( 'تغییر نسبت به ۲۴ ساعت قبل:', JLWI_TEXT_DOMAIN ) : __( 'تغییر نسبت به بازه مشابه دیروز:', JLWI_TEXT_DOMAIN );
				$lines[]      = '💰 ' . $sales_label . ' ' . $this->format_money( $current, $currency );
				$lines[]      = '📈 ' . $change_label . ' ' . $this->format_change( $current, $previous );
			} else {
				$lines[] = '💰 ' . ( $is_rolling ? __( 'فروش ۲۴ ساعت گذشته و تغییر نسبت به ۲۴ ساعت قبل:', JLWI_TEXT_DOMAIN ) : __( 'فروش امروز و تغییر نسبت به دیروز:', JLWI_TEXT_DOMAIN ) );
				foreach ( $currencies as $currency ) {
					$current  = isset( $today['sales_totals'][ $currency ] ) ? (float) $today['sales_totals'][ $currency ] : 0.0;
					$previous = isset( $yesterday['sales_totals'][ $currency ] ) ? (float) $yesterday['sales_totals'][ $currency ] : 0.0;
					$lines[]  = '• ' . $this->format_money( $current, $currency ) . ' (' . $this->format_change( $current, $previous ) . ')';
				}
			}
			++$content_count;
		}

		if ( isset( $enabled['orders'] ) ) {
			$lines[] = '';
			$order_label = $is_rolling ? __( 'تعداد سفارش‌های ۲۴ ساعت گذشته:', JLWI_TEXT_DOMAIN ) : __( 'تعداد سفارش‌ها:', JLWI_TEXT_DOMAIN );
			$lines[] = '🧾 ' . $order_label . ' ' . number_format_i18n( (int) $today['order_count'] );
			++$content_count;
		}

		if ( isset( $enabled['average_order'] ) ) {
			$lines[] = '';
			$averages = array();
			foreach ( $today['sales_totals'] as $currency => $total ) {
				$count = isset( $today['paid_order_counts'][ $currency ] ) ? (int) $today['paid_order_counts'][ $currency ] : 0;
				$averages[] = $this->format_money( $count > 0 ? (float) $total / $count : 0, $currency );
			}
			$lines[] = '📦 ' . __( 'میانگین مبلغ سفارش پرداخت‌شده:', JLWI_TEXT_DOMAIN ) . ' ' . implode( ' | ', $averages );
			++$content_count;
		}

		if ( isset( $enabled['new_customers'] ) ) {
			$lines[] = '';
			$customer_label = $is_rolling ? __( 'مشتری جدید در ۲۴ ساعت گذشته:', JLWI_TEXT_DOMAIN ) : __( 'مشتری جدید:', JLWI_TEXT_DOMAIN );
			$lines[] = '👤 ' . $customer_label . ' ' . number_format_i18n( (int) $data['new_customers'] );
			++$content_count;
		}

		if ( isset( $enabled['problem_orders'] ) ) {
			$lines[] = '';
			$lines[] = '⚠️ *' . __( 'سفارش‌های نیازمند بررسی', JLWI_TEXT_DOMAIN ) . '*';
			$lines[] = '• ' . __( 'لغوشده:', JLWI_TEXT_DOMAIN ) . ' ' . number_format_i18n( (int) $today['cancelled_count'] );
			$lines[] = '• ' . __( 'مرجوع/بازپرداخت‌شده:', JLWI_TEXT_DOMAIN ) . ' ' . number_format_i18n( (int) $today['refunded_count'] );
			$lines[] = '• ' . __( 'رهاشده:', JLWI_TEXT_DOMAIN ) . ' ' . number_format_i18n( (int) $today['abandoned_count'] );
			++$content_count;
		}

		$attention = isset( $data['inventory_attention'] ) && is_array( $data['inventory_attention'] ) ? $data['inventory_attention'] : array();
		if ( isset( $enabled['inventory_attention'] ) && ! empty( $attention['items'] ) ) {
			$lines[] = '';
			$lines[] = '📉 *' . __( 'موجودی نیازمند توجه', JLWI_TEXT_DOMAIN ) . '*';
			foreach ( $attention['items'] as $item ) {
				$status = isset( $item['stock_status'] ) ? $item['stock_status'] : '';
				if ( 'outofstock' === $status ) {
					$detail = __( 'ناموجود', JLWI_TEXT_DOMAIN );
				} elseif ( 'onbackorder' === $status ) {
					$detail = __( 'در حالت پیش‌سفارش', JLWI_TEXT_DOMAIN );
				} else {
					$quantity = isset( $item['stock_quantity'] ) ? $this->format_quantity( $item['stock_quantity'] ) : '0';
					$detail   = sprintf( __( 'موجودی: %s', JLWI_TEXT_DOMAIN ), $quantity );
				}
				$lines[] = '• ' . $item['name'] . ' — ' . $detail;
			}

			$total = isset( $attention['total'] ) ? (int) $attention['total'] : count( $attention['items'] );
			$more  = $total - count( $attention['items'] );
			if ( $more > 0 ) {
				$lines[] = sprintf( __( '• و %s مورد دیگر', JLWI_TEXT_DOMAIN ), number_format_i18n( $more ) );
			}
			++$content_count;
		}

		if ( 0 === $content_count ) {
			$lines[] = '';
			$lines[] = '✅ ' . __( 'در بخش‌های انتخاب‌شده موردی برای گزارش وجود ندارد.', JLWI_TEXT_DOMAIN );
		}

		if ( isset( $enabled['sales'] ) ) {
			$lines[] = '';
			$lines[] = 'ℹ️ ' . ( $is_rolling
				? __( 'ارقام با ۲۴ ساعت بلافاصله قبل از بازه گزارش مقایسه شده‌اند.', JLWI_TEXT_DOMAIN )
				: __( 'ارقام امروز تا زمان گزارش و مقایسه با همین بازه زمانی در دیروز هستند.', JLWI_TEXT_DOMAIN ) );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Format a monetary amount without HTML.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private function format_money( $amount, $currency ) {
		if ( function_exists( 'wc_price' ) ) {
			$value = wc_price( (float) $amount, array( 'currency' => (string) $currency ) );
			$value = wp_strip_all_tags( $value );
			return trim( html_entity_decode( $value, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' ) );
		}

		return number_format_i18n( (float) $amount, 0 ) . ' ' . (string) $currency;
	}

	/**
	 * Format percentage change while handling a zero comparison base.
	 *
	 * @param float $current  Current value.
	 * @param float $previous Previous value.
	 * @return string
	 */
	private function format_change( $current, $previous ) {
		if ( 0.0 === (float) $previous ) {
			return (float) $current > 0
				? __( 'فروش جدید (دیروز صفر)', JLWI_TEXT_DOMAIN )
				: __( 'بدون تغییر', JLWI_TEXT_DOMAIN );
		}

		$change = ( ( (float) $current - (float) $previous ) / abs( (float) $previous ) ) * 100;
		$prefix = $change > 0 ? '+' : '';
		return $prefix . number_format_i18n( $change, 1 ) . '%';
	}

	/**
	 * Format stock quantity with at most two decimal places.
	 *
	 * @param float $quantity Quantity.
	 * @return string
	 */
	private function format_quantity( $quantity ) {
		$decimals = floor( (float) $quantity ) === (float) $quantity ? 0 : 2;
		return number_format_i18n( (float) $quantity, $decimals );
	}

	/**
	 * Normalize a supported report period.
	 *
	 * @param mixed $period Period key.
	 * @return string
	 */
	private function normalize_period( $period ) {
		$period = is_scalar( $period ) ? sanitize_key( (string) $period ) : '';
		return 'last_24_hours' === $period ? 'last_24_hours' : 'today';
	}

	/**
	 * Store the last execution summary for the settings screen.
	 *
	 * @param string $status Status.
	 * @param int    $sent   Successful recipients.
	 * @param int    $failed Failed recipients.
	 * @param string $error  Error summary.
	 * @param string $period Report period.
	 * @return void
	 */
	private function store_result( $status, $sent, $failed, $error = '', $period = 'today' ) {
		update_option(
			JLWI_REPORT_STATE_OPTION,
			array(
				'timestamp' => time(),
				'status'    => sanitize_key( (string) $status ),
				'sent'      => max( 0, (int) $sent ),
				'failed'    => max( 0, (int) $failed ),
				'error'     => sanitize_text_field( (string) $error ),
				'period'    => $this->normalize_period( $period ),
			),
			false
		);
	}

	/**
	 * Write a WooCommerce log entry when logging is available.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	private function log( $level, $message, $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger            = wc_get_logger();
		$context['source'] = 'jetlinez-invoice';
		if ( is_callable( array( $logger, $level ) ) ) {
			$logger->{$level}( $message, $context );
		} else {
			$logger->log( $level, $message, $context );
		}
	}
}
