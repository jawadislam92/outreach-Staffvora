<?php

namespace App\Models;

use App\Enums\AccountProvider;
use App\Enums\AccountStatus;
use App\Enums\SmtpEncryption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailAccount extends Model
{
    use HasFactory, SoftDeletes;

    /** `secrets` is deliberately absent: it is only ever written via setSecrets(). */
    protected $fillable = [
        'label', 'provider', 'from_name', 'from_email', 'reply_to',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username',
        'daily_cap', 'signature_md', 'status', 'created_by',
    ];

    protected $hidden = ['secrets'];

    protected function casts(): array
    {
        return [
            'provider' => AccountProvider::class,
            'status' => AccountStatus::class,
            'smtp_encryption' => SmtpEncryption::class,
            'secrets' => 'encrypted:array',
            'smtp_port' => 'integer',
            'daily_cap' => 'integer',
            'last_test_at' => 'datetime',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_user')->withTimestamps();
    }

    public function sentEmails(): HasMany
    {
        return $this->hasMany(SentEmail::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AccountStatus::Active->value);
    }

    public function isGmail(): bool
    {
        return $this->provider === AccountProvider::GmailOauth;
    }

    public function isSmtp(): bool
    {
        return $this->provider === AccountProvider::Smtp;
    }

    public function isUsable(): bool
    {
        return $this->status === AccountStatus::Active && $this->deleted_at === null;
    }

    public function secret(string $key): mixed
    {
        return $this->secrets[$key] ?? null;
    }

    /** Merge keys into the encrypted secrets blob and persist. */
    public function setSecrets(array $values): void
    {
        $this->secrets = array_merge($this->secrets ?? [], $values);
        $this->save();
    }

    public function forgetSecrets(): void
    {
        $this->secrets = null;
        $this->save();
    }

    /** Presentation shape shared with the frontend — never includes secrets. */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'provider' => $this->provider->value,
            'provider_label' => $this->provider->label(),
            'from_name' => $this->from_name,
            'from_email' => $this->from_email,
            'reply_to' => $this->reply_to,
            'smtp_host' => $this->smtp_host,
            'smtp_port' => $this->smtp_port,
            'smtp_encryption' => $this->smtp_encryption?->value,
            'smtp_username' => $this->smtp_username,
            'has_password' => $this->isSmtp() && filled($this->secret('smtp_password')),
            'connected_email' => $this->isGmail() ? $this->secret('google_email') : null,
            'daily_cap' => $this->daily_cap,
            'signature_md' => $this->signature_md,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'last_test_at' => $this->last_test_at?->toIso8601String(),
            'last_error' => $this->last_error,
        ];
    }
}
