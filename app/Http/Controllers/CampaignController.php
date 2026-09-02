<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Enums\LeadStatus;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Template;
use App\Services\Ai\DraftGenerator;
use App\Services\Ai\GenerationRunner;
use App\Support\CsvExport;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignController extends Controller
{
    public function index(Request $request, Settings $settings): Response
    {
        $campaigns = Campaign::query()
            ->with('creator:id,name')
            ->withCount([
                'leads',
                'leads as ready_count' => fn ($q) => $q->where('status', LeadStatus::Ready->value),
                'leads as sent_count' => fn ($q) => $q->where('status', LeadStatus::Sent->value),
                'leads as pending_count' => fn ($q) => $q->where('status', LeadStatus::Pending->value),
                'leads as failed_count' => fn ($q) => $q->where('status', LeadStatus::Failed->value),
            ])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'done' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Campaign $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'creator' => $c->creator?->name,
                'leads_count' => $c->leads_count,
                'ready_count' => $c->ready_count,
                'sent_count' => $c->sent_count,
                'pending_count' => $c->pending_count,
                'failed_count' => $c->failed_count,
                'updated_at' => $c->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('Campaigns/Index', [
            'campaigns' => $campaigns,
            'templates' => Template::query()->active()->orderBy('name')->get(['id', 'name', 'subject', 'body_md']),
            'timezone' => $settings->timezone(),
            'ai_configured' => app(DraftGenerator::class)->configured(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $campaign = Campaign::query()->create($data + ['status' => CampaignStatus::Active, 'created_by' => $request->user()->id]);
        AuditLog::record('campaign.create', $request->user(), 'campaign', $campaign->id, null, ['name' => $campaign->name]);

        return redirect()->route('campaigns.import.create', $campaign)->with('success', "Campaign “{$campaign->name}” created — now import your leads.");
    }

    public function show(Request $request, Campaign $campaign, GenerationRunner $runner, Settings $settings): Response
    {
        $status = (string) $request->query('status', '');
        $q = trim((string) $request->query('q', ''));

        $leads = $campaign->leads()
            ->with('draft:id,lead_id,subject,source,generation_count,status')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $w->where('email', 'like', $like)->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('company', 'like', $like);
            }))
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Lead $lead) => $this->leadRow($lead));

        return Inertia::render('Campaigns/Show', [
            'campaign' => $this->campaignShape($campaign),
            'leads' => $leads,
            'filters' => ['status' => $status, 'q' => $q],
            'progress' => $runner->progress($campaign),
            'statuses' => collect(LeadStatus::cases())->map(fn (LeadStatus $s) => ['value' => $s->value, 'label' => $s->label()]),
            'templates' => Template::query()->active()->orderBy('name')->get(['id', 'name']),
            'ai_configured' => app(DraftGenerator::class)->configured(),
            'ai_model' => $settings->aiModel(),
            'timezone' => $settings->timezone(),
            'can_manage' => $this->canManage($request, $campaign),
        ]);
    }

    public function update(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($this->canManage($request, $campaign), 403);

        $data = $this->validated($request) + $request->validate([
            'status' => ['nullable', Rule::enum(CampaignStatus::class)],
        ]);

        $before = ['name' => $campaign->name, 'status' => $campaign->status->value, 'max_generations' => $campaign->max_generations];
        $campaign->fill(array_filter($data, fn ($v) => $v !== null))->save();
        $after = ['name' => $campaign->name, 'status' => $campaign->status->value, 'max_generations' => $campaign->max_generations];

        AuditLog::record('campaign.update', $request->user(), 'campaign', $campaign->id, $before, $after);

        return back()->with('success', 'Campaign saved.');
    }

    public function destroy(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($this->canManage($request, $campaign), 403);

        $name = $campaign->name;
        $campaign->delete(); // leads/drafts cascade; sent_emails keep their history (campaign_id nulls)
        AuditLog::record('campaign.delete', $request->user(), 'campaign', $campaign->id, ['name' => $name], null);

        return redirect()->route('campaigns.index')->with('success', "Campaign “{$name}” deleted. Its sent emails stay in the log.");
    }

    /** Leads with their (draft) subject/body, so the list round-trips through a spreadsheet. */
    public function export(Request $request, Campaign $campaign): StreamedResponse
    {
        $rows = (function () use ($campaign) {
            $query = $campaign->leads()->with('draft')->orderBy('id');
            foreach ($query->lazy(500) as $lead) {
                $attributes = $lead->attributes_data();
                yield [
                    $lead->email,
                    $lead->first_name,
                    $lead->last_name,
                    $lead->company,
                    $lead->status->value,
                    $lead->draft?->subject,
                    $lead->draft?->body_md,
                    $lead->draft?->source?->value,
                    $lead->previously_contacted_count,
                    $lead->generation_error,
                    $attributes ? json_encode($attributes, JSON_UNESCAPED_UNICODE) : null,
                ];
            }
        })();

        $slug = preg_replace('/[^a-z0-9]+/i', '-', $campaign->name) ?: 'campaign';

        return CsvExport::stream(
            strtolower(trim($slug, '-')).'-leads.csv',
            ['email', 'first_name', 'last_name', 'company', 'status', 'subject', 'body', 'draft_source', 'previously_contacted', 'generation_error', 'extra_columns_json'],
            $rows,
            $request->user(),
            'campaign.export',
            ['campaign_id' => $campaign->id, 'name' => $campaign->name],
        );
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'template_id' => ['nullable', 'integer', 'exists:templates,id'],
            'brief_offer' => ['nullable', 'string', 'max:3000'],
            'brief_goal' => ['nullable', 'string', 'max:1000'],
            'brief_tone' => ['nullable', 'string', 'max:255'],
            'extra_instructions' => ['nullable', 'string', 'max:3000'],
            'base_subject' => ['nullable', 'string', 'max:255'],
            'base_body_md' => ['nullable', 'string', 'max:10000'],
            'max_generations' => ['nullable', 'integer', 'min:1', 'max:20000'],
        ]);
    }

    private function canManage(Request $request, Campaign $campaign): bool
    {
        return $request->user()->isAdmin() || $campaign->created_by === $request->user()->id;
    }

    public static function campaignShape(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status->value,
            'status_label' => $campaign->status->label(),
            'template_id' => $campaign->template_id,
            'brief_offer' => $campaign->brief_offer,
            'brief_goal' => $campaign->brief_goal,
            'brief_tone' => $campaign->brief_tone,
            'extra_instructions' => $campaign->extra_instructions,
            'base_subject' => $campaign->base_subject,
            'base_body_md' => $campaign->base_body_md,
            'effective_subject' => $campaign->baseSubject(),
            'effective_body_md' => $campaign->baseBody(),
            'max_generations' => $campaign->max_generations,
            'created_by' => $campaign->created_by,
            'created_at' => $campaign->created_at?->toIso8601String(),
        ];
    }

    public static function leadRow(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'email' => $lead->email,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'name' => $lead->fullName(),
            'company' => $lead->company,
            'status' => $lead->status->value,
            'status_label' => $lead->status->label(),
            'previously_contacted_count' => $lead->previously_contacted_count,
            'generation_attempts' => $lead->generation_attempts,
            'generation_error' => $lead->generation_error,
            'import_row' => $lead->import_row,
            'draft' => $lead->draft ? [
                'id' => $lead->draft->id,
                'subject' => $lead->draft->subject,
                'source' => $lead->draft->source->value,
                'generation_count' => $lead->draft->generation_count,
                'status' => $lead->draft->status->value,
            ] : null,
        ];
    }
}
