<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Models\AiRequest;
use App\Models\Campaign;
use App\Models\Draft;
use App\Models\Lead;
use App\Models\User;
use App\Services\Ai\AiRefusedException;
use App\Services\Ai\AiTransientException;
use App\Services\Ai\FakeDraftGenerator;
use App\Services\Ai\GenerationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationTest extends TestCase
{
    use RefreshDatabase;

    private FakeDraftGenerator $ai;

    private User $user;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ai = FakeDraftGenerator::install();
        $this->user = User::factory()->create();
        $this->campaign = Campaign::query()->create(['name' => 'Gen', 'created_by' => $this->user->id, 'brief_goal' => 'Book a call', 'max_generations' => 10]);
    }

    private function lead(string $email, array $extra = []): Lead
    {
        return Lead::query()->create(['campaign_id' => $this->campaign->id, 'email' => $email, 'email_normalized' => $email, 'first_name' => ucfirst(strtok($email, '@')), 'company' => 'Acme'] + $extra);
    }

    public function test_a_run_drafts_a_batch_records_cost_and_the_loop_finishes(): void
    {
        $this->lead('ann@x.com');
        $this->lead('bob@x.com');
        $this->lead('cat@x.com');

        $first = $this->actingAs($this->user)->postJson("/campaigns/{$this->campaign->id}/generate", ['batch' => 2])->assertOk();
        $first->assertJsonPath('processed', 2)->assertJsonPath('generated', 2)->assertJsonPath('progress.remaining', 1)->assertJsonPath('stopped', null);

        $second = $this->actingAs($this->user)->postJson("/campaigns/{$this->campaign->id}/generate", ['batch' => 2])->assertOk();
        $second->assertJsonPath('processed', 1)->assertJsonPath('progress.remaining', 0)->assertJsonPath('progress.counts.ready', 3);

        $this->assertSame(3, $this->ai->calls);
        $this->assertSame(3, Draft::query()->where('source', 'ai')->count());
        $this->assertSame('Quick idea for Acme', Draft::query()->first()->subject);
        $this->assertSame(3, AiRequest::query()->where('status', 'ok')->count());
        $this->assertGreaterThan(0, (float) $second->json('progress.ai_cost_usd'));
        $this->assertSame(1, Lead::query()->first()->generation_attempts);
    }

    public function test_failures_count_attempts_and_give_up_after_three(): void
    {
        $lead = $this->lead('ann@x.com');
        $runner = app(GenerationRunner::class);

        $this->ai->failures = [new \RuntimeException('boom 1')];
        $runner->run($this->campaign, $this->user, 1);
        $this->assertSame(LeadStatus::Pending, $lead->fresh()->status);
        $this->assertSame(1, $lead->fresh()->generation_attempts);

        $this->ai->failures = [new AiRefusedException('nope')];
        $runner->run($this->campaign, $this->user, 1);
        $this->assertSame(2, $lead->fresh()->generation_attempts);
        $this->assertSame(1, AiRequest::query()->where('status', 'refused')->count());

        $this->ai->failures = [new \RuntimeException('boom 3')];
        $result = $runner->run($this->campaign, $this->user, 1);
        $this->assertSame(LeadStatus::Failed, $lead->fresh()->status);
        $this->assertSame('boom 3', $lead->fresh()->generation_error);
        $this->assertSame(1, $result['failed']);

        // Failed leads are no longer claimed.
        $this->assertSame(0, $runner->run($this->campaign, $this->user, 1)['processed']);
    }

    public function test_a_transient_provider_error_releases_the_lead_and_pauses(): void
    {
        $this->lead('ann@x.com');
        $this->lead('bob@x.com');
        $this->ai->failures = [new AiTransientException('429 rate limited')];

        $result = app(GenerationRunner::class)->run($this->campaign, $this->user, 2);

        $this->assertSame('rate_limited', $result['stopped']);
        $this->assertSame(2, Lead::query()->where('status', 'pending')->count());
        $this->assertSame(0, Lead::query()->where('generation_attempts', '>', 0)->count());
    }

    public function test_stale_generating_rows_are_released_and_budget_stops_the_loop(): void
    {
        $stuck = $this->lead('ann@x.com', ['status' => 'generating', 'locked_at' => now()->subMinutes(10)]);
        $runner = app(GenerationRunner::class);

        $runner->run($this->campaign, $this->user, 1);
        $this->assertSame(LeadStatus::Ready, $stuck->fresh()->status);

        $this->campaign->update(['max_generations' => 1]);
        $this->lead('bob@x.com');
        $result = $runner->run($this->campaign, $this->user, 1);
        $this->assertSame('budget', $result['stopped']);
        $this->assertSame(1, $result['progress']['remaining']);
    }

    public function test_regenerate_replaces_the_draft_and_not_configured_is_reported(): void
    {
        $lead = $this->lead('ann@x.com');
        $runner = app(GenerationRunner::class);
        $runner->run($this->campaign, $this->user, 1);

        $this->actingAs($this->user)->post("/leads/{$lead->id}/regenerate")->assertSessionHas('success');
        $this->assertSame(2, $lead->fresh()->draft->generation_count);
        $this->assertSame(2, $this->ai->calls);

        $this->ai->isConfigured = false;
        $this->lead('bob@x.com');
        $this->actingAs($this->user)->postJson("/campaigns/{$this->campaign->id}/generate")->assertJsonPath('stopped', 'not_configured');
    }
}
