<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeAdmin extends Command
{
    protected $signature = 'outreach:admin {email : Login email} {--name= : Display name (new users only)}';

    protected $description = 'Create an admin user (or promote an existing user) and print a one-time password';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not a valid email address.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        $password = Str::password(14, symbols: false);

        if ($user) {
            $user->forceFill(['role' => Role::Admin, 'is_active' => true, 'password' => $password, 'remember_token' => null])->save();
            AuditLog::record('user.promote_admin', null, 'user', $user->id);
            $this->info("Promoted {$user->email} to admin and reset their password.");
        } else {
            $user = User::query()->create([
                'name' => $this->option('name') ?: Str::before($email, '@'),
                'email' => $email,
                'password' => $password,
                'role' => Role::Admin,
                'is_active' => true,
            ]);
            AuditLog::record('user.create', null, 'user', $user->id, null, ['email' => $email, 'role' => 'admin']);
            $this->info("Created admin {$user->email}.");
        }

        $this->newLine();
        $this->line("  Email:    <fg=yellow>{$user->email}</>");
        $this->line("  Password: <fg=yellow>{$password}</>");
        $this->newLine();
        $this->comment('Shown once — change it from Profile after logging in.');

        return self::SUCCESS;
    }
}
