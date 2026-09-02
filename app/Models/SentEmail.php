<?php

namespace App\Models;

use App\Enums\AccountProvider;
use App\Enums\SendStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Append-only send log. Rows are created by SendService only and are never
 * deleted; status moves pending → sent | failed | unknown exactly once.
 */
class SentEmail extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'client_token', 'user_id', 'email_account_id', 'campaign_id', 'lead_id', 'draft_id',
        'to_email', 'to_email_normalized', 'to_name', 'cc', 'bcc', 'subject',
        'body_md', 'body_html', 'body_text', 'status', 'error', 'provider',
        'provider_message_id', 'message_id_header', 'thread_id', 'gap_seconds',
        'local_date', 'reserved_at', 'sent_at', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'cc' => 'array',
            'bcc' => 'array',
            'status' => SendStatus::class,
            'provider' => AccountProvider::class,
            'gap_seconds' => 'integer',
            'duration_ms' => 'integer',
            'local_date' => 'date:Y-m-d',
            'reserved_at' => 'datetime',
            'sent_at' => 'datetime',
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

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(Attachment::class, 'sent_email_attachments')
            ->withPivot('filename_as_sent')
            ->withTrashed();
    }
}
