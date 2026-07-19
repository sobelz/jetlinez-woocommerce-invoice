# Jetlinez Invoice for WooCommerce

افزونه وردپرس برای ارسال خودکار وضعیت سفارش و فاکتور PDF ووکامرس از طریق WhatsApp API جتلاینز.

## جریان ارسال

1. سفارش به وضعیت `processing` یا `completed` می‌رود.
2. یک Job در Action Scheduler ثبت می‌شود.
3. گیرنده‌های ثابت و، در صورت انتخاب، شماره صورتحساب نرمال می‌شوند.
4. افزونه از متد عمومی `make_pdf_file()` در PeproDev Ultimate Invoice فایل PDF می‌گیرد.
5. PDF با درخواست `multipart/form-data` و فیلد `file` در `POST /media` آپلود می‌شود.
6. متن وضعیت با `POST /whatsapps/{deviceId}/message` ارسال می‌شود.
7. فایل با همان endpoint و فیلد `mediaId` در پیام بعدی ارسال می‌شود.
8. وضعیت تحویل برای هر «وضعیت سفارش + گیرنده» در متادیتای سفارش ذخیره می‌شود.
9. اگر PDF در دسترس نباشد یا ارسال سند نهایی شکست بخورد، قالب کامل متنی ارسال می‌شود.

> جتلاینز برای نوع `document` متن را به‌عنوان caption فایل منتقل نمی‌کند؛ به همین دلیل متن و PDF عمداً دو پیام مستقل هستند.

## نصب و راه‌اندازی

فایل ZIP را از **افزونه‌ها ← افزودن ← بارگذاری افزونه** نصب کنید. سپس از **ووکامرس ← Jetlinez WhatsApp** موارد زیر را تنظیم کنید:

- Base URL: `https://my.jetlinez.com/api/v1`
- API Key
- Device ID
- شماره‌های گیرنده و کد کشور
- وضعیت‌های `processing` و `completed`
- قالب‌های پیام و رفتار PDF/Fallback

قبل از فعال‌سازی ارسال خودکار، بخش **ارسال پیام آزمایشی** را اجرا کنید.

## الزامات

- WordPress 6.2 یا جدیدتر
- PHP 7.4 یا جدیدتر
- WooCommerce 7.0 یا جدیدتر
- PeproDev Ultimate Invoice فقط برای PDF؛ بدون آن حالت متنی فعال می‌ماند

## نگهداری امن کلیدها

مقادیر حساس را می‌توان در `wp-config.php` تعریف کرد:

```php
define( 'JLWI_API_BASE_URL', 'https://my.jetlinez.com/api/v1' );
define( 'JLWI_API_KEY', 'YOUR_API_KEY' );
define( 'JLWI_DEVICE_ID', 'YOUR_DEVICE_ID' );
```

در این حالت فیلدهای متناظر در پنل تنظیمات قفل می‌شوند. افزونه API Key را در لاگ ذخیره نمی‌کند.

## شماره‌ها

فرمت خروجی فقط شامل رقم و در قالب شماره بین‌المللی است؛ نمونه: `989121234567`.

- `+989121234567` → `989121234567`
- `00989121234567` → `989121234567`
- `09121234567` با کد کشور 98 → `989121234567`
- ارقام فارسی و عربی به ASCII تبدیل می‌شوند

شماره‌های ثابت را می‌توان با خط جدید، ویرگول فارسی/انگلیسی یا نقطه‌ویرگول جدا کرد.

## قالب‌ها

متغیرهای قابل استفاده:

```text
{order_id} {order_number} {status} {status_slug}
{previous_status} {previous_status_slug}
{customer_name} {customer_first_name} {customer_last_name}
{customer_phone} {customer_email}
{order_total} {currency} {order_date}
{payment_method} {shipping_method}
{billing_address} {shipping_address} {customer_note}
{items} {item_count}
{site_name} {site_url} {admin_email}
{invoice_note} {invoice_available} {recipient}
```

## صف، Retry و جلوگیری از تکرار

- در ووکامرس از Action Scheduler استفاده می‌شود.
- در نبود آن، WP-Cron جایگزین است.
- خطاهای موقت هنگام ارسال پیام یا سند، شامل HTTPهای `408`, `425`, `429`, `500`, `502`, `503`, `504`، با Backoff نمایی دوباره تلاش می‌شوند. خطای تولید یا آپلود PDF برای حفظ سرعت اطلاع‌رسانی، بلافاصله به پیام متنی جایگزین تبدیل می‌شود.
- تحویل متن، سند و Fallback به‌صورت جزءبه‌جزء ثبت می‌شود؛ بنابراین Retry بخش موفق را دوباره ارسال نمی‌کند.
- گزینه جلوگیری از تکرار، برای هر وضعیت و هر گیرنده فقط یک تحویل موفق نگه می‌دارد.
- از بخش **Order actions** می‌توان ارسال اجباری/مجدد انجام داد.

## لاگ و عیب‌یابی

لاگ‌ها در **ووکامرس ← وضعیت ← لاگ‌ها** با منبع زیر هستند:

```text
jetlinez-invoice
```

برای مشاهده پاسخ‌های خلاصه، گزینه «لاگ جزئیات» را فعال کنید. اطلاعات گیرنده در لاگ ماسک می‌شود و API Key ثبت نمی‌شود.

صف‌ها از **ووکامرس ← وضعیت ← Scheduled Actions** و با Hook زیر قابل بررسی‌اند:

```text
jlwi_process_order
```

## فیلترهای توسعه‌دهنده

```php
// تأمین PDF از افزونه فاکتور دیگر.
add_filter( 'jlwi_invoice_file_path', function ( $path, $order_id, $order ) {
    return '/absolute/path/to/invoice.pdf';
}, 10, 3 );

// اگر فایل سفارشی موقت است و باید پس از پردازش حذف شود.
add_filter( 'jlwi_custom_invoice_is_temporary', '__return_true' );
```

فیلترهای دیگر:

- `jlwi_order_recipients`
- `jlwi_max_recipients_per_order`
- `jlwi_normalized_phone`
- `jlwi_template_tokens`
- `jlwi_rendered_message`
- `jlwi_http_request_args`

در `jlwi_http_request_args` از ثبت یا نمایش هدر `X-API-KEY` خودداری کنید.

## پاک‌سازی

- حذف فایل PDF محلی به‌صورت پیش‌فرض روشن است.
- حذف مدیای جتلاینز به‌صورت پیش‌فرض خاموش است تا سابقه فایل در پنل بماند.
- حذف تنظیمات هنگام Uninstall فقط در صورت فعال‌کردن گزینه مربوط انجام می‌شود.

## نسخه

`1.0.0`
