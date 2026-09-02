<?php

namespace Tests\Feature\Admin;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_a_library_file_and_it_is_stored_under_a_generated_name(): void
    {
        Storage::fake(Attachment::DISK);
        $admin = User::factory()->admin()->create(['name' => 'Ada']);

        $this->actingAs($admin)->from('/admin/library')
            ->post('/admin/library', ['file' => UploadedFile::fake()->create('deck.pdf', 100, 'application/pdf')])
            ->assertRedirect('/admin/library')
            ->assertSessionHas('success');

        $attachment = Attachment::query()->firstOrFail();
        $this->assertTrue($attachment->is_library);
        $this->assertTrue($attachment->is_active);
        $this->assertSame('deck.pdf', $attachment->original_name);
        $this->assertSame('application/pdf', $attachment->mime);
        $this->assertSame(100 * 1024, $attachment->size);
        $this->assertSame($admin->id, $attachment->uploaded_by);
        $this->assertSame(64, strlen($attachment->sha256));
        // ULID-named, never the client's filename.
        $this->assertMatchesRegularExpression('/^attachments\/[0-9A-Z]{26}\.pdf$/', $attachment->stored_path);
        Storage::disk(Attachment::DISK)->assertExists($attachment->stored_path);

        $this->assertDatabaseHas('audit_logs', ['action' => 'library.upload', 'user_id' => $admin->id, 'subject_id' => $attachment->id]);

        $this->actingAs($admin)->get('/admin/library')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Library')
            ->has('files', 1)
            ->where('files.0.id', $attachment->id)
            ->where('files.0.name', 'deck.pdf')
            ->where('files.0.mime', 'application/pdf')
            ->where('files.0.uploader', 'Ada')
            ->where('files.0.is_active', true)
            ->where('files.0.times_used', 0)
            ->where('allowed_extensions', config('outreach.attachments.allowed_extensions'))
            ->where('max_mb', 10)
        );
    }

    public function test_toggle_and_remove_hide_the_file_but_keep_it_on_disk_for_sent_emails(): void
    {
        Storage::fake(Attachment::DISK);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/library', ['file' => UploadedFile::fake()->create('deck.pdf', 100, 'application/pdf')]);
        $attachment = Attachment::query()->firstOrFail();

        $sent = SentEmail::factory()->create();
        DB::table('sent_email_attachments')->insert(['sent_email_id' => $sent->id, 'attachment_id' => $attachment->id, 'filename_as_sent' => 'deck.pdf']);

        $this->actingAs($admin)->get('/admin/library')->assertInertia(fn (Assert $page) => $page->where('files.0.times_used', 1));

        $this->actingAs($admin)->patch("/admin/library/{$attachment->id}", ['is_active' => false])->assertRedirect()->assertSessionHas('success');
        $this->assertFalse($attachment->fresh()->is_active);
        $audit = AuditLog::query()->where('action', 'library.update')->firstOrFail();
        $this->assertTrue($audit->before['is_active']);
        $this->assertFalse($audit->after['is_active']);

        // Re-sending the same state is not audit noise.
        $this->actingAs($admin)->patch("/admin/library/{$attachment->id}", ['is_active' => false])->assertRedirect();
        $this->assertSame(1, AuditLog::query()->where('action', 'library.update')->count());

        $this->actingAs($admin)->delete("/admin/library/{$attachment->id}")->assertRedirect()->assertSessionHas('success');

        $this->assertSoftDeleted('attachments', ['id' => $attachment->id]);
        Storage::disk(Attachment::DISK)->assertExists($attachment->stored_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'library.delete', 'user_id' => $admin->id]);
        $this->assertSame(1, DB::table('sent_email_attachments')->where('attachment_id', $attachment->id)->count());
        $this->assertSame('deck.pdf', $sent->attachments()->first()->original_name);

        $this->actingAs($admin)->get('/admin/library')->assertInertia(fn (Assert $page) => $page->has('files', 0));
        $this->actingAs($admin)->get('/compose')->assertInertia(fn (Assert $page) => $page->has('library', 0));
    }

    public function test_uploads_are_validated_by_content_and_members_are_forbidden(): void
    {
        Storage::fake(Attachment::DISK);
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $this->actingAs($admin)->from('/admin/library')
            ->post('/admin/library', ['file' => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload')])
            ->assertRedirect('/admin/library')
            ->assertSessionHasErrors('file');

        // A .pdf name over non-PDF bytes is rejected by the sniffed MIME, not the extension.
        $this->actingAs($admin)->from('/admin/library')
            ->post('/admin/library', ['file' => UploadedFile::fake()->create('deck.pdf', 10, 'text/plain')])
            ->assertRedirect('/admin/library')
            ->assertSessionHasErrors('file');

        $this->actingAs($admin)->from('/admin/library')->post('/admin/library', [])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('attachments', 0);
        $this->assertDatabaseCount('audit_logs', 0);

        $this->actingAs($member)->get('/admin/library')->assertForbidden();
        $this->actingAs($member)->post('/admin/library', ['file' => UploadedFile::fake()->create('deck.pdf', 10, 'application/pdf')])->assertForbidden();
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_per_send_uploads_are_not_manageable_through_the_library(): void
    {
        Storage::fake(Attachment::DISK);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/attachments', ['file' => UploadedFile::fake()->create('one-off.pdf', 10, 'application/pdf')])->assertCreated();
        $attachment = Attachment::query()->firstOrFail();
        $this->assertFalse($attachment->is_library);

        $this->actingAs($admin)->get('/admin/library')->assertInertia(fn (Assert $page) => $page->has('files', 0));
        $this->actingAs($admin)->patch("/admin/library/{$attachment->id}", ['is_active' => false])->assertNotFound();
        $this->actingAs($admin)->delete("/admin/library/{$attachment->id}")->assertNotFound();
        $this->assertTrue($attachment->fresh()->is_active);
    }
}
