<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SuppressionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_addresses_which_are_normalised_and_deduplicated(): void
    {
        $admin = User::factory()->admin()->create();
        Suppression::query()->create(['email_normalized' => 'old@example.com', 'reason' => 'asked', 'added_by' => $admin->id]);

        $response = $this->actingAs($admin)->from('/admin/suppressions')->post('/admin/suppressions', [
            'emails' => "Foo@Bar.com\nJane Doe <jane@acme.com>; foo@bar.com, old@example.com\nnot-an-email",
            'reason' => 'Unsubscribed',
        ]);

        $response->assertRedirect('/admin/suppressions');
        $response->assertSessionHas('success', fn ($m) => str_contains($m, '2 added')
            && str_contains($m, '1 already listed')
            && str_contains($m, '1 invalid (not-an-email)'));

        $this->assertEqualsCanonicalizing(
            ['old@example.com', 'foo@bar.com', 'jane@acme.com'],
            Suppression::query()->pluck('email_normalized')->all(),
        );
        $this->assertDatabaseHas('suppressions', ['email_normalized' => 'foo@bar.com', 'reason' => 'Unsubscribed', 'added_by' => $admin->id]);
        $this->assertDatabaseHas('suppressions', ['email_normalized' => 'old@example.com', 'reason' => 'asked']);
        $this->assertTrue(Suppression::covers('foo@bar.com'));

        $audit = AuditLog::query()->where('action', 'suppression.add')->firstOrFail();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame(['foo@bar.com', 'jane@acme.com'], $audit->after['emails']);
        $this->assertSame('Unsubscribed', $audit->after['reason']);
    }

    public function test_nothing_valid_adds_nothing_and_warns(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->from('/admin/suppressions')
            ->post('/admin/suppressions', ['emails' => "garbage\nalso bad"])
            ->assertRedirect('/admin/suppressions')
            ->assertSessionHas('warning', fn ($m) => str_starts_with($m, '0 added') && str_contains($m, '2 invalid (garbage, also bad)'));

        $this->assertDatabaseCount('suppressions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_index_lists_searches_and_remove_is_audited(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Ada']);
        $a = Suppression::query()->create(['email_normalized' => 'a@example.com', 'reason' => 'bounced', 'added_by' => $admin->id]);
        Suppression::query()->create(['email_normalized' => 'b@other.org', 'added_by' => $admin->id]);

        $this->actingAs($admin)->get('/admin/suppressions')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Suppressions')
            ->has('rows.data', 2)
            ->where('rows.total', 2)
            ->where('rows.data.0.email', 'b@other.org')
            ->where('rows.data.1.email', 'a@example.com')
            ->where('rows.data.1.reason', 'bounced')
            ->where('rows.data.1.added_by', 'Ada')
            ->has('rows.data.1.created_at')
            ->where('filters.q', '')
            ->where('total', 2)
        );

        $this->actingAs($admin)->get('/admin/suppressions?q=OTHER')->assertInertia(fn (Assert $page) => $page
            ->has('rows.data', 1)
            ->where('rows.data.0.email', 'b@other.org')
            ->where('filters.q', 'OTHER')
            ->where('total', 2)
        );

        $this->actingAs($admin)->delete("/admin/suppressions/{$a->id}")->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseMissing('suppressions', ['id' => $a->id]);
        $this->assertFalse(Suppression::covers('a@example.com'));
        $audit = AuditLog::query()->where('action', 'suppression.remove')->firstOrFail();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('a@example.com', $audit->before['email']);
        $this->assertSame('bounced', $audit->before['reason']);
    }

    public function test_validation_and_member_access(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $this->actingAs($admin)->from('/admin/suppressions')
            ->post('/admin/suppressions', ['emails' => '', 'reason' => str_repeat('x', 256)])
            ->assertRedirect('/admin/suppressions')
            ->assertSessionHasErrors(['emails', 'reason']);

        $this->actingAs($member)->get('/admin/suppressions')->assertForbidden();
        $this->actingAs($member)->post('/admin/suppressions', ['emails' => 'x@example.com'])->assertForbidden();

        $suppression = Suppression::query()->create(['email_normalized' => 'keep@example.com', 'added_by' => $admin->id]);
        $this->actingAs($member)->delete("/admin/suppressions/{$suppression->id}")->assertForbidden();
        $this->assertDatabaseCount('suppressions', 1);
    }
}
