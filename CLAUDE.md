# Outreach — guidance for AI assistants

**What this is.** A send-only team outreach mailer for Jawad's agency. Admin connects 1–3 of
his own mailboxes (Gmail via OAuth with ONLY the `gmail.send` scope, or SMTP); team members
log in, compose or review AI-personalised drafts, and send FROM those mailboxes. Every send is
logged (who, which account, when, gap since that account's previous send) and daily caps are
enforced per account. **There is no receive/IMAP code and there must never be any.**

**Before changing anything, read `docs/CONVENTIONS.md`** — toolchain quirks on Windows/Laragon,
backend and frontend patterns, test conventions. The full design is the approved plan at
`C:/Users/jawad/.claude/plans/i-wanted-to-create-elegant-island.md`.

## Non-negotiables
- All outbound mail goes through `App\Services\Sending\SendService` (reservation pattern:
  short lock → `pending` row → network → finalise as sent/failed/unknown). Never send around it.
- Secrets (`email_accounts.secrets`) are encrypted with `APP_KEY`, never fillable from a
  request, never returned to the browser, never in audit payloads.
- Caps and reports key on `sent_emails.local_date` (business timezone), not UTC timestamps.
- Aggregate queries over `sent_emails` use `DB::table(...)`, not Eloquent (enum cast trap).
- Pages are full-width; only semantic theme tokens (violet/fuchsia gradient brand, always-dark
  sidebar via the --side-* tokens); nav items appear automatically per route name in AppShell.
- Scheduled sends fire through `outreach:send-due` (Laravel scheduler, every minute) →
  `ScheduledSender` → SendService; cap blocks re-queue with back-off, everything else fails loudly.
- Leads may exist without a campaign (the shared list at /leads); campaign_id is nullable.
- Never deploy, push, or touch production without an explicit instruction. Production =
  https://outreach.staffvora.com (see docs/DEPLOY.md).

## Working here
```powershell
$env:PATH = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;C:\laragon\bin\composer;C:\laragon\bin\nodejs\node-v22;" + $env:PATH
Set-Location C:\laragon\www\outreach-staffvora
php artisan test --compact     # must stay green
npm run build                  # after any JSX change (no HMR set up)
php artisan serve --host=localhost --port=8000   # Google OAuth needs exactly this host
php artisan schedule:work      # only needed when testing scheduled sends locally
```
Local login: `admin@outreach.test` / `password` (seeded). Demo data: `php artisan db:seed --class=DemoSeeder`.

## Docs
- `docs/GOOGLE-OAUTH-SETUP.md` — one-time Google Cloud setup and how mailboxes connect.
- `docs/DEPLOY.md` — production install/update, backups (APP_KEY!).
- `docs/CONVENTIONS.md` — code patterns.
