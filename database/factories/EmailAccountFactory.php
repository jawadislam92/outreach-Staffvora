<?php

namespace Database\Factories;

use App\Enums\AccountProvider;
use App\Enums\AccountStatus;
use App\Enums\SmtpEncryption;
use App\Models\EmailAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailAccount>
 */
class EmailAccountFactory extends Factory
{
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'label' => ucfirst(fake()->word()).' mailbox',
            'provider' => AccountProvider::Smtp,
            'from_name' => fake()->name(),
            'from_email' => $email,
            'reply_to' => null,
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_encryption' => SmtpEncryption::Ssl,
            'smtp_username' => $email,
            'secrets' => ['smtp_password' => 'factory-secret'],
            'daily_cap' => 30,
            'signature_md' => null,
            'status' => AccountStatus::Active,
            'last_test_at' => null,
            'last_error' => null,
            'created_by' => null,
        ];
    }

    public function gmail(?string $email = null): static
    {
        $email ??= fake()->unique()->userName().'@gmail.com';

        return $this->state(fn () => [
            'provider' => AccountProvider::GmailOauth,
            'from_email' => $email,
            'smtp_host' => null,
            'smtp_port' => null,
            'smtp_encryption' => null,
            'smtp_username' => null,
            'secrets' => [
                'refresh_token' => 'refresh-'.fake()->sha1(),
                'access_token' => 'access-'.fake()->sha1(),
                'expires_at' => now()->addHour()->toIso8601String(),
                'google_email' => $email,
            ],
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['status' => AccountStatus::Disabled]);
    }

    public function needsReconnect(): static
    {
        return $this->state(fn () => ['status' => AccountStatus::NeedsReconnect]);
    }
}
