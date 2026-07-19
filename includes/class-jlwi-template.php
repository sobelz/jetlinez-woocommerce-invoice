<?php
/**
 * Plain-text message template renderer.
 *
 * @package JetlinezWooCommerceInvoice
 */

defined( 'ABSPATH' ) || exit;

final class JLWI_Template {

	/**
	 * Render a template for an order.
	 *
	 * @param string   $template          Template text.
	 * @param WC_Order $order             WooCommerce order.
	 * @param string   $status            Status slug.
	 * @param bool     $invoice_available Whether PDF/media is available.
	 * @param array    $context           Additional context values.
	 * @return string
	 */
	public static function render( $template, $order, $status, $invoice_available = false, $context = array() ) {
		$status          = JLWI_Settings::normalize_status( $status );
		$previous_status = isset( $context['previous_status'] ) ? JLWI_Settings::normalize_status( $context['previous_status'] ) : '';
		$created          = $order->get_date_created();
		$invoice_note     = $invoice_available
			? __( 'فاکتور PDF در پیام بعدی ارسال می‌شود.', JLWI_TEXT_DOMAIN )
			: __( 'نسخه PDF فاکتور در دسترس نبود و اطلاعات سفارش به‌صورت متنی ارسال می‌شود.', JLWI_TEXT_DOMAIN );

		if ( isset( $context['invoice_note'] ) && is_scalar( $context['invoice_note'] ) ) {
			$invoice_note = (string) $context['invoice_note'];
		}

		$tokens = array(
			'{order_id}'              => (string) $order->get_id(),
			'{order_number}'          => (string) $order->get_order_number(),
			'{status}'                => self::status_name( $status ),
			'{status_slug}'           => $status,
			'{previous_status}'       => self::status_name( $previous_status ),
			'{previous_status_slug}'  => $previous_status,
			'{customer_name}'         => trim( $order->get_formatted_billing_full_name() ),
			'{customer_first_name}'   => (string) $order->get_billing_first_name(),
			'{customer_last_name}'    => (string) $order->get_billing_last_name(),
			'{customer_phone}'        => (string) $order->get_billing_phone(),
			'{customer_email}'        => (string) $order->get_billing_email(),
			'{order_total}'           => self::html_to_text( $order->get_formatted_order_total() ),
			'{currency}'              => (string) $order->get_currency(),
			'{order_date}'            => $created ? $created->date_i18n( wc_date_format() . ' ' . wc_time_format() ) : '',
			'{payment_method}'        => (string) $order->get_payment_method_title(),
			'{shipping_method}'       => (string) $order->get_shipping_method(),
			'{billing_address}'       => self::html_to_text( $order->get_formatted_billing_address() ),
			'{shipping_address}'      => self::html_to_text( $order->get_formatted_shipping_address() ),
			'{customer_note}'         => (string) $order->get_customer_note(),
			'{items}'                 => self::format_items( $order ),
			'{item_count}'            => (string) $order->get_item_count(),
			'{site_name}'             => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'              => home_url( '/' ),
			'{admin_email}'           => (string) get_option( 'admin_email' ),
			'{invoice_note}'          => $invoice_note,
			'{invoice_available}'     => $invoice_available ? 'yes' : 'no',
			'{recipient}'             => isset( $context['recipient'] ) ? (string) $context['recipient'] : '',
		);

		/**
		 * Filter message placeholder values.
		 *
		 * @param array    $tokens             Placeholder/value map.
		 * @param WC_Order $order              Order object.
		 * @param string   $status             Normalized status.
		 * @param bool     $invoice_available  Whether a PDF is available.
		 * @param array    $context            Additional context.
		 */
		$tokens = apply_filters( 'jlwi_template_tokens', $tokens, $order, $status, $invoice_available, $context );

		$message = strtr( (string) $template, is_array( $tokens ) ? $tokens : array() );
		$message = preg_replace( "/\r\n|\r/", "\n", $message );
		$message = preg_replace( "/[ \t]+\n/", "\n", $message );
		$message = preg_replace( "/\n{4,}/", "\n\n\n", $message );

		/**
		 * Filter the final outgoing plain-text message.
		 *
		 * @param string   $message            Rendered message.
		 * @param WC_Order $order              Order object.
		 * @param string   $status             Normalized status.
		 * @param bool     $invoice_available  Whether a PDF is available.
		 * @param array    $context            Additional context.
		 */
		return trim( (string) apply_filters( 'jlwi_rendered_message', $message, $order, $status, $invoice_available, $context ) );
	}

	/**
	 * Format order line items for a text message.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	private static function format_items( $order ) {
		$lines = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$quantity = (float) $item->get_quantity();
			$quantity = ( floor( $quantity ) === $quantity ) ? (int) $quantity : $quantity;
			$subtotal = $order->get_formatted_line_subtotal( $item, false, false );
			$line     = sprintf(
				'• %1$s × %2$s — %3$s',
				$quantity,
				self::html_to_text( $item->get_name() ),
				self::html_to_text( $subtotal )
			);

			$lines[] = $line;
		}

		return empty( $lines ) ? __( 'بدون قلم سفارش', JLWI_TEXT_DOMAIN ) : implode( "\n", $lines );
	}

	/**
	 * Convert simple WooCommerce HTML fragments to readable plain text.
	 *
	 * @param string $html HTML/string value.
	 * @return string
	 */
	private static function html_to_text( $html ) {
		$text = preg_replace( '#<br\s*/?>#i', "\n", (string) $html );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		return trim( $text );
	}

	/**
	 * Human-readable status name.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private static function status_name( $status ) {
		if ( '' === (string) $status ) {
			return '';
		}

		return function_exists( 'wc_get_order_status_name' ) ? (string) wc_get_order_status_name( $status ) : (string) $status;
	}
}
