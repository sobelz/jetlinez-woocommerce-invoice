<?php
/**
 * WordPress admin settings page.
 *
 * @package JetlinezWooCommerceInvoice
 */

defined( 'ABSPATH' ) || exit;

final class JLWI_Admin {

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_jlwi_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_jlwi_send_test', array( $this, 'send_test' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
	}

	/**
	 * Add settings submenu.
	 *
	 * @return void
	 */
	public function admin_menu() {
		$capability = $this->capability();

		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page(
				'woocommerce',
				__( 'تنظیمات Jetlinez', JLWI_TEXT_DOMAIN ),
				__( 'Jetlinez WhatsApp', JLWI_TEXT_DOMAIN ),
				$capability,
				'jlwi-settings',
				array( $this, 'render_page' )
			);
		} else {
			add_options_page(
				__( 'تنظیمات Jetlinez', JLWI_TEXT_DOMAIN ),
				__( 'Jetlinez WhatsApp', JLWI_TEXT_DOMAIN ),
				'manage_options',
				'jlwi-settings',
				array( $this, 'render_page' )
			);
		}
	}

	/**
	 * Load admin CSS only on this plugin's page.
	 *
	 * @param string $hook_suffix Current admin hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'jlwi-settings' ) ) {
			return;
		}

		wp_enqueue_style( 'jlwi-admin', JLWI_URL . 'assets/admin.css', array(), JLWI_VERSION );
	}

	/**
	 * Render the complete settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( $this->capability() ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', JLWI_TEXT_DOMAIN ) );
		}

		$settings       = JLWI_Settings::raw();
		$effective      = JLWI_Settings::all();
		$api_key_locked = defined( 'JLWI_API_KEY' ) && '' !== trim( (string) JLWI_API_KEY );
		$base_locked    = defined( 'JLWI_API_BASE_URL' ) && '' !== trim( (string) JLWI_API_BASE_URL );
		$device_locked  = defined( 'JLWI_DEVICE_ID' ) && '' !== trim( (string) JLWI_DEVICE_ID );
		?>
		<div class="wrap jlwi-wrap" dir="rtl">
			<h1><?php echo esc_html__( 'Jetlinez Invoice برای WooCommerce', JLWI_TEXT_DOMAIN ); ?></h1>
			<p class="jlwi-lead">
				<?php echo esc_html__( 'ارسال خودکار پیام وضعیت و فاکتور سفارش‌های ووکامرس از طریق واتساپ جتلاینز.', JLWI_TEXT_DOMAIN ); ?>
			</p>

			<div class="jlwi-status-grid">
				<?php $this->status_card( __( 'اتصال API', JLWI_TEXT_DOMAIN ), JLWI_Settings::is_api_configured(), JLWI_Settings::is_api_configured() ? __( 'تنظیمات کامل است', JLWI_TEXT_DOMAIN ) : __( 'API Key یا Device ID ناقص است', JLWI_TEXT_DOMAIN ) ); ?>
				<?php $this->status_card( __( 'WooCommerce', JLWI_TEXT_DOMAIN ), class_exists( 'WooCommerce' ), class_exists( 'WooCommerce' ) ? __( 'فعال', JLWI_TEXT_DOMAIN ) : __( 'غیرفعال', JLWI_TEXT_DOMAIN ) ); ?>
				<?php $this->status_card( __( 'Ultimate Invoice', JLWI_TEXT_DOMAIN ), $this->invoice_available(), $this->invoice_available() ? __( 'تولید PDF آماده است', JLWI_TEXT_DOMAIN ) : __( 'حالت متنی استفاده می‌شود', JLWI_TEXT_DOMAIN ) ); ?>
				<?php $this->status_card( __( 'صف ارسال', JLWI_TEXT_DOMAIN ), function_exists( 'as_enqueue_async_action' ), function_exists( 'as_enqueue_async_action' ) ? __( 'Action Scheduler فعال است', JLWI_TEXT_DOMAIN ) : __( 'از WP-Cron/ارسال مستقیم استفاده می‌شود', JLWI_TEXT_DOMAIN ) ); ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="jlwi_save_settings">
				<?php wp_nonce_field( 'jlwi_save_settings' ); ?>

				<section class="jlwi-card">
					<h2><?php echo esc_html__( '۱. اتصال به Jetlinez', JLWI_TEXT_DOMAIN ); ?></h2>
					<table class="form-table" role="presentation">
						<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'فعال‌سازی ارسال خودکار', JLWI_TEXT_DOMAIN ); ?></th>
							<td><?php $this->checkbox( 'enabled', $settings['enabled'], __( 'با تغییر وضعیت سفارش، ارسال انجام شود.', JLWI_TEXT_DOMAIN ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="jlwi-api-base-url"><?php echo esc_html__( 'Base URL API', JLWI_TEXT_DOMAIN ); ?></label></th>
							<td>
								<input id="jlwi-api-base-url" type="url" class="regular-text ltr" name="jlwi[api_base_url]" value="<?php echo esc_attr( $base_locked ? $effective['api_base_url'] : $settings['api_base_url'] ); ?>" <?php disabled( $base_locked ); ?>>
								<p class="description"><code>https://my.jetlinez.com/api/v1</code><?php echo $base_locked ? ' — ' . esc_html__( 'از ثابت JLWI_API_BASE_URL خوانده می‌شود.', JLWI_TEXT_DOMAIN ) : ''; ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jlwi-api-key"><?php echo esc_html__( 'API Key', JLWI_TEXT_DOMAIN ); ?></label></th>
							<td>
								<input id="jlwi-api-key" type="password" class="regular-text ltr" name="jlwi[api_key]" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $api_key_locked || '' !== $settings['api_key'] ? __( 'تنظیم شده؛ برای حفظ مقدار خالی بگذارید', JLWI_TEXT_DOMAIN ) : __( 'X-API-KEY', JLWI_TEXT_DOMAIN ) ); ?>" <?php disabled( $api_key_locked ); ?>>
								<p class="description"><?php echo esc_html__( 'کلید در هدر X-API-KEY ارسال می‌شود و هرگز در لاگ ثبت نمی‌شود.', JLWI_TEXT_DOMAIN ); ?><?php echo $api_key_locked ? ' ' . esc_html__( 'مقدار از ثابت JLWI_API_KEY خوانده می‌شود.', JLWI_TEXT_DOMAIN ) : ''; ?></p>
								<?php if ( ! $api_key_locked && '' !== $settings['api_key'] ) : ?>
									<label><input type="checkbox" name="jlwi[clear_api_key]" value="yes"> <?php echo esc_html__( 'کلید ذخیره‌شده پاک شود.', JLWI_TEXT_DOMAIN ); ?></label>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jlwi-device-id"><?php echo esc_html__( 'Device ID واتساپ', JLWI_TEXT_DOMAIN ); ?></label></th>
							<td>
								<input id="jlwi-device-id" type="text" class="regular-text ltr" name="jlwi[device_id]" value="<?php echo esc_attr( $device_locked ? $effective['device_id'] : $settings['device_id'] ); ?>" <?php disabled( $device_locked ); ?>>
								<p class="description"><?php echo esc_html__( 'شناسه دستگاه متصل در پنل جتلاینز.', JLWI_TEXT_DOMAIN ); ?><?php echo $device_locked ? ' ' . esc_html__( 'از ثابت JLWI_DEVICE_ID خوانده می‌شود.', JLWI_TEXT_DOMAIN ) : ''; ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'تأخیر طبیعی جتلاینز', JLWI_TEXT_DOMAIN ); ?></th>
							<td><?php $this->checkbox( 'without_delay', $settings['without_delay'], __( 'بدون تأخیر تایپ جتلاینز ارسال شود (پارامتر without_delay).', JLWI_TEXT_DOMAIN ) ); ?></td>
						</tr>
						</tbody>
					</table>
				</section>

				<section class="jlwi-card">
					<h2><?php echo esc_html__( '۲. وضعیت‌ها و گیرنده‌ها', JLWI_TEXT_DOMAIN ); ?></h2>
					<table class="form-table" role="presentation">
						<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'وضعیت‌های محرک', JLWI_TEXT_DOMAIN ); ?></th>
							<td>
								<?php $this->checkbox( 'enable_processing', $settings['enable_processing'], __( 'در حال انجام (Processing)', JLWI_TEXT_DOMAIN ) ); ?><br>
								<?php $this->checkbox( 'enable_completed', $settings['enable_completed'], __( 'تکمیل شده (Completed)', JLWI_TEXT_DOMAIN ) ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jlwi-fixed-recipients"><?php echo esc_html__( 'شماره‌های ثابت گیرنده', JLWI_TEXT_DOMAIN ); ?></label></th>
							<td>
								<textarea id="jlwi-fixed-recipients" class="large-text code ltr" rows="6" name="jlwi[fixed_recipients]" placeholder="989121234567&#10;989351234567"><?php echo esc_textarea( $settings['fixed_recipients'] ); ?></textarea>
								<p class="description"><?php echo esc_html__( 'هر شماره در یک خط؛ جداکننده ویرگول یا نقطه‌ویرگول نیز پذیرفته می‌شود.', JLWI_TEXT_DOMAIN ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'ارسال فاکتور به خریدار', JLWI_TEXT_DOMAIN ); ?></th>
							<td>
								<?php $this->checkbox( 'include_billing_phone', $settings['include_billing_phone'], __( 'فاکتور علاوه بر گیرنده‌های ثابت، به شماره صورتحساب خریدار نیز ارسال شود.', JLWI_TEXT_DOMAIN ) ); ?>
								<p class="description"><?php echo esc_html__( 'ارسال به خریدار فقط زمانی انجام می‌شود که سفارش شماره صورتحساب معتبر داشته باشد. با خاموش‌کردن این گزینه، ارسال به گیرنده‌های ثابت بدون تغییر ادامه پیدا می‌کند.', JLWI_TEXT_DOMAIN ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jlwi-country-code"><?php echo esc_html__( 'کد کشور پیش‌فرض', JLWI_TEXT_DOMAIN ); ?></label></th>
							<td>
								<input id="jlwi-country-code" type="text" class="small-text ltr" inputmode="numeric" name="jlwi[default_country_code]" value="<?php echo esc_attr( $settings['default_country_code'] ); ?>">
								<p class="description"><?php echo esc_html__( 'بدون علامت +؛ برای ایران 98. شماره 0912… به 98912… تبدیل می‌شود.', JLWI_TEXT_DOMAIN ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'ارسال تکراری', JLWI_TEXT_DOMAIN ); ?></th>
							<td><?php $this->checkbox( 'prevent_duplicates', $settings['prevent_duplicates'], __( 'برای هر وضعیت و هر گیرنده فقط یک ارسال موفق ثبت شود.', JLWI_TEXT_DOMAIN ) ); ?></td>
						</tr>
						</tbody>
					</table>
				</section>

				<section class="jlwi-card">
					<h2><?php echo esc_html__( '۳. فاکتور و رفتار جایگزین', JLWI_TEXT_DOMAIN ); ?></h2>
					<table class="form-table" role="presentation">
						<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'فاکتور PDF', JLWI_TEXT_DOMAIN ); ?></th>
							<td>
								<?php $this->checkbox( 'send_pdf', $settings['send_pdf'], __( 'در صورت دسترسی به Ultimate Invoice، PDF تولید و ارسال شود.', JLWI_TEXT_DOMAIN ) ); ?><br>
								<?php $this->checkbox( 'send_text_with_pdf', $settings['send_text_with_pdf'], __( 'همراه PDF، پیام وضعیت نیز جداگانه ارسال شود.', JLWI_TEXT_DOMAIN ) ); ?>
								<p class="description"><?php echo esc_html__( 'API جتلاینز برای document کپشن متنی را منتقل نمی‌کند؛ بنابراین متن و PDF دو پیام جدا هستند.', JLWI_TEXT_DOMAIN ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'پاک‌سازی فایل‌ها', JLWI_TEXT_DOMAIN ); ?></th>
							<td>
								<?php $this->checkbox( 'delete_local_pdf', $settings['delete_local_pdf'], __( 'فایل موقت PDF روی هاست پس از پردازش حذف شود.', JLWI_TEXT_DOMAIN ) ); ?><br>
								<?php $this->checkbox( 'delete_remote_media', $settings['delete_remote_media'], __( 'مدیای آپلودشده در جتلاینز پس از پایان موفق ارسال حذف شود.', JLWI_TEXT_DOMAIN ) ); ?>
								<p class="description jlwi-warning-text"><?php echo esc_html__( 'حذف مدیای جتلاینز به‌صورت پیش‌فرض خاموش است تا سابقه فایل در پنل باقی بماند.', JLWI_TEXT_DOMAIN ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jlwi-max-file-mb"><?php echo esc_html__( 'سقف حجم فایل', JLWI_TEXT_DOMAIN ); ?></label></th>
							<td>
								<input id="jlwi-max-file-mb" type="number" class="small-text" min="1" max="100" name="jlwi[max_file_mb]" value="<?php echo esc_attr( $settings['max_file_mb'] ); ?>"> <?php echo esc_html__( 'مگابایت', JLWI_TEXT_DOMAIN ); ?>
								<p class="description"><?php echo esc_html__( 'جتلاینز برای document تا ۱۰۰ مگابایت می‌پذیرد؛ مقدار پیش‌فرض محافظه‌کارانه ۲۰ مگابایت است.', JLWI_TEXT_DOMAIN ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'ثبت یادداشت سفارش', JLWI_TEXT_DOMAIN ); ?></th>
							<td><?php $this->checkbox( 'add_order_notes', $settings['add_order_notes'], __( 'خلاصه نتیجه نهایی در یادداشت سفارش ثبت شود.', JLWI_TEXT_DOMAIN ) ); ?></td>
						</tr>
						</tbody>
					</table>
				</section>

				<section class="jlwi-card">
					<h2><?php echo esc_html__( '۴. متن پیام‌ها', JLWI_TEXT_DOMAIN ); ?></h2>
					<p><?php echo esc_html__( 'پیام وضعیت پیش از PDF ارسال می‌شود. اگر افزونه فاکتور موجود نباشد، تولید/آپلود PDF شکست بخورد یا ارسال فایل نهایی ناموفق باشد، قالب جایگزین متنی استفاده می‌شود.', JLWI_TEXT_DOMAIN ); ?></p>
					<table class="form-table" role="presentation">
						<tbody>
						<?php $this->template_row( 'template_processing', __( 'قالب «در حال انجام»', JLWI_TEXT_DOMAIN ), $settings['template_processing'] ); ?>
						<?php $this->template_row( 'template_completed', __( 'قالب «تکمیل شده»', JLWI_TEXT_DOMAIN ), $settings['template_completed'] ); ?>
						<?php $this->template_row( 'template_generic', __( 'قالب ارسال دستی/سایر وضعیت‌ها', JLWI_TEXT_DOMAIN ), $settings['template_generic'] ); ?>
						<?php $this->template_row( 'template_fallback', __( 'قالب جایگزین متنی', JLWI_TEXT_DOMAIN ), $settings['template_fallback'] ); ?>
						</tbody>
					</table>
					<div class="jlwi-token-list ltr">
						<code>{order_id}</code> <code>{order_number}</code> <code>{status}</code> <code>{previous_status}</code>
						<code>{customer_name}</code> <code>{customer_phone}</code> <code>{customer_email}</code>
						<code>{order_total}</code> <code>{order_date}</code> <code>{payment_method}</code>
						<code>{shipping_method}</code> <code>{items}</code> <code>{billing_address}</code>
						<code>{shipping_address}</code> <code>{site_name}</code> <code>{site_url}</code> <code>{invoice_note}</code>
					</div>
				</section>

				<section class="jlwi-card">
					<h2><?php echo esc_html__( '۵. شبکه، تلاش مجدد و لاگ', JLWI_TEXT_DOMAIN ); ?></h2>
					<table class="form-table" role="presentation">
						<tbody>
						<tr>
							<th scope="row"><label for="jlwi-timeout"><?php echo esc_html__( 'Timeout', JLWI_TEXT_DOMAIN ); ?></label></th>
							<td><input id="jlwi-timeout" type="number" class="small-text" min="5" max="120" name="jlwi[timeout]" value="<?php echo esc_attr( $settings['timeout'] ); ?>"> <?php echo esc_html__( 'ثانیه', JLWI_TEXT_DOMAIN ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="jlwi-redirects"><?php echo esc_html__( 'حداکثر Redirect', JLWI_TEXT_DOMAIN ); ?></label></th>
							<td><input id="jlwi-redirects" type="number" class="small-text" min="0" max="10" name="jlwi[max_redirects]" value="<?php echo esc_attr( $settings['max_redirects'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="jlwi-retries"><?php echo esc_html__( 'تلاش مجدد', JLWI_TEXT_DOMAIN ); ?></label></th>
							<td>
								<input id="jlwi-retries" type="number" class="small-text" min="0" max="5" name="jlwi[retry_count]" value="<?php echo esc_attr( $settings['retry_count'] ); ?>"> <?php echo esc_html__( 'بار', JLWI_TEXT_DOMAIN ); ?>
								&nbsp; <label for="jlwi-retry-delay"><?php echo esc_html__( 'فاصله پایه:', JLWI_TEXT_DOMAIN ); ?></label>
								<input id="jlwi-retry-delay" type="number" class="small-text" min="15" max="3600" name="jlwi[retry_base_delay]" value="<?php echo esc_attr( $settings['retry_base_delay'] ); ?>"> <?php echo esc_html__( 'ثانیه', JLWI_TEXT_DOMAIN ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'لاگ جزئیات', JLWI_TEXT_DOMAIN ); ?></th>
							<td>
								<?php $this->checkbox( 'debug_logging', $settings['debug_logging'], __( 'رویدادهای موفق و خلاصه پاسخ‌ها نیز در لاگ ووکامرس ثبت شود.', JLWI_TEXT_DOMAIN ) ); ?>
								<p class="description"><a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ); ?>"><?php echo esc_html__( 'مشاهده لاگ‌های WooCommerce', JLWI_TEXT_DOMAIN ); ?></a> — <code>source: jetlinez-invoice</code></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'حذف داده هنگام Uninstall', JLWI_TEXT_DOMAIN ); ?></th>
							<td><?php $this->checkbox( 'delete_data_on_uninstall', $settings['delete_data_on_uninstall'], __( 'تنظیمات افزونه هنگام حذف کامل پاک شود.', JLWI_TEXT_DOMAIN ) ); ?></td>
						</tr>
						</tbody>
					</table>
				</section>

				<?php submit_button( __( 'ذخیره تنظیمات', JLWI_TEXT_DOMAIN ) ); ?>
			</form>

			<section class="jlwi-card jlwi-test-card">
				<h2><?php echo esc_html__( 'ارسال پیام آزمایشی', JLWI_TEXT_DOMAIN ); ?></h2>
				<p><?php echo esc_html__( 'این دکمه یک پیام واقعی از دستگاه انتخاب‌شده ارسال می‌کند و ممکن است اعتبار حساب جتلاینز را مصرف کند.', JLWI_TEXT_DOMAIN ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="jlwi_send_test">
					<?php wp_nonce_field( 'jlwi_send_test' ); ?>
					<div class="jlwi-test-row">
						<label for="jlwi-test-phone"><?php echo esc_html__( 'شماره', JLWI_TEXT_DOMAIN ); ?></label>
						<input id="jlwi-test-phone" type="text" class="regular-text ltr" name="test_phone" required placeholder="989121234567">
					</div>
					<div class="jlwi-test-row">
						<label for="jlwi-test-message"><?php echo esc_html__( 'متن', JLWI_TEXT_DOMAIN ); ?></label>
						<textarea id="jlwi-test-message" class="large-text" rows="3" name="test_message"><?php echo esc_textarea( sprintf( __( 'پیام آزمایشی Jetlinez از %s', JLWI_TEXT_DOMAIN ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) ); ?></textarea>
					</div>
					<?php submit_button( __( 'ارسال آزمایشی', JLWI_TEXT_DOMAIN ), 'secondary', 'submit', false ); ?>
				</form>
			</section>

			<section class="jlwi-card jlwi-api-summary">
				<h2><?php echo esc_html__( 'خلاصه درخواست‌های API', JLWI_TEXT_DOMAIN ); ?></h2>
				<pre class="ltr">POST <?php echo esc_html( untrailingslashit( (string) $effective['api_base_url'] ) ); ?>/media
Content-Type: multipart/form-data
X-API-KEY: ********
file: invoice.pdf

POST <?php echo esc_html( untrailingslashit( (string) $effective['api_base_url'] ) ); ?>/whatsapps/{deviceId}/message
Content-Type: application/json
X-API-KEY: ********
{"phonenumber":"98912...","text":"...","mediaId":"uuid-optional"}</pre>
			</section>
		</div>
		<?php
	}

	/**
	 * Persist settings.
	 *
	 * @return void
	 */
	public function save_settings() {
		$this->authorize( 'jlwi_save_settings' );

		$existing = JLWI_Settings::raw();
		$raw      = isset( $_POST['jlwi'] ) && is_array( $_POST['jlwi'] ) ? wp_unslash( $_POST['jlwi'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$defaults = JLWI_Settings::defaults();
		$new      = $existing;

		$boolean_keys = array(
			'enabled',
			'enable_processing',
			'enable_completed',
			'include_billing_phone',
			'send_pdf',
			'send_text_with_pdf',
			'prevent_duplicates',
			'without_delay',
			'add_order_notes',
			'debug_logging',
			'delete_local_pdf',
			'delete_remote_media',
			'delete_data_on_uninstall',
		);

		foreach ( $boolean_keys as $key ) {
			$new[ $key ] = isset( $raw[ $key ] ) ? 'yes' : 'no';
		}

		if ( ! ( defined( 'JLWI_API_BASE_URL' ) && '' !== trim( (string) JLWI_API_BASE_URL ) ) ) {
			$base_url = isset( $raw['api_base_url'] ) ? untrailingslashit( esc_url_raw( trim( (string) $raw['api_base_url'] ) ) ) : '';
			if ( ! $this->valid_api_url( $base_url ) ) {
				$base_url = $existing['api_base_url'];
			}
			$new['api_base_url'] = $base_url;
		}

		if ( ! ( defined( 'JLWI_API_KEY' ) && '' !== trim( (string) JLWI_API_KEY ) ) ) {
			if ( isset( $raw['clear_api_key'] ) ) {
				$new['api_key'] = '';
			} else {
				$api_key = isset( $raw['api_key'] ) ? trim( (string) $raw['api_key'] ) : '';
				$api_key = preg_replace( '/[\r\n]+/', '', $api_key );
				if ( '' !== $api_key ) {
					$new['api_key'] = $api_key;
				}
			}
		}

		if ( ! ( defined( 'JLWI_DEVICE_ID' ) && '' !== trim( (string) JLWI_DEVICE_ID ) ) ) {
			$new['device_id'] = isset( $raw['device_id'] ) ? sanitize_text_field( trim( (string) $raw['device_id'] ) ) : '';
		}

		$new['fixed_recipients'] = isset( $raw['fixed_recipients'] ) ? sanitize_textarea_field( (string) $raw['fixed_recipients'] ) : '';
		$country_code           = isset( $raw['default_country_code'] ) ? JLWI_Settings::ascii_digits( $raw['default_country_code'] ) : '98';
		$country_code           = preg_replace( '/\D+/', '', $country_code );
		if ( 0 === strpos( $country_code, '00' ) ) {
			$country_code = substr( $country_code, 2 );
		}
		$new['default_country_code'] = ltrim( (string) $country_code, '0' );
		if ( '' === $new['default_country_code'] ) {
			$new['default_country_code'] = '98';
		}

		$new['timeout']          = $this->clamp_int( isset( $raw['timeout'] ) ? $raw['timeout'] : $existing['timeout'], 5, 120 );
		$new['max_redirects']    = $this->clamp_int( isset( $raw['max_redirects'] ) ? $raw['max_redirects'] : $existing['max_redirects'], 0, 10 );
		$new['max_file_mb']      = $this->clamp_int( isset( $raw['max_file_mb'] ) ? $raw['max_file_mb'] : $existing['max_file_mb'], 1, 100 );
		$new['retry_count']      = $this->clamp_int( isset( $raw['retry_count'] ) ? $raw['retry_count'] : $existing['retry_count'], 0, 5 );
		$new['retry_base_delay'] = $this->clamp_int( isset( $raw['retry_base_delay'] ) ? $raw['retry_base_delay'] : $existing['retry_base_delay'], 15, 3600 );

		$template_keys = array( 'template_processing', 'template_completed', 'template_generic', 'template_fallback' );
		foreach ( $template_keys as $key ) {
			$value       = isset( $raw[ $key ] ) ? sanitize_textarea_field( (string) $raw[ $key ] ) : '';
			$new[ $key ] = '' !== trim( $value ) ? $value : $defaults[ $key ];
		}

		update_option( JLWI_OPTION, $new, false );
		$this->set_notice( 'success', __( 'تنظیمات Jetlinez ذخیره شد.', JLWI_TEXT_DOMAIN ) );
		wp_safe_redirect( $this->settings_url() );
		exit;
	}

	/**
	 * Send a real test text message.
	 *
	 * @return void
	 */
	public function send_test() {
		$this->authorize( 'jlwi_send_test' );

		$phone   = isset( $_POST['test_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['test_phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$message = isset( $_POST['test_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['test_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$phone   = JLWI_Sender::normalize_phone( $phone, (string) JLWI_Settings::get( 'default_country_code', '98' ) );

		if ( '' === $phone ) {
			$this->set_notice( 'error', __( 'شماره آزمایشی معتبر نیست.', JLWI_TEXT_DOMAIN ) );
			$this->redirect_settings();
		}

		if ( '' === trim( $message ) ) {
			$message = __( 'پیام آزمایشی Jetlinez', JLWI_TEXT_DOMAIN );
		}

		$client = new JLWI_API_Client();
		$result = $client->send_message( $phone, $message );

		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', sprintf( __( 'ارسال آزمایشی ناموفق بود: %s', JLWI_TEXT_DOMAIN ), $result->get_error_message() ) );
		} else {
			$this->set_notice( 'success', __( 'پیام آزمایشی با موفقیت به API جتلاینز تحویل شد.', JLWI_TEXT_DOMAIN ) );
		}

		$this->redirect_settings();
	}

	/**
	 * Print a one-time admin notice.
	 *
	 * @return void
	 */
	public function admin_notice() {
		$notice = get_transient( 'jlwi_admin_notice_' . get_current_user_id() );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( 'jlwi_admin_notice_' . get_current_user_id() );
		$type = isset( $notice['type'] ) && in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}

	/**
	 * Render status card.
	 *
	 * @param string $title Title.
	 * @param bool   $ok    Success status.
	 * @param string $text  Detail.
	 * @return void
	 */
	private function status_card( $title, $ok, $text ) {
		?>
		<div class="jlwi-status-card <?php echo $ok ? 'is-ok' : 'is-warning'; ?>">
			<strong><?php echo esc_html( $title ); ?></strong>
			<span><?php echo esc_html( $text ); ?></span>
		</div>
		<?php
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param string $key   Setting key.
	 * @param string $value yes/no.
	 * @param string $label Label.
	 * @return void
	 */
	private function checkbox( $key, $value, $label ) {
		?>
		<label>
			<input type="checkbox" name="jlwi[<?php echo esc_attr( $key ); ?>]" value="yes" <?php checked( 'yes', $value ); ?>>
			<?php echo esc_html( $label ); ?>
		</label>
		<?php
	}

	/**
	 * Render one message template textarea row.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $value Current value.
	 * @return void
	 */
	private function template_row( $key, $label, $value ) {
		?>
		<tr>
			<th scope="row"><label for="jlwi-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><textarea id="jlwi-<?php echo esc_attr( $key ); ?>" class="large-text code" dir="rtl" rows="8" name="jlwi[<?php echo esc_attr( $key ); ?>]"><?php echo esc_textarea( $value ); ?></textarea></td>
		</tr>
		<?php
	}

	/**
	 * Is Pepro's public PDF method available?
	 *
	 * @return bool
	 */
	private function invoice_available() {
		return ! empty( $GLOBALS['PeproUltimateInvoice'] )
			&& is_object( $GLOBALS['PeproUltimateInvoice'] )
			&& method_exists( $GLOBALS['PeproUltimateInvoice'], 'make_pdf_file' );
	}

	/**
	 * Validate capability and nonce.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function authorize( $action ) {
		if ( ! current_user_can( $this->capability() ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز است.', JLWI_TEXT_DOMAIN ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * WooCommerce settings capability.
	 *
	 * @return string
	 */
	private function capability() {
		return class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Validate API base URL scheme.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function valid_api_url( $url ) {
		if ( '' === (string) $url ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && ! empty( $parts['host'] ) && isset( $parts['scheme'] ) && in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true );
	}

	/**
	 * Clamp integer setting.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 * @return int
	 */
	private function clamp_int( $value, $min, $max ) {
		return max( (int) $min, min( (int) $max, (int) $value ) );
	}

	/**
	 * Store one-time notice.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Message.
	 * @return void
	 */
	private function set_notice( $type, $message ) {
		set_transient(
			'jlwi_admin_notice_' . get_current_user_id(),
			array( 'type' => $type, 'message' => $message ),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Redirect to settings and stop.
	 *
	 * @return void
	 */
	private function redirect_settings() {
		wp_safe_redirect( $this->settings_url() );
		exit;
	}

	/**
	 * Return the settings-page URL for the active dependency state.
	 *
	 * @return string
	 */
	private function settings_url() {
		return class_exists( 'WooCommerce' )
			? admin_url( 'admin.php?page=jlwi-settings' )
			: admin_url( 'options-general.php?page=jlwi-settings' );
	}
}
