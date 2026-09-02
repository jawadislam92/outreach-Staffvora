<?php

namespace App\Models;

use App\Enums\DraftSource;
use App\Enums\DraftStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Draft extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id', 'campaign_id', 'subject', 'body_md', 'source', 'model',
        'input_tokens', 'output_tokens', 'cache_read_tokens', 'generation_count',
        'status', 'sent_email_id', 'edited_by',
    ];

    protected function casts(): array
    {
        return [
            'source' => DraftSource::class,
            'status' => DraftStatus::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'generation_count' => 'integer',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function sentEmail(): BelongsTo
    {
        return $this->belongsTo(SentEmail::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
