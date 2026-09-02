<?php

namespace App\Services\Sending;

use App\Enums\DraftStatus;
use App\Enums\LeadStatus;
use App\Enums\SendStatus;
use App\Models\Attachment;
use App\Models\Draft;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\SentEmail;
use App\Models\Suppression;
use App\Models\User;
use App\Support\EmailNormalizer;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only way a message leaves the app.
 *
 * Reservation pattern: a millisecond transaction locks the sender and the
 * account, checks caps/suppressions/duplicates and inserts a `pending` log
 * row; the network call happens OUTSIDE any transaction; a second short
 * transaction finalises the row as sent / failed / unknown and computes the
 * gap since the account's previous successful send.
 */
class SendService
{
    public function __construct(
        private ConnectorFactory $connectors,
        private MimeBuilder $mime,
        private RecipientHistory $history,
        private Settings $settings,
    ) {}

    /**
     * @throws SendBlockedException when nothing was reserved (cap, suppression, duplicate…)
     */
    public function send(SendRequest $request): SentEmail
    {
        $this->sweepStalePending();

        $existing = SentEmail::query()->where('client_token', $request->clientToken)->first();
        if ($existing) {
            return $existing;
        }

        $toNormalized = EmailNormalizer::normalize($request->to);
        if (! EmailNormalizer::isValid($toNormalized)) {
            throw new SendBlockedException(SendBlockedException::INVALID_RECIPIENT, 'That recipient address is not valid.');
        }

        $attachments = $this->resolveAttachments($request);

        try {
            $log = DB::transaction(fn () => $this->reserve($request, $toNormalized, $attachments));
        } catch (UniqueConstraintViolationException) {
            // A double-submit raced us; the first request owns this token.
            return SentEmail::query()->where('client_token', $request->clientToken)->firstOrFail();
        }

        return $this->deliver($log, $request, $attachments);
    }

    /** @param  list<Attachment>  $attachments */
    private function reserve(SendRequest $request, string $toNormalized, array $attachments): SentEmail
    {
        // Fixed lock order (user, then account) so concurrent sends never deadlock.
        $user = User::query()->whereKey($request->user->id)->lockForUpdate()->firstOrFail();
        $account = EmailAccount::query()->whereKey($request->account->id)->lockForUpdate()->firstOrFail();

        if (! $account->isUsable()) {
            throw new SendBlockedException(SendBlockedException::ACCOUNT_UNUSABLE, "{$account->label} cannot send right now ({$account->status->label()}).");
        }
        if (! $user->canUseAccount($account)) {
            throw new SendBlockedException(SendBlockedException::NOT_ALLOWED, 'You have not been given access to that sending account.');
        }
        if (Suppression::covers($toNormalized)) {
            throw new SendBlockedException(SendBlockedException::SUPPRESSED, 'This address is on the do-not-contact list.');
        }

        $history = $this->history->for($toNormalized);
        if ($history['count'] > 0 && ! $request->ackDuplicate) {
            throw new SendBlockedException(
                SendBlockedException::DUPLICATE_ACK_REQUIRED,
                'This person has been contacted before — confirm you still want to email them.',
                $history,
            );
        }

        $now = now();
        $localDate = $this->settings->localDate($now);

        $accountUsed = SentEmail::query()
            ->where('email_account_id', $account->id)
            ->where('local_date', $localDate)
            ->whereIn('status', SendStatus::countedTowardsCap())
            ->count();
        if ($accountUsed >= $account->daily_cap) {
            throw new SendBlockedException(
                SendBlockedException::CAP_REACHED,
                "{$account->label} has reached its daily cap of {$account->daily_cap}. It resets at midnight ({$this->settings->timezone()}).",
                ['used' => $accountUsed, 'cap' => $account->daily_cap],
            );
        }

        if ($user->daily_cap !== null) {
            $userUsed = SentEmail::query()
                ->where('user_id', $user->id)
                ->where('local_date', $localDate)
                ->whereIn('status', SendStatus::countedTowardsCap())
                ->count();
            if ($userUsed >= $user->daily_cap) {
                throw new SendBlockedException(
                    SendBlockedException::MEMBER_CAP_REACHED,
                    "You have reached your personal daily cap of {$user->daily_cap}.",
                    ['used' => $userUsed, 'cap' => $user->daily_cap],
                );
            }
        }

        $log = SentEmail::query()->create([
            'client_token' => $request->clientToken,
            'user_id' => $user->id,
            'email_account_id' => $account->id,
            'campaign_id' => $request->campaignId,
            'lead_id' => $request->leadId,
            'draft_id' => $request->draftId,
            'to_email' => trim($request->to),
            'to_email_normalized' => $toNormalized,
            'to_name' => $request->toName ?: null,
            'cc' => $request->cc ?: null,
            'bcc' => $request->bcc ?: null,
            'subject' => mb_substr($request->subject, 0, 500),
            'body_md' => $request->bodyMd,
            'status' => SendStatus::Pending,
            'provider' => $account->provider,
            'local_date' => $localDate,
            'reserved_at' => $now,
        ]);

        foreach ($attachments as $attachment) {
            $log->attachments()->attach($attachment->id, ['filename_as_sent' => $attachment->safeName()]);
        }

        return $log;
    }

