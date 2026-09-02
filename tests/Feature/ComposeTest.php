<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Models\SentEmail;
use App\Models\User;
use App\Services\Sending\Connectors\FakeConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ComposeTest extends TestCase
{
    use RefreshDatabase;

    private function payload(EmailAccount $account, array $overrides = []): array
    {
        return array_merge([
            'client_token' => (string) Str::uuid(),
            'email_account_id' => $account->id,
            'to' => 'lead@example.com',
            'to_name' => 'Lead Person',
            'company' => 'Acme',
            'subject' => 'Hello {{first_name}}',
            'body_md' => 'Hi {{first_name}} from {{company}}',
            'attachment_ids' => [],
            'ack_duplicate' => false,
        ], $overrides);
    }

    public function test_members_see_only_their_assigned_accounts(): void
    {
        $member = User::factory()->create();
        $mine = EmailAccount::factory()->create(['label' => 'Mine']);
        EmailAccount::factory()->create(['label' => 'Not mine']);
        $mine->users()->attach($member);

        $this->actingAs($member)->get('/compose')->assertInertia(fn (Assert $page) => $page
            ->component('Compose')
            ->has('accounts', 1)
            ->where('accounts.0.account.label', 'Mine')
            ->where('accounts.0.remaining', 30)
            ->has('client_token')
        );
    }

    public function test_a_valid_compose_sends_and_lands_in_the_log(): void
    {
        $fake = FakeConnector::install();
        $member = User::factory()->create();
        $account = EmailAccount::factory()->create();
        $account->users()->attach($member);

        $response = $this->actingAs($member)->post('/compose', $this->payload($account));

        $response->assertRedirect('/compose');
        $response->assertSessionHas('success');

        $log = SentEmail::query()->firstOrFail();
        $this->assertSame('Hello Lead', $log->subject);
        $this->assertSame('Hi Lead from Acme', $log->body_md);
        $this->assertSame($member->id, $log->user_id);
        $this->assertSame('sent', $log->status->value);
        $this->assertCount(1, $fake->sent);
    }

    public function test_a_previously_contacted_recipient_is_blocked_until_acknowledged(): void
    {
        FakeConnector::install();
        $admin = User::factory()->admin()->create();
        $account = EmailAccount::factory()->create();
        SentEmail::factory()->create(['to_email' => 'lead@example.com', 'to_email_normalized' => 'lead@example.com', 'user_id' => $admin->id, 'email_account_id' => $account->id]);

        $response = $this->actingAs($admin)->from('/compose')->post('/compose', $this->payload($account));
        $response->assertRedirect('/compose');
        $response->assertSessionHasErrors('to');
        $response->assertSessionHas('blocked', fn ($b) => $b['reason'] === 'duplicate_ack_required' && $b['details']['count'] === 1);
        $this->assertSame(1, SentEmail::query()->count());

        $this->actingAs($admin)->post('/compose', $this->payload($account, ['ack_duplicate' => true]))->assertSessionHas('success');
        $this->assertSame(2, SentEmail::query()->count());
    }

    public function test_a_failed_send_reports_the_error_and_keeps_the_input(): void
    {
        $fake = FakeConnector::install();
        $fake->nextFailure = new \RuntimeException('SMTP 550 no such user');
        $admin = User::factory()->admin()->create();
        $account = EmailAccount::factory()->create();

        $response = $this->actingAs($admin)->from('/compose')->post('/compose', $this->payload($account));

        $response->assertRedirect('/compose');
        $response->assertSessionHas('error', fn ($m) => str_contains($m, '550'));
        $this->assertSame('failed', SentEmail::query()->firstOrFail()->status->value);
    }

    public function test_validation_and_recipient_check(): void
    {
        FakeConnector::install();
        $admin = User::factory()->admin()->create();
        $account = EmailAccount::factory()->create();

        $this->actingAs($admin)->from('/compose')
            ->post('/compose', $this->payload($account, ['subject' => '', 'to' => 'nope']))
            ->assertSessionHasErrors(['subject', 'to']);

        SentEmail::factory()->create(['to_email' => 'seen@example.com', 'to_email_normalized' => 'seen@example.com', 'user_id' => $admin->id, 'email_account_id' => $account->id, 'subject' => 'Earlier note']);

        $this->actingAs($admin)->getJson('/recipients/check?email=Seen@Example.com')
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('history.count', 1)
            ->assertJsonPath('history.last_subject', 'Earlier note');

        $this->actingAs($admin)->getJson('/recipients/check?email=garbage')->assertJsonPath('valid', false);
    }
}
