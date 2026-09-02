<?php

namespace App\Http\Controllers;

use App\Services\Templates\TemplateRenderer;
use App\Support\Markdown;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Server-side render so the preview is exactly what the recipient gets. */
class MarkdownPreviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'markdown' => ['nullable', 'string', 'max:20000'],
            'variables' => ['nullable', 'array'],
            'variables.*' => ['nullable', 'string', 'max:500'],
        ]);

        $rendered = TemplateRenderer::render((string) ($data['markdown'] ?? ''), $data['variables'] ?? []);

        return response()->json([
            'html' => Markdown::toHtml($rendered),
            'text' => Markdown::toText($rendered),
            'unresolved' => TemplateRenderer::unresolved($rendered),
        ]);
    }
}
