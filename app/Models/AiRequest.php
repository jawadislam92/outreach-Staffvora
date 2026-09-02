<?php

namespace App\Models;

use App\Enums\AiRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id', 'lead_id', 'user_id', 'model', 'effort',
        'input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens',
        'duration_ms', 'status', 'error', 'estimated_cost_usd',
    ];

    protected function casts(): array
    {
        return [
            'status' => AiRequestStatus::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_input_tokens' => 'integer',
            'cache_creation_input_tokens' => 'integer',
            'duration_ms' => 'integer',
            'estimated_cost_usd' => 'decimal:6',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** USD estimate from config pricing (per-million-token rates). */
    public static function estimateCost(string $model, int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): float
    {
        $rates = config("outreach.anthropic.pricing.{$model}");
        if (! is_array($rates)) {
            return 0.0;
        }
        [$in, $out, $read, $write] = array_pad($rates, 4, 0);

        return round(($input * $in + $output * $out + $cacheRead * $read + $cacheWrite * $write) / 1_000_000, 6);
    }
}
