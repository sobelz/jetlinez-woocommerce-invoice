<?php
/**
 * Jetlinez HTTP API client.
 *
 * @package JetlinezWooCommerceInvoice
 */

defined( 'ABSPATH' ) || exit;

final class JLWI_API_Client {

	/** @var array */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array|null $settings Optional settings override.
	 */
	public function __construct( $settings = null ) {
		$this->settings = is_array( $settings ) ? wp_parse_args( $settings, JLWI_Settings::all() ) : JLWI_Settings::all();
	}

	/**
	 * Validate required configuration.
	 *
	 * @return true|WP_Error
	 */
	public function validate_configuration() {
		$base_url = trim( (string) $this->settings['api_base_url'] );
		$parts    = wp_parse_url( $base_url );
		if ( '' === $base_url || ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'jlwi_missing_base_url', __( 'آدرس پایه API جتلاینز معتبر نیست.', JLWI_TEXT_DOMAIN ) );
		}

		if ( '' === trim( (string) $this->settings['api_key'] ) ) {
			return new WP_Error( 'jlwi_missing_api_key', __( 'API Key جتلاینز وارد نشده است.', JLWI_TEXT_DOMAIN ) );
		}

		if ( '' === trim( (string) $this->settings['device_id'] ) ) {
			return new WP_Error( 'jlwi_missing_device_id', __( 'Device ID واتساپ جتلاینز وارد نشده است.', JLWI_TEXT_DOMAIN ) );
		}

