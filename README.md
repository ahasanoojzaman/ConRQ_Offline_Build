# ConrQ — Deployment Guide (Razor Host / cPanel)

A multi-tenant billing, inventory & accounting web app built in pure PHP 8.4 + MySQL.

Domain: https://conrq.krenx.in
Root Directory: /home/wqftutpg/conrq.krenx.in

---

## 1. Upload & Extract

1. Log into cPanel → **File Manager** (or use FTP).
2. Go to `/home/wqftutpg/conrq.krenx.in` (this should already be your document root for the subdomain).
3. Upload `conrq.zip` and extract it **directly into this folder** (not into a subfolder). After extracting you
   should see `index.php`, `app/`, `admin/`, `config/`, `database/`, etc. directly inside
   `/home/wqftutpg/conrq.krenx.in`.

## 2. Confirm PHP version

In cPanel → **MultiPHP Manager**, set this domain to **PHP 8.4** (or the highest 8.x available).

## 3. SSH into your server

```bash
ssh wqftutpg@conrq.krenx.in
# or use the exact SSH details from your cPanel "SSH Access" page
cd ~/conrq.krenx.in
```

## 4. Run the database migration

This creates all tables in the `wqftutpg_conrq` database (credentials are already set in `config/config.php`).

```bash
php database/migrate.php
```

You should see `Migration complete.`

## 5. Seed initial data (Super Admin + Plans + Demo tenant)

This is an interactive wizard — it will ask you to set your Super Admin email/password.

```bash
php database/seed.php
```

Follow the prompts. It creates:
- 3 pricing plans (Starter ₹499, Growth ₹999, Business ₹2499)
- Your Super Admin login (you choose the email & password)
- A demo tenant you can log into immediately: `demo@conrq.krenx.in` / `demo1234`

Re-running this script is safe — it skips anything that already exists.

## 6. Set folder permissions

```bash
chmod -R 755 storage
chmod -R 755 assets/uploads
```

## 7. You're live

- **Landing page:** https://conrq.krenx.in
- **Tenant login:** https://conrq.krenx.in/login.php
- **Super Admin:** https://conrq.krenx.in/admin/login.php

---

## Important security step

Open `config/config.php` and change this line to a random 32+ character string before going live:

```php
define('APP_KEY', 'CHANGE_THIS_TO_A_RANDOM_LONG_SECRET_STRING_732101');
```

You can generate one via SSH:
```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

## Optional: WhatsApp / SMS / Email

- **WhatsApp:** works out of the box via `wa.me` deep links (no API key needed) — click "WhatsApp" on any
  invoice to open a pre-filled chat with the customer.
- **Email:** currently uses `mailto:` links (opens the user's own email app). To send email directly from the
  server (via PHP/SMTP), fill in the `SMTP_*` constants in `config/config.php` and let us know — the send
  logic can be wired in as a next step.
- **SMS:** requires an SMS gateway account (e.g. MSG91, Twilio, Fast2SMS). Fill in `SMS_GATEWAY_URL` and
  `SMS_GATEWAY_API_KEY` in `config/config.php` once you have one, and the send logic can be wired in.

## Importing data from your old software

Products, customers and opening stock can be bulk-imported via a CSV importer — this is a natural next
module to add once your product/customer column layout from the old system is known.

## Adding more tenants

Log into `/admin/` (Super Admin) → **Tenants** → **New Tenant**. This creates the company record, its
settings, and an owner login in one step.

## File map

```
/index.php                 Landing page (features, tutorial, testimonials, pricing, contact/demo form)
/login.php, /logout.php    Tenant login
/admin/                    Super Admin console (tenants, plans, demo requests)
/app/                      Tenant application (dashboard, invoices, products, parties, payments, ledger...)
/config/config.php         DB credentials & app settings (edit here)
/database/schema.sql       Full DB schema
/database/migrate.php      Run once via SSH to create tables
/database/seed.php         Run once via SSH to create plans/admin/demo tenant
/includes/                 Shared PHP libraries (DB, Auth, helpers)
/assets/                   CSS/JS/uploads
```

## What's included in this build (Phase 1)

- Public landing page: features, "how it works" tutorial, testimonials, 3-tier pricing, contact + demo
  request form (saved to DB, visible in Super Admin)
- Super Admin: tenant CRUD (create company + owner login in one step), plan management, demo request inbox,
  platform-wide MRU/MRR-style dashboard
- Multi-tenant core app: dashboard, Products/Services (with stock tracking), Customers/Suppliers, fast
  Invoice/Proforma/Quotation/Purchase Bill builder with live GST (CGST/SGST/IGST) calculation, A4 and thermal
  print layouts, WhatsApp/Email share links, payment recording, party ledgers, expenses, GST JSON export,
  company settings, team/user management
- Mobile-responsive throughout (collapsible sidebar, touch-friendly forms)

## Natural next steps (not yet built)

- CSV import tool for bulk product/customer/opening-stock migration from Tally/Vyapar/Excel
- Server-side email sending (PHPMailer/SMTP) and SMS gateway integration (both are stubbed with config
  constants ready to fill in)
- Bill of Materials / manufacturing module
- Barcode scanning at point-of-sale
- Role-based permission granularity beyond owner/admin/staff
- Automated recurring invoices & subscription billing reminders for tenants approaching plan limits
