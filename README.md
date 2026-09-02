# Outreach

A small, send-only outreach mailer for a team. The owner connects one to three of their own
mailboxes — **Gmail through Google's send-only permission** (the app can send as the mailbox but
can never read it) or **any SMTP mailbox** (Hostinger, cPanel, Outlook…) — and team members log
in with their own accounts to send personal, one-to-one emails from those mailboxes.

Every send is logged: who sent it, from which mailbox, when, and the gap since that mailbox's
previous send. Each mailbox has a hard daily cap. Leads can be imported from a CSV and the
Claude API writes a personalised first draft for each one; a person reviews, tweaks and sends.

There is no inbox anywhere in the app. Replies land in the real mailbox as usual.

## Features
- **Send-only connections** — Gmail OAuth (`gmail.send` only) or SMTP over TLS; credentials encrypted at rest.
- **Compose** — Markdown with live preview, templates with `{{first_name}}`-style variables, attachments (per-send or from a shared library), Cc/Bcc.
- **Guardrails** — enforced daily cap per mailbox (and optional per member), do-not-contact list, "already contacted" warning that must be acknowledged, soft warning when sends are too close together.
- **Send log & reports** — filterable log with full message detail, per-day / per-mailbox / per-member reports, gap statistics, CSV export.
- **Campaigns** — CSV import (auto-maps columns, handles Excel/Sheets quirks), AI-personalised drafts generated in small batches from the browser (no queue worker), a review-and-send queue, export back to CSV.
- **Admin** — team logins with one-time passwords, mailbox management with connection tests, settings (timezone, caps, AI model), audit trail of every admin change.

## Local development (Windows / Laragon)
```powershell
$env:PATH = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;C:\laragon\bin\composer;C:\laragon\bin\nodejs\node-v22;" + $env:PATH
Set-Location C:\laragon\www\outreach
composer install
npm install
copy .env.example .env          # then set DB_* (MySQL database `outreach`) and, when you have them, GOOGLE_* / ANTHROPIC_API_KEY
php artisan key:generate
php artisan migrate --seed      # seeds admin@outreach.test / password
php artisan db:seed --class=DemoSeeder   # optional demo campaign
npm run build
php artisan serve --host=localhost --port=8000
```
Open <http://localhost:8000>. Google OAuth requires exactly `localhost:8000` locally
(see `docs/GOOGLE-OAUTH-SETUP.md`). Tests: `php artisan test`.

Create a production admin with `php artisan outreach:admin you@example.com --name="Your Name"`.

## Documentation
- `docs/GOOGLE-OAUTH-SETUP.md` — connecting Gmail (one-time Google Cloud setup).
- `docs/DEPLOY.md` — hosting, updates, backups.
- `docs/CONVENTIONS.md` — how the code is organised.
- `CLAUDE.md` — guidance for AI assistants working on the code.

## Stack
Laravel 13 · PHP 8.3 · MySQL · Inertia 2 + React 18 · Tailwind 3 · Symfony Mailer (SMTP) ·
Gmail REST API · Anthropic PHP SDK · league/csv.
