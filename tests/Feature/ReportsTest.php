<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Models\SentEmail;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $ann;

    private User $bob;

    private EmailAccount $alpha;

    private EmailAccount $beta;

    /**
     * 2 accounts × 2 members over three business days (10–12 Aug 2026).
     * sent_at is UTC; the business timezone is the default Asia/Karachi (+05:00).
     */
    private function seedActivity(): void
    {
        $this->ann = User::factory()->create(['name' => 'Ann']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        $this->alpha = EmailAccount::factory()->create(['label' => 'Alpha', 'daily_cap' => 10]);
        $this->beta = EmailAccount::factory()->create(['label' => 'Beta', 'daily_cap' => 4]);

        $row = fn (User $u, EmailAccount $a, string $date, ?string $sentAt, ?int $gap = null) => [
            'user_id' => $u->id, 'email_account_id' => $a->id, 'local_date' => $date, 'sent_at' => $sentAt, 'gap_seconds' => $gap,
        ];

        // Day 1 — three sends between 09:00 and 09:30 local (04:xx UTC) and one failure.
        SentEmail::factory()->create($row($this->ann, $this->alpha, '2026-08-10', '2026-08-10 04:00:00', null));
        SentEmail::factory()->create($row($this->ann, $this->alpha, '2026-08-10', '2026-08-10 04:01:40', 100));
        SentEmail::factory()->create($row($this->bob, $this->alpha, '2026-08-10', '2026-08-10 04:30:00', 200));
        SentEmail::factory()->failed()->create($row($this->bob, $this->beta, '2026-08-10', null));

        // Day 2 — a 50000s gap (first send of the day: not pacing), one paced send at 15:00 local, one unknown.
        SentEmail::factory()->create($row($this->ann, $this->alpha, '2026-08-11', '2026-08-11 04:00:00', 50000));
        SentEmail::factory()->create($row($this->ann, $this->beta, '2026-08-11', '2026-08-11 10:00:00', 600));
        SentEmail::factory()->unknown()->create($row($this->bob, $this->beta, '2026-08-11', null));

        // Day 3 — 19:30 UTC is 00:30 the next local day, so it buckets into hour 0.
        SentEmail::factory()->create($row($this->bob, $this->beta, '2026-08-12', '2026-08-12 19:30:00', null));
    }

    public function test_reports_aggregate_a_date_range(): void
    {
        $this->seedActivity();
        $admin = User::factory()->admin()->create(['name' => 'Zed']);

        $this->actingAs($admin)->get('/reports?from=2026-08-09&to=2026-08-13')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index')
            ->where('filters.from', '2026-08-09')
            ->where('filters.to', '2026-08-13')
            ->where('filters.account_id', null)
            ->where('totals.sent', 6)
            ->where('totals.failed', 1)
            ->where('totals.unknown', 1)
            ->where('totals.pending', 0)
            ->where('totals.senders', 2)
            ->where('totals.days_active', 3)
            // Pacing gaps are 100, 200 and 600: the 50000s gap and the nulls are ignored.
            ->where('totals.avg_gap', 300)
            ->where('totals.median_gap', 200)
            ->where('totals.min_gap', 100)
            ->where('totals.gap_samples', 3)
            // Accounts: busiest first; utilisation = sent / (cap × 5 calendar days).
            ->has('by_account', 2)
            ->where('by_account.0.label', 'Alpha')
            ->where('by_account.0.sent', 4)
            ->where('by_account.0.failed', 0)
            ->where('by_account.0.avg_gap', 150)
            ->where('by_account.0.min_gap', 100)
            ->where('by_account.0.cap', 10)
            ->where('by_account.0.days', 5)
            ->where('by_account.0.utilization_pct', fn ($v) => (float) $v === 8.0)
            ->where('by_account.1.label', 'Beta')
            ->where('by_account.1.sent', 2)
            ->where('by_account.1.failed', 1)
            ->where('by_account.1.unknown', 1)
            ->where('by_account.1.avg_gap', 600)
            ->where('by_account.1.min_gap', 600)
            ->where('by_account.1.utilization_pct', fn ($v) => (float) $v === 10.0)
            // Members: busiest first.
            ->has('by_member', 2)
            ->where('by_member.0.name', 'Ann')
            ->where('by_member.0.sent', 4)
            ->where('by_member.0.failed', 0)
            ->where('by_member.0.avg_gap', 350)
            ->where('by_member.0.accounts_used', 2)
            ->where('by_member.1.name', 'Bob')
            ->where('by_member.1.sent', 2)
            ->where('by_member.1.failed', 1)
            ->where('by_member.1.avg_gap', 200)
            ->where('by_member.1.accounts_used', 2)
            // Pivot: successful sends only.
            ->has('pivot.accounts', 2)
            ->where('pivot.accounts.0.label', 'Alpha')
            ->has('pivot.members', 2)
            ->where('pivot.members.0.name', 'Ann')
            ->where('pivot.cells.'.$this->alpha->id.':'.$this->ann->id, 3)
            ->where('pivot.cells.'.$this->alpha->id.':'.$this->bob->id, 1)
            ->where('pivot.cells.'.$this->beta->id.':'.$this->ann->id, 1)
            ->where('pivot.cells.'.$this->beta->id.':'.$this->bob->id, 1)
            // Timeline: one entry per day in the range, zero-filled at both edges.
            ->has('timeline', 5)
            ->where('timeline.0', ['date' => '2026-08-09', 'sent' => 0, 'failed' => 0, 'unknown' => 0])
            ->where('timeline.1.date', '2026-08-10')
            ->where('timeline.1.sent', 3)
            ->where('timeline.1.failed', 1)
            ->where('timeline.2.sent', 2)
            ->where('timeline.2.unknown', 1)
            ->where('timeline.3.sent', 1)
            ->where('timeline.4', ['date' => '2026-08-13', 'sent' => 0, 'failed' => 0, 'unknown' => 0])
            // Hour of day is bucketed in the business timezone (+05:00), not UTC.
            ->has('hour_of_day', 24)
            ->where('hour_of_day', fn ($hours) => $hours->sum() === 6)
            ->where('hour_of_day.9', 4)
            ->where('hour_of_day.15', 1)
            ->where('hour_of_day.0', 1)
            ->where('hour_of_day.4', 0)
            ->where('timezone', 'Asia/Karachi')
            ->has('accounts', 2)
            ->has('members', 3)
        );
    }

    public function test_account_and_member_filters_narrow_every_dataset(): void
    {
        $this->seedActivity();

        $this->actingAs($this->ann)->get('/reports?from=2026-08-09&to=2026-08-13&account_id='.$this->beta->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.account_id', $this->beta->id)
            ->where('totals.sent', 2)
            ->where('totals.failed', 1)
            ->where('totals.unknown', 1)
            ->where('totals.senders', 2)
            ->where('totals.avg_gap', 600)
            ->where('totals.gap_samples', 1)
            ->has('by_account', 1)
            ->where('by_account.0.label', 'Beta')
            ->has('by_member', 2)
            ->where('by_member.0.sent', 1)
            ->where('by_member.1.sent', 1)
            ->where('timeline.1.sent', 0)
            ->where('timeline.1.failed', 1)
            ->where('hour_of_day', fn ($hours) => $hours->sum() === 2)
            // The pivot is always the whole team.
            ->has('pivot.accounts', 2)
        );

        $this->actingAs($this->ann)->get('/reports?from=2026-08-09&to=2026-08-13&user_id='.$this->bob->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.user_id', $this->bob->id)
            ->where('totals.sent', 2)
            ->where('totals.failed', 1)
            ->where('totals.unknown', 1)
            ->where('totals.senders', 1)
            ->where('totals.days_active', 2)
            ->where('totals.avg_gap', 200)
            ->where('totals.gap_samples', 1)
            ->has('by_member', 1)
            ->where('by_member.0.name', 'Bob')
            ->has('by_account', 2)
            ->where('by_account.0.sent', 1)
            ->where('by_account.1.sent', 1)
            ->where('hour_of_day', fn ($hours) => $hours->sum() === 2)
        );
    }

    public function test_the_default_range_is_the_last_30_business_days(): void
    {
        $member = User::factory()->create();
        $today = app(Settings::class)->today();

        $this->actingAs($member)->get('/reports')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index')
            ->where('filters.from', CarbonImmutable::parse($today)->subDays(29)->toDateString())
            ->where('filters.to', $today)
            ->where('filters.account_id', null)
            ->where('filters.user_id', null)
            ->where('today', $today)
            ->where('totals.sent', 0)
            ->where('totals.avg_gap', null)
            ->where('totals.gap_samples', 0)
            ->has('timeline', 30)
            ->has('hour_of_day', 24)
            ->has('by_account', 0)
            ->has('by_member', 0)
            ->has('pivot.members', 0)
        );
    }

    public function test_invalid_ranges_are_rejected(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->get('/reports?from=2026-08-13&to=2026-08-09')->assertSessionHasErrors('to');
        $this->actingAs($member)->get('/reports?from=2025-01-01&to=2026-08-01')->assertSessionHasErrors('to');
        $this->actingAs($member)->get('/reports?from=13-08-2026')->assertSessionHasErrors('from');
        $this->actingAs($member)->get('/reports/export?from=2026-08-13&to=2026-08-09')->assertSessionHasErrors('to');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'reports.export']);

        // 366 days inclusive is the ceiling.
        $this->actingAs($member)->get('/reports?from=2025-01-01&to=2026-01-01')->assertOk()->assertInertia(fn (Assert $page) => $page->has('timeline', 366));
    }

    public function test_export_streams_one_row_per_day_account_and_member(): void
    {
        $this->seedActivity();

        $response = $this->actingAs($this->bob)->get('/reports/export?from=2026-08-09&to=2026-08-13');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r?\n/', $csv)));
        $this->assertCount(8, $lines, 'header + 7 day × account × member combinations with activity');
        $this->assertStringContainsString('Date,Account,Member,Sent,Failed,Unknown', $lines[0]);
        $this->assertStringContainsString('Avg gap (s)', $lines[0]);
        $this->assertSame('2026-08-10,Alpha,Ann,2,0,0,100', $lines[1]);
        $this->assertContains('2026-08-11,Alpha,Ann,1,0,0,', $lines); // the 50000s gap is not pacing
        $this->assertContains('2026-08-11,Beta,Bob,0,0,1,', $lines);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reports.export', 'user_id' => $this->bob->id]);

        // Filters apply to the export too.
        $filtered = $this->actingAs($this->bob)->get('/reports/export?from=2026-08-09&to=2026-08-13&account_id='.$this->beta->id)->streamedContent();
        $this->assertStringNotContainsString('Alpha', $filtered);
        $this->assertStringContainsString('Beta', $filtered);
    }

    public function test_members_can_view_reports_and_guests_cannot(): void
    {
        $member = User::factory()->create();

        // Guest first: actingAs() keeps the user logged in for the rest of the test.
        $this->get('/reports')->assertRedirect(route('login'));
        $this->get('/reports/export')->assertRedirect(route('login'));

        $this->actingAs($member)->get('/reports')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Reports/Index'));
    }
}
