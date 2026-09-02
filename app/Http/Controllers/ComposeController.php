<?php

namespace App\Http\Controllers;

use App\Enums\SendStatus;
use App\Models\Attachment;
use App\Models\EmailAccount;
use App\Models\Template;
use App\Services\Reports\ReportQueries;
use App\Services\Sending\SendBlockedException;
use App\Services\Sending\SendRequest;
use App\Services\Sending\SendService;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ComposeController extends Controller
{
    public function create(Request $request, ReportQueries $reports, Settings $settings): Response
    {
        $user = $request->user();
        $accounts = $user->usableAccounts();

        return Inertia::render('Compose', [
            'accounts' => collect($reports->accountUsage($accounts))->map(function (array $row) use ($accounts) {
                $account = $accounts->firstWhere('id', $row['account']['id']);
                $row['account']['signature_md'] = $account?->signature_md;

                return $row;
            })->values(),
            'templates' => Template::query()->active()->orderBy('name')->get(['id', 'name', 'subject', 'body_md']),
            'library' => Attachment::query()->where('is_library', true)->where('is_active', true)->orderBy('original_name')->get()->map->toSummary(),
            'settings' => [
                'min_gap_warning_seconds' => $settings->minGapWarningSeconds(),
                'max_attachment_mb' => (int) $settings->get('max_attachment_mb'),
                'timezone' => $settings->timezone(),
                'allowed_extensions' => config('outreach.attachments.allowed_extensions'),
            ],
            'prefill' => [
                'to' => (string) $request->query('to', ''),
                'to_name' => (string) $request->query('to_name', ''),
                'company' => (string) $request->query('company', ''),
            ],
            'client_token' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, SendService $sender): RedirectResponse
    {
        $data = $request->validate([
            'client_token' => ['required', 'string', 'max:64'],
            'email_account_id' => ['required', 'integer', 'exists:email_accounts,id'],
            'to' => ['required', 'string', 'email:rfc', 'max:255'],
            'to_name' => ['nullable', 'string', 'max:150'],
            'company' => ['nullable', 'string', 'max:150'],
            'cc' => ['nullable', 'array', 'max:5'],
            'cc.*' => ['email:rfc'],
            'bcc' => ['nullable', 'array', 'max:5'],
            'bcc.*' => ['email:rfc'],
            'subject' => ['required', 'string', 'max:500'],
            'body_md' => ['required', 'string', 'max:20000'],
            'attachment_ids' => ['nullable', 'array', 'max:10'],
            'attachment_ids.*' => ['string', 'exists:attachments,id'],
            'ack_duplicate' => ['nullable', 'boolean'],
        ]);

        $account = EmailAccount::query()->findOrFail($data['email_account_id']);
        $toName = trim((string) ($data['to_name'] ?? ''));
        [$first, $last] = array_pad(preg_split('/\s+/', $toName, 2) ?: [], 2, '');

        $sendRequest = new SendRequest(
            user: $request->user(),
            account: $account,
            to: trim($data['to']),
            toName: $toName ?: null,
            subject: $data['subject'],
            bodyMd: $data['body_md'],
            clientToken: $data['client_token'],
            cc: array_values(array_filter($data['cc'] ?? [])),
            bcc: array_values(array_filter($data['bcc'] ?? [])),
            attachmentIds: array_values($data['attachment_ids'] ?? []),
            variables: [
                'name' => $toName,
                'first_name' => $first,
                'last_name' => $last,
                'company' => trim((string) ($data['company'] ?? '')),
                'email' => trim($data['to']),
            ],
            ackDuplicate: (bool) ($data['ack_duplicate'] ?? false),
        );

        try {
            $log = $sender->send($sendRequest);
        } catch (SendBlockedException $e) {
            return back()
                ->withInput()
                ->withErrors([$e->field() => $e->getMessage()])
                ->with('blocked', ['reason' => $e->reason, 'details' => $e->details]);
        }

        return match ($log->status) {
            SendStatus::Sent => redirect()->route('compose.create')->with('success', "Sent to {$log->to_email} from {$account->label}."),
            SendStatus::Unknown => redirect()->route('compose.create')->with('warning', "No confirmation from the mail server for {$log->to_email} — check the mailbox before resending. Logged as unknown."),
            default => back()->withInput()->with('error', "Sending to {$log->to_email} failed: {$log->error}"),
        };
    }
}
