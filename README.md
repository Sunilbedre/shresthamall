# Shreeshta Family Store – ₹1 Special Offer Registration System

A lightweight, production-ready registration + WhatsApp voucher system built
for the Shreeshta Family Store (Malleshwaram) ₹1 special offer event.

Plain PHP 8.2+, SQLite, Tailwind (CDN), vanilla JS. No Composer, no Node
build step, no framework. Deploys on almost any shared hosting account, a
VPS, or your laptop.

---

## 1. Recommended Stack

| Layer      | Choice                                   |
|------------|-------------------------------------------|
| Backend    | PHP 8.2+ (PDO/SQLite, cURL)                |
| Database   | SQLite (single file, `storage/database.sqlite`) |
| Frontend   | Server-rendered PHP + Tailwind CDN + vanilla JS |
| Messaging  | Meta WhatsApp Cloud API (template messages) |
| Hosting    | Any shared host with PHP 8.2+, a VPS, or local PHP dev server |

No MySQL, no Node, no Composer dependencies — everything needed to run this
ships in this folder.

## 2. Architecture

```
Customer  ──▶  /offer  (public/offer.php)
                 │  validates + normalises mobile
                 │  checks one-mobile-one-voucher rule
                 │  writes customers + vouchers rows (1 DB transaction)
                 ▼
            WhatsAppService::sendVoucher()  ──▶  Meta Cloud API
                 │
                 ▼
             /success  (public/success.php)

Meta Cloud API  ──▶  /webhook  (public/webhook.php)  ──▶  updates whatsapp_logs

Staff/Admin  ──▶  /admin/login  ──▶  session
                 │
                 ├─ /admin              dashboard (summary + product totals)
                 ├─ /admin/registrations  search / filter / resend / block
                 ├─ /admin/settings       event + timing + limits (admin only)
                 ├─ /admin/exports        CSV / JSON / SQLite backup (admin only)
                 └─ /verify               voucher search + redemption counter
```

## 3. Database Schema

SQLite file at `storage/database.sqlite`, created automatically by
`app/Database.php::migrate()` (also runnable directly via
`scripts/init_db.php`).

- **admins** — username, password_hash, role (`admin`|`staff`), lockout fields
- **customers** — one row per registration; `mobile_number` is `UNIQUE`
- **vouchers** — one row per customer (`customer_id UNIQUE`); `voucher_code` is `UNIQUE`
- **whatsapp_logs** — one row per send/resend attempt, with delivery status
- **settings** — key/value store for event config
- **audit_logs** — who did what, when (logins, redemptions, exports, settings changes)

Both the **mobile number** and the **voucher code** carry real database
`UNIQUE` constraints — this is the hard backstop against duplicate vouchers
even under concurrent submissions, on top of the application-level check.

## 4. Route Map

| URL (with `.htaccess`) | File                        | Access        |
|-------------------------|------------------------------|---------------|
| `/`                     | `public/index.php`           | Public        |
| `/offer`                | `public/offer.php`            | Public        |
| `/success`               | `public/success.php`         | Public (session-gated) |
| `/verify`                | `public/verify.php`          | Staff / Admin |
| `/webhook`               | `public/webhook.php`         | Meta only     |
| `/admin/login`           | `admin/login.php`            | Public (login form) |
| `/admin`                 | `admin/dashboard.php`        | Staff / Admin |
| `/admin/registrations`   | `admin/registrations.php`    | Staff / Admin |
| `/admin/settings`        | `admin/settings.php`         | Admin only    |
| `/admin/exports`         | `admin/exports.php`          | Admin only    |

## 5. Registration Workflow

1. Customer fills the single form on `/offer` (name, mobile, one product, consent).
2. Server validates everything again (never trusts client-side JS).
3. Mobile number is normalised to `+91XXXXXXXXXX`.
4. **Duplicate check**: if the mobile number already has a registration, the
   customer sees "This mobile number has already received a voucher" with a
   **Resend Existing Voucher** button (max 3 resends/day, configurable).
5. If new: customer + voucher rows are inserted **in a single DB transaction**.
   A `UNIQUE` constraint on `mobile_number` is the final backstop against a
   race condition (two simultaneous submits with the same number).
6. A unique voucher code (`SFS1-XXXXXX`, avoiding `O`, `0`, `I`, `1`) is generated
   server-side only.
7. The voucher is sent via WhatsApp template message. If WhatsApp sending
   fails, the registration is **never lost** — it's marked `FAILED` in
   `whatsapp_logs` and can be resent from the admin panel.
8. Customer is shown the success screen with voucher details.

## 6. Duplicate-Prevention Workflow

