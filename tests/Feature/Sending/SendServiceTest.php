<?php

namespace Tests\Feature\Sending;

use App\Enums\SendStatus;
use App\Models\EmailAccount;
use App\Models\SentEmail;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Sending\Connectors\FakeConnector;
use App\Services\Sending\SendBlockedException;
use App\Services\Sending\SendRequest;
use App\Services\Sending\SendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SendServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeConnector $fake;

    private User $admin;

    private EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = FakeConnector::install();
        $this->admin = User::factory()->admin()->create();
        $this->account = EmailAccount::factory()->create(['daily_cap' => 30]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): SendService
    {
        return app(SendService::class);
    }

    private function request(?User $user = null, ?EmailAccount $account = null, array $o = []): SendRequest
    {
        return new SendRequest(
            user: $user ?? $this->admin,
            account: $account ?? $this->account,
            to: $o['to'] ?? 'lead@example.com',
            toName: $o['to_name'] ?? 'Lead Person',
            subject: $o['subject'] ?? 'Hello {{first_name}}',
            bodyMd: $o['body'] ?? "Hi {{first_name}},\n\nQuick **note**.",
            clientToken: $o['token'] ?? (string) Str::uuid(),
            cc: $o['cc'] ?? [],
            bcc: $o['bcc'] ?? [],
            attachmentIds: [],
            variables: $o['vars'] ?? ['first_name' => 'Lead', 'name' => 'Lead Person', 'company' => 'Acme'],
            ackDuplicate: $o['ack'] ?? false,
        );
    }

    public function test_a_send_is_logged_with_rendered_template_and_provider_ids(): void
    {
        $log = $this->service()->send($this->request());

        $this->assertSame(SendStatus::Sent, $log->status);
        $this->assertSame('Hello Lead', $log->subject);
        $this->assertStringContainsString('Hi Lead,', $log->body_md);
        $this->assertStringContainsString('<strong>note</strong>', $log->body_html);
        $this->assertSame('fake-1', $log->provider_message_id);
        $this->assertNotEmpty($log->message_id_header);
        $this->assertNull($log->gap_seconds);
        $this->assertNotNull($log->sent_at);
        $this->assertCount(1, $this->fake->sent);
    }

    public function test_gap_is_measured_from_the_previous_successful_send_on_the_same_account(): void
    {
        Carbon::setTestNow('2026-09-02 09:00:00');
        $this->service()->send($this->request(o: ['to' => 'a@example.com']));

        Carbon::setTestNow('2026-09-02 09:01:30');
        $second = $this->service()->send($this->request(o: ['to' => 'b@example.com']));
        $this->assertSame(90, $second->gap_seconds);

        // A different account has its own clock.
        $other = EmailAccount::factory()->create();
        Carbon::setTestNow('2026-09-02 09:02:00');
        $third = $this->service()->send($this->request(account: $other, o: ['to' => 'c@example.com']));
        $this->assertNull($third->gap_seconds);
    }

    public function test_failed_sends_neither_reset_nor_create_gaps(): void
    {
        Carbon::setTestNow('2026-09-02 09:00:00');
        $this->service()->send($this->request(o: ['to' => 'a@example.com']));

        Carbon::setTestNow('2026-09-02 09:00:30');
        $this->fake->nextFailure = new \RuntimeException('550 mailbox unavailable');
        $failed = $this->service()->send($this->request(o: ['to' => 'b@example.com']));
        $this->assertSame(SendStatus::Failed, $failed->status);
        $this->assertNull($failed->gap_seconds);
        $this->assertStringContainsString('550', $failed->error);

        Carbon::setTestNow('2026-09-02 09:02:00');
        $third = $this->service()->send($this->request(o: ['to' => 'c@example.com']));
        $this->assertSame(120, $third->gap_seconds);
    }

    public function test_daily_cap_is_enforced_at_the_boundary(): void
    {
        $this->account->update(['daily_cap' => 2]);

        $this->service()->send($this->request(o: ['to' => 'a@example.com']));
        $this->service()->send($this->request(o: ['to' => 'b@example.com']));

        try {
            $this->service()->send($this->request(o: ['to' => 'c@example.com']));
            $this->fail('Third send should have been blocked');
        } catch (SendBlockedException $e) {
            $this->assertSame(SendBlockedException::CAP_REACHED, $e->reason);
            $this->assertSame(['used' => 2, 'cap' => 2], $e->details);
        }

        $this->assertSame(2, SentEmail::query()->count());
    }

    public function test_failed_sends_do_not_consume_the_cap_but_unknown_ones_do(): void
    {
        $this->account->update(['daily_cap' => 1]);

        $this->fake->nextFailure = new \RuntimeException('boom');
        $this->service()->send($this->request(o: ['to' => 'a@example.com']));

        // Still one slot: the failure freed it.
        $this->fake->timeout = true;
        $unknown = $this->service()->send($this->request(o: ['to' => 'b@example.com']));
        $this->assertSame(SendStatus::Unknown, $unknown->status);
        $this->fake->timeout = false;

        $this->expectException(SendBlockedException::class);
        $this->service()->send($this->request(o: ['to' => 'c@example.com']));
    }

    public function test_member_cap_applies_across_accounts(): void
    {
        $member = User::factory()->create(['daily_cap' => 1]);
        $second = EmailAccount::factory()->create();
        $this->account->users()->attach($member);
        $second->users()->attach($member);

        $this->service()->send($this->request($member, $this->account, ['to' => 'a@example.com']));

        try {
            $this->service()->send($this->request($member, $second, ['to' => 'b@example.com']));
            $this->fail('Member cap should block');
        } catch (SendBlockedException $e) {
            $this->assertSame(SendBlockedException::MEMBER_CAP_REACHED, $e->reason);
        }
    }

    public function test_the_same_client_token_never_sends_twice(): void
    {
        $token = (string) Str::uuid();
        $first = $this->service()->send($this->request(o: ['token' => $token]));
        $second = $this->service()->send($this->request(o: ['token' => $token, 'to' => 'other@example.com']));

        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, $this->fake->sent);
        $this->assertSame(1, SentEmail::query()->count());
    }

    public function test_suppressed_addresses_are_refused(): void
    {
        Suppression::query()->create(['email_normalized' => 'optout@example.com', 'reason' => 'asked']);

        try {
            $this->service()->send($this->request(o: ['to' => 'OptOut@Example.com']));
            $this->fail('Suppressed address should block');
        } catch (SendBlockedException $e) {
            $this->assertSame(SendBlockedException::SUPPRESSED, $e->reason);
        }
        $this->assertSame(0, SentEmail::query()->count());
    }

    public function test_previously_contacted_recipients_need_an_explicit_acknowledgement(): void
    {
        $this->service()->send($this->request(o: ['to' => 'lead@example.com']));

        try {
            $this->service()->send($this->request(o: ['to' => 'Lead@Example.com']));
            $this->fail('Duplicate should require ack');
        } catch (SendBlockedException $e) {
            $this->assertSame(SendBlockedException::DUPLICATE_ACK_REQUIRED, $e->reason);
            $this->assertSame(1, $e->details['count']);
            $this->assertSame($this->admin->name, $e->details['last_by']);
        }

        $acked = $this->service()->send($this->request(o: ['to' => 'Lead@Example.com', 'ack' => true]));
        $this->assertSame(SendStatus::Sent, $acked->status);
    }

    public function test_members_can_only_use_assigned_accounts_and_disabled_accounts_are_refused(): void
    {
        $member = User::factory()->create();

        try {
            $this->service()->send($this->request($member));
            $this->fail('Unassigned member should be blocked');
        } catch (SendBlockedException $e) {
            $this->assertSame(SendBlockedException::NOT_ALLOWED, $e->reason);
        }

        $this->account->users()->attach($member);
        $this->assertSame(SendStatus::Sent, $this->service()->send($this->request($member))->status);

        $this->account->forceFill(['status' => 'disabled'])->save();
        try {
            $this->service()->send($this->request($member, o: ['to' => 'x@example.com']));
            $this->fail('Disabled account should be blocked');
        } catch (SendBlockedException $e) {
            $this->assertSame(SendBlockedException::ACCOUNT_UNUSABLE, $e->reason);
        }
    }

    public function test_the_cap_resets_at_midnight_in_the_business_timezone(): void
    {
        // Default business timezone is Asia/Karachi (UTC+5).
        $this->account->update(['daily_cap' => 1]);

        Carbon::setTestNow('2026-09-02 18:59:30'); // 23:59:30 in Karachi
        $first = $this->service()->send($this->request(o: ['to' => 'a@example.com']));
        $this->assertSame('2026-09-02', $first->local_date->format('Y-m-d'));

        Carbon::setTestNow('2026-09-02 19:00:30'); // 00:00:30 next day in Karachi
        $second = $this->service()->send($this->request(o: ['to' => 'b@example.com']));
        $this->assertSame('2026-09-03', $second->local_date->format('Y-m-d'));
        $this->assertSame(SendStatus::Sent, $second->status);
    }

    public function test_stale_pending_rows_become_unknown_and_still_count(): void
    {
        $this->account->update(['daily_cap' => 1]);
        SentEmail::factory()->pending()->create([
            'user_id' => $this->admin->id,
            'email_account_id' => $this->account->id,
            'reserved_at' => now()->subMinutes(20),
        ]);

        try {
            $this->service()->send($this->request());
            $this->fail('Unknown row should consume the cap');
        } catch (SendBlockedException $e) {
            $this->assertSame(SendBlockedException::CAP_REACHED, $e->reason);
        }

        $this->assertSame(1, SentEmail::query()->where('status', SendStatus::Unknown->value)->count());
    }

    public function test_message_has_text_and_html_parts_with_lead_data_escaped_and_signature_once(): void
    {
        $this->account->update(['signature_md' => "Best,\nJawad"]);

        $this->service()->send($this->request(o: [
            'body' => 'Hi {{first_name}}, see <script>alert(1)</script> **bold**',
            'vars' => ['first_name' => '<b>Lead</b>'],
        ]));

        $email = $this->fake->last();
        $html = $email->getHtmlBody();
        $text = $email->getTextBody();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;b&gt;Lead&lt;/b&gt;', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('bold', $text);
        $this->assertStringNotContainsString('**', $text);
        $this->assertSame(1, substr_count($text, 'Best,'));
        $this->assertSame(1, substr_count($html, 'Best,'));
        $this->assertSame($this->account->from_email, $email->getFrom()[0]->getAddress());
    }
}
