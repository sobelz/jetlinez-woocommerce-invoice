<?php
/**
 * Settings storage and defaults.
 *
 * @package JetlinezWooCommerceInvoice
 */

defined( 'ABSPATH' ) || exit;

final class JLWI_Settings {

	/**
	 * Convert Persian and Arabic-Indic numerals to ASCII digits.
	 *
	 * @param mixed $value Input value.
	 * @return string
	 */
	public static function ascii_digits( $value ) {
		return strtr(
			(string) $value,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'                          => 'no',
			'api_base_url'                     => 'https://my.jetlinez.com/api/v1',
			'api_key'                          => '',
			'device_id'                        => '',
			'target_statuses'                   => array( 'processing', 'completed' ),
			// Kept for migrating settings saved by versions earlier than 1.2.0.
			'enable_processing'                 => 'yes',
			'enable_completed'                  => 'yes',
			'fixed_recipients'                  => '',
			'report_recipients'                 => '',
			'include_billing_phone'              => 'no',
			'default_country_code'              => '98',
			'delivery_modes'                    => array(
				'processing' => array(
					'customer' => 'text',
					'admin'    => 'file',
				),
				'completed'  => array(
					'customer' => 'both',
					'admin'    => 'text',
				),
			),
			// Kept for migrating settings saved by versions earlier than 1.4.0.
			'send_pdf'                          => 'yes',
			'send_text_with_pdf'                => 'yes',
			'prevent_duplicates'                => 'yes',
			'without_delay'                     => 'no',
			'add_order_notes'                   => 'yes',
			'debug_logging'                     => 'no',
			'delete_local_pdf'                  => 'yes',
			'delete_remote_media'               => 'no',
			'delete_data_on_uninstall'          => 'no',
			'daily_report_enabled'              => 'no',
			'daily_report_time'                 => '20:00',
			'daily_report_sections'             => array(
				'sales',
				'orders',
				'average_order',
				'new_customers',
				'problem_orders',
				'inventory_attention',
			),
			'weekly_report_enabled'             => 'no',
			'weekly_report_day'                 => 6,
			'weekly_report_time'                => '20:00',
			'weekly_report_sections'            => array(
				'total_sales',
				'sales_change',
				'orders_average',
				'daily_sales',
				'customer_mix',
				'top_low_products',
				'product_changes',
				'problem_orders',
				'discounts',
			),
			'timeout'                           => 45,
			'max_redirects'                     => 3,
			'max_file_mb'                       => 20,
			'retry_count'                       => 2,
			'retry_base_delay'                  => 60,
			'template_processing'               => self::default_processing_template(),
			'template_completed'                => self::default_completed_template(),
			'template_generic'                  => self::default_generic_template(),
			'template_fallback'                 => self::default_fallback_template(),
		);
	}

	/**
	 * Processing status message.
	 *
	 * @return string
	 */
	private static function default_processing_template() {
		return "📦 سفارش #{order_number}\n"
			. "وضعیت: {status}\n"
			. "مشتری: {customer_name}\n"
			. "تلفن: {customer_phone}\n"
			. "مبلغ: {order_total}\n"
			. "تاریخ: {order_date}\n\n"
			. "{invoice_note}\n"
			. "{site_name}";
	}

	/**
	 * Completed status message.
	 *
	 * @return string
	 */
	private static function default_completed_template() {
		return "✅ سفارش #{order_number} تکمیل شد\n"
			. "مشتری: {customer_name}\n"
			. "تلفن: {customer_phone}\n"
			. "مبلغ: {order_total}\n"
			. "تاریخ: {order_date}\n\n"
			. "{invoice_note}\n"
			. "{site_name}";
	}

	/**
	 * Generic manual-send message.
	 *
	 * @return string
	 */
	private static function default_generic_template() {
		return "📄 سفارش #{order_number}\n"
			. "وضعیت: {status}\n"
			. "مشتری: {customer_name}\n"
			. "تلفن: {customer_phone}\n"
			. "مبلغ: {order_total}\n\n"
			. "{invoice_note}\n"
			. "{site_name}";
	}

