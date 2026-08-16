<?php
/**
 * Weekly WooCommerce report generation and WhatsApp delivery.
 *
 * @package JetlinezWooCommerceInvoice
 */

defined( 'ABSPATH' ) || exit;

final class JLWI_Weekly_Report {

	const CRON_HOOK = 'jlwi_send_weekly_report';
	const LOCK_KEY  = 'jlwi_weekly_report_lock';

	/** @var array */
	private $last_report_data = array();

	/**
	 * Register report and scheduling hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_report' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 31 );
	}

	/**
	 * Send a scheduled report and create the next local-time event.
	 *
	 * @return void
	 */
	public function run_scheduled_report() {
		if ( ! self::schedule_enabled() ) {
			self::clear_schedule();
			return;
		}

		self::schedule_next();
		$result = $this->send_now( 'scheduled' );
		if ( is_wp_error( $result ) ) {
			$this->log(
				'error',
				'Weekly WhatsApp report failed.',
				array(
					'error_code' => $result->get_error_code(),
					'error'      => $result->get_error_message(),
				)
			);
			return;
		}

		$this->log(
			empty( $result['failed'] ) ? 'info' : 'warning',
			'Weekly WhatsApp report finished.',
			array(
				'sent'   => (int) $result['sent'],
				'failed' => (int) $result['failed'],
			)
		);
	}

	/**
	 * Ensure exactly one future event exists for the selected weekday and time.
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
	 * Rebuild the report schedule from current settings.
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
	 * Remove all weekly-report cron events.
	 *
	 * @return void
	 */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Return the next weekly report timestamp.
	 *
	 * @return int|false
	 */
	public static function next_scheduled() {
		return wp_next_scheduled( self::CRON_HOOK );
	}

