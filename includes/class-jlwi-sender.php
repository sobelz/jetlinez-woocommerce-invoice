<?php
/**
 * WooCommerce order-event sender.
 *
 * @package JetlinezWooCommerceInvoice
 */

defined( 'ABSPATH' ) || exit;

final class JLWI_Sender {

	const PROCESS_HOOK = 'jlwi_process_order';
	const ACTION_GROUP = 'jetlinez-invoice';
	const RECORD_META  = '_jlwi_delivery_records';

	/** @var WC_Logger|null */
	private $logger = null;

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_status_change' ), 20, 4 );
		add_action( self::PROCESS_HOOK, array( $this, 'process_order' ), 10, 5 );
		add_filter( 'woocommerce_order_actions', array( $this, 'add_manual_order_action' ) );
		add_action( 'woocommerce_order_action_jlwi_send_invoice', array( $this, 'manual_order_action' ) );
	}

	/**
	 * Queue an automatic send after a target status transition.
	 *
	 * @param int           $order_id       Order ID.
	 * @param string        $previous_status Old status.
	 * @param string        $new_status      New status.
	 * @param WC_Order|null $order           Order object.
	 * @return void
	 */
	public function handle_status_change( $order_id, $previous_status, $new_status, $order = null ) {
		$new_status = JLWI_Settings::normalize_status( $new_status );

		if ( ! JLWI_Settings::enabled( 'enabled' ) || ! JLWI_Settings::is_target_status( $new_status ) ) {
			return;
		}

		$this->queue_order( (int) $order_id, $new_status, $previous_status, 0, false, 0 );
	}

	/**
	 * Add manual resend action to WooCommerce order actions.
	 *
	 * @param array $actions Existing actions.
	 * @return array
	 */
	public function add_manual_order_action( $actions ) {
		$actions['jlwi_send_invoice'] = __( 'ارسال/ارسال مجدد فاکتور با Jetlinez', JLWI_TEXT_DOMAIN );
		return $actions;
	}

	/**
	 * Handle manual WooCommerce order action.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function manual_order_action( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$queued = $this->queue_order( $order->get_id(), $order->get_status(), '', 0, true, 0 );
		if ( false === $queued ) {
			$order->add_order_note( __( 'Jetlinez: ثبت ارسال دستی در صف ناموفق بود؛ لاگ ووکامرس را بررسی کنید.', JLWI_TEXT_DOMAIN ) );
		} else {
			$order->add_order_note( __( 'ارسال دستی Jetlinez در صف قرار گرفت.', JLWI_TEXT_DOMAIN ) );
		}
	}

	/**
	 * Queue an order notification using Action Scheduler when available.
	 *
	 * @param int    $order_id       Order ID.
	 * @param string $status         Status slug.
	 * @param string $previous_status Previous status.
	 * @param int    $attempt        Retry number, starting at zero.
	 * @param bool   $force          Manual/bypass automatic rules.
	 * @param int    $delay          Delay in seconds.
	 * @return mixed Action ID, true, false, or null.
	 */
	public function queue_order( $order_id, $status, $previous_status = '', $attempt = 0, $force = false, $delay = 0 ) {
		$args = array(
			(int) $order_id,
			JLWI_Settings::normalize_status( $status ),
			JLWI_Settings::normalize_status( $previous_status ),
			(int) $attempt,
			(bool) $force,
		);

		$delay = max( 0, (int) $delay );

		if ( $delay > 0 && function_exists( 'as_schedule_single_action' ) ) {
			try {
				$action_id = as_schedule_single_action( time() + $delay, self::PROCESS_HOOK, $args, self::ACTION_GROUP, true );
				if ( ! empty( $action_id ) || $this->action_scheduler_has( $args ) ) {
					return ! empty( $action_id ) ? $action_id : true;
				}
			} catch ( Throwable $throwable ) {
				$this->log(
					'warning',
					'Action Scheduler could not schedule the Jetlinez retry; using WP-Cron fallback.',
					array( 'order_id' => (int) $order_id, 'error' => $throwable->getMessage() )
				);
			}
		}

		if ( 0 === $delay && function_exists( 'as_enqueue_async_action' ) ) {
			try {
				$action_id = as_enqueue_async_action( self::PROCESS_HOOK, $args, self::ACTION_GROUP, true );
				if ( ! empty( $action_id ) || $this->action_scheduler_has( $args ) ) {
					return ! empty( $action_id ) ? $action_id : true;
				}
			} catch ( Throwable $throwable ) {
				$this->log(
					'warning',
					'Action Scheduler could not enqueue the Jetlinez job; using WP-Cron fallback.',
					array( 'order_id' => (int) $order_id, 'error' => $throwable->getMessage() )
				);
			}
		}

		if ( function_exists( 'wp_schedule_single_event' ) ) {
			$timestamp = time() + max( 1, $delay );
			if ( wp_next_scheduled( self::PROCESS_HOOK, $args ) ) {
				return true;
			}

			$scheduled = wp_schedule_single_event( $timestamp, self::PROCESS_HOOK, $args );
			if ( false !== $scheduled && ! is_wp_error( $scheduled ) ) {
				return true;
			}
		}

		// Ensure the initial status notification is not lost on installations
		// where neither Action Scheduler nor WP-Cron scheduling is available.
		if ( 0 === $delay ) {
			$this->process_order( $args[0], $args[1], $args[2], $args[3], $args[4] );
			return true;
		}

		$this->log(
			'error',
			'Could not schedule Jetlinez retry.',
			array( 'order_id' => (int) $order_id, 'attempt' => (int) $attempt )
		);

		return false;
	}

	/**
	 * Process one queued order send.
	 *
	 * @param int        $order_id       Order ID.
	 * @param string     $status         Status slug.
	 * @param string     $previous_status Previous status.
	 * @param int        $attempt        Attempt number.
	 * @param bool|mixed $force          Manual/bypass flag.
	 * @return void
	 */
	public function process_order( $order_id, $status, $previous_status = '', $attempt = 0, $force = false ) {
		$order_id       = (int) $order_id;
		$status         = JLWI_Settings::normalize_status( $status );
		$previous_status = JLWI_Settings::normalize_status( $previous_status );
		$attempt        = max( 0, (int) $attempt );
		$force          = $this->to_bool( $force );
		$order          = wc_get_order( $order_id );

		if ( ! $order ) {
			$this->log( 'error', 'Order not found for Jetlinez send.', array( 'order_id' => $order_id ) );
			return;
		}

		if ( ! $force ) {
			if ( ! JLWI_Settings::enabled( 'enabled' ) || ! JLWI_Settings::is_target_status( $status ) ) {
				return;
			}
		}

		$lock = $this->acquire_lock( $order_id, $status );
		if ( false === $lock ) {
			$this->log( 'warning', 'Jetlinez order job is already running.', array( 'order_id' => $order_id, 'status' => $status ) );
			return;
		}

		$invoice = null;

		try {
			$client = new JLWI_API_Client();
			$config = $client->validate_configuration();
			if ( is_wp_error( $config ) ) {
				$this->record_terminal_configuration_error( $order, $config );
				return;
			}

			$recipients = $this->get_recipients( $order, $status );
			if ( empty( $recipients ) ) {
				$this->log( 'warning', 'No valid Jetlinez recipients found.', array( 'order_id' => $order_id ) );
				if ( JLWI_Settings::enabled( 'add_order_notes' ) ) {
					$order->add_order_note( __( 'Jetlinez: هیچ شماره گیرنده معتبری تنظیم نشده است.', JLWI_TEXT_DOMAIN ) );
				}
				return;
			}

			$records              = $this->load_records( $order );
			$prevent_duplicates   = JLWI_Settings::enabled( 'prevent_duplicates' );
			$reset_current_batch  = 0 === $attempt && ( $force || ! $prevent_duplicates );
			$states               = array();
			$pending              = array();

			foreach ( $recipients as $phone ) {
				$state = $this->get_state( $records, $status, $phone );
				if ( $reset_current_batch ) {
					$state = $this->blank_state( $phone, (int) $state['send_count'] + 1 );
				}

				$states[ $phone ] = $state;
				if ( ! $prevent_duplicates || $force || ! $state['complete'] || $attempt > 0 ) {
					$pending[] = $phone;
				}
			}

			if ( empty( $pending ) ) {
				$this->log( 'info', 'Jetlinez send skipped because all recipients were already completed.', array( 'order_id' => $order_id, 'status' => $status ) );
				return;
			}

			$send_pdf         = JLWI_Settings::enabled( 'send_pdf' );
			$send_text_pdf    = JLWI_Settings::enabled( 'send_text_with_pdf' );
			$needs_media      = false;
			$media_id         = '';
			$upload_error     = null;
			$fallback_note    = $send_pdf
				? __( 'نسخه PDF فاکتور در دسترس نبود و اطلاعات سفارش به‌صورت متنی ارسال شد.', JLWI_TEXT_DOMAIN )
				: __( 'ارسال متنی در تنظیمات افزونه انتخاب شده است.', JLWI_TEXT_DOMAIN );

			if ( $send_pdf ) {
				foreach ( $pending as $phone ) {
					$state = $states[ $phone ];
					if ( ! $state['media_sent'] && 'fallback' !== $state['mode'] ) {
						$needs_media = true;
						break;
					}
				}
			}

			if ( $needs_media ) {
				$invoice = $this->create_invoice_file( $order );
				if ( is_wp_error( $invoice ) ) {
					$upload_error = $invoice;
					$this->log_wp_error( 'warning', 'Invoice generation unavailable; using text fallback.', $invoice, $order_id );
					$invoice = null;
				} else {
					$upload = $client->upload_media( $invoice['path'] );
					if ( is_wp_error( $upload ) ) {
						$upload_error = $upload;
						$this->log_wp_error( 'error', 'Jetlinez media upload failed; using text fallback.', $upload, $order_id );
					} else {
						$media_id = (string) $upload['id'];
						$this->log( 'info', 'Invoice uploaded to Jetlinez media.', array( 'order_id' => $order_id, 'media_id' => $media_id ) );
					}
				}
			}

			// Lock each recipient into either PDF mode or fallback mode for this batch.
			foreach ( $pending as $phone ) {
				$state = $states[ $phone ];

				if ( '' === $state['mode'] ) {
					$state['mode'] = ( $send_pdf && '' !== $media_id ) ? 'pdf' : 'fallback';
				}

				if ( 'pdf' === $state['mode'] && ! $state['media_sent'] && '' === $media_id ) {
					$state['mode'] = 'fallback';
				}

				$states[ $phone ] = $state;
				$this->save_state( $order, $records, $status, $phone, $state );
			}

			$max_retries    = max( 0, min( 5, (int) JLWI_Settings::get( 'retry_count', 2 ) ) );
			$retry_needed   = false;
			$activity_count = 0;

			foreach ( $pending as $phone ) {
				$state = $states[ $phone ];
				if ( $state['complete'] && ! $reset_current_batch ) {
					continue;
				}

				$context = array(
					'previous_status' => $previous_status,
					'recipient'       => $phone,
				);

				if ( 'pdf' === $state['mode'] ) {
					if ( $send_text_pdf && ! $state['text_sent'] ) {
						$context['invoice_note'] = $state['media_sent']
							? __( 'فاکتور PDF قبلاً ارسال شده است.', JLWI_TEXT_DOMAIN )
							: __( 'فاکتور PDF در پیام بعدی ارسال می‌شود.', JLWI_TEXT_DOMAIN );
						$text = JLWI_Template::render( JLWI_Settings::status_template( $status ), $order, $status, true, $context );
						$res  = $client->send_message( $phone, $text );
						++$activity_count;

						if ( is_wp_error( $res ) ) {
							$this->mark_error( $state, $res, $attempt );
							$this->log_wp_error( 'error', 'Jetlinez status text failed.', $res, $order_id, $phone );
							if ( JLWI_API_Client::is_transient_error( $res ) && $attempt < $max_retries ) {
								$retry_needed = true;
							}
						} else {
							$state['text_sent'] = true;
							$state['last_response_id'] = $this->response_identifier( $res );
						}

						$this->touch_state( $state, $attempt );
						$this->save_state( $order, $records, $status, $phone, $state );
					}

					if ( ! $state['media_sent'] ) {
						if ( '' !== $media_id ) {
							$res = $client->send_message( $phone, '', $media_id );
							++$activity_count;

							if ( is_wp_error( $res ) ) {
								$this->mark_error( $state, $res, $attempt );
								$this->log_wp_error( 'error', 'Jetlinez PDF document send failed.', $res, $order_id, $phone );

								if ( JLWI_API_Client::is_transient_error( $res ) && $attempt < $max_retries ) {
									$retry_needed = true;
								} else {
									$state['mode']          = 'fallback';
									$state['fallback_note'] = __( 'ارسال فایل PDF ناموفق بود؛ اطلاعات سفارش به‌صورت متنی ارسال شد.', JLWI_TEXT_DOMAIN );
								}
							} else {
								$state['media_sent']          = true;
								$state['last_media_id']       = $media_id;
								$state['last_response_id']    = $this->response_identifier( $res );
							}
						} else {
							$state['mode'] = 'fallback';
						}

						$this->touch_state( $state, $attempt );
						$this->save_state( $order, $records, $status, $phone, $state );
					}

					if ( 'pdf' === $state['mode'] ) {
						$state['complete'] = $state['media_sent'] && ( ! $send_text_pdf || $state['text_sent'] );
						if ( $state['complete'] ) {
							$state['last_error']      = '';
							$state['last_error_code'] = '';
						}
					}
				}

				if ( 'fallback' === $state['mode'] && ! $state['fallback_sent'] ) {
					$fallback_context                 = $context;
					$fallback_context['invoice_note'] = '' !== (string) $state['fallback_note'] ? (string) $state['fallback_note'] : $fallback_note;
					$fallback = JLWI_Template::render(
						(string) JLWI_Settings::get( 'template_fallback' ),
						$order,
						$status,
						false,
						$fallback_context
					);
					$res      = $client->send_message( $phone, $fallback );
					++$activity_count;

					if ( is_wp_error( $res ) ) {
						$this->mark_error( $state, $res, $attempt );
						$this->log_wp_error( 'error', 'Jetlinez fallback text failed.', $res, $order_id, $phone );
						if ( JLWI_API_Client::is_transient_error( $res ) && $attempt < $max_retries ) {
							$retry_needed = true;
						}
					} else {
						$state['fallback_sent']     = true;
						$state['complete']          = true;
						$state['last_error']        = '';
						$state['last_error_code']   = '';
						$state['last_response_id']  = $this->response_identifier( $res );
					}
				}

				if ( 'fallback' === $state['mode'] && $state['fallback_sent'] ) {
					$state['complete'] = true;
				}

				$this->touch_state( $state, $attempt );
				$this->save_state( $order, $records, $status, $phone, $state );
				$states[ $phone ] = $state;

			}

			// Recalculate final totals across every configured recipient. A retry may
			// skip recipients completed by a previous attempt, but the order note
			// should still report the complete delivery batch rather than only the
			// recipients touched by the latest worker run.
			$completed_count = 0;
			$fallback_count  = 0;
			$failed_count    = 0;
			foreach ( $recipients as $phone ) {
				$final_state = isset( $states[ $phone ] ) ? $states[ $phone ] : $this->get_state( $records, $status, $phone );
				if ( ! empty( $final_state['complete'] ) ) {
					++$completed_count;
					if ( 'fallback' === (string) $final_state['mode'] ) {
						++$fallback_count;
					}
				} else {
					++$failed_count;
				}
			}

			if ( $retry_needed ) {
				$retry_needed = false;
				foreach ( $pending as $phone ) {
					if ( isset( $states[ $phone ] ) && ! $states[ $phone ]['complete'] ) {
						$retry_needed = true;
						break;
					}
				}
			}

			if ( $retry_needed && $attempt < $max_retries ) {
				$base_delay = max( 15, min( 3600, (int) JLWI_Settings::get( 'retry_base_delay', 60 ) ) );
				$delay      = min( 3600, $base_delay * ( 2 ** $attempt ) );
				$this->queue_order( $order_id, $status, $previous_status, $attempt + 1, $force, $delay );
				$this->log(
					'warning',
					'Jetlinez retry scheduled.',
					array( 'order_id' => $order_id, 'attempt' => $attempt + 1, 'delay' => $delay )
				);
			} elseif ( JLWI_Settings::enabled( 'add_order_notes' ) && $activity_count > 0 ) {
				$note = sprintf(
					/* translators: 1: completed recipients, 2: fallback recipients, 3: failed recipients. */
					__( 'Jetlinez: ارسال برای %1$d گیرنده تکمیل شد؛ %2$d مورد به‌صورت متنی و %3$d مورد ناموفق بود.', JLWI_TEXT_DOMAIN ),
					$completed_count,
					$fallback_count,
					$failed_count
				);
				$order->add_order_note( $note );
			}

			if ( '' !== $media_id && ! $retry_needed && JLWI_Settings::enabled( 'delete_remote_media' ) ) {
				$deleted = $client->delete_media( $media_id );
				if ( is_wp_error( $deleted ) ) {
					$this->log_wp_error( 'warning', 'Could not delete remote Jetlinez media.', $deleted, $order_id );
				} else {
					$this->log( 'info', 'Remote Jetlinez media deleted after send.', array( 'order_id' => $order_id, 'media_id' => $media_id ) );
				}
			}

			if ( $upload_error && JLWI_Settings::enabled( 'debug_logging' ) ) {
				$this->log_wp_error( 'debug', 'PDF fallback reason.', $upload_error, $order_id );
			}
		} catch ( Throwable $throwable ) {
			$this->log(
				'error',
				'Unexpected Jetlinez order processing exception: ' . $throwable->getMessage(),
				array( 'order_id' => $order_id, 'exception' => get_class( $throwable ) )
			);

			if ( JLWI_Settings::enabled( 'add_order_notes' ) ) {
				$order->add_order_note( __( 'Jetlinez: یک خطای پیش‌بینی‌نشده هنگام ارسال رخ داد. جزئیات در لاگ ووکامرس ثبت شد.', JLWI_TEXT_DOMAIN ) );
			}
		} finally {
			if ( is_array( $invoice ) && ! empty( $invoice['path'] ) && ! empty( $invoice['temporary'] ) && JLWI_Settings::enabled( 'delete_local_pdf' ) ) {
				$this->delete_local_file( $invoice['path'] );
			}
			$this->release_lock( $lock );
		}
	}

	/**
	 * Generate an invoice file using a custom filter or Pepro Ultimate Invoice.
	 *
	 * @param WC_Order $order Order object.
	 * @return array|WP_Error
	 */
	private function create_invoice_file( $order ) {
		$order_id = $order->get_id();

		/**
		 * Supply a PDF file from another invoice provider.
		 * Return an absolute readable path, or an empty string to use Pepro.
		 *
		 * @param string   $path  Existing path (initially empty).
		 * @param int      $order_id Order ID.
		 * @param WC_Order $order Order object.
		 */
		$custom_path = apply_filters( 'jlwi_invoice_file_path', '', $order_id, $order );
		if ( is_string( $custom_path ) && '' !== $custom_path ) {
			$valid = $this->validate_invoice_path( $custom_path );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			return array(
				'path'      => $custom_path,
				'temporary' => (bool) apply_filters( 'jlwi_custom_invoice_is_temporary', false, $custom_path, $order_id, $order ),
				'source'    => 'filter',
			);
		}

		if ( empty( $GLOBALS['PeproUltimateInvoice'] ) || ! is_object( $GLOBALS['PeproUltimateInvoice'] ) || ! method_exists( $GLOBALS['PeproUltimateInvoice'], 'make_pdf_file' ) ) {
			return new WP_Error(
				'jlwi_invoice_plugin_missing',
				__( 'افزونه PeproDev Ultimate Invoice فعال نیست یا متد تولید PDF آن در دسترس نیست.', JLWI_TEXT_DOMAIN )
			);
		}

		$buffer_level = ob_get_level();
		$path         = false;
		$caught       = null;
		$die_filters  = array();
		$runtime      = array(
			'error_reporting'      => error_reporting(),
			'display_errors'       => ini_get( 'display_errors' ),
			'max_execution_time'   => ini_get( 'max_execution_time' ),
			'pcre.backtrack_limit' => ini_get( 'pcre.backtrack_limit' ),
			'memory_limit'         => ini_get( 'memory_limit' ),
		);

		// Pepro's PDF routine may call wp_die() on an mPDF exception. Replacing
		// the handler only for this call turns that hard stop into a catchable
		// exception so the order can still use the required text fallback.
		if ( function_exists( 'add_filter' ) && function_exists( 'remove_filter' ) ) {
			$die_handler = static function ( $message = '', $title = '', $args = array() ) {
				if ( is_wp_error( $message ) ) {
					$text = $message->get_error_message();
				} elseif ( is_scalar( $message ) ) {
					$text = wp_strip_all_tags( (string) $message );
				} else {
					$text = __( 'تولید PDF فاکتور متوقف شد.', JLWI_TEXT_DOMAIN );
				}

				throw new RuntimeException( '' !== trim( $text ) ? $text : __( 'تولید PDF فاکتور متوقف شد.', JLWI_TEXT_DOMAIN ) );
			};
			$die_filter = static function () use ( $die_handler ) {
				return $die_handler;
			};

			// Action Scheduler can execute through AJAX, WP-Cron, REST-like or
			// regular requests. WordPress selects a different wp_die handler filter
			// for each context, so temporarily cover all core handler variants.
			$die_filters = array(
				'wp_die_handler',
				'wp_die_ajax_handler',
				'wp_die_json_handler',
				'wp_die_jsonp_handler',
				'wp_die_xmlrpc_handler',
				'wp_die_xml_handler',
			);
			foreach ( $die_filters as $filter_name ) {
				add_filter( $filter_name, $die_filter, PHP_INT_MAX );
			}
		}

		ob_start();
		try {
			$path = $GLOBALS['PeproUltimateInvoice']->make_pdf_file( $order_id );
		} catch ( Throwable $throwable ) {
			$caught = $throwable;
		} finally {
			if ( isset( $die_filter ) && is_callable( $die_filter ) ) {
				foreach ( $die_filters as $filter_name ) {
					remove_filter( $filter_name, $die_filter, PHP_INT_MAX );
				}
			}

			error_reporting( (int) $runtime['error_reporting'] );
			foreach ( array( 'display_errors', 'max_execution_time', 'pcre.backtrack_limit', 'memory_limit' ) as $setting_name ) {
				if ( false !== $runtime[ $setting_name ] ) {
					@ini_set( $setting_name, (string) $runtime[ $setting_name ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}

			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
		}

		if ( $caught instanceof Throwable ) {
			return new WP_Error( 'jlwi_invoice_generation_failed', $caught->getMessage() );
		}

		if ( ! is_string( $path ) || '' === $path ) {
			return new WP_Error( 'jlwi_invoice_generation_empty', __( 'افزونه فاکتور مسیر فایل PDF را برنگرداند.', JLWI_TEXT_DOMAIN ) );
		}

		$valid = $this->validate_invoice_path( $path );
		if ( is_wp_error( $valid ) ) {
			if ( JLWI_Settings::enabled( 'delete_local_pdf' ) && is_file( $path ) ) {
				$this->delete_local_file( $path );
			}
			return $valid;
		}

		return array(
			'path'      => $path,
			'temporary' => true,
			'source'    => 'pepro-ultimate-invoice',
		);
	}

	/**
	 * Validate an invoice path.
	 *
	 * @param string $path File path.
	 * @return true|WP_Error
	 */
	private function validate_invoice_path( $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'jlwi_invoice_file_missing', __( 'فایل PDF فاکتور ایجاد نشد یا قابل خواندن نیست.', JLWI_TEXT_DOMAIN ) );
		}

		if ( (int) filesize( $path ) <= 0 ) {
			return new WP_Error( 'jlwi_invoice_file_empty', __( 'فایل PDF فاکتور خالی است.', JLWI_TEXT_DOMAIN ) );
		}

		$header = file_get_contents( $path, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $header || false === strpos( $header, '%PDF-' ) ) {
			return new WP_Error( 'jlwi_invoice_file_invalid', __( 'فایل تولیدشده ساختار معتبر PDF ندارد.', JLWI_TEXT_DOMAIN ) );
		}

		return true;
	}

	/**
	 * Build and normalize configured recipients.
	 *
	 * @param WC_Order $order  Order object.
	 * @param string   $status Status slug.
	 * @return array
	 */
	private function get_recipients( $order, $status ) {
		$raw_numbers = preg_split( '/[\r\n,;،؛]+/u', (string) JLWI_Settings::get( 'fixed_recipients', '' ) );
		$raw_numbers = is_array( $raw_numbers ) ? $raw_numbers : array();

		if ( JLWI_Settings::enabled( 'include_billing_phone' ) ) {
			$billing_phone = trim( (string) $order->get_billing_phone() );
			if ( '' !== $billing_phone ) {
				$raw_numbers[] = $billing_phone;
			}
		}

		/**
		 * Filter raw recipient values before normalization.
		 *
		 * @param array    $raw_numbers Raw values.
		 * @param WC_Order $order       Order object.
		 * @param string   $status      Status slug.
		 */
		$raw_numbers = apply_filters( 'jlwi_order_recipients', $raw_numbers, $order, $status );
		$raw_numbers = is_array( $raw_numbers ) ? $raw_numbers : array();
		$country_code = (string) JLWI_Settings::get( 'default_country_code', '98' );
		$recipients   = array();

		foreach ( $raw_numbers as $raw_number ) {
			$phone = self::normalize_phone( $raw_number, $country_code );
			if ( '' !== $phone ) {
				$recipients[ $phone ] = $phone;
			}
		}

		$max = (int) apply_filters( 'jlwi_max_recipients_per_order', 100, $order, $status );
		return array_slice( array_values( $recipients ), 0, max( 1, $max ) );
	}

	/**
	 * Normalize a phone number to digits suitable for Baileys/JID encoding.
	 *
	 * Examples with country code 98:
	 * +98912... -> 98912...
	 * 0098912... -> 98912...
	 * 0912... -> 98912...
	 * 912... -> 98912...
	 *
	 * @param mixed  $raw          Raw number.
	 * @param string $country_code Default country calling code without +.
	 * @return string Empty string when invalid.
	 */
	public static function normalize_phone( $raw, $country_code = '98' ) {
		if ( ! is_scalar( $raw ) || ! is_scalar( $country_code ) ) {
			return '';
		}

		$number       = JLWI_Settings::ascii_digits( trim( (string) $raw ) );
		$country_code = preg_replace( '/\D+/', '', JLWI_Settings::ascii_digits( $country_code ) );
		$number       = preg_replace( '/\D+/', '', $number );

		if ( 0 === strpos( $country_code, '00' ) ) {
			$country_code = substr( $country_code, 2 );
		}
		$country_code = ltrim( (string) $country_code, '0' );

		if ( 0 === strpos( $number, '00' ) ) {
			$number = substr( $number, 2 );
		}

		if ( '' !== $country_code && 0 === strpos( $number, '0' ) ) {
			$number = $country_code . ltrim( $number, '0' );
		} elseif ( '' !== $country_code && strlen( $number ) <= 10 && 0 !== strpos( $number, $country_code ) ) {
			$number = $country_code . $number;
		}

		$length = strlen( $number );
		if ( $length < 8 || $length > 15 ) {
			return '';
		}

		/**
		 * Filter a normalized recipient number.
		 * Return an empty string to reject it.
		 *
		 * @param string $number       Digits-only number.
		 * @param mixed  $raw          Original value.
		 * @param string $country_code Default country code.
		 */
		$filtered_number = apply_filters( 'jlwi_normalized_phone', $number, $raw, $country_code );
		if ( ! is_scalar( $filtered_number ) ) {
			return '';
		}

		$number = (string) $filtered_number;
		return preg_match( '/^[0-9]{8,15}$/', $number ) ? $number : '';
	}

	/**
	 * Load delivery state from HPOS-safe order metadata.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function load_records( $order ) {
		$records = $order->get_meta( self::RECORD_META, true );
		return is_array( $records ) ? $records : array();
	}

	/**
	 * Read a recipient state.
	 *
	 * @param array  $records Records.
	 * @param string $status  Status.
	 * @param string $phone   Number.
	 * @return array
	 */
	private function get_state( $records, $status, $phone ) {
		$key   = hash( 'sha256', $phone );
		$state = isset( $records[ $status ][ $key ] ) && is_array( $records[ $status ][ $key ] )
			? $records[ $status ][ $key ]
			: array();

		return wp_parse_args( $state, $this->blank_state( $phone, isset( $state['send_count'] ) ? (int) $state['send_count'] : 0 ) );
	}

	/**
	 * Persist one recipient state immediately.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $records Records passed by reference.
	 * @param string   $status  Status.
	 * @param string   $phone   Number.
	 * @param array    $state   State.
	 * @return void
	 */
	private function save_state( $order, &$records, $status, $phone, $state ) {
		$key = hash( 'sha256', $phone );
		if ( ! isset( $records[ $status ] ) || ! is_array( $records[ $status ] ) ) {
			$records[ $status ] = array();
		}
		$records[ $status ][ $key ] = $state;
		$order->update_meta_data( self::RECORD_META, $records );
		$order->save_meta_data();
	}

	/**
	 * Default recipient state.
	 *
	 * @param string $phone      Number.
	 * @param int    $send_count Batch counter.
	 * @return array
	 */
	private function blank_state( $phone, $send_count = 0 ) {
		return array(
			'phone_mask'        => $this->mask_phone( $phone ),
			'mode'              => '',
			'text_sent'         => false,
			'media_sent'        => false,
			'fallback_sent'     => false,
			'fallback_note'     => '',
			'complete'          => false,
			'last_media_id'     => '',
			'last_response_id'  => '',
			'last_error'        => '',
			'last_error_code'   => '',
			'last_attempt'      => 0,
			'updated_at'        => '',
			'send_count'        => max( 0, (int) $send_count ),
		);
	}

	/**
	 * Mark state with an API error.
	 *
	 * @param array    $state   State by reference.
	 * @param WP_Error $error   Error.
	 * @param int      $attempt Attempt.
	 * @return void
	 */
	private function mark_error( &$state, $error, $attempt ) {
		$state['last_error']      = $error->get_error_message();
		$state['last_error_code'] = $error->get_error_code();
		$this->touch_state( $state, $attempt );
	}

	/**
	 * Update common state timestamps.
	 *
	 * @param array $state   State by reference.
	 * @param int   $attempt Attempt.
	 * @return void
	 */
	private function touch_state( &$state, $attempt ) {
		$state['last_attempt'] = (int) $attempt;
		$state['updated_at']   = current_time( 'mysql', true );
	}

	/**
	 * Extract a useful message record ID from a successful API response.
	 *
	 * @param array $response API response.
	 * @return string
	 */
	private function response_identifier( $response ) {
		if ( ! is_array( $response ) || ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return '';
		}

		foreach ( array( 'id', 'messageId', 'message_id' ) as $key ) {
			if ( isset( $response['data'][ $key ] ) && is_scalar( $response['data'][ $key ] ) ) {
				return sanitize_text_field( (string) $response['data'][ $key ] );
			}
		}

		return '';
	}

	/**
	 * Record missing API configuration in logs/order notes.
	 *
	 * @param WC_Order $order Order.
	 * @param WP_Error $error Error.
	 * @return void
	 */
	private function record_terminal_configuration_error( $order, $error ) {
		$this->log_wp_error( 'error', 'Jetlinez API configuration is incomplete.', $error, $order->get_id() );
		if ( JLWI_Settings::enabled( 'add_order_notes' ) ) {
			$order->add_order_note( 'Jetlinez: ' . $error->get_error_message() );
		}
	}

	/**
	 * Check whether Action Scheduler already contains this exact job.
	 *
	 * @param array $args Action arguments.
	 * @return bool
	 */
	private function action_scheduler_has( $args ) {
		return function_exists( 'as_has_scheduled_action' )
			&& (bool) as_has_scheduled_action( self::PROCESS_HOOK, $args, self::ACTION_GROUP );
	}

	/**
	 * Acquire an atomic short-lived lock.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status   Status.
	 * @return string|false Option key or false.
	 */
	private function acquire_lock( $order_id, $status ) {
		$key = 'jlwi_lock_' . md5( get_current_blog_id() . '|' . (int) $order_id . '|' . (string) $status );
		$now = time();

		if ( add_option( $key, $now, '', 'no' ) ) {
			return $key;
		}

		$existing = (int) get_option( $key, 0 );
		if ( $existing > 0 && ( $now - $existing ) > 10 * MINUTE_IN_SECONDS ) {
			delete_option( $key );
			if ( add_option( $key, $now, '', 'no' ) ) {
				return $key;
			}
		}

		return false;
	}

	/**
	 * Release a lock option.
	 *
	 * @param string|false $key Lock key.
	 * @return void
	 */
	private function release_lock( $key ) {
		if ( is_string( $key ) && '' !== $key ) {
			delete_option( $key );
		}
	}

	/**
	 * Delete a generated local PDF.
	 *
	 * @param string $path File path.
	 * @return void
	 */
	private function delete_local_file( $path ) {
		if ( is_file( $path ) ) {
			if ( function_exists( 'wp_delete_file' ) ) {
				wp_delete_file( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}

	/**
	 * Convert serialized action values to boolean safely.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function to_bool( $value ) {
		return true === $value || 1 === $value || '1' === $value || 'yes' === $value || 'true' === $value;
	}

	/**
	 * Mask a phone number for logs/order metadata.
	 *
	 * @param string $phone Number.
	 * @return string
	 */
	private function mask_phone( $phone ) {
		$phone = (string) $phone;
		$len   = strlen( $phone );
		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}
		return substr( $phone, 0, 2 ) . str_repeat( '*', max( 2, $len - 6 ) ) . substr( $phone, -4 );
	}

	/**
	 * Log a WP_Error with redacted recipient context.
	 *
	 * @param string   $level    Log level.
	 * @param string   $message  Message.
	 * @param WP_Error $error    Error.
	 * @param int      $order_id Order ID.
	 * @param string   $phone    Optional phone.
	 * @return void
	 */
	private function log_wp_error( $level, $message, $error, $order_id, $phone = '' ) {
		$data    = $error->get_error_data();
		$context = array(
			'order_id'  => (int) $order_id,
			'error_code' => $error->get_error_code(),
			'error'     => $error->get_error_message(),
		);

		if ( '' !== $phone ) {
			$context['recipient'] = $this->mask_phone( $phone );
		}
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$context['http_status'] = (int) $data['status'];
		}
		if ( JLWI_Settings::enabled( 'debug_logging' ) && is_array( $data ) && isset( $data['body'] ) ) {
			$context['response_excerpt'] = (string) $data['body'];
		}

		$this->log( $level, $message, $context );
	}

	/**
	 * Write to WooCommerce logger; errors are always logged, info/debug only
	 * when debug logging is enabled.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	private function log( $level, $message, $context = array() ) {
		$level = strtolower( (string) $level );
		if ( in_array( $level, array( 'debug', 'info' ), true ) && ! JLWI_Settings::enabled( 'debug_logging' ) ) {
			return;
		}

		$context           = is_array( $context ) ? $context : array();
		$context['source'] = 'jetlinez-invoice';

		if ( function_exists( 'wc_get_logger' ) ) {
			if ( null === $this->logger ) {
				$this->logger = wc_get_logger();
			}
			if ( is_object( $this->logger ) && method_exists( $this->logger, 'log' ) ) {
				$this->logger->log( $level, (string) $message, $context );
				return;
			}
		}

		error_log( '[jetlinez-invoice][' . $level . '] ' . (string) $message . ' ' . wp_json_encode( $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
