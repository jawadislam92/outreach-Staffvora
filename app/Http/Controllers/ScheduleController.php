<?php

namespace App\Http\Controllers;

use App\Enums\DraftSource;
use App\Enums\LeadStatus;
use App\Enums\ScheduleStatus;
use App\Models\Draft;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\ScheduledEmail;
use App\Models\Suppression;
use App\Services\Sending\RecipientHistory;
use App\Services\Sending\ScheduledSender;
use App\Support\EmailNormalizer;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Queue an email for later. One endpoint serves both Compose (raw recipient)
 * and the campaign queue (lead_id). The scheduler command delivers due rows.
 */
class ScheduleController extends Controller
{
    public function store(Request $request, RecipientHistory $history, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'email_account_id' => ['required', 'integer', 'exists:email_accounts,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'to' => ['required_without:lead_id', 'nullable', 'string', 'email:rfc', 'max:255'],
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
            'scheduled_for' => ['required', 'string'],
        ]);

        $user = $request->user();
        $account = EmailAccount::query()->findOrFail($data['email_account_id']);
        abort_unless($user->canUseAccount($account), 403, 'You have not been given access to that sending account.');

        $when = $this->parseWhen($data['scheduled_for'], $settings);

        $lead = isset($data['lead_id']) ? Lead::query()->findOrFail($data['lead_id']) : null;
        $to = $lead?->email ?? trim((string) $data['to']);
        $toNormalized = EmailNormalizer::normalize($to);

        if (Suppression::covers($toNormalized)) {
            throw ValidationException::withMessages(['to' => 'This address is on the do-not-contact list.']);
        }
        $ack = (bool) ($data['ack_duplicate'] ?? false);
        if (! $ack && $history->for($toNormalized)['count'] > 0) {
            throw ValidationException::withMessages(['to' => 'This person has been contacted before — tick "send anyway" to schedule it regardless.']);
        }

        $draftId = null;
        if ($lead) {
            // Persist the reviewed text so the queue and the scheduled send agree.
            $draft = Draft::query()->firstOrNew(['lead_id' => $lead->id], ['campaign_id' => $lead->campaign_id, 'source' => DraftSource::Manual]);
            $draft->fill(['campaign_id' => $lead->campaign_id, 'subject' => mb_substr($data['subject'], 0, 255), 'body_md' => $data['body_md'], 'edited_by' => $user->id]);
            if (! $draft->exists) {
                $draft->source = DraftSource::Manual;
            }
            if ($lead->campaign_id !== null) {
                $draft->save();
                $draftId = $draft->id;
            }
        }

        $toName = $lead?->fullName() ?? (trim((string) ($data['to_name'] ?? '')) ?: null);
        [$first, $rest] = array_pad(preg_split('/\s+/', (string) $toName, 2) ?: [], 2, '');

        $row = ScheduledEmail::query()->create([
            'user_id' => $user->id,
            'email_account_id' => $account->id,
            'lead_id' => $lead?->id,
            'campaign_id' => $lead?->campaign_id,
            'draft_id' => $draftId,
            'to_email' => $to,
            'to_name' => $toName,
            'cc' => array_values(array_filter($data['cc'] ?? [])) ?: null,
            'bcc' => array_values(array_filter($data['bcc'] ?? [])) ?: null,
            'subject' => $data['subject'],
            'body_md' => $data['body_md'],
            'variables' => $lead?->variables() ?? [
                'name' => (string) $toName,
                'first_name' => $first,
                'last_name' => $rest,
                'company' => trim((string) ($data['company'] ?? '')),
                'email' => $to,
            ],
            'attachment_ids' => array_values($data['attachment_ids'] ?? []) ?: null,
            'ack_duplicate' => $ack,
            'scheduled_for' => $when,
            'status' => ScheduleStatus::Scheduled,
        ]);

        $local = $when->setTimezone($settings->timezone())->format('D d M, H:i');

        return back()->with('success', "Scheduled for {$local} ({$settings->timezone()}) from {$account->label} to {$row->to_email}.");
    }

    public function cancel(Request $request, ScheduledEmail $scheduledEmail): RedirectResponse
    {
        $this->authorizeRow($request, $scheduledEmail);
        abort_unless($scheduledEmail->status === ScheduleStatus::Scheduled, 422, 'Only scheduled emails can be cancelled.');

        $scheduledEmail->forceFill(['status' => ScheduleStatus::Cancelled])->save();

        return back()->with('info', "Scheduled email to {$scheduledEmail->to_email} cancelled.");
    }

    public function sendNow(Request $request, ScheduledEmail $scheduledEmail, ScheduledSender $sender): RedirectResponse
    {
        $this->authorizeRow($request, $scheduledEmail);
        abort_unless($scheduledEmail->status === ScheduleStatus::Scheduled, 422, 'This email is no longer scheduled.');

        $result = $sender->fire($scheduledEmail);

        return match ($result->status) {
            ScheduleStatus::Sent => back()->with('success', "Sent to {$result->to_email}."),
            ScheduleStatus::Scheduled => back()->with('warning', $result->last_error ?: 'Could not send right now — it stays scheduled.'),
            default => back()->with('error', "Sending failed: {$result->last_error}"),
        };
    }

    /** Members manage their own scheduled emails; admins manage all. */
    private function authorizeRow(Request $request, ScheduledEmail $row): void
    {
        abort_unless($request->user()->isAdmin() || $row->user_id === $request->user()->id, 403);
    }

    private function parseWhen(string $value, Settings $settings): CarbonImmutable
    {
        try {
            $when = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, $settings->timezone());
        } catch (\Throwable) {
            $when = false;
        }
        if ($when === false) {
            throw ValidationException::withMessages(['scheduled_for' => 'Pick a valid date and time.']);
        }

        $when = $when->utc();
        if ($when->lte(now()->addMinute())) {
            throw ValidationException::withMessages(['scheduled_for' => 'Pick a time at least a couple of minutes in the future.']);
        }
        if ($when->gt(now()->addDays(60))) {
            throw ValidationException::withMessages(['scheduled_for' => 'Scheduling is limited to the next 60 days.']);
        }

        return $when;
    }

    /** Shape shared with the Log page's Scheduled tab. */
    public static function row(ScheduledEmail $row): array
    {
        return [
            'id' => $row->id,
            'to_email' => $row->to_email,
            'to_name' => $row->to_name,
            'subject' => $row->subject,
            'status' => $row->status->value,
            'status_label' => $row->status->label(),
            'user' => $row->user?->name,
            'user_id' => $row->user_id,
            'account' => $row->account?->label,
            'campaign' => $row->campaign?->name,
            'scheduled_for' => $row->scheduled_for?->toIso8601String(),
            'attempts' => $row->attempts,
            'last_error' => $row->last_error,
            'sent_email_id' => $row->sent_email_id,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