	/**
	 * Send the report for the last seven complete calendar days.
	 *
	 * This intentionally works while automatic scheduling is disabled, allowing
	 * administrators to preview and send the real report on demand.
	 *
	 * @param string $context Delivery context: manual or scheduled.
	 * @return array|WP_Error Delivery counters or error.
	 */
	public function send_now( $context = 'manual' ) {
		$context = 'scheduled' === sanitize_key( (string) $context ) ? 'scheduled' : 'manual';
		if ( get_transient( self::LOCK_KEY ) ) {
			return new WP_Error( 'jlwi_weekly_report_locked', __( 'یک گزارش هفتگی دیگر در حال پردازش است.', JLWI_TEXT_DOMAIN ) );
		}

		set_transient( self::LOCK_KEY, '1', 20 * MINUTE_IN_SECONDS );

		try {
			$client = new JLWI_API_Client();
			$config = $client->validate_configuration();
			if ( is_wp_error( $config ) ) {
				$this->store_result( 'failed', 0, 0, $config->get_error_message() );
				return $config;
			}

			$recipients = self::admin_recipients();
			if ( empty( $recipients ) ) {
				$error = new WP_Error( 'jlwi_weekly_report_no_recipients', __( 'برای گزارش هفتگی هیچ شماره ادمین معتبری تنظیم نشده است.', JLWI_TEXT_DOMAIN ) );
				$this->store_result( 'failed', 0, 0, $error->get_error_message() );
				return $error;
			}

			$message = $this->build_message( $context );
			if ( is_wp_error( $message ) ) {
				$this->store_result( 'failed', 0, 0, $message->get_error_message() );
				return $message;
			}

			$sent                  = 0;
			$failed                = 0;
			$errors                = array();
			$successful_recipients = array();
			foreach ( $recipients as $phone ) {
				$response = $client->send_message( $phone, $message );
				if ( is_wp_error( $response ) ) {
					++$failed;
					$errors[] = $response->get_error_message();
					continue;
				}

				++$sent;
				$successful_recipients[] = $phone;
			}

			$chart_sent   = 0;
			$chart_failed = 0;
			if ( $sent > 0 && $this->sales_change_chart_enabled() ) {
				try {
					$chart_file = $this->build_change_chart_file( $this->last_report_data );
				} catch ( Throwable $throwable ) {
					$chart_file = new WP_Error( 'jlwi_quickchart_unexpected_error', __( 'ساخت نمودار با یک خطای پیش‌بینی‌نشده متوقف شد.', JLWI_TEXT_DOMAIN ) );
				}
				if ( is_wp_error( $chart_file ) ) {
					$this->log_chart_error( 'Weekly report chart generation skipped; text delivery continued.', $chart_file );
				} else {
					$media_id = '';
					try {
						$upload = $client->upload_media( $chart_file );
						if ( is_wp_error( $upload ) ) {
							$this->log_chart_error( 'Weekly report chart upload failed; text delivery continued.', $upload );
						} else {
							$media_id = isset( $upload['id'] ) ? (string) $upload['id'] : '';
							if ( '' === $media_id ) {
								$this->log( 'warning', 'Weekly report chart upload returned no media ID; text delivery continued.' );
							} else {
								foreach ( $successful_recipients as $phone ) {
									$chart_response = $client->send_message( $phone, '', $media_id );
									if ( is_wp_error( $chart_response ) ) {
										++$chart_failed;
										$this->log_chart_error( 'Weekly report chart send failed; text report was already delivered.', $chart_response );
									} else {
										++$chart_sent;
									}
								}
							}
						}

						if ( '' !== $media_id && JLWI_Settings::enabled( 'delete_remote_media' ) ) {
							$deleted = $client->delete_media( $media_id );
							if ( is_wp_error( $deleted ) ) {
								$this->log_chart_error( 'Could not delete remote weekly report chart.', $deleted );
							}
						}
					} catch ( Throwable $throwable ) {
						$this->log(
							'warning',
							'Unexpected weekly report chart delivery error; text report was already delivered.',
							array( 'exception' => get_class( $throwable ) )
						);
					} finally {
						$this->delete_local_chart( $chart_file );
					}
				}
			}

			$status = 0 === $failed ? 'success' : ( $sent > 0 ? 'partial' : 'failed' );
			$error  = empty( $errors ) ? '' : implode( ' | ', array_unique( $errors ) );
			$this->store_result( $status, $sent, $failed, $error );

			if ( 0 === $sent ) {
				return new WP_Error(
					'jlwi_weekly_report_delivery_failed',
					'' !== $error ? $error : __( 'ارسال گزارش هفتگی ناموفق بود.', JLWI_TEXT_DOMAIN )
				);
			}

			return array(
				'sent'         => $sent,
				'failed'       => $failed,
				'chart_sent'   => $chart_sent,
				'chart_failed' => $chart_failed,
				'message'      => $message,
			);
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Generate the weekly plain-text report from WooCommerce CRUD data.
	 *
	 * @param string $context Delivery context: manual or scheduled.
	 * @return string|WP_Error Message or query error.
	 */
	public function build_message( $context = 'manual' ) {
		$this->last_report_data = array();

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'jlwi_weekly_report_woocommerce_missing', __( 'برای ساخت گزارش هفتگی، WooCommerce باید فعال باشد.', JLWI_TEXT_DOMAIN ) );
		}

		$sections = JLWI_Settings::sanitize_weekly_report_sections( JLWI_Settings::get( 'weekly_report_sections', array() ) );
		if ( empty( $sections ) ) {
			return new WP_Error( 'jlwi_weekly_report_no_sections', __( 'حداقل یک بخش برای گزارش هفتگی انتخاب کنید.', JLWI_TEXT_DOMAIN ) );
		}

		$context         = 'scheduled' === sanitize_key( (string) $context ) ? 'scheduled' : 'manual';
		$now             = current_datetime();
		$period_boundary = $now->setTime( 0, 0, 0 );
		if ( 'scheduled' === $context ) {
			$scheduled_day = JLWI_Settings::sanitize_weekly_report_day( JLWI_Settings::get( 'weekly_report_day', 6 ) );
			$days_since_scheduled_day = ( (int) $period_boundary->format( 'w' ) - $scheduled_day + 7 ) % 7;
			if ( $days_since_scheduled_day > 0 ) {
				$period_boundary = $period_boundary->modify( '-' . $days_since_scheduled_day . ' days' );
			}
		}
		$current_start   = $period_boundary->modify( '-7 days' );
		$current_end     = $period_boundary->modify( '-1 second' );
		$previous_start  = $current_start->modify( '-7 days' );
		$previous_end    = $current_start->modify( '-1 second' );
		$current_orders  = $this->orders_between( $current_start, $current_end );
		$needs_previous  = in_array( 'sales_change', $sections, true ) || in_array( 'product_changes', $sections, true );
		$previous_orders = $needs_previous ? $this->orders_between( $previous_start, $previous_end ) : array();

		$current  = $this->summarize_orders( $current_orders, $current_start, $current_end );
		$previous = $this->summarize_orders( $previous_orders, $previous_start, $previous_end );
		$data     = array(
			'context'       => $context,
			'generated_at'  => $now,
			'current_start' => $current_start,
			'current_end'   => $current_end,
			'previous_start' => $previous_start,
			'previous_end'   => $previous_end,
			'current'        => $current,
			'previous'       => $previous,
			'customer_mix'   => in_array( 'customer_mix', $sections, true )
				? JLWI_Report_Customers::mix( $current_orders, $current_start, 'weekly' )
				: array( 'new' => 0, 'returning' => 0 ),
		);

		/**
		 * Filter calculated weekly-report data before rendering.
		 *
		 * @param array $data Calculated report values.
		 */
		$filtered_data = apply_filters( 'jlwi_weekly_report_data', $data );
		if ( is_array( $filtered_data ) ) {
			$data = $filtered_data;
		}
		$this->last_report_data = $data;

		$message = $this->render_message( $data, $sections );

		/**
		 * Filter the final weekly-report text.
		 *
		 * @param string $message  Rendered report text.
		 * @param array  $data     Calculated report values.
		 * @param array  $sections Enabled section keys.
		 */
		return trim( (string) apply_filters( 'jlwi_weekly_report_message', $message, $data, $sections ) );
	}

	/**
	 * Return normalized fixed admin recipients for weekly reports.
	 *
	 * @return string[]
	 */
	public static function admin_recipients() {
		$raw = preg_split( '/[\r\n,;،؛]+/u', (string) JLWI_Settings::get( 'fixed_recipients', '' ) );
		$raw = is_array( $raw ) ? $raw : array();
		$raw = apply_filters( 'jlwi_weekly_report_recipients', $raw );
		$raw = is_array( $raw ) ? $raw : array();

		$country_code = (string) JLWI_Settings::get( 'default_country_code', '98' );
		$recipients   = array();
		foreach ( $raw as $number ) {
			$phone = JLWI_Sender::normalize_phone( $number, $country_code );
			if ( '' !== $phone ) {
				$recipients[ $phone ] = $phone;
			}
		}

		$max = max( 1, (int) apply_filters( 'jlwi_max_weekly_report_recipients', 100 ) );
		return array_slice( array_values( $recipients ), 0, $max );
	}