    /** @param  list<Attachment>  $attachments */
    private function deliver(SentEmail $log, SendRequest $request, array $attachments): SentEmail
    {
        $account = $request->account->fresh();
        $started = microtime(true);

        try {
            $built = $this->mime->build($request, $account, $attachments);
        } catch (Throwable $e) {
            return $this->finalize($log, SendStatus::Failed, error: 'Could not build the message: '.$e->getMessage(), startedAt: $started);
        }

        $log->forceFill([
            'subject' => mb_substr($built['subject'], 0, 500),
            'body_md' => $built['body_md'],
            'body_html' => $built['body_html'],
            'body_text' => $built['body_text'],
            'message_id_header' => $built['email']->getHeaders()->get('Message-ID')?->getBodyAsString(),
        ])->save();

        try {
            $result = $this->connectors->make($account)->send($built['email']);
        } catch (SendTimeoutException $e) {
            Log::warning('Send timed out', ['sent_email' => $log->id, 'error' => $e->getMessage()]);

            return $this->finalize($log, SendStatus::Unknown, error: $e->getMessage(), startedAt: $started);
        } catch (Throwable $e) {
            Log::warning('Send failed', ['sent_email' => $log->id, 'error' => $e->getMessage()]);

            return $this->finalize($log, SendStatus::Failed, error: $e->getMessage(), startedAt: $started);
        }

        return $this->finalize($log, SendStatus::Sent, result: $result, startedAt: $started);
    }

    private function finalize(SentEmail $log, SendStatus $status, ?SendResult $result = null, ?string $error = null, float $startedAt = 0.0): SentEmail
    {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        DB::transaction(function () use ($log, $status, $result, $error, $durationMs) {
            // Serialise gap computation per account: two sends finishing at the
            // same moment must see each other.
            EmailAccount::query()->whereKey($log->email_account_id)->lockForUpdate()->first();

            $sentAt = $status === SendStatus::Sent ? now() : null;
            $gap = null;

            if ($sentAt) {
                $previous = SentEmail::query()
                    ->where('email_account_id', $log->email_account_id)
                    ->where('status', SendStatus::Sent->value)
                    ->whereKeyNot($log->id)
                    ->max('sent_at');
                if ($previous) {
                    $gap = max(0, $sentAt->diffInSeconds(\Carbon\Carbon::parse($previous), true));
                }
            }

            $log->forceFill([
                'status' => $status,
                'sent_at' => $sentAt,
                'gap_seconds' => $gap === null ? null : (int) $gap,
                'error' => $error ? mb_substr($error, 0, 2000) : null,
                'provider_message_id' => $result?->providerMessageId,
                'message_id_header' => $result?->messageIdHeader ?: $log->message_id_header,
                'thread_id' => $result?->threadId,
                'duration_ms' => $durationMs,
            ])->save();

            if ($status === SendStatus::Sent) {
                if ($log->draft_id) {
                    Draft::query()->whereKey($log->draft_id)->update(['status' => DraftStatus::Sent->value, 'sent_email_id' => $log->id]);
                }
                if ($log->lead_id) {
                    Lead::query()->whereKey($log->lead_id)->update(['status' => LeadStatus::Sent->value]);
                }
            }
        });

        return $log->refresh();
    }

    /**
     * A request that died mid-send leaves a `pending` row forever; after the
     * grace period it becomes `unknown` (still counted against the cap).
     */
    public function sweepStalePending(): int
    {
        $cutoff = now()->subMinutes((int) config('outreach.pending_stale_minutes', 10));

        return SentEmail::query()
            ->where('status', SendStatus::Pending->value)
            ->where('reserved_at', '<', $cutoff)
            ->update(['status' => SendStatus::Unknown->value, 'error' => 'No response was recorded — the message may or may not have been sent. Check the mailbox before resending.']);
    }

    /** @return list<Attachment> */
    private function resolveAttachments(SendRequest $request): array
    {
        if ($request->attachmentIds === []) {
            return [];
        }

        $attachments = Attachment::query()
            ->whereIn('id', $request->attachmentIds)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Attachment $a) => $a->is_library || $a->uploaded_by === $request->user->id)
            ->values()
            ->all();

        $total = array_sum(array_map(fn (Attachment $a) => $a->size, $attachments));
        if ($total > $this->settings->maxAttachmentBytes()) {
            throw new SendBlockedException(SendBlockedException::INVALID_RECIPIENT, 'Attachments exceed the size limit.');
        }

        return $attachments;
    }
}
