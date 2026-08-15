# Jetlinez Invoice for WooCommerce

افزونه وردپرس برای ارسال خودکار وضعیت سفارش و فاکتور PDF ووکامرس از طریق WhatsApp API جتلاینز.

## جریان ارسال

1. سفارش به یکی از وضعیت‌های انتخاب‌شده در تنظیمات می‌رود.
2. یک Job در Action Scheduler ثبت می‌شود.
3. شماره صورتحساب به‌عنوان «مشتری» و شماره‌های ثابت به‌عنوان «ادمین» نرمال می‌شوند.
4. برای هر مسیر، حالت انتخاب‌شده همان وضعیت (`عدم ارسال`، `فقط متن`، `فقط فایل` یا `متن و فایل`) خوانده می‌شود.
5. فقط اگر یکی از مسیرهای فعال به فایل نیاز داشته باشد، افزونه از متد عمومی `make_pdf_file()` در PeproDev Ultimate Invoice فایل PDF می‌گیرد.
6. PDF یک بار با درخواست `multipart/form-data` و فیلد `file` در `POST /media` آپلود می‌شود.
7. متن و/یا فایل مطابق تنظیم همان گیرنده با `POST /whatsapps/{deviceId}/message` ارسال می‌شود.
8. وضعیت تحویل برای هر «وضعیت سفارش + نوع گیرنده + شماره» در متادیتای سفارش ذخیره می‌شود.
9. اگر PDF در حالت «متن و فایل» در دسترس نباشد، قالب کامل متنی ارسال می‌شود؛ حالت «فقط فایل» هیچ متن جایگزینی نمی‌فرستد.

> جتلاینز برای نوع `document` متن را به‌عنوان caption فایل منتقل نمی‌کند؛ به همین دلیل متن و PDF عمداً دو پیام مستقل هستند.

## نصب و راه‌اندازی

فایل ZIP را از **افزونه‌ها ← افزودن ← بارگذاری افزونه** نصب کنید. سپس از **ووکامرس ← Jetlinez WhatsApp** موارد زیر را تنظیم کنید:

- Base URL: `https://my.jetlinez.com/api/v1`
- API Key
- Device ID
- شماره‌های ادمین، گزینه ارسال به مشتری و کد کشور
- وضعیت‌هایی که باید ارسال خودکار پیام و فاکتور را فعال کنند (همه وضعیت‌های اصلی و سفارشی ووکامرس قابل انتخاب‌اند)
- نوع ارسال مستقل مشتری و ادمین در هر وضعیت
- قالب‌های پیام و رفتار PDF/Fallback
- ساعت، فعال/غیرفعال بودن و بخش‌های گزارش روزانه واتساپ

قبل از فعال‌سازی ارسال خودکار، بخش **ارسال پیام آزمایشی** را اجرا کنید.

## گزارش روزانه واتساپ

بخش **گزارش روزانه واتساپ** در همان صفحه تنظیمات قرار دارد و از API Key، Device ID و شماره‌های ادمین موجود استفاده می‌کند. ساعت ارسال بر اساس منطقه زمانی وردپرس است و رویداد تک‌اجرای WP-Cron بعد از هر اجرا برای روز بعد ساخته می‌شود.

هرکدام از این اجزا را می‌توان مستقل فعال یا غیرفعال کرد:

- فروش خالص امروز و درصد تغییر نسبت به بازه زمانی مشابه دیروز
- تعداد کل سفارش‌های ساخته‌شده امروز
- میانگین مبلغ سفارش‌های پرداخت‌شده
- تعداد حساب‌های مشتری ساخته‌شده امروز
- سفارش‌های لغوشده، بازپرداخت‌شده و رهاشده
- محصولات ناموجود، در حالت پیش‌سفارش یا کم‌موجودی

سفارش رهاشده در این گزارش یعنی سفارش `failed` یا سفارش `pending` قدیمی‌تر از مهلت نگهداری موجودی ووکامرس؛ اگر آن مهلت غیرفعال باشد، ۶۰ دقیقه استفاده می‌شود. بخش موجودی فقط در صورت وجود مورد نیازمند توجه به پیام اضافه می‌شود.

دکمه **ارسال گزارش ۲۴ ساعت گذشته همین حالا** بدون نیاز به فعال‌بودن زمان‌بندی، یک گزارش واقعی از بازه شناور ۲۴ ساعت گذشته می‌سازد و به شماره‌های ادمین می‌فرستد. درصد فروش این گزارش با بازه ۲۴ تا ۴۸ ساعت قبل مقایسه می‌شود؛ بنابراین هم برای تست اتصال و هم برای دریافت گزارش فوری قابل استفاده است.

WP-Cron در اولین بازدید بعد از ساعت تعیین‌شده اجرا می‌شود؛ برای سایت‌های کم‌ترافیک بهتر است Cron واقعی سرور، `wp-cron.php` را منظم فراخوانی کند.