	/**
	 * Schedule the next selected weekday and time in the site timezone.
	 *
	 * @return void
	 */
	private static function schedule_next() {
		if ( false !== wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		$day       = JLWI_Settings::sanitize_weekly_report_day( JLWI_Settings::get( 'weekly_report_day', 6 ) );
		$time      = JLWI_Settings::sanitize_report_time( JLWI_Settings::get( 'weekly_report_time', '20:00' ) );
		$now       = current_datetime();
		$day_delta = ( $day - (int) $now->format( 'w' ) + 7 ) % 7;
		$next      = new DateTimeImmutable( $now->format( 'Y-m-d' ) . ' ' . $time . ':00', wp_timezone() );
		if ( $day_delta > 0 ) {
			$next = $next->modify( '+' . $day_delta . ' days' );
		} elseif ( $next->getTimestamp() <= $now->getTimestamp() ) {
			$next = $next->modify( '+7 days' );
		}

		$result = wp_schedule_single_event( $next->getTimestamp(), self::CRON_HOOK, array(), true );
		if ( is_wp_error( $result ) && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				'Weekly report cron scheduling failed.',
				array(
					'source'     => 'jetlinez-invoice',
					'error_code' => $result->get_error_code(),
					'error'      => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * Whether settings describe an enabled weekly report.
	 *
	 * @return bool
	 */
	private static function schedule_enabled() {
		$sections = JLWI_Settings::sanitize_weekly_report_sections( JLWI_Settings::get( 'weekly_report_sections', array() ) );
		return JLWI_Settings::enabled( 'weekly_report_enabled' ) && ! empty( $sections );
	}

	/**
	 * Check an existing event against the selected weekday and time.
	 *
	 * @param int $timestamp Event timestamp.
	 * @return bool
	 */
	private static function scheduled_time_matches( $timestamp ) {
		$expected_day  = JLWI_Settings::sanitize_weekly_report_day( JLWI_Settings::get( 'weekly_report_day', 6 ) );
		$expected_time = JLWI_Settings::sanitize_report_time( JLWI_Settings::get( 'weekly_report_time', '20:00' ) );
		return $expected_day === (int) wp_date( 'w', (int) $timestamp, wp_timezone() )
			&& $expected_time === wp_date( 'H:i', (int) $timestamp, wp_timezone() );
	}

	/**
	 * Load orders through WooCommerce CRUD for HPOS compatibility.
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

			$args   = apply_filters( 'jlwi_weekly_report_order_query_args', $args, $start, $end );
			$result = wc_get_orders( is_array( $args ) ? $args : array() );
			if ( is_array( $result ) ) {
				$orders = array_merge( $orders, $result );
				break;
			}

			$batch  = is_object( $result ) && isset( $result->orders ) && is_array( $result->orders ) ? $result->orders : array();
			$orders = array_merge( $orders, $batch );
			$pages  = is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;
			++$page;
		} while ( $page <= $pages );

		return $orders;
	}

	/**
	 * Calculate monetary, order, daily, and product metrics for an interval.
	 *
	 * @param array             $orders Orders.
	 * @param DateTimeInterface $start  Interval start.
	 * @param DateTimeInterface $end    Interval end.
	 * @return array
	 */
	private function summarize_orders( $orders, $start, $end ) {
		$currency      = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		$paid_statuses = JLWI_Report_Customers::paid_statuses();
		$daily_sales   = array();
		$cursor        = $start;
		while ( $cursor->getTimestamp() <= $end->getTimestamp() ) {
			$daily_sales[ $cursor->format( 'Y-m-d' ) ] = array( $currency => 0.0 );
			$cursor = $cursor->modify( '+1 day' );
		}

		$summary = array(
			'order_count'            => 0,
			'paid_order_counts'      => array( $currency => 0 ),
			'sales_totals'           => array( $currency => 0.0 ),
			'discount_totals'        => array( $currency => 0.0 ),
			'discounted_order_count' => 0,
			'cancelled_count'        => 0,
			'refunded_count'         => 0,
			'failed_count'           => 0,
			'daily_sales'            => $daily_sales,
			'products'               => array(),
		);

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) ) {
				continue;
			}

			++$summary['order_count'];
			$status         = JLWI_Settings::normalize_status( $order->get_status() );
			$order_currency = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : $currency;
			$order_currency = '' !== $order_currency ? $order_currency : $currency;
			$refunded_total = method_exists( $order, 'get_total_refunded' ) ? (float) $order->get_total_refunded() : 0.0;

			if ( 'cancelled' === $status ) {
				++$summary['cancelled_count'];
			}
			if ( 'refunded' === $status || $refunded_total > 0 ) {
				++$summary['refunded_count'];
			}
			if ( 'failed' === $status ) {
				++$summary['failed_count'];
			}

			if ( ! in_array( $status, $paid_statuses, true ) ) {
				continue;
			}

			if ( ! isset( $summary['sales_totals'][ $order_currency ] ) ) {
				$summary['sales_totals'][ $order_currency ]           = 0.0;
				$summary['paid_order_counts'][ $order_currency ]       = 0;
				$summary['discount_totals'][ $order_currency ]         = 0.0;
			}

			$total = method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0;
			$net   = max( 0, $total - $refunded_total );
			$summary['sales_totals'][ $order_currency ] += $net;
			++$summary['paid_order_counts'][ $order_currency ];

			$discount = method_exists( $order, 'get_discount_total' ) ? (float) $order->get_discount_total() : 0.0;
			$discount += method_exists( $order, 'get_discount_tax' ) ? (float) $order->get_discount_tax() : 0.0;
			$summary['discount_totals'][ $order_currency ] += max( 0, $discount );
			if ( $discount > 0 ) {
				++$summary['discounted_order_count'];
			}

			$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
			$day     = $created && method_exists( $created, 'getTimestamp' )
				? $this->internal_date_key( $created->getTimestamp() )
				: '';
			if ( isset( $summary['daily_sales'][ $day ] ) ) {
				if ( ! isset( $summary['daily_sales'][ $day ][ $order_currency ] ) ) {
					$summary['daily_sales'][ $day ][ $order_currency ] = 0.0;
				}
				$summary['daily_sales'][ $day ][ $order_currency ] += $net;
			}

			$this->add_order_products( $summary['products'], $order, $order_currency );
		}

		return $summary;
	}

	/**
	 * Add paid line-item quantities and net line revenue to product metrics.
	 *
	 * @param array  $products       Product metrics, passed by reference.
	 * @param object $order          WooCommerce order.
	 * @param string $order_currency Order currency.
	 * @return void
	 */
	private function add_order_products( &$products, $order, $order_currency ) {
		if ( ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		foreach ( (array) $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}

			$product_id   = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
			$variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
			$product_id   = $variation_id > 0 ? $variation_id : $product_id;
			$name         = method_exists( $item, 'get_name' ) ? wp_strip_all_tags( (string) $item->get_name() ) : '';
			if ( '' === trim( $name ) ) {
				$name = sprintf( __( 'محصول #%d', JLWI_TEXT_DOMAIN ), $product_id );
			}

			$quantity = method_exists( $item, 'get_quantity' ) ? (float) $item->get_quantity() : 0.0;
			$line_sale = method_exists( $item, 'get_total' ) ? (float) $item->get_total() : 0.0;
			$actual_item_id = method_exists( $item, 'get_id' ) ? (int) $item->get_id() : (int) $item_id;
			if ( method_exists( $order, 'get_qty_refunded_for_item' ) ) {
				$quantity -= abs( (float) $order->get_qty_refunded_for_item( $actual_item_id, 'line_item' ) );
			}
			if ( method_exists( $order, 'get_total_refunded_for_item' ) ) {
				$line_sale -= abs( (float) $order->get_total_refunded_for_item( $actual_item_id, 'line_item' ) );
			}

			$key = ( $product_id > 0 ? (string) $product_id : md5( $name ) ) . ':' . $order_currency;
			if ( ! isset( $products[ $key ] ) ) {
				$products[ $key ] = array(
					'id'       => $product_id,
					'name'     => $name,
					'currency' => $order_currency,
					'quantity' => 0.0,
					'sales'    => 0.0,
				);
			}

			$products[ $key ]['quantity'] += max( 0, $quantity );
			$products[ $key ]['sales']    += max( 0, $line_sale );
		}
	}

	/**
	 * Build an unlocalized Gregorian key for internal day aggregation.
	 *
	 * Display dates still pass through wp_date(), but array keys must not be
	 * changed by Jalali/local-calendar filters.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private function internal_date_key( $timestamp ) {
		$date = new DateTimeImmutable( '@' . (int) $timestamp );
		return $date->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	}

	/**
	 * Whether the selected report includes the week-over-week change metric.
	 *
	 * @return bool
	 */
	private function sales_change_chart_enabled() {
		$sections = JLWI_Settings::sanitize_weekly_report_sections( JLWI_Settings::get( 'weekly_report_sections', array() ) );
		return in_array( 'sales_change', $sections, true );
	}

	/**
	 * Request a privacy-minimized weekly-change bar chart from QuickChart.
	 *
	 * The payload includes only currency labels and normalized percentage indices.
	 * It intentionally excludes sales amounts, dates, store identity, order details,
	 * products, customers, recipients, and Jetlinez credentials.
	 *
	 * @param array $data Calculated report data.
	 * @return string|WP_Error Temporary JPEG path or error.
	 */
	private function build_change_chart_file( $data ) {
		if ( ! is_array( $data ) || empty( $data['current']['sales_totals'] ) || ! isset( $data['previous']['sales_totals'] ) ) {
			return new WP_Error( 'jlwi_quickchart_data_missing', __( 'داده کافی برای نمودار تغییر فروش وجود ندارد.', JLWI_TEXT_DOMAIN ) );
		}

		$current    = (array) $data['current']['sales_totals'];
		$previous   = (array) $data['previous']['sales_totals'];
		$currencies = array_unique( array_merge( array_keys( $current ), array_keys( $previous ) ) );
		$datasets   = array();
		$changes    = array();
		$max_index  = 100.0;

		foreach ( $currencies as $currency ) {
			$current_total  = isset( $current[ $currency ] ) ? (float) $current[ $currency ] : 0.0;
			$previous_total = isset( $previous[ $currency ] ) ? (float) $previous[ $currency ] : 0.0;
			if ( $previous_total <= 0 ) {
				// A finite comparison index does not exist when the base is zero.
				continue;
			}

			$change = ( ( $current_total - $previous_total ) / abs( $previous_total ) ) * 100;
			if ( ! is_finite( $change ) ) {
				continue;
			}

			$change = round( $change, 1 );
			$index  = round( max( 0, 100 + $change ), 1 );
			$label  = strtoupper( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $currency ) );
			$color  = $change > 0 ? '#16a34a' : ( $change < 0 ? '#dc2626' : '#64748b' );
			$datasets[] = array(
				'label'           => '' !== $label ? $label : 'Sales',
				'data'            => array( 100, $index ),
				'backgroundColor' => array( '#94a3b8', $color ),
				'borderColor'     => array( '#64748b', $color ),
				'borderWidth'     => 1,
				'borderRadius'    => 7,
				'maxBarThickness' => 120,
			);
			$changes[] = $change;
			$max_index = max( $max_index, $index );
		}

		if ( empty( $datasets ) ) {
			return new WP_Error( 'jlwi_quickchart_change_undefined', __( 'درصد تغییر قابل نمایش برای نمودار وجود ندارد.', JLWI_TEXT_DOMAIN ) );
		}

		$subtitle = 1 === count( $datasets )
			? sprintf( 'Previous week = 100  |  Change: %s%.1f%%', $changes[0] > 0 ? '+' : '', $changes[0] )
			: 'Previous week = 100  |  Values are normalized per currency';
		$suggested_max = max( 120, ceil( $max_index / 10 ) * 10 + 10 );

		$payload = array(
			'version'          => '4',
			'backgroundColor'  => '#ffffff',
			'width'            => 720,
			'height'           => 440,
			'devicePixelRatio' => 1,
			'format'           => 'jpg',
			'chart'            => array(
				'type'    => 'bar',
				'data'    => array(
					'labels'   => array( 'Previous week', 'Current week' ),
					'datasets' => $datasets,
				),
				'options' => array(
					'responsive'        => false,
					'animation'         => false,
					'maintainAspectRatio' => false,
					'layout'            => array(
						'padding' => array( 'top' => 18, 'right' => 24, 'bottom' => 8, 'left' => 16 ),
					),
					'plugins'    => array(
						'legend' => array(
							'display'  => count( $datasets ) > 1,
							'position' => 'bottom',
							'labels'   => array( 'usePointStyle' => true, 'padding' => 18 ),
						),
						'title'  => array(
							'display' => true,
							'text'    => 'Weekly sales comparison (indexed)',
							'font'    => array( 'size' => 21, 'weight' => 'bold' ),
							'padding' => array( 'bottom' => 4 ),
						),
						'subtitle' => array(
							'display' => true,
							'text'    => $subtitle,
							'color'   => '#475569',
							'font'    => array( 'size' => 14 ),
							'padding' => array( 'bottom' => 16 ),
						),
						'datalabels' => array(
							'display'         => true,
							'anchor'          => 'end',
							'align'           => 'top',
							'offset'          => 2,
							'clamp'           => true,
							'color'           => '#0f172a',
							'backgroundColor' => 'rgba(255,255,255,0.88)',
							'borderRadius'    => 4,
							'padding'         => 4,
							'font'            => array( 'size' => 15, 'weight' => 'bold' ),
						),
					),
					'scales'     => array(
						'y' => array(
							'beginAtZero'  => true,
							'suggestedMax' => $suggested_max,
							'title'       => array(
								'display' => true,
								'text'    => 'Sales index',
								'font'    => array( 'size' => 14, 'weight' => 'bold' ),
							),
							'grid' => array( 'color' => '#e2e8f0' ),
							'ticks' => array( 'precision' => 1 ),
						),
						'x' => array(
							'grid'  => array( 'display' => false ),
							'ticks' => array( 'font' => array( 'size' => 15, 'weight' => 'bold' ) ),
						),
					),
				),
			),
		);

		$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( false === $body || '' === $body ) {
			return new WP_Error( 'jlwi_quickchart_json_failed', __( 'ساخت درخواست نمودار ناموفق بود.', JLWI_TEXT_DOMAIN ) );
		}

		$response = wp_remote_post(
			'https://quickchart.io/chart',
			array(
				'timeout'             => max( 5, min( 15, (int) JLWI_Settings::get( 'timeout', 45 ) ) ),
				'redirection'         => 0,
				'httpversion'         => '1.1',
				'blocking'            => true,
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => 5 * MB_IN_BYTES,
				'headers'             => array(
					'Accept'       => 'image/jpeg',
					'Content-Type' => 'application/json; charset=utf-8',
					'User-Agent'   => 'Jetlinez-WooCommerce-Invoice/' . JLWI_VERSION,
				),
				'body'                => $body,
				'data_format'         => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'jlwi_quickchart_transport_error', __( 'پاسخی از QuickChart دریافت نشد.', JLWI_TEXT_DOMAIN ) );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$chart_error = trim( (string) wp_remote_retrieve_header( $response, 'x-quickchart-error' ) );
		$content_type = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
		$image_body   = (string) wp_remote_retrieve_body( $response );
		if ( $status_code < 200 || $status_code >= 300 || '' !== $chart_error ) {
			return new WP_Error( 'jlwi_quickchart_http_error', sprintf( __( 'QuickChart پاسخ معتبر نداد (HTTP %d).', JLWI_TEXT_DOMAIN ), $status_code ) );
		}

		if ( 0 !== strpos( $content_type, 'image/jpeg' ) || strlen( $image_body ) < 4 || 0 !== strpos( $image_body, "\xFF\xD8\xFF" ) ) {
			return new WP_Error( 'jlwi_quickchart_invalid_image', __( 'پاسخ QuickChart یک تصویر JPEG معتبر نبود.', JLWI_TEXT_DOMAIN ) );
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			$file_api = ABSPATH . 'wp-admin/includes/file.php';
			if ( is_readable( $file_api ) ) {
				require_once $file_api;
			}
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			return new WP_Error( 'jlwi_quickchart_temp_unavailable', __( 'ساخت فایل موقت نمودار ممکن نیست.', JLWI_TEXT_DOMAIN ) );
		}

		$temp_path = wp_tempnam( 'jlwi-weekly-sales-change.jpg' );
		if ( ! is_string( $temp_path ) || '' === $temp_path ) {
			return new WP_Error( 'jlwi_quickchart_temp_failed', __( 'ساخت فایل موقت نمودار ناموفق بود.', JLWI_TEXT_DOMAIN ) );
		}

		$written = file_put_contents( $temp_path, $image_body, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written || strlen( $image_body ) !== (int) $written ) {
			$this->delete_local_chart( $temp_path );
			return new WP_Error( 'jlwi_quickchart_write_failed', __( 'ذخیره تصویر نمودار ناموفق بود.', JLWI_TEXT_DOMAIN ) );
		}

		return $temp_path;
	}

	/**
	 * Delete a temporary chart file.
	 *
	 * @param string $path Absolute local path.
	 * @return void
	 */
	private function delete_local_chart( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) ) {
			return;
		}

