<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shared attachment library. Files are never removed from disk: every sent
 * email references its attachments by id, so "remove" is a soft delete plus
 * is_active=false, which only hides the file from Compose.
 */
class LibraryController extends Controller
{
    public function index(Settings $settings): Response
    {
        $files = Attachment::query()
            ->where('is_library', true)
            ->with('uploader:id,name')
            ->withCount('sentEmails as times_used')
            ->orderByDesc('is_active')
            ->orderBy('original_name')
            ->get()
            ->map(fn (Attachment $a) => $a->toSummary() + [
                'uploader' => $a->uploader?->name,
                'times_used' => (int) $a->times_used,
            ]);

        return Inertia::render('Admin/Library', [
            'files' => $files,
            'allowed_extensions' => config('outreach.attachments.allowed_extensions'),
            'max_mb' => (int) $settings->get('max_attachment_mb'),
        ]);
    }

    public function store(Request $request, Settings $settings): RedirectResponse
    {
        $attachment = AttachmentController::storeUpload($request, $settings, isLibrary: true);

        AuditLog::record('library.upload', $request->user(), 'attachment', $attachment->id, null, $this->auditShape($attachment));

        return back()->with('success', "{$attachment->original_name} was added to the library.");
    }

    public function update(Request $request, Attachment $attachment): RedirectResponse
    {
        abort_unless($attachment->is_library, 404);

        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $before = $this->auditShape($attachment);
        $attachment->forceFill(['is_active' => (bool) $data['is_active']])->save();
        $after = $this->auditShape($attachment);

        if ($before !== $after) {
            AuditLog::record('library.update', $request->user(), 'attachment', $attachment->id, $before, $after);
        }

        return back()->with('success', "{$attachment->original_name} is now ".($attachment->is_active ? 'available in Compose.' : 'hidden from Compose.'));
    }

    public function destroy(Request $request, Attachment $attachment): RedirectResponse
    {
        abort_unless($attachment->is_library, 404);

        $before = $this->auditShape($attachment);
        $attachment->forceFill(['is_active' => false])->save();
        $attachment->delete(); // soft delete only — the file stays on disk for the send log

        AuditLog::record('library.delete', $request->user(), 'attachment', $attachment->id, $before, null);

        return back()->with('success', "{$attachment->original_name} was removed from the library. Emails that already went out keep their copy.");
    }

    private function auditShape(Attachment $a): array
    {
        return [
            'name' => $a->original_name,
            'mime' => $a->mime,
            'size' => $a->size,
            'sha256' => $a->sha256,
            'is_active' => (bool) $a->is_active,
        ];
    }
}