		return true;
	}

	/**
	 * Upload a supported document or image to Jetlinez media storage.
	 *
	 * Jetlinez expects multipart/form-data with a field named "file".
	 *
	 * @param string $file_path Absolute local path.
	 * @return array|WP_Error Array includes id and data on success.
	 */
	public function upload_media( $file_path ) {
		$config = $this->validate_configuration();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$file_path = (string) $file_path;
		if ( '' === $file_path || ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error( 'jlwi_media_not_readable', __( 'فایل مدیا قابل خواندن نیست.', JLWI_TEXT_DOMAIN ) );
		}

		$file_size = (int) filesize( $file_path );
		$max_bytes = max( 1, (int) $this->settings['max_file_mb'] ) * MB_IN_BYTES;
		if ( $file_size <= 0 ) {
			return new WP_Error( 'jlwi_media_empty', __( 'فایل مدیا خالی است.', JLWI_TEXT_DOMAIN ) );
		}

		if ( $file_size > $max_bytes ) {
			return new WP_Error(
				'jlwi_media_too_large',
				sprintf(
					/* translators: 1: file size in MB, 2: configured limit in MB. */
					__( 'حجم فایل مدیا %.2f مگابایت است و از سقف تنظیم‌شده %d مگابایت بیشتر است.', JLWI_TEXT_DOMAIN ),
					$file_size / MB_IN_BYTES,
					(int) $this->settings['max_file_mb']
				)
			);
		}

		$file_contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $file_contents ) {
			return new WP_Error( 'jlwi_media_read_failed', __( 'خواندن فایل مدیا ناموفق بود.', JLWI_TEXT_DOMAIN ) );
		}

		$signature = substr( $file_contents, 0, 8 );
		if ( 0 === strpos( $signature, '%PDF-' ) ) {
			$mime      = 'application/pdf';
			$extension = 'pdf';
		} elseif ( 0 === strpos( $signature, "\xFF\xD8\xFF" ) ) {
			$mime      = 'image/jpeg';
			$extension = 'jpg';
		} else {
			unset( $file_contents );
			return new WP_Error( 'jlwi_media_type_unsupported', __( 'نوع فایل مدیا پشتیبانی نمی‌شود؛ فقط PDF و JPEG مجاز هستند.', JLWI_TEXT_DOMAIN ) );
		}

		$filename = sanitize_file_name( wp_basename( $file_path ) );
		if ( '' === $filename ) {
			$filename = 'jetlinez-media.' . $extension;
		} elseif ( $extension !== strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ) && ! ( 'jpg' === $extension && 'jpeg' === strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ) ) ) {
			$filename = preg_replace( '/\.[^.]*$/', '', $filename ) . '.' . $extension;
		}

		// Detect the two supported types from their file signatures rather than the
		// temporary filename or server-dependent MIME databases.
		$boundary      = '--------------------------' . str_replace( '-', '', wp_generate_uuid4() );
		$safe_filename = str_replace( array( '"', "\r", "\n" ), '', $filename );
		$body          = '--' . $boundary . "\r\n";
		$body         .= 'Content-Disposition: form-data; name="file"; filename="' . $safe_filename . '"' . "\r\n";
		$body         .= 'Content-Type: ' . $mime . "\r\n\r\n";
		$body         .= $file_contents . "\r\n";
		$body         .= '--' . $boundary . "--\r\n";

		$response = $this->request(
			'POST',
			'/media',
			array(
				'headers' => array(
					'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		unset( $file_contents, $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$media_id = isset( $response['data']['id'] ) ? (string) $response['data']['id'] : '';
		if ( '' === $media_id ) {
			return new WP_Error(
				'jlwi_media_id_missing',
				__( 'پاسخ آپلود جتلاینز معتبر بود، اما شناسه مدیا در آن وجود نداشت.', JLWI_TEXT_DOMAIN ),
				array( 'response' => $response )
			);
		}

		return array(
			'id'   => $media_id,
			'data' => $response['data'],
			'raw'  => $response,
		);
	}

	/**
	 * Send a WhatsApp message.
	 *
	 * @param string $phone    Normalized phone number (digits only).
	 * @param string $text     Optional text.
	 * @param string $media_id Optional Jetlinez media UUID.
	 * @return array|WP_Error
	 */
	public function send_message( $phone, $text = '', $media_id = '' ) {
		$config = $this->validate_configuration();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$phone    = preg_replace( '/\D+/', '', (string) $phone );
		$text     = trim( (string) $text );
		$media_id = trim( (string) $media_id );

		if ( '' === $phone ) {
			return new WP_Error( 'jlwi_phone_missing', __( 'شماره گیرنده خالی است.', JLWI_TEXT_DOMAIN ) );
		}

		if ( ! preg_match( '/^[0-9]{8,15}$/', $phone ) ) {
			return new WP_Error( 'jlwi_phone_invalid', __( 'شماره گیرنده باید بین ۸ تا ۱۵ رقم و بدون علامت + باشد.', JLWI_TEXT_DOMAIN ) );
		}

		if ( '' === $text && '' === $media_id ) {
			return new WP_Error( 'jlwi_message_empty', __( 'برای ارسال، متن یا شناسه مدیا لازم است.', JLWI_TEXT_DOMAIN ) );
		}

		$payload = array(
			'phonenumber' => $phone,
		);

		if ( '' !== $text ) {
			$payload['text'] = $text;
		}

		if ( '' !== $media_id ) {
			$payload['mediaId'] = $media_id;
		}

		$endpoint = '/whatsapps/' . rawurlencode( (string) $this->settings['device_id'] ) . '/message';
		if ( 'yes' === $this->settings['without_delay'] ) {
			$endpoint = add_query_arg( 'without_delay', '1', $endpoint );
		}

		return $this->request(
			'POST',
			$endpoint,
			array(
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);
	}

	/**
	 * Delete a remote Jetlinez media object.
	 *
	 * @param string $media_id Media UUID.
	 * @return array|WP_Error
	 */
	public function delete_media( $media_id ) {
		$media_id = trim( (string) $media_id );
		if ( '' === $media_id ) {
			return new WP_Error( 'jlwi_media_id_missing', __( 'شناسه مدیا خالی است.', JLWI_TEXT_DOMAIN ) );
		}

		return $this->request( 'DELETE', '/media/' . rawurlencode( $media_id ) );
	}

	/**
	 * Determine whether an API error is transient and suitable for retry.
	 *
	 * @param WP_Error $error Error object.
	 * @return bool
	 */
	public static function is_transient_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		if ( in_array( $error->get_error_code(), array( 'http_request_failed', 'jlwi_transport_error' ), true ) ) {
			return true;
		}

		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		return in_array( $status, array( 408, 425, 429, 500, 502, 503, 504 ), true );
	}

	/**
	 * Execute a request and normalize Jetlinez responses.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint Relative endpoint beginning with slash.
	 * @param array  $args     Request overrides.
	 * @return array|WP_Error
	 */
	private function request( $method, $endpoint, $args = array() ) {
		$base_url = untrailingslashit( (string) $this->settings['api_base_url'] );
		$url      = $base_url . '/' . ltrim( (string) $endpoint, '/' );

		$api_key = preg_replace( '/[\r\n]+/', '', (string) $this->settings['api_key'] );
		$headers = array(
			'Accept'     => 'application/json',
			'X-API-KEY'  => $api_key,
			'User-Agent' => 'Jetlinez-WooCommerce-Invoice/' . JLWI_VERSION . '; ' . home_url( '/' ),
		);

		if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
			$headers = array_merge( $headers, $args['headers'] );
		}

		$request_args = array(
			'method'      => strtoupper( (string) $method ),
			'timeout'     => max( 5, min( 120, (int) $this->settings['timeout'] ) ),
			'redirection' => max( 0, min( 10, (int) $this->settings['max_redirects'] ) ),
			'httpversion' => '1.1',
			'blocking'    => true,
			'sslverify'   => true,
			'headers'     => $headers,
			'data_format' => 'body',
		);

		if ( array_key_exists( 'body', $args ) ) {
			$request_args['body'] = $args['body'];
		}

		/**
		 * Filter the final Jetlinez HTTP request.
		 *
		 * Do not log or expose the X-API-KEY header in callbacks.
		 *
		 * @param array  $request_args WP HTTP arguments.
		 * @param string $url          Full request URL.
		 * @param string $method       HTTP method.
		 */
		$filtered_request_args = apply_filters( 'jlwi_http_request_args', $request_args, $url, strtoupper( (string) $method ) );
		if ( is_array( $filtered_request_args ) ) {
			$request_args = $filtered_request_args;
		}

		$response = wp_remote_request( $url, $request_args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'jlwi_transport_error',
				sprintf(
					/* translators: %s: transport error message. */
					__( 'ارتباط با API جتلاینز برقرار نشد: %s', JLWI_TEXT_DOMAIN ),
					$response->get_error_message()
				),
				array( 'original_error' => $response->get_error_code() )
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = (string) wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );
		$is_json     = JSON_ERROR_NONE === json_last_error() && is_array( $decoded );

		if ( $status_code >= 200 && $status_code < 300 ) {
			if ( $is_json && isset( $decoded['success'] ) && false === $decoded['success'] ) {
				return $this->response_error( $status_code, $decoded, $raw_body );
			}

			if ( $is_json ) {
				return $decoded;
			}

			if ( 204 === $status_code || '' === trim( $raw_body ) ) {
				return array( 'success' => true, 'data' => array() );
			}

			return new WP_Error(
				'jlwi_invalid_json',
				__( 'پاسخ API جتلاینز JSON معتبر نبود.', JLWI_TEXT_DOMAIN ),
				array(
					'status' => $status_code,
					'body'   => $this->body_excerpt( $raw_body ),
				)
			);
		}

		return $this->response_error( $status_code, $is_json ? $decoded : array(), $raw_body, $response );
	}

	/**
	 * Create a normalized WP_Error from an HTTP response.
	 *
	 * @param int   $status_code HTTP status.
	 * @param array $decoded     Decoded response body.
	 * @param string $raw_body   Raw response body.
	 * @param array|null $response Full WP response.
	 * @return WP_Error
	 */
	private function response_error( $status_code, $decoded, $raw_body, $response = null ) {
		$message = $this->extract_error_message( $decoded );
		if ( '' === $message ) {
			$message = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'API جتلاینز خطای HTTP %d برگرداند.', JLWI_TEXT_DOMAIN ),
				(int) $status_code
			);
		}

		$data = array(
			'status'   => (int) $status_code,
			'response' => $decoded,
			'body'     => $this->body_excerpt( $raw_body ),
		);

		if ( is_array( $response ) ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			if ( '' !== (string) $retry_after ) {
				$data['retry_after'] = (int) $retry_after;
			}
		}

		return new WP_Error( 'jlwi_http_' . (int) $status_code, $message, $data );
	}

	/**
	 * Extract a useful error message from the Jetlinez response shape.
	 *
	 * @param array $decoded Decoded body.
	 * @return string
	 */
	private function extract_error_message( $decoded ) {
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$candidates = array();
		if ( isset( $decoded['message'] ) ) {
			$candidates[] = $decoded['message'];
		}
		if ( isset( $decoded['error']['message'] ) ) {
			$candidates[] = $decoded['error']['message'];
		}
		if ( isset( $decoded['error']['name'] ) ) {
			$candidates[] = $decoded['error']['name'];
		}
		if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
			$candidates[] = $decoded['error'];
		}

		foreach ( $candidates as $candidate ) {
			if ( is_scalar( $candidate ) && '' !== trim( (string) $candidate ) ) {
				return sanitize_text_field( (string) $candidate );
			}
		}

		return '';
	}

	/**
	 * Limit response body stored in errors/logs.
	 *
	 * @param string $body Raw body.
	 * @return string
	 */
	private function body_excerpt( $body ) {
		$body = wp_strip_all_tags( (string) $body );
		return strlen( $body ) > 2000 ? substr( $body, 0, 2000 ) . '…' : $body;
	}
}
