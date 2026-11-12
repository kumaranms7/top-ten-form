# TopTen Form changelog

## Unreleased

- **Captcha is now a form field.** Add the **Captcha** field in the builder to place it anywhere in a form and pick its type. Platform Settings: "Captcha is mandatory for all website forms" adds one automatically at the end of every form; unticked, captcha is optional and each form adds the field when it needs one. Existing forms keep their captcha (migration 8 adds the field).
- **Question and answer captcha** now asks a random, easy question (weekdays, months, simple sums in words, colours); no need to write questions.
- **Google reCAPTCHA / Cloudflare Turnstile load only when the visitor starts the form**, so their third-party code no longer counts against page-speed scores.
- **Less layout shift (CLS):** space is reserved for the JavaScript embed (the copy-paste snippet now includes a min-height), the form waits for its stylesheet, multi-step forms and captcha boxes no longer jump, conditional fields start hidden.
- **Dates and times everywhere** (admin, emails, reports, exports) now read MM-DD-YYYY and 12-hour time without a leading zero (3:05 PM); emails have no seconds.
- The "Browser" row was removed from the entry-details block of notification emails.
- Migrations now always run in version order.

## 2.3.0 - multi-site platform

- **Super admin layer.** One shared login for everyone. Super admins manage all websites, users and forms (Platform, Websites, Forms, Users pages), can "Enter" any website, and are the only ones who change platform settings (mail, captcha default, storage, antivirus, cron, backups, updates).
- **Websites.** Each website has its own forms, entries, notifications, webhooks, spam rules, users, logo and details. A user can belong to several websites with a different role in each and switches from the top bar.
- **Site settings** (website admins): details, allowed domains, site logo (shown next to the brand logo in the admin and in emails; priority: form logo, site logo, platform logo), email look, optional own SMTP / From / Reply-To, optional own Google reCAPTCHA or Cloudflare Turnstile keys, data-retention overrides.
- Suspending a website stops its forms accepting entries and its users signing in. Allowed domains are enforced for submissions (a form's own allowed origins still take priority).
- Existing data moves to a "Default site"; the first active admin becomes super admin.
- Fixed: the dashboard showed "last 0 days" when opened without a period.

## 2.2.0

- **Built-in captcha, required on every form** (self-hosted, no account or keys): Math problem (default), Text image, or your own Question and answers. Chosen per form in the builder.
- If Google reCAPTCHA or Cloudflare Turnstile is configured in Settings, every form uses that instead; otherwise the built-in captcha is used.
- Challenges are drawn by the server as images (the answer is never in the page), are single-use, expire after 10 minutes and allow 5 tries. A "use a text question instead" link keeps it accessible.
- New setting "Require a captcha on every form" (on by default). Forms pasted as Full HTML before this update have no captcha box, so replace that code (or use the PHP / JavaScript embed).

## 2.1.0

- **Name field formats:** First + Last, First + Middle + Last, or Full name (one box).
- **Address field formats:** Street + City + State + ZIP, Detailed (with apartment line and country), or Full address (one box).
- **Phone field formats:** US (auto-formatted as (XXX) XXX-XXXX), International (+country code and full number), or any format.
- **Built-in spam protection, on for every form** (no captcha): server-signed single-use form token, server-side timing, honeypot, rate limits, link / HTML injection and disposable-email checks, repeat-message detection and your own block rules. Choose Standard (default), Strict or Basic per form.
- **Import entries** from CSV or Excel with automatic column matching (files exported from this app re-import as they are). Imports never send emails or webhooks. CSV and Excel exports now include every field.
- **"No status"**: entries that were read but are still "New" after 30 days are cleared to "No status" automatically (the number of days is in Settings). Replying from an entry sets it to "Contacted".
- **Source page URL** on every entry: the page the visitor came from (for example an inner page of your site). The Source shows the site name only.

## 2.0.0

### Entries
- Entry status (New, Contacted, Won, Lost), internal notes, assignment to a team member, and in-app email replies.
- Spam folder with blocked words, email domains and IPs, a minimum-fill-time check and a link limit. Suspected spam never sends emails or webhooks.
- Choose and reorder the columns shown per form; sortable columns; unread filter; bulk actions.
- Export to CSV, Excel (.xlsx) and PDF, following your filters and columns.

### Form builder
- New fields: Name, Address, Rating, Time, Price, Calculated, Signature and Page break.
- Conditional logic (show or hide fields by answers), multi-step forms with a progress bar, save and continue later.
- Themes (presets and custom colours), per-form scoped custom CSS, undo / redo, form templates, JSON import and export.
- Notifications can be routed by answer (for example Commercial leads to a different person).

### Email and integrations
- Branded email template with your logo (per form or site-wide) and footer.
- Webhooks: every new entry as signed JSON, with automatic retries and delivery logs.
- Emails and webhooks are sent from a background queue with retries, so visitors never wait for a slow mail server.

### Reporting
- Dashboard charts: entries over time, by form, traffic source (UTM / referrer), lead status and top campaigns.

### Security and operations
- Optional two-step sign-in by email code, with "remember this device for 30 days".
- Uploads are stored outside the web folder, with strict file checks and optional ClamAV virus scanning.
- Backups (manual or scheduled) with restore; update from a ZIP with automatic backup and rollback.
- Background tasks (cron.php) or automatic pseudo-cron; versioned database upgrades.
