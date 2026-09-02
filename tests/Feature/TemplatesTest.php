<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_everyone_can_view_templates_with_their_variables(): void
    {
        $member = User::factory()->create();
        $admin = User::factory()->admin()->create(['name' => 'Ada']);
        Template::factory()->create(['name' => 'Intro', 'created_by' => $admin->id]);
        Template::factory()->create(['name' => 'Bare', 'subject' => 'Hello', 'body_md' => 'No variables here', 'is_active' => false]);

        $this->actingAs($member)->get('/templates')->assertInertia(fn (Assert $page) => $page
            ->component('Templates/Index')
            ->has('templates', 2)
            ->where('templates.0.name', 'Bare')
            ->where('templates.0.is_active', false)
            ->where('templates.0.variables', [])
            ->where('templates.0.creator', null)
            ->where('templates.1.name', 'Intro')
            ->where('templates.1.variables', ['first_name', 'company'])
            ->where('templates.1.creator', 'Ada')
            ->has('templates.1.updated_at')
            ->where('can_manage', false)
        );

        $this->actingAs($admin)->get('/templates')->assertInertia(fn (Assert $page) => $page->where('can_manage', true));
    }

    public function test_admin_can_create_edit_toggle_and_delete_a_template(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->from('/templates')->post('/templates', [
            'name' => 'Follow-up',
            'subject' => 'Re: {{company}}',
            'body_md' => 'Hi {{first_name|there}}',
        ])->assertRedirect('/templates')->assertSessionHas('success');

        $template = Template::query()->firstOrFail();
        $this->assertTrue($template->is_active);
        $this->assertSame($admin->id, $template->created_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'template.create', 'user_id' => $admin->id]);
        $this->assertSame('Follow-up', AuditLog::query()->where('action', 'template.create')->value('after')['name'] ?? null);

        $this->actingAs($admin)->patch("/templates/{$template->id}", [
            'name' => 'Follow-up v2',
            'subject' => 'Re: {{company}}',
            'body_md' => 'Hello {{first_name}}',
            'is_active' => true,
        ])->assertRedirect();

        $template->refresh();
        $this->assertSame('Follow-up v2', $template->name);
        $this->assertSame('Hello {{first_name}}', $template->body_md);
        $audit = AuditLog::query()->where('action', 'template.update')->firstOrFail();
        $this->assertSame('Follow-up', $audit->before['name']);
        $this->assertSame('Follow-up v2', $audit->after['name']);
        $this->assertSame(mb_strlen('Hello {{first_name}}'), $audit->after['body_length']);
        $this->assertArrayNotHasKey('body_md', $audit->after);

        // The card toggle sends only is_active.
        $this->actingAs($admin)->patch("/templates/{$template->id}", ['is_active' => false])->assertRedirect();
        $template->refresh();
        $this->assertFalse($template->is_active);
        $this->assertSame('Follow-up v2', $template->name);
        $this->assertSame(2, AuditLog::query()->where('action', 'template.update')->count());

        $campaign = Campaign::query()->create(['name' => 'Q4 push', 'template_id' => $template->id]);

        $this->actingAs($admin)->delete("/templates/{$template->id}")->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseMissing('templates', ['id' => $template->id]);
        $this->assertNull($campaign->fresh()->template_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'template.delete', 'user_id' => $admin->id]);
    }

    public function test_validation_rejects_a_missing_subject_and_an_oversized_body(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->from('/templates')->post('/templates', [
            'name' => 'Broken',
            'subject' => '',
            'body_md' => str_repeat('x', 20001),
        ])->assertRedirect('/templates')->assertSessionHasErrors(['subject', 'body_md']);

        $this->assertDatabaseCount('templates', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_members_can_view_but_not_manage_templates(): void
    {
        $member = User::factory()->create();
        $template = Template::factory()->create(['name' => 'Keep me']);

        $this->actingAs($member)->get('/templates')->assertOk();

        $this->actingAs($member)->post('/templates', ['name' => 'X', 'subject' => 'S', 'body_md' => 'B'])->assertForbidden();
        $this->actingAs($member)->patch("/templates/{$template->id}", ['is_active' => false])->assertForbidden();
        $this->actingAs($member)->delete("/templates/{$template->id}")->assertForbidden();

        $this->assertDatabaseCount('templates', 1);
        $this->assertTrue($template->fresh()->is_active);
    }
}
