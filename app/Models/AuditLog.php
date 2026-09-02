<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'before', 'after', 'ip', 'created_at'];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(
        string $action,
        ?User $actor,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        ?array $before = null,
        ?array $after = null,
    ): self {
        return self::query()->create([
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'before' => $before,
            'after' => $after,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
