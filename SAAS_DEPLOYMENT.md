# MirzaBot SaaS deployment checklist

این فایل مسیر فعال‌سازی تدریجی لایه SaaS را مشخص می‌کند. Migrationها روی همان دیتابیس فعلی اجرا می‌شوند و برای هر Bot دیتابیس یا Process جدا ساخته نمی‌شود.

## 1. کلیدها و Environment

`MIRZABOT_SECRET_KEY` باید خارج از Repository و خارج از دیتابیس نگهداری شود. مقدار آن یا ۳۲ بایت خام یا Base64 همین ۳۲ بایت است:

```text
MIRZABOT_SECRET_KEY=base64:<32-byte-key>
MIRZABOT_WEBHOOK_BASE_URL=https://bot.example.com
MIRZABOT_BACKUP_PATH=/var/lib/mirzabot/backups
MIRZABOT_SAAS_WORKER_BATCH=10
MIRZABOT_FORCE_SECURE_COOKIE=1
```

`MIRZABOT_WEBHOOK_BASE_URL` باید HTTPS عمومی باشد. Token تلگرام و Secret وب‌هوک هرگز در این فایل، URL، HTML یا Log قرار نمی‌گیرند.

## 2. Migration و Bootstrap

روی Backup دیتابیس تست‌شده، یک بار Bootstrap فعلی را اجرا کنید:

```bash
php table.php
```

ترتیب Migrationهای SaaS:

- `013_saas_foundation`: ساخت Tenant قدیمی، انتقال هویت Admin، افزودن `tenant_id` و انتقال Botها.
- `014_tenant_insert_context_triggers`: انتقال Tenant معتبر به INSERTهای Legacy.
- `015_dispatch_eligibility_and_bot_context`: ثبت وضعیت صریح Core Dispatch و انتقال Bot Context به INSERTها.
- `016_tenant_scoped_legacy_keys`: تبدیل کلیدهای خطرناک Legacy به کلیدهای Tenant-scoped و آماده‌سازی Clone تنظیمات Tenant.

Migrationها Checksum دارند؛ فایل Migration اجراشده نباید بعداً تغییر داده شود.

## 3. ورود و مدیریت

- ورود: `/panel/saas_login.php`
- داشبورد Tenant: `/panel/saas_dashboard.php`
- داشبورد Master Admin: `/panel/master_dashboard.php`
- APIهای مدیریت: `saas_tenants`, `saas_subscriptions`, `saas_bots`, `saas_domains`, `saas_backups`, `saas_audit`, `saas_health`

کاربر اولیه از جدول Legacy `admin` به `saas_user` با Hash امن منتقل می‌شود. ساخت کاربر جدید فقط توسط Master Admin و با رمز حداقل ۱۲ کاراکتری انجام می‌شود.

## 4. Webhook و وضعیت Dispatch

Webhook مدیریت‌شده فقط برای Bot فعال، Tenant فعال، Subscription مجاز و Header معتبر تلگرام پاسخ می‌دهد:

```text
/telegram_webhook.php?bot=<public_bot_uuid>
```

`core_dispatch_status` به‌صورت پیش‌فرض `pending` است. مسیر ساخت Tenant، پس از Clone تنظیمات Legacy و موفقیت Transaction آن را به `ready` تبدیل می‌کند؛ اگر Bootstrap ناقص باشد Tenant ساخته نمی‌شود. Tenantهایی که مستقیماً در دیتابیس ساخته شوند تا زمان Bootstrap معتبر پاسخ `503 bot core migration pending` می‌گیرند.

این وضعیت را با SQL دستی برای Tenant جدید تغییر ندهید؛ فقط Provisioner باید بعد از Clone موفق آن را فعال کند.

## 5. Queue و Cron

`saas_worker` باید از همان Dispatcher فعلی هر دقیقه اجرا شود. Worker با Lock، Idempotency Key، Retry و بازیابی Jobهای قفل‌مانده کار می‌کند. Credentialهای Bot حذف‌شده بعد از Retention از دیتابیس پاک می‌شوند.

## 6. Backup

Backup در فایل رمزنگاری‌شده با مجوز `0600` و Manifest/Checksum versioned ذخیره می‌شود. سه Mode فعلی:

```text
full | data_only | config_only
```

Restore فعلاً فقط Validate/Preview است. Mutationهای `new`, `merge`, `replace` تا زمان Remap کردن Primary Keyهای Legacy مثل `user.id` و `invoice.id_invoice` عمداً مسدود هستند.

## 7. بررسی قبل از فعال‌سازی Strict Mode

```bash
php tests/SaaSFoundationTest.php
php tests/TenantIsolationTest.php
php tests/TenantLoadTest.php
```

برای تست دیتابیسی، این متغیرها را تنظیم کنید:

```text
MIRZABOT_TEST_DSN
MIRZABOT_TEST_USER
MIRZABOT_TEST_PASSWORD
```

`TenantLoadTest` Cohortهای ۱۰، ۵۰، ۱۰۰ و ۵۰۰ Tenant را بررسی می‌کند.

پس از Refactor شدن تمام Legacy APIها، Panelها، Cronها و Restore، می‌توان Strict Mode را فعال کرد:

```text
MIRZABOT_SAAS_ENFORCE_TENANT=1
```

تا آن زمان Legacy API و Panel برای سازگاری روشن می‌مانند، اما مسیرهای SaaS جدید از `AuthContext` و `TenantScopedRepository` استفاده می‌کنند و Tenant جدید به Core قدیمی Dispatch نمی‌شود.
