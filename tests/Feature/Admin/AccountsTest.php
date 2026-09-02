<?php

namespace Tests\Feature\Admin;

use App\Models\EmailAccount;
use App\Models\User;
use App\Services\Sending\Connectors\FakeConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountsTest extends TestCase
{
    use RefreshDatabase;

    private function smtpPayload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Sparking Asia',
            'from_name' => 'Jawad',
            'from_email' => 'hello@sparkingasia.com',
            'reply_to' => null,
            'smtp_host' => 'smtp.hostinger.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => 'hello@sparkingasia.com',
            'smtp_password' => 'super-secret-pass',
            'daily_cap' => 25,
            'signature_md' => "Best,\nJawad",
            'member_ids' => [],
        ], $overrides);
    }

    public function test_admin_can_add_an_smtp_account_and_the_password_is_encrypted_and_never_exposed(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $this->actingAs($admin)
            ->post('/admin/accounts', $this->smtpPayload(['member_ids' => [$member->id]]))
            ->assertRedirect();

        $account = EmailAccount::query()->firstOrFail();
        $this->assertSame('super-secret-pass', $account->secret('smtp_password'));
        $this->assertSame(25, $account->daily_cap);
        $this->assertTrue($member->fresh()->canUseAccount($account));

        $raw = DB::table('email_accounts')->value('secrets');
        $this->assertStringNotContainsString('super-secret-pass', (string) $raw);

        $this->actingAs($admin)->get('/admin/accounts')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Accounts')
            ->has('accounts', 1)
            ->where('accounts.0.has_password', true)
            ->where('accounts.0.member_ids', [$member->id])
            ->missing('accounts.0.secrets')
        );

        $this->assertDatabaseHas('audit_logs', ['action' => 'account.create']);
        $audit = DB::table('audit_logs')->where('action', 'account.create')->value('after');
        $this->assertStringNotContainsString('super-secret-pass', (string) $audit);
    }

    public function test_updating_without_a_password_keeps_the_old_one(): void
    {
        $admin = User::factory()->admin()->create();
        $account = EmailAccount::factory()->create();

        $this->actingAs($admin)
            ->patch("/admin/accounts/{$account->id}", $this->smtpPayload(['smtp_password' => '', 'label' => 'Renamed', 'daily_cap' => 10]))
            ->assertRedirect();

        $account->refresh();
        $this->assertSame('Renamed', $account->label);
        $this->assertSame(10, $account->daily_cap);
        $this->assertSame('factory-secret', $account->secret('smtp_password'));
    }

    public function test_removing_an_account_soft_deletes_it_and_forgets_its_secrets(): void
    {
        $admin = User::factory()->admin()->create();
        $account = EmailAccount::factory()->create();

        $this->actingAs($admin)->delete("/admin/accounts/{$account->id}")->assertRedirect();

        $this->assertSoftDeleted('email_accounts', ['id' => $account->id]);
        $this->assertNull(EmailAccount::withTrashed()->find($account->id)->secrets);
    }

    public function test_connection_test_records_success_and_failure(): void
    {
        $fake = FakeConnector::install();
        $admin = User::factory()->admin()->create();
        $account = EmailAccount::factory()->create();

        $this->actingAs($admin)->post("/admin/accounts/{$account->id}/test")->assertSessionHas('success');
        $this->assertNotNull($account->fresh()->last_test_at);
        $this->assertSame(1, $fake->testCalls);

        $fake->nextFailure = new \RuntimeException('SMTP 535 bad credentials');
        $this->actingAs($admin)->post("/admin/accounts/{$account->id}/test")->assertSessionHas('error');
        $this->assertStringContainsString('535', $account->fresh()->last_error);
    }

    public function test_send_test_uses_the_connector_without_touching_the_outreach_log(): void
    {
        $fake = FakeConnector::install();
        $admin = User::factory()->admin()->create(['email' => 'me@example.com']);
        $account = EmailAccount::factory()->create();

        $this->actingAs($admin)->post("/admin/accounts/{$account->id}/send-test")->assertSessionHas('success');

        $this->assertCount(1, $fake->sent);
        $this->assertSame('me@example.com', $fake->last()->getTo()[0]->getAddress());
        $this->assertDatabaseCount('sent_emails', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.test_send']);
    }

    public function test_validation_rejects_unsupported_ports_and_members_are_forbidden(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $this->actingAs($admin)->from('/admin/accounts')
            ->post('/admin/accounts', $this->smtpPayload(['smtp_port' => 25]))
            ->assertSessionHasErrors('smtp_port');

        $this->actingAs($member)->post('/admin/accounts', $this->smtpPayload())->assertForbidden();
        $this->actingAs($member)->get('/admin/accounts')->assertForbidden();
    }
}
