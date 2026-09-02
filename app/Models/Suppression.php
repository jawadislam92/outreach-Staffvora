<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Suppression extends Model
{
    use HasFactory;

    protected $fillable = ['email_normalized', 'reason', 'added_by'];

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public static function covers(string $emailNormalized): bool
    {
        return $emailNormalized !== '' && self::query()->where('email_normalized', $emailNormalized)->exists();
    }
}
