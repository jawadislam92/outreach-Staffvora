<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Lead;
use App\Models\SentEmail;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LeadListTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_add_dedupes_and_flags_prior_contact(): void
    {
        $member = User::factory()->create();
        SentEmail::factory()->create(['to_email' => 'seen@x.com', 'to_email_normalized' => 'seen@x.com', 'user_id' => $member->id]);

        $this->actingAs($member)->post('/leads', [
            'email' => 'Seen@X.com',
            'first_name' => 'Sara',
            'company' => 'Acme',
            'notes' => 'Met at expo',
        ])->assertRedirect()->assertSessionHas('success');

        $lead = Lead::query()->firstOrFail();
        $this->assertNull($lead->campaign_id);
        $this->assertSame('seen@x.com', $lead->email_normalized);
        $this->assertSame(1, $lead->previously_contacted_count);
        $this->assertSame(['notes' => 'Met at expo'], $lead->attributes_data());

        $this->actingAs($member)->from('/leads')->post('/leads', ['email' => 'seen@x.com'])->assertSessionHasErrors('email');

        Suppression::query()->create(['email_normalized' => 'no@x.com']);
        $this->actingAs($member)->from('/leads')->post('/leads', ['email' => 'no@x.com'])->assertSessionHasErrors('email');
        $this->assertSame(1, Lead::query()->count());
    }

    public function test_list_import_goes_to_the_pool_and_dedupes_against_everyone(): void
    {
        $member = User::factory()->create();
        $campaign = Campaign::query()->create(['name' => 'C', 'created_by' => $member->id]);
        Lead::query()->create(['campaign_id' => $campaign->id, 'email' => 'in-campaign@x.com', 'email_normalized' => 'in-campaign@x.com']);

        $csv = "email,first name,subject,body\nnew@x.com,Nia,Should be ignored,Also ignored\nin-campaign@x.com,Dup,,\n";
        $preview = $this->actingAs($member)->postJson('/leads/import/preview', [
            'file' => UploadedFile::fake()->createWithContent('leads.csv', $csv),
        ])->assertOk();

        $store = $this->actingAs($member)->postJson('/leads/import/store', [
            'token' => $preview->json('token'),
            'mapping' => $preview->json('mapping'),
        ])->assertOk();

        $store->assertJsonPath('created', 1)->assertJsonPath('with_drafts', 0)->assertJsonPath('rejected', 1);
        $this->assertSame(route('leads.index'), $store->json('campaign_url'));

        $new = Lead::query()->where('email_normalized', 'new@x.com')->firstOrFail();
        $this->assertNull($new->campaign_id);
        $this->assertNull($new->draft); // subject/body columns ignored for list imports
        $this->assertDatabaseHas('audit_logs', ['action' => 'leads.import']);
    }

    public function test_index_filters_and_bulk_assign_moves_only_unassigned(): void
    {
        $member = User::factory()->create();
        $campaign = Campaign::query()->create(['name' => 'Target', 'created_by' => $member->id]);
        $free1 = Lead::query()->create(['email' => 'a@x.com', 'email_normalized' => 'a@x.com', 'first_name' => 'Ann']);
        $free2 = Lead::query()->create(['email' => 'b@x.com', 'email_normalized' => 'b@x.com']);
        Lead::query()->create(['campaign_id' => $campaign->id, 'email' => 'b@x.com', 'email_normalized' => 'b@x.com']); // same address already in campaign
        Lead::query()->create(['campaign_id' => $campaign->id, 'email' => 'c@x.com', 'email_normalized' => 'c@x.com']);

        $this->actingAs($member)->get('/leads')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Leads/Index')
            ->where('counts.all', 4)
            ->where('counts.unassigned', 2)
            ->has('campaigns', 1)
        );

        $this->actingAs($member)->get('/leads?scope=unassigned')->assertInertia(fn (Assert $page) => $page->has('leads.data', 2));
        $this->actingAs($member)->get('/leads?q=ann')->assertInertia(fn (Assert $page) => $page->has('leads.data', 1));

        $this->actingAs($member)->post('/leads/assign', [
            'lead_ids' => [$free1->id, $free2->id],
            'campaign_id' => $campaign->id,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame($campaign->id, $free1->fresh()->campaign_id);
        $this->assertSame('pending', $free1->fresh()->status->value);
        $this->assertNull($free2->fresh()->campaign_id); // duplicate address skipped
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.assign']);
    }
}