	/**
	 * Text-only fallback message.
	 *
	 * @return string
	 */
	private static function default_fallback_template() {
		return "📋 اطلاعات سفارش #{order_number}\n"
			. "وضعیت: {status}\n"
			. "مشتری: {customer_name}\n"
			. "تلفن: {customer_phone}\n"
			. "روش پرداخت: {payment_method}\n"
			. "روش ارسال: {shipping_method}\n\n"
			. "اقلام سفارش:\n{items}\n\n"
			. "مبلغ کل: {order_total}\n"
			. "تاریخ: {order_date}\n\n"
			. "{invoice_note}\n"
			. "{site_name}";
	}

	/**
	 * Read all settings, applying optional wp-config.php constants.
	 *
	 * Supported constants:
	 * JLWI_API_BASE_URL, JLWI_API_KEY, JLWI_DEVICE_ID.
	 *
	 * @return array
	 */
	public static function all() {
		$saved    = get_option( JLWI_OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$settings                            = wp_parse_args( $saved, self::defaults() );
		$settings['target_statuses']         = self::resolve_target_statuses( $saved, $settings );
		$settings['delivery_modes']          = self::resolve_delivery_modes( $saved, $settings );
		$settings['report_recipients']       = self::resolve_report_recipients( $saved, $settings );
		$settings['daily_report_time']       = self::sanitize_report_time( $settings['daily_report_time'] );
		$settings['daily_report_sections']   = self::sanitize_report_sections( $settings['daily_report_sections'] );
		$settings['weekly_report_day']       = self::sanitize_weekly_report_day( $settings['weekly_report_day'] );
		$settings['weekly_report_time']      = self::sanitize_report_time( $settings['weekly_report_time'] );
		$settings['weekly_report_sections'] = self::sanitize_weekly_report_sections( $settings['weekly_report_sections'] );

		$constant_map = array(
			'api_base_url' => 'JLWI_API_BASE_URL',
			'api_key'      => 'JLWI_API_KEY',
			'device_id'    => 'JLWI_DEVICE_ID',
		);

		foreach ( $constant_map as $setting_key => $constant_name ) {
			if ( defined( $constant_name ) && '' !== trim( (string) constant( $constant_name ) ) ) {
				$settings[ $setting_key ] = trim( (string) constant( $constant_name ) );
			}
		}

		return $settings;
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::all();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Read raw saved options without constant overrides.
	 *
	 * @return array
	 */
	public static function raw() {
		$saved    = get_option( JLWI_OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$settings                            = wp_parse_args( $saved, self::defaults() );
		$settings['target_statuses']         = self::resolve_target_statuses( $saved, $settings );
		$settings['delivery_modes']          = self::resolve_delivery_modes( $saved, $settings );
		$settings['report_recipients']       = self::resolve_report_recipients( $saved, $settings );
		$settings['daily_report_time']       = self::sanitize_report_time( $settings['daily_report_time'] );
		$settings['daily_report_sections']   = self::sanitize_report_sections( $settings['daily_report_sections'] );
		$settings['weekly_report_day']       = self::sanitize_weekly_report_day( $settings['weekly_report_day'] );
		$settings['weekly_report_time']      = self::sanitize_report_time( $settings['weekly_report_time'] );
		$settings['weekly_report_sections'] = self::sanitize_weekly_report_sections( $settings['weekly_report_sections'] );

		return $settings;
	}

	/**
	 * Whether a yes/no option is enabled.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function enabled( $key ) {
		return 'yes' === self::get( $key, 'no' );
	}

	/**
	 * Is an automatic target status enabled?
	 *
	 * @param string $status Status slug, with or without wc- prefix.
	 * @return bool
	 */
	public static function is_target_status( $status ) {
		$status = self::normalize_status( $status );
		return '' !== $status && in_array( $status, self::target_statuses(), true );
	}

	/**
	 * Return normalized statuses selected for automatic sending.
	 *
	 * @return string[]
	 */
	public static function target_statuses() {
		return self::sanitize_statuses( self::get( 'target_statuses', array() ) );
	}

	/**
	 * Return the configured content mode for a status and recipient audience.
	 *
	 * Supported modes are: none, text, file and both.
	 *
	 * @param string $status   Status slug, with or without wc- prefix.
	 * @param string $audience Recipient audience: customer or admin.
	 * @return string
	 */
	public static function delivery_mode( $status, $audience ) {
		$status   = self::normalize_status( $status );
		$audience = self::sanitize_audience( $audience );
		$modes    = self::get( 'delivery_modes', array() );

		if ( '' === $status || '' === $audience ) {
			return 'none';
		}

		if ( isset( $modes[ $status ][ $audience ] ) ) {
			return self::sanitize_delivery_mode( $modes[ $status ][ $audience ] );
		}

		return self::legacy_delivery_mode( self::all() );
	}

	/**
	 * Sanitize the complete status/audience delivery matrix.
	 *
	 * @param mixed $modes Raw matrix.
	 * @return array
	 */
	public static function sanitize_delivery_modes( $modes ) {
		if ( ! is_array( $modes ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $modes as $status => $audiences ) {
			$status = self::normalize_status( $status );
			if ( '' === $status || ! is_array( $audiences ) ) {
				continue;
			}

			foreach ( array( 'customer', 'admin' ) as $audience ) {
				if ( isset( $audiences[ $audience ] ) ) {
					$sanitized[ $status ][ $audience ] = self::sanitize_delivery_mode( $audiences[ $audience ] );
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize one delivery mode.
	 *
	 * @param mixed $mode Raw mode.
	 * @return string
	 */
	public static function sanitize_delivery_mode( $mode ) {
		$mode = is_scalar( $mode ) ? sanitize_key( (string) $mode ) : '';
		if ( 'pdf' === $mode ) {
			$mode = 'file';
		}

		return in_array( $mode, array( 'none', 'text', 'file', 'both' ), true ) ? $mode : 'none';
	}

	/**
	 * Sanitize a list of WooCommerce status slugs.
	 *
	 * @param mixed $statuses Status list.
	 * @return string[]
	 */
	public static function sanitize_statuses( $statuses ) {
		if ( is_string( $statuses ) ) {
			$statuses = preg_split( '/[\s,]+/', $statuses );
		}

		if ( ! is_array( $statuses ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $statuses as $status ) {
			if ( ! is_scalar( $status ) ) {
				continue;
			}

			$status = self::normalize_status( $status );
			if ( '' !== $status ) {
				$normalized[ $status ] = $status;
			}
		}

		return array_values( $normalized );
	}

	/**
	 * Sanitize the local time used for the daily report.
	 *
	 * @param mixed $time Time in HH:MM format.
	 * @return string
	 */
	public static function sanitize_report_time( $time ) {
		$time = self::ascii_digits( is_scalar( $time ) ? $time : '' );
		$time = trim( $time );

		if ( ! preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time ) ) {
			return self::defaults()['daily_report_time'];
		}

		return $time;
	}

	/**
	 * Return the supported daily-report section keys and their labels.
	 *
	 * @return array<string,string>
	 */
	public static function report_section_labels() {
		return array(
			'sales'               => __( 'فروش امروز و تغییر نسبت به دیروز', JLWI_TEXT_DOMAIN ),
			'orders'              => __( 'تعداد سفارش‌ها', JLWI_TEXT_DOMAIN ),
			'average_order'       => __( 'میانگین مبلغ سفارش', JLWI_TEXT_DOMAIN ),
			'new_customers'       => __( 'تعداد مشتری جدید', JLWI_TEXT_DOMAIN ),
			'problem_orders'      => __( 'سفارش‌های لغو، مرجوع و رهاشده', JLWI_TEXT_DOMAIN ),
			'inventory_attention' => __( 'محصولات ناموجودشده بر اثر فروش همان بازه', JLWI_TEXT_DOMAIN ),
		);
	}

	/**
	 * Sanitize enabled daily-report sections.
	 *
	 * @param mixed $sections Raw section list.
	 * @return string[]
	 */
	public static function sanitize_report_sections( $sections ) {
		if ( ! is_array( $sections ) ) {
			return array();
		}

		$allowed   = self::report_section_labels();
		$sanitized = array();
		foreach ( $sections as $section ) {
			$section = is_scalar( $section ) ? sanitize_key( (string) $section ) : '';
			if ( isset( $allowed[ $section ] ) ) {
				$sanitized[ $section ] = $section;
			}
		}

		return array_values( $sanitized );
	}

	/**
	 * Return the supported weekly-report section keys and their labels.
	 *
	 * @return array<string,string>
	 */
	public static function weekly_report_section_labels() {
		return array(
			'total_sales'      => __( 'مجموع فروش هفته', JLWI_TEXT_DOMAIN ),
			'sales_change'     => __( 'رشد یا افت نسبت به هفته قبل', JLWI_TEXT_DOMAIN ),
			'orders_average'   => __( 'تعداد سفارش‌ها و میانگین ارزش سفارش', JLWI_TEXT_DOMAIN ),
			'daily_sales'      => __( 'فروش روزبه‌روز هفته', JLWI_TEXT_DOMAIN ),
			'customer_mix'     => __( 'مشتریان جدید در مقابل مشتریان تکراری', JLWI_TEXT_DOMAIN ),
			'top_low_products' => __( 'محصولات پرفروش و کم‌فروش', JLWI_TEXT_DOMAIN ),
			'product_changes'  => __( 'محصولات با بیشترین رشد یا افت فروش', JLWI_TEXT_DOMAIN ),
			'problem_orders'   => __( 'تعداد لغو، مرجوعی و پرداخت ناموفق', JLWI_TEXT_DOMAIN ),
			'discounts'        => __( 'میزان تخفیف استفاده‌شده', JLWI_TEXT_DOMAIN ),
		);
	}

	/**
	 * Sanitize enabled weekly-report sections.
	 *
	 * @param mixed $sections Raw section list.
	 * @return string[]
	 */
	public static function sanitize_weekly_report_sections( $sections ) {
		if ( ! is_array( $sections ) ) {
			return array();
		}

		$allowed   = self::weekly_report_section_labels();
		$sanitized = array();
		foreach ( $sections as $section ) {
			$section = is_scalar( $section ) ? sanitize_key( (string) $section ) : '';
			if ( isset( $allowed[ $section ] ) ) {
				$sanitized[ $section ] = $section;
			}
		}

		return array_values( $sanitized );
	}

	/**
	 * Sanitize a weekly report weekday using PHP's 0 (Sunday) to 6 (Saturday).
	 *
	 * @param mixed $day Weekday number.
	 * @return int
	 */
	public static function sanitize_weekly_report_day( $day ) {
		$day = trim( self::ascii_digits( is_scalar( $day ) ? $day : '' ) );
		return preg_match( '/^[0-6]$/', $day ) ? (int) $day : (int) self::defaults()['weekly_report_day'];
	}

	/**
	 * Resolve the new status list, including legacy processing/completed flags.
	 *
	 * @param array $saved    Raw saved settings.
	 * @param array $settings Settings merged with defaults.
	 * @return string[]
	 */
	private static function resolve_target_statuses( $saved, $settings ) {
		if ( array_key_exists( 'target_statuses', $saved ) ) {
			return self::sanitize_statuses( $saved['target_statuses'] );
		}

		$statuses = array();
		if ( isset( $settings['enable_processing'] ) && 'yes' === $settings['enable_processing'] ) {
			$statuses[] = 'processing';
		}
		if ( isset( $settings['enable_completed'] ) && 'yes' === $settings['enable_completed'] ) {
			$statuses[] = 'completed';
		}

		return $statuses;
	}

	/**
	 * Resolve the delivery matrix, including legacy global PDF/text switches.
	 *
	 * @param array $saved    Raw saved settings.
	 * @param array $settings Settings merged with defaults.
	 * @return array
	 */
	private static function resolve_delivery_modes( $saved, $settings ) {
		if ( array_key_exists( 'delivery_modes', $saved ) ) {
			return self::sanitize_delivery_modes( $saved['delivery_modes'] );
		}

		// A fresh activation stores the new defaults. Existing installations that
		// have not saved the matrix retain their previous global send behavior.
		if ( empty( $saved ) ) {
			return self::sanitize_delivery_modes( self::defaults()['delivery_modes'] );
		}

		$legacy_mode = self::legacy_delivery_mode( $settings );
		$statuses    = self::resolve_target_statuses( $saved, $settings );
		$modes       = array();
		foreach ( $statuses as $status ) {
			$modes[ $status ] = array(
				'customer' => $legacy_mode,
				'admin'    => $legacy_mode,
			);
		}

		return $modes;
	}

	/**
	 * Resolve dedicated report recipients for installations created before the
	 * report and invoice recipient lists were separated.
	 *
	 * @param array $saved    Raw saved settings.
	 * @param array $settings Settings merged with defaults.
	 * @return string
	 */
	private static function resolve_report_recipients( $saved, $settings ) {
		if ( array_key_exists( 'report_recipients', $saved ) ) {
			return (string) $saved['report_recipients'];
		}

		return isset( $settings['fixed_recipients'] ) ? (string) $settings['fixed_recipients'] : '';
	}

	/**
	 * Convert the pre-1.4.0 global switches to one delivery mode.
	 *
	 * @param array $settings Settings array.
	 * @return string
	 */
	private static function legacy_delivery_mode( $settings ) {
		$send_file = isset( $settings['send_pdf'] ) && 'yes' === $settings['send_pdf'];
		$send_text = ! $send_file || ( isset( $settings['send_text_with_pdf'] ) && 'yes' === $settings['send_text_with_pdf'] );

		if ( $send_file && $send_text ) {
			return 'both';
		}
		if ( $send_file ) {
			return 'file';
		}

		return 'text';
	}

	/**
	 * Sanitize a recipient audience.
	 *
	 * @param mixed $audience Raw audience.
	 * @return string
	 */
	private static function sanitize_audience( $audience ) {
		$audience = is_scalar( $audience ) ? sanitize_key( (string) $audience ) : '';
		return in_array( $audience, array( 'customer', 'admin' ), true ) ? $audience : '';
	}

	/**
	 * Normalize WooCommerce status slug.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function normalize_status( $status ) {
		$status = sanitize_key( (string) $status );
		return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	/**
	 * Return the configured template for a status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function status_template( $status ) {
		$status = self::normalize_status( $status );

		if ( 'processing' === $status ) {
			return (string) self::get( 'template_processing', self::default_processing_template() );
		}

		if ( 'completed' === $status ) {
			return (string) self::get( 'template_completed', self::default_completed_template() );
		}

		return (string) self::get( 'template_generic', self::default_generic_template() );
	}

	/**
	 * Is API configuration complete?
	 *
	 * @return bool
	 */
	public static function is_api_configured() {
		$base_url = trim( (string) self::get( 'api_base_url' ) );
		$parts    = wp_parse_url( $base_url );
		$valid_url = is_array( $parts )
			&& ! empty( $parts['host'] )
			&& ! empty( $parts['scheme'] )
			&& in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true );

		return $valid_url
			&& '' !== trim( (string) self::get( 'api_key' ) )
			&& '' !== trim( (string) self::get( 'device_id' ) );
	}
}
