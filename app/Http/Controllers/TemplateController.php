<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Template;
use App\Services\Templates\TemplateRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Everyone can view and use templates; the admin routes manage them. */
class TemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $templates = Template::query()
            ->with('creator:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Template $t) => self::row($t));

        return Inertia::render('Templates/Index', [
            'templates' => $templates,
            'can_manage' => $request->user()->isAdmin(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateTemplate($request);

        $template = Template::query()->create([
            'name' => $data['name'],
            'subject' => $data['subject'],
            'body_md' => $data['body_md'],
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        AuditLog::record('template.create', $request->user(), 'template', $template->id, null, $this->auditShape($template));

        return back()->with('success', "{$template->name} was added.");
    }

    /** Full edit from the modal, or just `is_active` from the card toggle. */
    public function update(Request $request, Template $template): RedirectResponse
    {
        $data = $this->validateTemplate($request, partial: true);

        $before = $this->auditShape($template);
        $template->fill($data)->save();
        $after = $this->auditShape($template);

        if ($before !== $after) {
            AuditLog::record('template.update', $request->user(), 'template', $template->id, $before, $after);
        }

        if (array_keys($data) === ['is_active']) {
            return back()->with('success', "{$template->name} is now ".($template->is_active ? 'active.' : 'inactive.'));
        }

        return back()->with('success', "{$template->name} was updated.");
    }

    /** Hard delete; campaigns.template_id nulls via the FK and sent mail keeps its own copy of the text. */
    public function destroy(Request $request, Template $template): RedirectResponse
    {
        $before = $this->auditShape($template);
        $template->delete();

        AuditLog::record('template.delete', $request->user(), 'template', $template->id, $before, null);

        return back()->with('success', "{$template->name} was deleted.");
    }

    public static function row(Template $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'subject' => $t->subject,
            'body_md' => $t->body_md,
            'is_active' => $t->is_active,
            'variables' => TemplateRenderer::variables($t->subject."\n".$t->body_md),
            'creator' => $t->creator?->name,
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }

    private function validateTemplate(Request $request, bool $partial = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            // templates.subject is varchar(255); a longer limit would only surface as a DB error.
            'subject' => ['required', 'string', 'max:255'],
            'body_md' => ['required', 'string', 'max:20000'],
            'is_active' => ['nullable', 'boolean'],
        ];

        if ($partial) {
            $rules = array_map(fn (array $rule) => ['sometimes', ...$rule], $rules);
        }

        return $request->validate($rules);
    }

    /** Audit snapshot — the body is long and user content, so only its length is kept. */
    private function auditShape(Template $t): array
    {
        return [
            'name' => $t->name,
            'subject' => $t->subject,
            'is_active' => (bool) $t->is_active,
            'body_length' => mb_strlen((string) $t->body_md),
        ];
    }
}
