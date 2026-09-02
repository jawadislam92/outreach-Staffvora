<?php

namespace App\Models;

use App\Enums\ScheduleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A composed email waiting for its send time. Fired by outreach:send-due. */
class ScheduledEmail extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'email_account_id', 'lead_id', 'campaign_id', 'draft_id',
        'to_email', 'to_name', 'cc', 'bcc', 'subject', 'body_md', 'variables',
        'attachment_ids', 'ack_duplicate', 'scheduled_for', 'next_attempt_at',
        'status', 'attempts', 'last_error', 'sent_email_id',
    ];

    protected function casts(): array
    {
        return [
            'cc' => 'array',
            'bcc' => 'array',
            'variables' => 'array',
            'attachment_ids' => 'array',
            'ack_duplicate' => 'boolean',
            'scheduled_for' => 'datetime',
            'next_attempt_at' => 'datetime',
            'status' => ScheduleStatus::class,
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id')->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ScheduleStatus::Scheduled->value);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->pending()
            ->where('scheduled_for', '<=', now())
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
    }
}
