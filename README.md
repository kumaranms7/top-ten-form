# TopTen Form

A self-hosted form builder and lead management platform written in plain PHP and MySQL/MariaDB. Build forms with drag and drop, embed them on any website as real HTML (no iframe), and manage every submission, notification and client website from one admin panel.

## Features

**Form builder**
- Drag-and-drop builder with text, email, phone, number, textarea, select, radio, checkbox, date, time, file upload, name, address, price, calculated, rating, signature, consent, hidden, heading, paragraph and page-break fields
- Placeholders for every field, including each part of name and address fields
- Multi-step forms, conditional logic, help text, themes and form tags
- Consent text supports safe links and basic formatting
- Embed with a copy-paste snippet (HTML or JavaScript); the form is always real markup, never an iframe

**Entries and leads**
- Entries table with search, filters, status, assignment, notes, newest first and a selectable page size
- Export to CSV and Excel, and import from CSV
- Source and campaign tracking (referrer and UTM tags)

**Notifications and integrations**
- Email notifications and autoresponders with merge tags, CC/BCC, conditions and branded templates
- Platform-wide default BCC set by the super admin
- Webhooks with delivery logs
- Background job queue with automatic retries; PHP mail() or SMTP

**Multi-site platform**
- One login for everyone; a super admin manages all websites, users and forms
- Each website has its own forms, entries, users, notifications, spam rules, logo, domains and optional SMTP and captcha keys
- Per-website roles: admin, editor and viewer

**Security and protection**
- Honeypot, rate limiting, spam rules, built-in math captcha, Google reCAPTCHA and Cloudflare Turnstile
- Two-factor authentication, trusted devices, CSRF protection, activity log and email log
- Optional antivirus scan for uploads (ClamAV)

**Reporting**
- Automatic monthly lead report: one email covering every website, with a "View full report" link per website
- Full report page with a table per form, print/save as PDF and a read-only share link per website

**Operations**
- Scheduled backups, in-app updates, data retention rules, versioned database migrations

## Requirements

- PHP 8.1 or newer with PDO MySQL, mbstring, GD and cURL
- MySQL 5.7+ or MariaDB 10.3+
- Apache (the project ships `.htaccess` files) or another server configured to match
- A working mail setup (SMTP recommended)

## Installation

1. Upload the project to your web server (for example `public_html/topten-form`).
2. Create an empty database and a database user.
3. Open `https://your-domain/topten-form/install.php` and follow the steps. It creates `config/config.php` and the admin account.
4. Sign in at `/admin/login.php`, then open **Settings** to set the site name, timezone and email sending.

## Scheduled tasks

The app runs background tasks while it is being used. For reliable timing, add these to your server's cron or Task Scheduler:

```
# every minute: emails, retries, cleanup, backups
* * * * *  php "/path/to/topten-form/cron.php"

# 12:00 AM on the 1st of each month: monthly lead report
0 0 1 * *  php "/path/to/topten-form/cron.php" report
```

Settings shows these commands with your real paths, and a URL version protected by a secret key.

## Embedding a form

Open **Forms → Get code** and paste the snippet into your page. Edits made in the builder go live without re-pasting.

## Project structure

```
admin/      Admin panel (pages and assets)
app/        Core logic: bootstrap, rendering, validation, mailer, queue, reports, migrations
public/     Public endpoints and assets: embed.php, submit.php, report.php, form CSS/JS
config/     Generated configuration (not committed)
vendor/     PHPMailer
tools/      Release packaging
cron.php    Background tasks
install.php Installer
```

## Configuration and data

`config/config.php`, `uploads/` and `public/media/` are created per installation and are deliberately excluded from version control. Keep your database credentials private.

## License

All rights reserved. This code may not be copied, modified or redistributed without written permission.