Enforced at two levels simultaneously — never remove either:

- **Application level**: a `SELECT` on `customers.mobile_number` before insert (fast, friendly error message).
- **Database level**: `UNIQUE INDEX` on `customers.mobile_number` (hard backstop against race conditions).

## 7. Voucher Verification Workflow (`/verify`)

Staff search by voucher code or mobile number. Status is computed live
against the configured session window (Asia/Kolkata timezone):

- **VALID** (green) — within the applicable time window, not yet redeemed → Redeem button enabled
- **NOT_ACTIVE_YET** (yellow) — before the session start time
- **TIME_EXPIRED** (red) — after the session end time
- **ALREADY_REDEEMED** (red) — already used
- **BLOCKED** / **CANCELLED** (red) — manually disabled by admin
- **INVALID** (gray) — no matching voucher

Redemption uses `BEGIN IMMEDIATE` (SQLite write-lock-on-start) inside the
transaction, so two staff members tapping "Redeem" on the same voucher at
the same moment cannot both succeed.

## 8. Installation (Local / VPS)

**Requirements:** PHP 8.2+ with `pdo_sqlite`, `curl`, and `mbstring` extensions
(all standard on shared hosting and default Ubuntu/Debian PHP installs).

```bash
# 1. Copy environment file and edit it
cp .env.example .env
nano .env          # set APP_URL, APP_SECRET, WhatsApp credentials, etc.

# 2. Create the database + first admin account
php scripts/init_db.php --username=admin --password=YourStrongPassword123 --role=admin

# Optional: create a verification-staff account too
php scripts/init_db.php --username=staff1 --password=AnotherStrongPassword --role=staff

# 3. Run locally to test (document root = project root, so routes work via .htaccess... 
#    but PHP's built-in server ignores .htaccess, so for local testing use the /public
#    and /admin paths directly, e.g.:
php -S localhost:8000
# then visit: http://localhost:8000/public/offer.php
#             http://localhost:8000/admin/login.php
```

For a fully working `/offer`, `/verify`, `/admin` style clean URLs, run behind
Apache (see deployment section) or Nginx with the equivalent rewrite rules.

## 9. Shared Hosting Deployment

1. Upload the **entire project folder** (not just `/public`) to your hosting
   account, e.g. via FTP/SFTP or your host's file manager.
2. **Point your domain's document root at the project root** (the folder
   containing this `README.md` and `.htaccess`) — not at `/public`. The root
   `.htaccess` handles routing to the right files in `/public` and `/admin`
   and blocks direct web access to `/app`, `/config`, `/storage`, `/scripts`,
   and `/templates`.
3. Make sure `mod_rewrite` is enabled (standard on cPanel/shared Apache hosts).
4. Create `.env` from `.env.example` (via file manager or SFTP) and fill in
   your real values. **Never commit `.env` to any public repository.**
5. Ensure the `storage/` folder (and its `logs/` and `backups/` subfolders)
   is writable by PHP (`chmod 775 storage storage/logs storage/backups` is
   usually sufficient; the exact user/group depends on your host).
6. SSH in (or use your host's "Run PHP script" / cron tool) and run:
   ```bash
   php scripts/init_db.php --username=admin --password=YourStrongPassword123
   ```
   If you don't have SSH access, temporarily visit any page once (the schema
   auto-migrates on first request) then create the admin account by running
   `init_db.php` via your host's cron/task runner, or ask your host to enable
   SSH temporarily.
7. Visit `https://yourdomain.com/offer` to confirm the registration page loads.
8. Visit `https://yourdomain.com/admin/login` and log in with the account you created.
9. Configure the WhatsApp webhook (see below) pointing at
   `https://yourdomain.com/webhook`.

### Cron-based daily JSON backup (optional)

Add a cron job on your host:
```
0 23 * * * php /home/youruser/public_html/scripts/init_db.php >/dev/null 2>&1
```
(You can also write a small `scripts/backup.php` that copies
`storage/database.sqlite` into `storage/backups/registrations-YYYY-MM-DD.json`
using the same query as `admin/exports.php`'s `json_all` export — the SQLite
file itself is already the authoritative backup via the **Download Complete
Database Backup** button in `/admin/exports`.)

## 10. WhatsApp Cloud API Setup