		if ( function_exists( 'wp_delete_file' ) ) {
			wp_delete_file( $path );
		} else {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * Log a chart error without turning successful text delivery into a failure.
	 *
	 * @param string   $message Log message.
	 * @param WP_Error $error   Chart error.
	 * @return void
	 */
	private function log_chart_error( $message, $error ) {
		$this->log(
			'warning',
			$message,
			array(
				'error_code' => is_wp_error( $error ) ? $error->get_error_code() : '',
				'error'      => is_wp_error( $error ) ? $error->get_error_message() : '',
			)
		);
	}

	/**
	 * Render all enabled weekly sections.
	 *
	 * @param array $data     Calculated report data.
	 * @param array $sections Enabled sections.
	 * @return string
	 */
	private function render_message( $data, $sections ) {
		$current  = $data['current'];
		$previous = $data['previous'];
		$enabled  = array_fill_keys( $sections, true );
		$lines    = array(
			'📊 *' . __( 'گزارش هفتگی فروشگاه', JLWI_TEXT_DOMAIN ) . '*',
			'🏪 ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			sprintf(
				'🗓 ' . __( 'بازه: %1$s تا %2$s', JLWI_TEXT_DOMAIN ),
				wp_date( get_option( 'date_format' ), $data['current_start']->getTimestamp(), wp_timezone() ),
				wp_date( get_option( 'date_format' ), $data['current_end']->getTimestamp(), wp_timezone() )
			),
		);

		if ( isset( $enabled['total_sales'] ) ) {
			$lines[] = '';
			$lines[] = '💰 *' . __( 'مجموع فروش هفته', JLWI_TEXT_DOMAIN ) . '*';
			foreach ( $current['sales_totals'] as $currency => $total ) {
				$lines[] = '• ' . $this->format_money( $total, $currency );
			}
		}

		if ( isset( $enabled['sales_change'] ) ) {
			$lines[] = '';
			$lines[] = '📈 *' . __( 'تغییر نسبت به هفته قبل', JLWI_TEXT_DOMAIN ) . '*';
			$currencies = array_unique( array_merge( array_keys( $current['sales_totals'] ), array_keys( $previous['sales_totals'] ) ) );
			foreach ( $currencies as $currency ) {
				$current_total  = isset( $current['sales_totals'][ $currency ] ) ? (float) $current['sales_totals'][ $currency ] : 0.0;
				$previous_total = isset( $previous['sales_totals'][ $currency ] ) ? (float) $previous['sales_totals'][ $currency ] : 0.0;
				$line = '• ' . ( count( $currencies ) > 1 ? $currency . ': ' : '' ) . $this->format_change( $current_total, $previous_total );
				if ( isset( $enabled['total_sales'] ) ) {
					$line .= ' — ' . $this->format_money( $previous_total, $currency ) . ' ← ' . $this->format_money( $current_total, $currency );
				}
				$lines[] = $line;
			}
		}

		if ( isset( $enabled['orders_average'] ) ) {
			$lines[] = '';
			$paid_order_count = array_sum( array_map( 'intval', (array) $current['paid_order_counts'] ) );
			$lines[] = '🧾 ' . __( 'تعداد کل سفارش‌ها:', JLWI_TEXT_DOMAIN ) . ' ' . number_format_i18n( (int) $current['order_count'] );
			$lines[] = '✅ ' . __( 'سفارش‌های پرداخت‌شده:', JLWI_TEXT_DOMAIN ) . ' ' . number_format_i18n( $paid_order_count );
			$averages = array();
			foreach ( $current['sales_totals'] as $currency => $total ) {
				$count = isset( $current['paid_order_counts'][ $currency ] ) ? (int) $current['paid_order_counts'][ $currency ] : 0;
				$averages[] = $this->format_money( $count > 0 ? (float) $total / $count : 0, $currency );
			}
			$lines[] = '📦 ' . __( 'میانگین ارزش سفارش پرداخت‌شده:', JLWI_TEXT_DOMAIN ) . ' ' . implode( ' | ', $averages );
		}

		if ( isset( $enabled['daily_sales'] ) ) {
			$lines[] = '';
			$lines[] = '📅 *' . __( 'فروش روزبه‌روز', JLWI_TEXT_DOMAIN ) . '*';
			foreach ( $current['daily_sales'] as $date => $totals ) {
				$amounts = array();
				foreach ( $totals as $currency => $total ) {
					$amounts[] = $this->format_money( $total, $currency );
				}
				$timestamp = ( new DateTimeImmutable( $date . ' 12:00:00', wp_timezone() ) )->getTimestamp();
				$lines[] = '• ' . wp_date( 'l، ' . get_option( 'date_format' ), $timestamp, wp_timezone() ) . ': ' . implode( ' | ', $amounts );
			}
		}

		if ( isset( $enabled['customer_mix'] ) ) {
			$mix     = isset( $data['customer_mix'] ) && is_array( $data['customer_mix'] ) ? $data['customer_mix'] : array();
			$new     = isset( $mix['new'] ) ? (int) $mix['new'] : 0;
			$returning = isset( $mix['returning'] ) ? (int) $mix['returning'] : 0;
			$lines[] = '';
			$lines[] = '👥 *' . __( 'ترکیب مشتریان یکتای پرداخت‌شده', JLWI_TEXT_DOMAIN ) . '*';
			$lines[] = '• ' . __( 'مشتری جدید:', JLWI_TEXT_DOMAIN ) . ' ' . number_format_i18n( $new );
			$lines[] = '• ' . __( 'مشتری تکراری:', JLWI_TEXT_DOMAIN ) . ' ' . number_format_i18n( $returning );
		}

		if ( isset( $enabled['top_low_products'] ) ) {
			$this->render_product_rankings( $lines, $current['products'] );
		}

		if ( isset( $enabled['product_changes'] ) ) {
			$this->render_product_changes( $lines, $current['products'], $previous['products'] );
		}

		if ( isset( $enabled['problem_orders'] ) ) {
			$lines[] = '';
			$lines[] = '⚠️ *' . __( 'سفارش‌های مسئله‌دار', JLWI_TEXT_DOMAIN ) . '*';
			$lines[] = '• ' . __( 'لغوشده:', JLWI_TEXT_DOMAIN ) . ' ' . number_format_i18n( (int) $current['cancelled_count'] );
			$lines[] = '• ' . __( 'مرجوع/بازپرداخت‌شده:', JLWI_TEXT_DOMAIN ) . ' ' . number_format_i18n( (int) $current['refunded_count'] );
			$lines[] = '• ' . __( 'پرداخت ناموفق:', JLWI_TEXT_DOMAIN ) . ' ' . number_format_i18n( (int) $current['failed_count'] );
		}

		if ( isset( $enabled['discounts'] ) ) {
			$lines[] = '';
			$lines[] = '🏷 *' . __( 'تخفیف استفاده‌شده', JLWI_TEXT_DOMAIN ) . '*';
			foreach ( $current['discount_totals'] as $currency => $total ) {
				$lines[] = '• ' . $this->format_money( $total, $currency );
			}
			$lines[] = '• ' . __( 'سفارش‌های دارای تخفیف:', JLWI_TEXT_DOMAIN ) . ' ' . number_format_i18n( (int) $current['discounted_order_count'] );
		}

		$lines[] = '';
		$lines[] = 'ℹ️ ' . __( 'فروش، میانگین و آمار محصول بر مبنای سفارش‌های پرداخت‌شده و پس از کسر بازپرداخت ثبت‌شده محاسبه شده‌اند.', JLWI_TEXT_DOMAIN );
		return implode( "\n", $lines );
	}

