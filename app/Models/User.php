<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'daily_cap', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'daily_cap' => 'integer',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /** Accounts this member has been granted (admins may use every usable account). */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(EmailAccount::class, 'account_user')->withTimestamps();
    }

    public function sentEmails(): HasMany
    {
        return $this->hasMany(SentEmail::class);
    }

    public function canUseAccount(EmailAccount $account): bool
    {
        if (! $account->isUsable()) {
            return false;
        }

        return $this->isAdmin() || $this->accounts()->whereKey($account->id)->exists();
    }

    /** Usable accounts for the compose screen, in a stable order. */
    public function usableAccounts()
    {
        $query = $this->isAdmin() ? EmailAccount::query() : $this->accounts();

        return $query->active()->orderBy('label')->get();
    }

    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'is_admin' => $this->isAdmin(),
            'daily_cap' => $this->daily_cap,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
