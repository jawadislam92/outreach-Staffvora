<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /** Per-send upload (from Compose / draft review). Library uploads go through the admin controller. */
    public function store(Request $request, Settings $settings): JsonResponse
    {
        $attachment = self::storeUpload($request, $settings, isLibrary: false);

        return response()->json($attachment->toSummary(), 201);
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk(Attachment::DISK)->exists($attachment->stored_path), 404);

        return Storage::disk(Attachment::DISK)->download($attachment->stored_path, $attachment->safeName(), [
            'Content-Type' => $attachment->mime,
        ]);
    }

    /**
     * Validate (extension allow-list + content-sniffed MIME + size), store
     * under a ULID name, record sha256. Shared with the library controller.
     */
    public static function storeUpload(Request $request, Settings $settings, bool $isLibrary): Attachment
    {
        $extensions = config('outreach.attachments.allowed_extensions');
        $maxKb = (int) floor($settings->maxAttachmentBytes() / 1024);

        $request->validate([
            'file' => ['required', File::types($extensions)->max($maxKb)],
        ]);

        $file = $request->file('file');
        $mime = (string) $file->getMimeType(); // sniffed from content, not the client header
        abort_unless(in_array($mime, config('outreach.attachments.allowed_mimes'), true), 422, 'That file type is not allowed.');

        $ext = strtolower($file->getClientOriginalExtension());
        $name = (string) Str::ulid().'.'.$ext;
        $path = Storage::disk(Attachment::DISK)->putFileAs(Attachment::DIRECTORY, $file, $name);

        return Attachment::query()->create([
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'stored_path' => $path,
            'mime' => $mime,
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'is_library' => $isLibrary,
            'is_active' => true,
            'uploaded_by' => $request->user()->id,
        ]);
    }
}
