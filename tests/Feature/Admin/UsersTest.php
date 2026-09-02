<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_member_and_sees_the_password_once(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->from('/admin/users')->post('/admin/users', [
            'name' => 'Sara Khan',
            'email' => 'sara@example.com',
            'role' => 'member',
            'daily_cap' => 20,
        ]);

        $response->assertRedirect('/admin/users');
        $response->assertSessionHas('credentials', fn ($c) => $c['email'] === 'sara@example.com' && strlen($c['password']) >= 12);

        $this->assertDatabaseHas('users', ['email' => 'sara@example.com', 'role' => 'member', 'daily_cap' => 20, 'is_active' => 1]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.create', 'user_id' => $admin->id]);
    }

    public function test_admin_can_deactivate_and_change_caps(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['daily_cap' => null]);

        $this->actingAs($admin)->patch("/admin/users/{$member->id}", [
            'name' => $member->name,
            'email' => $member->email,
            'role' => 'member',
            'daily_cap' => 15,
            'is_active' => false,
        ])->assertRedirect();

        $member->refresh();
        $this->assertFalse($member->is_active);
        $this->assertSame(15, $member->daily_cap);
        $this->assertSame(1, AuditLog::query()->where('action', 'user.update')->count());
    }

    public function test_the_last_active_admin_cannot_be_demoted_or_deactivated(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->from('/admin/users')->patch("/admin/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'member',
            'daily_cap' => null,
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_reset_password_invalidates_the_old_one(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $response = $this->actingAs($admin)->post("/admin/users/{$member->id}/reset-password");
        $response->assertSessionHas('credentials');

        $newPassword = session('credentials')['password'];

        $this->post('/logout');
        $this->post('/login', ['email' => $member->email, 'password' => 'password']);
        $this->assertGuest();

        $this->post('/login', ['email' => $member->email, 'password' => $newPassword]);
        $this->assertAuthenticatedAs($member);
    }

    public function test_members_cannot_manage_users(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->post('/admin/users', [
            'name' => 'X', 'email' => 'x@example.com', 'role' => 'admin',
        ])->assertForbidden();
    }
}