1. Create a Meta App at [developers.facebook.com](https://developers.facebook.com)
   and add the **WhatsApp** product.
2. Note your **Phone Number ID** and **WhatsApp Business Account ID** from
   the app dashboard.
3. Generate a permanent **System User access token** (Business Settings →
   System Users) with `whatsapp_business_messaging` permission — do not use
   the 24-hour temporary token in production.
4. Create and submit an approved **message template** named
   `shreeshta_one_rupee_offer` (or your own name — set it in `.env` as
   `WHATSAPP_TEMPLATE_NAME`) with **8 body variables** in this exact order:

   ```
   🎉 Congratulations, {{1}}!

   Your ₹1 Special Offer Voucher from Shreeshta Family Store has been confirmed. ❤️

   🎟 Voucher Code: {{2}}
   🛍 Selected Product: {{3}}
   📅 Offer Date: {{4}}
   ⏰ Applicable Time: {{5}}

   ✅ One customer can avail only one product
   ✅ One voucher is allowed per mobile number
   ✅ Voucher is valid only during the applicable time slot
   ✅ Voucher is valid for one-time use only
   ✅ Original WhatsApp voucher must be shown at the verification counter

   📍 Store: {{6}}
   📞 Contact: {{8}}

   Please visit during your applicable time slot and show this WhatsApp
   message at the counter.

   Terms and conditions apply.
   ```

   Variable order used by `app/WhatsAppService.php`:
   `{{1}}` name · `{{2}}` voucher code · `{{3}}` product · `{{4}}` date ·
   `{{5}}` time · `{{6}}` store name+branch · `{{7}}` maps link · `{{8}}` contact number

   A **shorter utility-template version** (for easier/faster Meta approval)
   is documented in the original project brief — use the same 8 variables in
   the same order if you submit that version instead.

5. Once approved, put your credentials in `.env`:
   ```
   WHATSAPP_PHONE_NUMBER_ID=...
   WHATSAPP_BUSINESS_ACCOUNT_ID=...
   WHATSAPP_ACCESS_TOKEN=...
   WHATSAPP_TEMPLATE_NAME=shreeshta_one_rupee_offer
   WHATSAPP_API_VERSION=v20.0
   WHATSAPP_WEBHOOK_VERIFY_TOKEN=some-long-random-string-you-choose
   ```
6. In the Meta App Dashboard → WhatsApp → Configuration, set the **Callback
   URL** to `https://yourdomain.com/webhook` and the **Verify Token** to the
   same value as `WHATSAPP_WEBHOOK_VERIFY_TOKEN`. Subscribe to the `messages`
   webhook field so delivery/read/failed statuses flow back into the admin
   panel.

Until WhatsApp credentials are configured, registrations still work
perfectly — the system just logs the send attempt as `FAILED` and shows the
customer "WhatsApp delivery is pending", and staff can resend once
credentials are set.

## 11. Sample Local Admin Credentials

None are pre-created — you set them yourself when you run
`scripts/init_db.php` (see Installation above). There is no default
password shipped in this codebase.

## 12. Security Notes

- All form input is re-validated server-side regardless of client-side checks.
- Prepared statements (PDO) are used everywhere — no raw string-concatenated SQL.
- CSRF tokens are required on every state-changing form (registration, resend,
  redemption, settings, login).
- Admin sessions are `HttpOnly`, `SameSite=Lax`, and regenerate their session
  ID on login.
- Login attempts are rate-limited and accounts lock for 15 minutes after 5
  failed attempts.
- The WhatsApp access token is never sent to the browser — all API calls
  happen server-side in `app/WhatsAppService.php`.
- `/app`, `/config`, `/storage`, `/scripts`, `/templates` are blocked from
  direct web access by `.htaccess` at both the root and per-folder level.
- Exports and the database backup are admin-only and logged in `audit_logs`.

## 13. Folder Structure

```
/public          Customer-facing entry points (offer, success, verify, webhook, index)
/admin            Admin/staff panel (login, dashboard, registrations, settings, exports)
/app              Core services (Database, CustomerService, VoucherService, WhatsAppService, AuthService, Validation, Products, Settings)
/config           config.php — env loading, sessions, CSRF helpers
/storage          database.sqlite, logs/, backups/ (never web-accessible)
/templates        Shared header/footer/admin-nav partials
/scripts          init_db.php — one-time DB + admin account setup
.env.example      Copy to .env and fill in real values
.htaccess         Root routing + security rules
```

## 14. Customising Store Details / Offer Catalogue

- **Store name, branch, address, contact, maps link, event date, session
  timings, resend limits, product caps**: all editable from `/admin/settings`
  (admin role) — no code changes needed for day-to-day running.
- **The product catalogue itself** (adding/removing/renaming a ₹1 item, or
  changing which session it belongs to) is defined in one place:
  `app/Products.php`. Edit the `CATALOGUE` array there — the form, dashboard,
  exports, and WhatsApp messages all read from it automatically.

---

Terms and conditions apply. Built for Shreeshta Family Store, Malleshwaram, Bengaluru.
