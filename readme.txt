=== Jetlinez Invoice for WooCommerce ===
Contributors: jetlinez
Tags: woocommerce, whatsapp, invoice, pdf, jetlinez, order status
Requires at least: 6.2
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ارسال خودکار پیام وضعیت و فاکتور PDF سفارش‌های ووکامرس از طریق WhatsApp API جتلاینز، با پشتیبانی از PeproDev Ultimate Invoice و جایگزین متنی.

== Description ==

این افزونه با تغییر وضعیت سفارش ووکامرس به «در حال انجام» یا «تکمیل شده»، پیام سفارش را برای شماره‌های تعیین‌شده از طریق Jetlinez ارسال می‌کند.

در صورت فعال بودن PeproDev Ultimate Invoice، افزونه PDF فاکتور را تولید می‌کند، ابتدا آن را در endpoint مدیای Jetlinez آپلود می‌کند و سپس شناسه mediaId را برای ارسال فایل به endpoint واتساپ می‌فرستد.

اگر افزونه فاکتور فعال نباشد، تولید PDF ناموفق باشد، فایل معتبر نباشد، آپلود شکست بخورد یا ارسال نهایی سند ممکن نشود، اطلاعات سفارش با قالب جایگزین متنی ارسال می‌شود.

ویژگی‌های اصلی:

* پنل تنظیمات کامل در WooCommerce > Jetlinez WhatsApp
* احراز هویت با هدر X-API-KEY
* پشتیبانی از وضعیت‌های Processing و Completed
* شماره‌های ثابت و شماره صورتحساب خریدار
* نرمال‌سازی شماره‌های فارسی، عربی و بین‌المللی
* تولید PDF با متد عمومی PeproDev Ultimate Invoice
* آپلود multipart/form-data با فیلد file
* ارسال متن و PDF در دو پیام مستقل
* قالب‌های قابل ویرایش با متغیرهای سفارش
* جلوگیری از ارسال تکراری برای هر وضعیت و گیرنده
* صف Action Scheduler، با جایگزین WP-Cron
* Retry نمایی برای خطاهای موقت API
* ثبت نتیجه در Order Notes و WooCommerce Logs
* اکشن ارسال/ارسال مجدد دستی در صفحه سفارش
* سازگار با HPOS از طریق WooCommerce CRUD
* امکان تعریف اطلاعات حساس در wp-config.php

== Installation ==

1. فایل ZIP افزونه را از مسیر Plugins > Add New > Upload Plugin نصب و فعال کنید.
2. WooCommerce باید فعال باشد.
3. برای PDF، افزونه PeproDev Ultimate Invoice را نصب و فعال کنید. نبودن آن مانع ارسال متنی نمی‌شود.
4. به WooCommerce > Jetlinez WhatsApp بروید.
5. Base URL را روی `https://my.jetlinez.com/api/v1` قرار دهید.
6. API Key و Device ID دستگاه واتساپ متصل را وارد کنید.
7. گیرنده‌ها، کد کشور و وضعیت‌های محرک را تنظیم کنید.
8. ابتدا با بخش «ارسال پیام آزمایشی» اتصال را بررسی کنید.
9. گزینه «فعال‌سازی ارسال خودکار» را روشن و تنظیمات را ذخیره کنید.

== Frequently Asked Questions ==

= چرا متن و PDF دو پیام جدا هستند؟ =

در مسیر ارسال document جتلاینز، متن به‌عنوان caption سند منتقل نمی‌شود. افزونه برای جلوگیری از حذف متن، پیام وضعیت را جداگانه و سپس فایل PDF را ارسال می‌کند.

= اگر Ultimate Invoice نصب نباشد چه می‌شود؟ =

قالب جایگزین متنی شامل وضعیت، مشتری، اقلام، روش پرداخت/ارسال و مبلغ سفارش ارسال می‌شود.

= شماره‌ها با چه فرمتی وارد شوند؟ =

فرمت پیشنهادی E.164 بدون علامت مثبت است؛ برای نمونه `989121234567`. شماره `09121234567` نیز با کد کشور پیش‌فرض 98 به همین قالب تبدیل می‌شود. ارقام فارسی و عربی نیز پشتیبانی می‌شوند.

= لاگ‌ها کجا هستند؟ =

WooCommerce > Status > Logs و سپس منبع `jetlinez-invoice` را انتخاب کنید. API Key در لاگ ثبت نمی‌شود.

= چگونه اطلاعات API را خارج از دیتابیس نگه دارم؟ =

ثابت‌های زیر را در wp-config.php تعریف کنید. فیلدهای متناظر در پنل قفل می‌شوند:

`define( 'JLWI_API_BASE_URL', 'https://my.jetlinez.com/api/v1' );`
`define( 'JLWI_API_KEY', 'YOUR_API_KEY' );`
`define( 'JLWI_DEVICE_ID', 'YOUR_DEVICE_ID' );`

= چگونه فاکتور را از افزونه دیگری تأمین کنم؟ =

با فیلتر `jlwi_invoice_file_path` یک مسیر مطلق و خواندنی به فایل PDF برگردانید. برای اعلام موقت بودن فایل و حذف آن پس از پردازش، فیلتر `jlwi_custom_invoice_is_temporary` را true کنید.

== Template placeholders ==

`{order_id}`، `{order_number}`، `{status}`، `{status_slug}`، `{previous_status}`، `{previous_status_slug}`، `{customer_name}`، `{customer_first_name}`، `{customer_last_name}`، `{customer_phone}`، `{customer_email}`، `{order_total}`، `{currency}`، `{order_date}`، `{payment_method}`، `{shipping_method}`، `{billing_address}`، `{shipping_address}`، `{customer_note}`، `{items}`، `{item_count}`، `{site_name}`، `{site_url}`، `{admin_email}`، `{invoice_note}`، `{invoice_available}` و `{recipient}`.

== Developer filters ==

* `jlwi_invoice_file_path`
* `jlwi_custom_invoice_is_temporary`
* `jlwi_order_recipients`
* `jlwi_max_recipients_per_order`
* `jlwi_normalized_phone`
* `jlwi_template_tokens`
* `jlwi_rendered_message`
* `jlwi_http_request_args`

== Changelog ==

= 1.0.0 =

* انتشار اولیه.
* ارسال خودکار برای Processing و Completed.
* ادغام PDF با PeproDev Ultimate Invoice.
* آپلود مدیا و ارسال mediaId با Jetlinez.
* جایگزین متنی، صف، Retry، Dedupe، لاگ و HPOS.
