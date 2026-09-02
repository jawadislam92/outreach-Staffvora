<?php

namespace Database\Factories;

use App\Enums\AccountProvider;
use App\Enums\SendStatus;
use App\Models\EmailAccount;
use App\Models\SentEmail;
use App\Models\User;
use App\Support\EmailNormalizer;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SentEmail>
 */
class SentEmailFactory extends Factory
{
    public function definition(): array
    {
        $to = fake()->unique()->safeEmail();
        $now = now();

        return [
            'client_token' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'email_account_id' => EmailAccount::factory(),
            'to_email' => $to,
            'to_email_normalized' => EmailNormalizer::normalize($to),
            'to_name' => fake()->name(),
            'subject' => fake()->sentence(4),
            'body_md' => 'Hello there',
            'body_html' => '<p>Hello there</p>',
            'body_text' => 'Hello there',
            'status' => SendStatus::Sent,
            'provider' => AccountProvider::Smtp,
            'local_date' => app(Settings::class)->localDate($now),
            'reserved_at' => $now,
            'sent_at' => $now,
            'gap_seconds' => null,
            'duration_ms' => 800,
        ];
    }

    public function failed(string $error = 'SMTP 550 rejected'): static
    {
        return $this->state(fn () => ['status' => SendStatus::Failed, 'sent_at' => null, 'error' => $error]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => SendStatus::Pending, 'sent_at' => null]);
    }

    public function unknown(): static
    {
        return $this->state(fn () => ['status' => SendStatus::Unknown, 'sent_at' => null]);
    }
}
