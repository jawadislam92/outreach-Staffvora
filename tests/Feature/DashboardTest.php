<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_aggregates_todays_activity(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        // Labels control the order accounts are listed in.
        $account = EmailAccount::factory()->create(['daily_cap' => 10, 'label' => 'A main']);
        $other = EmailAccount::factory()->create(['daily_cap' => 5, 'label' => 'B other']);

        SentEmail::factory()->count(3)->create(['user_id' => $member->id, 'email_account_id' => $account->id, 'gap_seconds' => 120]);
        SentEmail::factory()->failed()->create(['user_id' => $member->id, 'email_account_id' => $account->id]);
        SentEmail::factory()->unknown()->create(['user_id' => $admin->id, 'email_account_id' => $other->id]);
        SentEmail::factory()->create(['user_id' => $admin->id, 'email_account_id' => $other->id, 'local_date' => '2000-01-01', 'sent_at' => '2000-01-01 10:00:00']);

        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('totals.sent', 3)
            ->where('totals.failed', 1)
            ->where('totals.unknown', 1)
            ->where('totals.senders', 1)
            ->where('totals.avg_gap', 120)
            ->has('accounts', 2)
            ->where('accounts.0.used', 3)
            ->where('accounts.0.remaining', 7)
            ->where('accounts.0.failed', 1)
            ->where('accounts.1.used', 1)
            ->where('accounts.1.remaining', 4)
            // Everyone with an attempt today is listed, best sender first.
            ->has('members', 2)
            ->where('members.0.sent', 3)
            ->where('members.1.sent', 0)
            ->has('series', 14)
            ->has('recent', 6)
        );

        // A member only sees the accounts assigned to them.
        $account->users()->attach($member);
        $this->actingAs($member)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('accounts', 1)
            ->where('my_sent_today', 3)
        );
    }

    public function test_compose_still_renders_once_sends_exist(): void
    {
        $admin = User::factory()->admin()->create();
        $account = EmailAccount::factory()->create();
        SentEmail::factory()->create(['user_id' => $admin->id, 'email_account_id' => $account->id]);
        SentEmail::factory()->failed()->create(['user_id' => $admin->id, 'email_account_id' => $account->id]);

        $this->actingAs($admin)->get('/compose')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('accounts.0.used', 1)
            ->where('accounts.0.failed', 1)
            ->where('accounts.0.remaining', 29)
        );
    }
}