	/**
	 * Append top- and low-selling product lists.
	 *
	 * @param array $lines    Message lines, passed by reference.
	 * @param array $products Product metrics.
	 * @return void
	 */
	private function render_product_rankings( &$lines, $products ) {
		$products = array_values(
			array_filter(
				(array) $products,
				static function ( $product ) {
					return isset( $product['quantity'] ) && (float) $product['quantity'] > 0;
				}
			)
		);
		$limit = max( 1, min( 10, (int) apply_filters( 'jlwi_weekly_report_product_limit', 5 ) ) );
		$top   = $products;
		usort(
			$top,
			static function ( $left, $right ) {
				$quantity_compare = (float) $right['quantity'] <=> (float) $left['quantity'];
				return 0 !== $quantity_compare ? $quantity_compare : ( (float) $right['sales'] <=> (float) $left['sales'] );
			}
		);
		$low = $products;
		usort(
			$low,
			static function ( $left, $right ) {
				$quantity_compare = (float) $left['quantity'] <=> (float) $right['quantity'];
				return 0 !== $quantity_compare ? $quantity_compare : ( (float) $left['sales'] <=> (float) $right['sales'] );
			}
		);

		$lines[] = '';
		$lines[] = '🏆 *' . __( 'محصولات پرفروش', JLWI_TEXT_DOMAIN ) . '*';
		if ( empty( $top ) ) {
			$lines[] = '• ' . __( 'فروشی ثبت نشده است.', JLWI_TEXT_DOMAIN );
		} else {
			foreach ( array_slice( $top, 0, $limit ) as $product ) {
				$lines[] = '• ' . $this->format_product( $product );
			}
		}

		$lines[] = '';
		$lines[] = '📉 *' . __( 'محصولات کم‌فروشِ دارای فروش', JLWI_TEXT_DOMAIN ) . '*';
		if ( empty( $low ) ) {
			$lines[] = '• ' . __( 'فروشی ثبت نشده است.', JLWI_TEXT_DOMAIN );
		} else {
			foreach ( array_slice( $low, 0, $limit ) as $product ) {
				$lines[] = '• ' . $this->format_product( $product );
			}
		}
	}

