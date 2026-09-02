<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attachment extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    /** The `local` disk already roots at storage/app/private (never web-served). */
    public const DISK = 'local';

    public const DIRECTORY = 'attachments';

    protected $fillable = [
        'original_name', 'stored_path', 'mime', 'size', 'sha256', 'is_library', 'is_active', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'is_library' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Sends this file went out with (why library files are never deleted from disk). */
    public function sentEmails(): BelongsToMany
    {
        return $this->belongsToMany(SentEmail::class, 'sent_email_attachments');
    }

    /** Safe filename for Content-Disposition / MIME part names. */
    public function safeName(): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|]+/u', '_', $this->original_name) ?? 'attachment';
        $name = trim($name, " ._");

        return $name !== '' ? mb_substr($name, 0, 150) : 'attachment';
    }

    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'is_library' => $this->is_library,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
