# Outreach — code conventions (read before adding a feature)

## What the app is
Send-only team outreach mailer. Admin connects 1–3 of the owner's mailboxes (Gmail via
OAuth `gmail.send` only, or SMTP). Team members log in, compose or review AI drafts, and
send FROM those mailboxes. Every send goes through `App\Services\Sending\SendService` and
is logged in `sent_emails` (append-only). There is no receive/IMAP code and there must
never be any. Full design: `C:/Users/jawad/.claude/plans/i-wanted-to-create-elegant-island.md`.

## Toolchain (Windows / Laragon — nothing is on PATH by default)
```powershell
$env:PATH = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;C:\laragon\bin\composer;C:\laragon\bin\nodejs\node-v22;" + $env:PATH
Set-Location C:\laragon\www\outreach-staffvora
php artisan test --compact                       # PHPUnit, SQLite in-memory, fast
php artisan test --compact --filter=ReportsTest  # one class
npm run build                                    # also the JSX syntax check (Vite 8 uses rolldown; esbuild is not installed). ~2–5s
```
Dev server: `php artisan serve --host=localhost --port=8000` (MySQL `outreach` DB, seeded
admin `admin@outreach.test` / `password`). Frontend: `npm run build` (Vite) — coordinate
builds; never run two builds at once.

## Backend patterns
- Laravel 13, PHP 8.3. Controllers are thin; logic in `app/Services/...`; enums in `app/Enums`
  (backed string enums with `label()`), cast on models via `casts()`.
- Auth: `auth` middleware; `admin` alias = `App\Http\Middleware\EnsureAdmin`. `$user->isAdmin()`.
- Settings: `app(App\Support\Settings::class)` — `timezone()`, `today()`, `localDate($carbon)`,
  `defaultDailyCap()`, `aiModel()`, `aiEffort()`, `maxAttachmentBytes()`.
- Audit: `App\Models\AuditLog::record('thing.action', $request->user(), 'subject_type', $id, $before, $after)`
  for every admin-level change. Never put secrets in audit payloads.
- CSV download: `App\Support\CsvExport::stream($filename, $headers, $rowsIterable, $user, 'x.export', $detail)`
  (writes an audit row, adds a UTF-8 BOM).
- Email normalisation: `App\Support\EmailNormalizer::normalize()/parse()/isValid()`.
- Markdown: `App\Support\Markdown::toHtml()/toText()`; template vars: `App\Services\Templates\TemplateRenderer::render($text, $vars)`.
- Attachments: `App\Http\Controllers\AttachmentController::storeUpload($request, $settings, isLibrary: bool)`.
- Reports: `App\Services\Reports\ReportQueries` — aggregate with `DB::table('sent_emails')`
  (NOT Eloquent: the `status` cast to enum breaks `pluck('n','status')`). Filter by `local_date`.
- Inertia pages: `Inertia::render('Dir/Page', [...])`; props shaped in the controller
  (models expose `toSummary()`), never raw models with secrets.
- Flash: `->with('success'|'error'|'warning'|'info', 'message')`; the shell toasts them.

## Frontend patterns (React 18 JSX, Inertia 2, Tailwind 3)
- Page skeleton:
  ```jsx
  import AppShell from '@/Layouts/AppShell';
  import PageShell from '@/Components/Layout/PageShell';
  import PageHeader from '@/Components/Layout/PageHeader';
  import { Badge, Button, Card, ConfirmDialog, EmptyState, Field, Input, KpiCard, Pagination, Select, Table, TBody, Td, Th, THead, Textarea, Toggle, Tr } from '@/Components/ui';
  export default function Page(props) {
      return (
          <AppShell title="Page title">
              <PageShell>
                  <PageHeader eyebrow="Admin" title="…" description="…" actions={<Button …/>} />
                  …
              </PageShell>
          </AppShell>
      );
  }
  ```
- Full width always (PageShell is fluid; never add `max-w-*` containers to pages).
- Theme tokens only: `bg-app bg-surface bg-surface-2 border-line text-body text-muted text-subtle
  text-accent bg-accent text-success text-danger text-warning bg-success-soft …` — no raw grey/blue classes.
- Modals: `import Modal from '@/Components/Modal'` (`show`, `onClose`, `maxWidth`); confirmations: `ConfirmDialog`.
- Forms: `useForm` from Inertia for simple forms; for modal forms use `router.post/patch/delete(url, data, { preserveScroll: true, onError: setErrors, onSuccess })` (see `Pages/Admin/Users.jsx`).
- Toasts: `import notify from '@/Utils/notify'` → `notify.success('…')`. Flash toasts are automatic.
- Formatting helpers: `@/Utils/format` → `formatDateTime(iso, tz)`, `formatDate(ymd)`, `formatGap(seconds)`, `formatBytes`, `formatUsd`. Team timezone: `usePage().props.app.timezone`.
- Charts: `chart.js` + `react-chartjs-2`; colours from CSS vars via `@/Components/charts/theme` (`vizPalette()`, `textTokens()`, `useThemeName()`); series colour is fixed per entity (violet=sent volume, blue=people, green=gaps, amber=warnings, pink=failures).
- Icons: `lucide-react`. Nav items appear automatically once their route name exists (`AppShell` checks `route().has()`).

## Tests
- PHPUnit (`tests/Feature`, `tests/Unit`), `RefreshDatabase`, factories for User (`->admin()`),
  EmailAccount (`->gmail()`, `->disabled()`), SentEmail (`->failed()`, `->pending()`, `->unknown()`), Template.
- Inertia assertions: `->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->component('X')->has('rows', 2)->where('a.b', 1))`.
- Fake transport: `App\Services\Sending\Connectors\FakeConnector::install()`.
- Every route needs: a member-vs-admin authorisation test where relevant, a happy path, and one validation failure.