	/**
	 * Append products with the largest positive and negative revenue changes.
	 *
	 * @param array $lines    Message lines, passed by reference.
	 * @param array $current  Current product metrics.
	 * @param array $previous Previous product metrics.
	 * @return void
	 */
	private function render_product_changes( &$lines, $current, $previous ) {
		$changes = array();
		foreach ( array_unique( array_merge( array_keys( (array) $current ), array_keys( (array) $previous ) ) ) as $key ) {
			$product = isset( $current[ $key ] ) ? $current[ $key ] : $previous[ $key ];
			$current_sales  = isset( $current[ $key ]['sales'] ) ? (float) $current[ $key ]['sales'] : 0.0;
			$previous_sales = isset( $previous[ $key ]['sales'] ) ? (float) $previous[ $key ]['sales'] : 0.0;
			if ( $current_sales === $previous_sales ) {
				continue;
			}
			$product['current_sales']  = $current_sales;
			$product['previous_sales'] = $previous_sales;
			$product['delta']          = $current_sales - $previous_sales;
			$changes[]                 = $product;
		}

		usort(
			$changes,
			static function ( $left, $right ) {
				return (float) $right['delta'] <=> (float) $left['delta'];
			}
		);
		$limit   = max( 1, min( 10, (int) apply_filters( 'jlwi_weekly_report_product_change_limit', 5 ) ) );
		$growth  = array_values( array_filter( $changes, static function ( $item ) { return (float) $item['delta'] > 0; } ) );
		$decline = array_values( array_filter( $changes, static function ( $item ) { return (float) $item['delta'] < 0; } ) );
		$decline = array_reverse( $decline );

		$lines[] = '';
		$lines[] = '🚀 *' . __( 'بیشترین رشد فروش محصول', JLWI_TEXT_DOMAIN ) . '*';
		$this->append_product_changes( $lines, array_slice( $growth, 0, $limit ) );
		$lines[] = '';
		$lines[] = '🔻 *' . __( 'بیشترین افت فروش محصول', JLWI_TEXT_DOMAIN ) . '*';
		$this->append_product_changes( $lines, array_slice( $decline, 0, $limit ) );
	}