## الزامات

- WordPress 6.2 یا جدیدتر
- PHP 7.4 یا جدیدتر
- WooCommerce 7.0 یا جدیدتر
- PeproDev Ultimate Invoice فقط برای PDF؛ بدون آن حالت متنی فعال می‌ماند

## انتشار و به‌روزرسان خودکار

این افزونه نسخه جدید را از آدرس زیر بررسی می‌کند:

```text
https://plugins.sobelz.ir/jetlinez-woocommerce-invoice/update.json
```

بعد از هر انتشار، سرور باید این دو فایل عمومی را ارائه کند:

```text
jetlinez-woocommerce-invoice/update.json
jetlinez-woocommerce-invoice/jetlinez-woocommerce-invoice.zip
```

پس از `git pull --ff-only` روی سرور، بسته استاندارد وردپرس را بسازید:

```bash
./tools/build-release.sh
```

اسکریپت یکسان بودن نسخه در هدر افزونه، ثابت `JLWI_VERSION` و `update.json` و تمیز بودن checkout را کنترل می‌کند. فایل ZIP نیز با پوشه ریشه `jetlinez-woocommerce-invoice/` ساخته می‌شود تا وردپرس افزونه را در مسیر اشتباه نصب نکند. فایل‌های خام Git به‌تنهایی قابل نصب توسط updater نیستند و وجود ZIP ضروری است.

برای انتشار نسخه بعدی، نسخه را در این چهار محل تغییر دهید، تغییرات را commit/push کنید و سپس اسکریپت بالا را روی checkout همان commit اجرا کنید:

- هدر `Version` در `jetlinez-woocommerce-invoice.php`
- ثابت `JLWI_VERSION`
- مقدار `Stable tag` در `readme.txt`
- مقدار `version` و متن `sections.changelog` در `update.json`

کلاس عمومی updater در `includes/updater/class-sobelz-plugin-updater.php` مستقل از این افزونه است. برای استفاده در افزونه دیگر، فایل را کپی و بعد از تعریف ثابت فایل اصلی ثبت کنید:

```php
require_once plugin_dir_path( __FILE__ ) . 'includes/updater/class-sobelz-plugin-updater.php';

\Sobelz\PluginUpdater\V1\Updater::register(
    array(
        'plugin_file' => __FILE__,
        'slug'        => 'my-plugin',
        'update_uri'  => 'https://plugins.sobelz.ir/my-plugin',
    )
);
```

در افزونه مقصد همین URL را با هدر `Update URI` نیز اضافه کنید. updater به‌طور پیش‌فرض فایل `update.json` را کنار URL بالا می‌خواند؛ `manifest_url`، `cache_ttl` و `headers` نیز قابل تنظیم‌اند. دو فیلتر `sobelz_plugin_updater_request_args` و `sobelz_plugin_updater_manifest` برای سفارشی‌سازی عمومی وجود دارند.

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

برای ارسال به خریدار، در بخش **وضعیت‌ها و گیرنده‌ها** گزینه **ارسال به مشتری** را فعال کنید. شماره صورتحساب سفارش مسیر «مشتری» است و شماره‌های ثابت مسیر «ادمین» هستند. نوع محتوای هر مسیر را می‌توان برای هر وضعیت جداگانه تعیین کرد. خاموش‌کردن گزینه مشتری فقط همان مسیر را متوقف می‌کند و روی شماره‌های ادمین اثری ندارد.

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

صف سفارش‌ها از **ووکامرس ← وضعیت ← Scheduled Actions** قابل بررسی است. Hookهای افزونه:

```text
jlwi_process_order
jlwi_send_daily_report
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
- `jlwi_order_recipient_groups`
- `jlwi_max_recipients_per_order`
- `jlwi_normalized_phone`
- `jlwi_template_tokens`
- `jlwi_rendered_message`
- `jlwi_http_request_args`
- `jlwi_daily_report_recipients`
- `jlwi_daily_report_data`
- `jlwi_daily_report_message`
- `jlwi_daily_report_order_query_args`
- `jlwi_daily_report_inventory_attention`
- `jlwi_daily_report_abandoned_after_minutes`
- `jlwi_daily_report_customer_roles`
- `jlwi_daily_report_inventory_limit`
- `jlwi_max_daily_report_recipients`

در `jlwi_http_request_args` از ثبت یا نمایش هدر `X-API-KEY` خودداری کنید.

## پاک‌سازی

- حذف فایل PDF محلی به‌صورت پیش‌فرض روشن است.
- حذف مدیای جتلاینز به‌صورت پیش‌فرض خاموش است تا سابقه فایل در پنل بماند.
- حذف تنظیمات هنگام Uninstall فقط در صورت فعال‌کردن گزینه مربوط انجام می‌شود.

## نسخه

`1.5.0`
