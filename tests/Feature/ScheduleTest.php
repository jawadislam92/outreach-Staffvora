<?php

namespace Tests\Feature;

use App\Enums\ScheduleStatus;
use App\Models\Campaign;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\ScheduledEmail;
use App\Models\SentEmail;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Sending\Connectors\FakeConnector;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ScheduleTest extends TestCase
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email_account_id' => $this->account->id,
            'to' => 'lead@example.com',
            'to_name' => 'Lead Person',
            'company' => 'Acme',
            'subject' => 'Hello {{first_name}}',
            'body_md' => 'Hi {{first_name}} from {{company}}',
            // Business tz default is Asia/Karachi (UTC+5): 14:00 local = 09:00 UTC.
            'scheduled_for' => now('Asia/Karachi')->addDay()->format('Y-m-d').'T14:00',
        ], $overrides);
    }

    public function test_scheduling_stores_the_row_in_utc_and_nothing_is_sent_yet(): void
    {
        $this->actingAs($this->admin)->post('/schedule', $this->payload())->assertRedirect()->assertSessionHas('success');

        $row = ScheduledEmail::query()->firstOrFail();
        $this->assertSame(ScheduleStatus::Scheduled, $row->status);
        $this->assertSame('lead@example.com', $row->to_email);
        $this->assertSame('09:00', $row->scheduled_for->format('H:i'));
        $this->assertSame('Lead', $row->variables['first_name']);
        $this->assertCount(0, $this->fake->sent);
        $this->assertDatabaseCount('sent_emails', 0);
    }

    public function test_validation_rejects_past_far_future_suppressed_and_unacknowledged_duplicates(): void
    {
        $this->actingAs($this->admin)->from('/compose')
            ->post('/schedule', $this->payload(['scheduled_for' => now('Asia/Karachi')->subHour()->format('Y-m-d\TH:i')]))
            ->assertSessionHasErrors('scheduled_for');

        $this->actingAs($this->admin)->from('/compose')
            ->post('/schedule', $this->payload(['scheduled_for' => now('Asia/Karachi')->addDays(90)->format('Y-m-d\TH:i')]))
            ->assertSessionHasErrors('scheduled_for');

        Suppression::query()->create(['email_normalized' => 'optout@example.com']);
        $this->actingAs($this->admin)->from('/compose')
            ->post('/schedule', $this->payload(['to' => 'OptOut@Example.com']))
            ->assertSessionHasErrors('to');

        SentEmail::factory()->create(['to_email' => 'seen@x.com', 'to_email_normalized' => 'seen@x.com', 'user_id' => $this->admin->id, 'email_account_id' => $this->account->id]);
        $this->actingAs($this->admin)->from('/compose')
            ->post('/schedule', $this->payload(['to' => 'seen@x.com']))
            ->assertSessionHasErrors('to');
        $this->actingAs($this->admin)->post('/schedule', $this->payload(['to' => 'seen@x.com', 'ack_duplicate' => true]))->assertSessionHas('success');

        $member = User::factory()->create();
        $this->actingAs($member)->post('/schedule', $this->payload())->assertForbidden();

        $this->assertSame(1, ScheduledEmail::query()->count());
    }

    public function test_due_rows_are_sent_by_the_command_and_land_in_the_log(): void
    {
        $this->actingAs($this->admin)->post('/schedule', $this->payload());
        $row = ScheduledEmail::query()->firstOrFail();

        Artisan::call('outreach:send-due');
        $this->assertSame(ScheduleStatus::Scheduled, $row->fresh()->status); // not due yet

        Carbon::setTestNow($row->scheduled_for->copy()->addMinute());
        Artisan::call('outreach:send-due');

        $row->refresh();
        $this->assertSame(ScheduleStatus::Sent, $row->status);
        $this->assertNotNull($row->sent_email_id);
        $log = SentEmail::query()->findOrFail($row->sent_email_id);
        $this->assertSame('Hello Lead', $log->subject);
        $this->assertSame('sched-'.$row->id, $log->client_token);
        $this->assertCount(1, $this->fake->sent);

        // Firing again is idempotent.
        Artisan::call('outreach:send-due');
        $this->assertCount(1, $this->fake->sent);
    }

    public function test_cap_blocked_rows_wait_and_retry_instead_of_failing(): void
    {
        $this->account->update(['daily_cap' => 0]);
        $this->actingAs($this->admin)->post('/schedule', $this->payload());
        $row = ScheduledEmail::query()->firstOrFail();

        Carbon::setTestNow($row->scheduled_for->copy()->addMinute());
        Artisan::call('outreach:send-due');

        $row->refresh();
        $this->assertSame(ScheduleStatus::Scheduled, $row->status);
        $this->assertStringContainsString('daily cap', strtolower((string) $row->last_error));
        $this->assertTrue($row->next_attempt_at->gt(now()));
        $this->assertCount(0, $this->fake->sent);

        // Cap freed → the retry sends it.
        $this->account->update(['daily_cap' => 30]);
        Carbon::setTestNow($row->next_attempt_at->copy()->addMinute());
        Artisan::call('outreach:send-due');
        $this->assertSame(ScheduleStatus::Sent, $row->fresh()->status);
    }

    public function test_terminal_blocks_fail_the_row_with_the_reason(): void
    {
        $this->actingAs($this->admin)->post('/schedule', $this->payload());
        $row = ScheduledEmail::query()->firstOrFail();
        Suppression::query()->create(['email_normalized' => 'lead@example.com']);

        Carbon::setTestNow($row->scheduled_for->copy()->addMinute());
        Artisan::call('outreach:send-due');

        $row->refresh();
        $this->assertSame(ScheduleStatus::Failed, $row->status);
        $this->assertStringContainsString('do-not-contact', $row->last_error);
        $this->assertCount(0, $this->fake->sent);
    }

    public function test_scheduling_from_the_queue_persists_the_draft_and_marks_the_lead_on_send(): void
    {
        $campaign = Campaign::query()->create(['name' => 'Q', 'created_by' => $this->admin->id]);
        $lead = Lead::query()->create(['campaign_id' => $campaign->id, 'email' => 'a@x.com', 'email_normalized' => 'a@x.com', 'first_name' => 'Ann', 'status' => 'ready']);

        $this->actingAs($this->admin)->post('/schedule', [
            'lead_id' => $lead->id,
            'email_account_id' => $this->account->id,
            'subject' => 'Hi {{first_name}}',
            'body_md' => 'Body for {{first_name}}',
            'scheduled_for' => now('Asia/Karachi')->addHours(3)->format('Y-m-d\TH:i'),
        ])->assertSessionHas('success');

        $row = ScheduledEmail::query()->firstOrFail();
        $this->assertSame($lead->id, $row->lead_id);
        $this->assertSame($campaign->id, $row->campaign_id);
        $this->assertSame('Hi {{first_name}}', $lead->fresh()->draft->subject);

        Carbon::setTestNow($row->scheduled_for->copy()->addMinute());
        Artisan::call('outreach:send-due');

        $this->assertSame('sent', $lead->fresh()->status->value);
        $this->assertSame('Hi Ann', SentEmail::query()->firstOrFail()->subject);
    }

    public function test_cancel_and_send_now_respect_ownership(): void
    {
        $member = User::factory()->create();
        $this->account->users()->attach($member);

        $this->actingAs($member)->post('/schedule', $this->payload());
        $row = ScheduledEmail::query()->firstOrFail();

        $other = User::factory()->create();
        $this->actingAs($other)->post("/scheduled/{$row->id}/cancel")->assertForbidden();

        $this->actingAs($member)->post("/scheduled/{$row->id}/send-now")->assertSessionHas('success');
        $this->assertSame(ScheduleStatus::Sent, $row->fresh()->status);
        $this->assertCount(1, $this->fake->sent);

        $this->actingAs($this->admin)->post('/schedule', $this->payload(['to' => 'b@x.com']));
        $second = ScheduledEmail::query()->latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post("/scheduled/{$second->id}/cancel")->assertSessionHas('info');
        $this->assertSame(ScheduleStatus::Cancelled, $second->fresh()->status);
    }
}