	/**
	 * Append a product-change collection to the message.
	 *
	 * @param array $lines   Message lines, passed by reference.
	 * @param array $changes Product changes.
	 * @return void
	 */
	private function append_product_changes( &$lines, $changes ) {
		if ( empty( $changes ) ) {
			$lines[] = '• ' . __( 'موردی وجود ندارد.', JLWI_TEXT_DOMAIN );
			return;
		}

		foreach ( $changes as $product ) {
			$currency = isset( $product['currency'] ) ? (string) $product['currency'] : '';
			$lines[] = '• ' . $product['name'] . ' — ' . $this->format_change( $product['current_sales'], $product['previous_sales'] )
				. ' (' . $this->format_money( $product['previous_sales'], $currency ) . ' ← ' . $this->format_money( $product['current_sales'], $currency ) . ')';
		}
	}

	/**
	 * Format one product ranking line.
	 *
	 * @param array $product Product metrics.
	 * @return string
	 */
	private function format_product( $product ) {
		return $product['name'] . ' — ' . $this->format_quantity( $product['quantity'] ) . ' ' . __( 'عدد', JLWI_TEXT_DOMAIN )
			. ' — ' . $this->format_money( $product['sales'], $product['currency'] );
	}

	/**
	 * Format money without WooCommerce HTML markup.
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
	 * Format a percentage change while handling a zero comparison base.
	 *
	 * @param float $current  Current value.
	 * @param float $previous Previous value.
	 * @return string
	 */
	private function format_change( $current, $previous ) {
		if ( 0.0 === (float) $previous ) {
			return (float) $current > 0
				? __( 'رشد جدید (هفته قبل صفر)', JLWI_TEXT_DOMAIN )
				: __( 'بدون تغییر', JLWI_TEXT_DOMAIN );
		}

		$change = ( ( (float) $current - (float) $previous ) / abs( (float) $previous ) ) * 100;
		$prefix = $change > 0 ? '+' : '';
		return $prefix . number_format_i18n( $change, 1 ) . '%';
	}

	/**
	 * Format quantities with at most two decimal places.
	 *
	 * @param float $quantity Quantity.
	 * @return string
	 */
	private function format_quantity( $quantity ) {
		$decimals = floor( (float) $quantity ) === (float) $quantity ? 0 : 2;
		return number_format_i18n( (float) $quantity, $decimals );
	}

	/**
	 * Store the latest weekly delivery result for the settings screen.
	 *
	 * @param string $status Status.
	 * @param int    $sent   Successful recipients.
	 * @param int    $failed Failed recipients.
	 * @param string $error  Error summary.
	 * @return void
	 */
	private function store_result( $status, $sent, $failed, $error = '' ) {
		update_option(
			JLWI_WEEKLY_REPORT_STATE_OPTION,
			array(
				'timestamp' => time(),
				'status'    => sanitize_key( (string) $status ),
				'sent'      => max( 0, (int) $sent ),
				'failed'    => max( 0, (int) $failed ),
				'error'     => sanitize_text_field( (string) $error ),
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
		}
	}
}
