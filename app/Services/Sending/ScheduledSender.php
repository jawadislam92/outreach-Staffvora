<?php

namespace App\Services\Sending;

use App\Enums\ScheduleStatus;
use App\Enums\SendStatus;
use App\Models\ScheduledEmail;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fires one due ScheduledEmail through SendService. Cap blocks re-schedule
 * with a back-off (the cap frees when the business day rolls over); every
 * other block is a terminal failure a human must look at.
 */
class ScheduledSender
{
    public const RETRY_MINUTES = 15;

    public function __construct(private SendService $sender) {}

    public function fire(ScheduledEmail $row): ScheduledEmail
    {
        if ($row->status !== ScheduleStatus::Scheduled) {
            return $row;
        }

        $account = $row->account;
        $user = $row->user;
        if (! $account || ! $user) {
            return $this->fail($row, 'The sending account or user no longer exists.');
        }

        // Prefer the lead's live data so late edits still apply.
        $variables = $row->lead?->variables() ?? (array) ($row->variables ?? []);

        $request = new SendRequest(
            user: $user,
            account: $account,
            to: $row->to_email,
            toName: $row->to_name,
            subject: $row->subject,
            bodyMd: $row->body_md,
            clientToken: 'sched-'.$row->id,
            cc: array_values($row->cc ?? []),
            bcc: array_values($row->bcc ?? []),
            attachmentIds: array_values($row->attachment_ids ?? []),
            variables: $variables,
            ackDuplicate: $row->ack_duplicate,
            campaignId: $row->campaign_id,
            leadId: $row->lead_id,
            draftId: $row->draft_id,
        );

        $row->increment('attempts');

        try {
            $log = $this->sender->send($request);
        } catch (SendBlockedException $e) {
            if (in_array($e->reason, [SendBlockedException::CAP_REACHED, SendBlockedException::MEMBER_CAP_REACHED], true)) {
                $row->forceFill([
                    'next_attempt_at' => now()->addMinutes(self::RETRY_MINUTES),
                    'last_error' => $e->getMessage().' Will retry automatically.',
                ])->save();

                return $row;
            }

            return $this->fail($row, $e->getMessage());
        } catch (Throwable $e) {
            Log::warning('Scheduled send crashed', ['scheduled_email' => $row->id, 'error' => $e->getMessage()]);

            return $this->fail($row, $e->getMessage());
        }

        if ($log->status === SendStatus::Failed) {
            return $this->fail($row, (string) $log->error, $log->id);
        }

        $row->forceFill([
            'status' => ScheduleStatus::Sent,
            'sent_email_id' => $log->id,
            'last_error' => $log->status === SendStatus::Unknown
                ? 'No confirmation from the mail server — logged as unknown; check the mailbox.'
                : null,
        ])->save();

        return $row;
    }

    private function fail(ScheduledEmail $row, string $error, ?string $sentEmailId = null): ScheduledEmail
    {
        $row->forceFill([
            'status' => ScheduleStatus::Failed,
            'last_error' => mb_substr($error, 0, 1000),
            'sent_email_id' => $sentEmailId,
        ])->save();

        return $row;
    }
}
