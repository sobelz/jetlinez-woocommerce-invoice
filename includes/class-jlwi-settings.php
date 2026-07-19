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
			'enable_processing'                 => 'yes',
			'enable_completed'                  => 'yes',
			'fixed_recipients'                  => '',
			'include_billing_phone'              => 'no',
			'default_country_code'              => '98',
			'send_pdf'                          => 'yes',
			'send_text_with_pdf'                => 'yes',
			'prevent_duplicates'                => 'yes',
			'without_delay'                     => 'no',
			'add_order_notes'                   => 'yes',
			'debug_logging'                     => 'no',
			'delete_local_pdf'                  => 'yes',
			'delete_remote_media'               => 'no',
			'delete_data_on_uninstall'          => 'no',
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
		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );

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
		$saved = get_option( JLWI_OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
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

		if ( 'processing' === $status ) {
			return self::enabled( 'enable_processing' );
		}

		if ( 'completed' === $status ) {
			return self::enabled( 'enable_completed' );
		}

		return false;
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
