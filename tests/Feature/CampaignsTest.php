<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Lead;
use App\Models\User;
use App\Services\Ai\FakeDraftGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CampaignsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeDraftGenerator::install();
    }

    public function test_create_then_import_through_preview_and_confirm(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->post('/campaigns', ['name' => 'Shopify agencies', 'brief_goal' => 'Book a call', 'max_generations' => 100])
            ->assertRedirect('/campaigns/1/import');
        $campaign = Campaign::query()->firstOrFail();
        $this->assertSame($member->id, $campaign->created_by);

        $csv = "email,first name,company,website,subject,body\n"
            ."jane@acme.com,Jane,Acme,acme.com,,\n"
            ."bob@beta.io,Bob,Beta,,Hi Bob,\"Ready body\"\n"
            ."bad,Nope,,,,\n";

        $preview = $this->actingAs($member)->postJson("/campaigns/{$campaign->id}/import/preview", [
            'file' => UploadedFile::fake()->createWithContent('leads.csv', $csv),
        ]);
        $preview->assertOk()
            ->assertJsonPath('row_count', 3)
            ->assertJsonPath('mapping.email', 'email')
            ->assertJsonPath('mapping.first_name', 'first_name')
            ->assertJsonPath('mapping.company', 'company')
            ->assertJsonPath('mapping.body', 'body');
        $token = $preview->json('token');

        $store = $this->actingAs($member)->postJson("/campaigns/{$campaign->id}/import", [
            'token' => $token,
            'mapping' => $preview->json('mapping'),
        ]);
        $store->assertOk()->assertJsonPath('created', 2)->assertJsonPath('with_drafts', 1)->assertJsonPath('rejected', 1);

        $rejects = $this->actingAs($member)->get($store->json('rejects_url'));
        $rejects->assertOk();
        $this->assertStringContainsString('bad', $rejects->streamedContent());

        $this->assertSame(2, Lead::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.import']);

        // A second confirm with the same token is rejected (token consumed).
        $this->actingAs($member)->postJson("/campaigns/{$campaign->id}/import", ['token' => $token, 'mapping' => ['email' => 'email']])
            ->assertStatus(422);
    }

    public function test_show_lists_leads_with_progress_and_export_round_trips_drafts(): void
    {
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::query()->create(['name' => 'C', 'created_by' => $admin->id]);
        $lead = Lead::query()->create(['campaign_id' => $campaign->id, 'email' => 'a@x.com', 'email_normalized' => 'a@x.com', 'first_name' => 'Ann', 'status' => 'ready', 'attributes' => ['website' => 'x.com']]);
        $lead->draft()->create(['campaign_id' => $campaign->id, 'subject' => 'Hello Ann', 'body_md' => 'Body', 'source' => 'ai']);

        $this->actingAs($admin)->get("/campaigns/{$campaign->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Campaigns/Show')
            ->where('campaign.name', 'C')
            ->has('leads.data', 1)
            ->where('leads.data.0.draft.subject', 'Hello Ann')
            ->where('progress.counts.ready', 1)
            ->where('progress.total', 1)
            ->where('can_manage', true)
        );

        $csv = $this->actingAs($admin)->get("/campaigns/{$campaign->id}/export")->assertOk()->streamedContent();
        $this->assertStringContainsString('a@x.com', $csv);
        $this->assertStringContainsString('Hello Ann', $csv);
        $this->assertStringContainsString('"{""website"":""x.com""}"', $csv);
    }

    public function test_only_the_creator_or_an_admin_can_change_or_delete_a_campaign(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::query()->create(['name' => 'Mine', 'created_by' => $owner->id]);

        $this->actingAs($other)->patch("/campaigns/{$campaign->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($other)->delete("/campaigns/{$campaign->id}")->assertForbidden();
        $this->actingAs($other)->get("/campaigns/{$campaign->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('can_manage', false));

        $this->actingAs($owner)->patch("/campaigns/{$campaign->id}", ['name' => 'Renamed', 'status' => 'done'])->assertRedirect();
        $this->assertSame('Renamed', $campaign->fresh()->name);
        $this->assertSame('done', $campaign->fresh()->status->value);

        $this->actingAs($admin)->delete("/campaigns/{$campaign->id}")->assertRedirect('/campaigns');
        $this->assertDatabaseMissing('campaigns', ['id' => $campaign->id]);
    }
}
