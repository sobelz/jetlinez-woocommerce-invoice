<?php
/**
 * Shared customer metrics for daily and weekly reports.
 *
 * @package JetlinezWooCommerceInvoice
 */

defined( 'ABSPATH' ) || exit;

final class JLWI_Report_Customers {

	/**
	 * Count unique first-time and returning customers among paid orders.
	 *
	 * A customer is new when they have no paid order strictly before the
	 * beginning of the report interval. Registered customers are matched by
	 * customer ID and guests by normalized billing email or phone.
	 *
	 * @param array             $orders  Orders in the report interval.
	 * @param DateTimeInterface $start   Report interval start.
	 * @param string            $context Report context: daily or weekly.
	 * @return array{new:int,returning:int}
	 */
	public static function mix( $orders, $start, $context = 'weekly' ) {
		$context   = 'daily' === sanitize_key( (string) $context ) ? 'daily' : 'weekly';
		$customers = array();
		foreach ( (array) $orders as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) || ! in_array( JLWI_Settings::normalize_status( $order->get_status() ), self::paid_statuses(), true ) ) {
				continue;
			}

			$identity = self::identity( $order );
			if ( ! empty( $identity['key'] ) ) {
				$customers[ $identity['key'] ] = $identity;
			}
		}

		$mix = array( 'new' => 0, 'returning' => 0 );
		foreach ( $customers as $identity ) {
			$is_new = ! self::had_paid_order_before( $identity, $start, $context );

			/**
			 * Filter whether a paid customer is new for the report interval.
			 *
			 * @param bool              $is_new   Whether no earlier paid order exists.
			 * @param array             $identity Stable customer identity and query field.
			 * @param DateTimeInterface $start    Report interval start.
			 * @param string            $context  Report context: daily or weekly.
			 */
			$is_new = (bool) apply_filters( 'jlwi_report_customer_is_new', $is_new, $identity, $start, $context );
			$is_new = (bool) apply_filters( 'jlwi_' . $context . '_report_customer_is_new', $is_new, $identity, $start );

			if ( $is_new ) {
				++$mix['new'];
			} else {
				++$mix['returning'];
			}
		}

		return $mix;
	}

	/**
	 * Return normalized paid-order statuses.
	 *
	 * @return string[]
	 */
	public static function paid_statuses() {
		$statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		return array_map( array( 'JLWI_Settings', 'normalize_status' ), (array) $statuses );
	}

	/**
	 * Build a stable registered or guest customer identity from an order.
	 *
	 * @param object $order WooCommerce order.
	 * @return array
	 */
	private static function identity( $order ) {
		$customer_id = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;
		if ( $customer_id > 0 ) {
			return array( 'key' => 'user:' . $customer_id, 'query_key' => 'customer_id', 'query_value' => $customer_id );
		}

		$email = method_exists( $order, 'get_billing_email' ) ? strtolower( trim( (string) $order->get_billing_email() ) ) : '';
		if ( '' !== $email ) {
			return array( 'key' => 'email:' . $email, 'query_key' => 'billing_email', 'query_value' => $email );
		}

		$phone = method_exists( $order, 'get_billing_phone' ) ? preg_replace( '/\D+/', '', JLWI_Settings::ascii_digits( $order->get_billing_phone() ) ) : '';
		if ( '' !== $phone ) {
			return array( 'key' => 'phone:' . $phone, 'query_key' => 'billing_phone', 'query_value' => $phone );
		}

		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		return array( 'key' => 'anonymous:' . $order_id, 'query_key' => '', 'query_value' => '' );
	}

	/**
	 * Check for a paid order strictly before the report interval.
	 *
	 * @param array             $identity Customer identity.
	 * @param DateTimeInterface $start    Report interval start.
	 * @param string            $context  Report context.
	 * @return bool
	 */
	private static function had_paid_order_before( $identity, $start, $context ) {
		if ( empty( $identity['query_key'] ) ) {
			return false;
		}

		$args = array(
			'type'         => 'shop_order',
			'status'       => self::paid_statuses(),
			// WooCommerce comparison syntax is <TIMESTAMP, not ...TIMESTAMP.
			'date_created' => '<' . $start->getTimestamp(),
			'limit'        => 1,
			'paginate'     => false,
			'return'       => 'ids',
			'orderby'      => 'date',
			'order'        => 'DESC',
		);
		$args[ $identity['query_key'] ] = $identity['query_value'];

		/**
		 * Filter the shared query used to find a customer's earlier paid order.
		 *
		 * @param array             $args     WC_Order_Query arguments.
		 * @param array             $identity Stable customer identity.
		 * @param DateTimeInterface $start    Report interval start.
		 * @param string            $context  Report context: daily or weekly.
		 */
		$args = apply_filters( 'jlwi_report_previous_customer_query_args', $args, $identity, $start, $context );
		$args = apply_filters( 'jlwi_' . $context . '_report_previous_customer_query_args', $args, $identity, $start );
		$result = wc_get_orders( is_array( $args ) ? $args : array() );

		if ( is_array( $result ) ) {
			return ! empty( $result );
		}

		return is_object( $result ) && isset( $result->orders ) && ! empty( $result->orders );
	}
}
