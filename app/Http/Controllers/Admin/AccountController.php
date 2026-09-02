<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountProvider;
use App\Enums\AccountStatus;
use App\Enums\SmtpEncryption;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EmailAccount;
use App\Models\User;
use App\Services\Reports\ReportQueries;
use App\Services\Sending\ConnectorFactory;
use App\Services\Sending\Google\GoogleOAuth;
use App\Services\Sending\MimeBuilder;
use App\Services\Sending\SendRequest;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public const PRESETS = [
        ['key' => 'hostinger', 'label' => 'Hostinger mailbox', 'host' => 'smtp.hostinger.com', 'port' => 465, 'encryption' => 'ssl'],
        ['key' => 'gmail_app_password', 'label' => 'Gmail with app password', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'starttls'],
        ['key' => 'outlook', 'label' => 'Outlook / Microsoft 365', 'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'starttls'],
        ['key' => 'custom', 'label' => 'Other (cPanel / custom)', 'host' => '', 'port' => 465, 'encryption' => 'ssl'],
    ];

    public function index(ReportQueries $reports, Settings $settings, GoogleOAuth $google): Response
    {
        $accounts = EmailAccount::query()->with('users:id,name')->orderBy('label')->get();
        $usage = collect($reports->accountUsage($accounts))->keyBy('account.id');

        return Inertia::render('Admin/Accounts', [
            'accounts' => $accounts->map(fn (EmailAccount $a) => $a->toSummary() + [
                'member_ids' => $a->users->pluck('id')->values(),
                'members' => $a->users->pluck('name')->values(),
                'usage' => $usage[$a->id] ?? null,
            ])->values(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->value]),
            'presets' => self::PRESETS,
            'default_daily_cap' => $settings->defaultDailyCap(),
            'google_configured' => $google->configured(),
            'timezone' => $settings->timezone(),
        ]);
    }

    public function store(Request $request, Settings $settings): RedirectResponse
    {
        $data = $this->validateSmtp($request);

        $account = EmailAccount::query()->create([
            'label' => $data['label'],
            'provider' => AccountProvider::Smtp,
            'from_name' => $data['from_name'],
            'from_email' => strtolower($data['from_email']),
            'reply_to' => $data['reply_to'] ?? null,
            'smtp_host' => $data['smtp_host'],
            'smtp_port' => $data['smtp_port'],
            'smtp_encryption' => $data['smtp_encryption'],
            'smtp_username' => $data['smtp_username'],
            'daily_cap' => $data['daily_cap'] ?? $settings->defaultDailyCap(),
            'signature_md' => $data['signature_md'] ?? null,
            'status' => AccountStatus::Active,
            'created_by' => $request->user()->id,
        ]);
        $account->setSecrets(['smtp_password' => $data['smtp_password']]);
        $account->users()->sync($data['member_ids'] ?? []);

        AuditLog::record('account.create', $request->user(), 'email_account', $account->id, null, $this->auditShape($account));

        return back()->with('success', "{$account->label} was added. Run “Test connection” to confirm the credentials.");
    }

    public function update(Request $request, EmailAccount $account): RedirectResponse
    {
        $before = $this->auditShape($account);

        if ($account->isSmtp()) {
            $data = $this->validateSmtp($request, $account);
            $account->fill([
                'label' => $data['label'],
                'from_name' => $data['from_name'],
                'from_email' => strtolower($data['from_email']),
                'reply_to' => $data['reply_to'] ?? null,
                'smtp_host' => $data['smtp_host'],
                'smtp_port' => $data['smtp_port'],
                'smtp_encryption' => $data['smtp_encryption'],
                'smtp_username' => $data['smtp_username'],
                'daily_cap' => $data['daily_cap'],
                'signature_md' => $data['signature_md'] ?? null,
            ])->save();
            if (filled($data['smtp_password'] ?? null)) {
                $account->setSecrets(['smtp_password' => $data['smtp_password']]);
            }
        } else {
            $data = $request->validate([
                'label' => ['required', 'string', 'max:100'],
                'from_name' => ['required', 'string', 'max:120'],
                'reply_to' => ['nullable', 'email:rfc', 'max:255'],
                'daily_cap' => ['required', 'integer', 'min:0', 'max:10000'],
                'signature_md' => ['nullable', 'string', 'max:5000'],
                'member_ids' => ['nullable', 'array'],
                'member_ids.*' => ['integer', 'exists:users,id'],
            ]);
            $account->fill([
                'label' => $data['label'],
                'from_name' => $data['from_name'],
                'reply_to' => $data['reply_to'] ?? null,
                'daily_cap' => $data['daily_cap'],
                'signature_md' => $data['signature_md'] ?? null,
            ])->save();
        }

        if (array_key_exists('member_ids', $data)) {
            $account->users()->sync($data['member_ids'] ?? []);
        }

        $after = $this->auditShape($account->fresh());
        if ($before !== $after) {
            AuditLog::record('account.update', $request->user(), 'email_account', $account->id, $before, $after);
        }

        return back()->with('success', "{$account->label} was updated.");
    }

    public function destroy(Request $request, EmailAccount $account): RedirectResponse
    {
        $account->users()->detach();
        $account->forgetSecrets();
        $account->forceFill(['status' => AccountStatus::Disabled])->save();
        $account->delete();

        AuditLog::record('account.delete', $request->user(), 'email_account', $account->id, $this->auditShape($account), null);

        return back()->with('success', "{$account->label} was removed. Its send history stays in the log.");
    }

    public function status(Request $request, EmailAccount $account): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'disabled'])]]);
        $before = $account->status->value;

        if ($account->status === AccountStatus::NeedsReconnect && $data['status'] === 'active') {
            return back()->with('error', 'Reconnect the Google account first.');
        }

        $account->forceFill(['status' => AccountStatus::from($data['status'])])->save();
        AuditLog::record('account.status', $request->user(), 'email_account', $account->id, ['status' => $before], ['status' => $data['status']]);

        return back()->with('success', "{$account->label} is now {$account->status->label()}.");
    }

    public function members(Request $request, EmailAccount $account): RedirectResponse
    {
        $data = $request->validate([
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', 'exists:users,id'],
        ]);
        $before = $account->users()->pluck('users.id')->sort()->values()->all();
        $account->users()->sync($data['member_ids'] ?? []);
        $after = $account->users()->pluck('users.id')->sort()->values()->all();

        if ($before !== $after) {
            AuditLog::record('account.members', $request->user(), 'email_account', $account->id, ['members' => $before], ['members' => $after]);
        }

        return back()->with('success', 'Access updated.');
    }

    public function test(Request $request, EmailAccount $account, ConnectorFactory $connectors): RedirectResponse
    {
        try {
            $connectors->make($account)->test();
        } catch (\Throwable $e) {
            $account->forceFill(['last_test_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            return back()->with('error', "Connection test failed for {$account->label}: {$e->getMessage()}");
        }

        $account->forceFill(['last_test_at' => now(), 'last_error' => null])->save();

        return back()->with('success', "{$account->label} connected and authenticated successfully.");
    }

    /** Sends a real message to the admin's own address via the connector; not logged as outreach. */
    public function sendTest(Request $request, EmailAccount $account, ConnectorFactory $connectors, MimeBuilder $mime): RedirectResponse
    {
        $me = $request->user();
        $sendRequest = new SendRequest(
            user: $me,
            account: $account,
            to: $me->email,
            toName: $me->name,
            subject: "Outreach test from {$account->label}",
            bodyMd: "Hi {$me->name},\n\nThis is a test message from **{$account->label}** ({$account->from_email}) sent at ".now()->toDayDateTimeString()." UTC.\n\nIf you can read this, the account is ready to send outreach.",
            clientToken: (string) Str::uuid(),
        );

        try {
            $built = $mime->build($sendRequest, $account);
            $connectors->make($account)->send($built['email']);
        } catch (\Throwable $e) {
            $account->forceFill(['last_test_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            AuditLog::record('account.test_send', $me, 'email_account', $account->id, null, ['to' => $me->email, 'ok' => false, 'error' => $e->getMessage()]);

            return back()->with('error', "Test send failed for {$account->label}: {$e->getMessage()}");
        }

        $account->forceFill(['last_test_at' => now(), 'last_error' => null])->save();
        AuditLog::record('account.test_send', $me, 'email_account', $account->id, null, ['to' => $me->email, 'ok' => true]);

        return back()->with('success', "Test email sent to {$me->email} from {$account->label}. Check your inbox (and spam).");
    }

    private function validateSmtp(Request $request, ?EmailAccount $existing = null): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'from_name' => ['required', 'string', 'max:120'],
            'from_email' => ['required', 'email:rfc', 'max:255'],
            'reply_to' => ['nullable', 'email:rfc', 'max:255'],
            'smtp_host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'smtp_port' => ['required', 'integer', Rule::in([465, 587])],
            'smtp_encryption' => ['required', Rule::enum(SmtpEncryption::class)],
            'smtp_username' => ['required', 'string', 'max:255'],
            'smtp_password' => [$existing ? 'nullable' : 'required', 'string', 'max:255'],
            'daily_cap' => ['required', 'integer', 'min:0', 'max:10000'],
            'signature_md' => ['nullable', 'string', 'max:5000'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', 'exists:users,id'],
        ]);
    }

    /** Audit snapshot — never includes secrets. */
    private function auditShape(EmailAccount $a): array
    {
        return [
            'label' => $a->label,
            'provider' => $a->provider->value,
            'from_name' => $a->from_name,
            'from_email' => $a->from_email,
            'reply_to' => $a->reply_to,
            'smtp_host' => $a->smtp_host,
            'smtp_port' => $a->smtp_port,
            'smtp_encryption' => $a->smtp_encryption?->value,
            'smtp_username' => $a->smtp_username,
            'daily_cap' => $a->daily_cap,
            'status' => $a->status->value,
            'has_signature' => filled($a->signature_md),
        ];
    }
}
