<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\SentEmail;
use App\Models\User;
use App\Services\Sending\Connectors\FakeConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_edit_send_moves_to_the_next_lead_and_marks_everything(): void
    {
        $fake = FakeConnector::install();
        $member = User::factory()->create();
        $account = EmailAccount::factory()->create();
        $account->users()->attach($member);
        $campaign = Campaign::query()->create(['name' => 'Q', 'created_by' => $member->id]);

        $first = Lead::query()->create(['campaign_id' => $campaign->id, 'email' => 'a@x.com', 'email_normalized' => 'a@x.com', 'first_name' => 'Ann', 'status' => 'ready', 'attributes' => ['role' => 'CTO']]);
        $first->draft()->create(['campaign_id' => $campaign->id, 'subject' => 'Hi {{first_name}}', 'body_md' => 'Role: {{role}}', 'source' => 'ai']);
        $second = Lead::query()->create(['campaign_id' => $campaign->id, 'email' => 'b@x.com', 'email_normalized' => 'b@x.com', 'status' => 'ready']);
        $second->draft()->create(['campaign_id' => $campaign->id, 'subject' => 'Hi', 'body_md' => 'B', 'source' => 'csv']);

        $this->actingAs($member)->get("/campaigns/{$campaign->id}/queue")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Campaigns/Queue')
            ->has('queue', 2)
            ->where('current.id', $first->id)
            ->where('current.draft_full.subject', 'Hi {{first_name}}')
            ->where('current.variables.role', 'CTO')
            ->where('current.history.count', 0)
            ->has('accounts', 1)
        );

        $this->actingAs($member)->patchJson("/leads/{$first->id}/draft", ['subject' => 'Hi {{first_name}} (edited)', 'body_md' => 'Role: {{role}}!'])
            ->assertOk()->assertJsonPath('draft_full.subject', 'Hi {{first_name}} (edited)');

        $response = $this->actingAs($member)->post("/leads/{$first->id}/send", [
            'client_token' => (string) Str::uuid(),
            'email_account_id' => $account->id,
            'subject' => 'Hi {{first_name}} (final)',
            'body_md' => 'Role: {{role}}!',
            'attachment_ids' => [],
        ]);

        $response->assertRedirect(route('campaigns.queue', ['campaign' => $campaign->id, 'lead' => $second->id], absolute: false));
        $response->assertSessionHas('success');

        $log = SentEmail::query()->firstOrFail();
        $this->assertSame('Hi Ann (final)', $log->subject);
        $this->assertSame('Role: CTO!', $log->body_md);
        $this->assertSame($campaign->id, $log->campaign_id);
        $this->assertSame($first->id, $log->lead_id);
        $this->assertSame('sent', $first->fresh()->status->value);
        $this->assertSame('sent', $first->fresh()->draft->status->value);
        $this->assertSame($log->id, $first->fresh()->draft->sent_email_id);
        $this->assertCount(1, $fake->sent);

        // Sending again is refused; a sent lead cannot be removed.
        $this->actingAs($member)->post("/leads/{$first->id}/send", ['client_token' => (string) Str::uuid(), 'email_account_id' => $account->id, 'subject' => 'x', 'body_md' => 'y'])->assertSessionHas('warning');
        $this->actingAs($member)->delete("/leads/{$first->id}")->assertStatus(422);

        $this->actingAs($member)->post("/leads/{$second->id}/skip")->assertRedirect();
        $this->assertSame('skipped', $second->fresh()->status->value);
    }

    public function test_previously_contacted_leads_need_an_acknowledgement_from_the_queue_too(): void
    {
        FakeConnector::install();
        $admin = User::factory()->admin()->create();
        $account = EmailAccount::factory()->create();
        $campaign = Campaign::query()->create(['name' => 'Q', 'created_by' => $admin->id]);
        SentEmail::factory()->create(['to_email' => 'a@x.com', 'to_email_normalized' => 'a@x.com', 'user_id' => $admin->id, 'email_account_id' => $account->id]);
        $lead = Lead::query()->create(['campaign_id' => $campaign->id, 'email' => 'a@x.com', 'email_normalized' => 'a@x.com', 'status' => 'ready', 'previously_contacted_count' => 1]);

        $this->actingAs($admin)->get("/campaigns/{$campaign->id}/queue")->assertInertia(fn (Assert $page) => $page->where('current.history.count', 1));

        $payload = ['client_token' => (string) Str::uuid(), 'email_account_id' => $account->id, 'subject' => 'S', 'body_md' => 'B'];
        $this->actingAs($admin)->from("/campaigns/{$campaign->id}/queue")->post("/leads/{$lead->id}/send", $payload)->assertSessionHasErrors('to');
        $this->actingAs($admin)->post("/leads/{$lead->id}/send", $payload + ['ack_duplicate' => true])->assertSessionHas('success');
    }
}
