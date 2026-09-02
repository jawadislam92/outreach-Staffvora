<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Enums\LeadStatus;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Suppression;
use App\Services\Sending\RecipientHistory;
use App\Support\CsvExport;
use App\Support\EmailNormalizer;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The shared lead list: every lead across campaigns plus unassigned ones.
 * Leads can be added by hand or by CSV, then pushed into a campaign later.
 */
class LeadListController extends Controller
{
    public function index(Request $request, Settings $settings): Response
    {
        $q = trim((string) $request->query('q', ''));
        $scope = (string) $request->query('scope', ''); // '' all | 'unassigned' | campaign id

        $leads = Lead::query()
            ->with(['campaign:id,name', 'draft:id,lead_id,subject,source,generation_count,status'])
            ->when($scope === 'unassigned', fn ($query) => $query->whereNull('campaign_id'))
            ->when(ctype_digit($scope), fn ($query) => $query->where('campaign_id', (int) $scope))
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $w->where('email', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('company', 'like', $like);
            }))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Lead $lead) => CampaignController::leadRow($lead) + [
                'campaign' => $lead->campaign ? ['id' => $lead->campaign->id, 'name' => $lead->campaign->name] : null,
                'created_at' => $lead->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Leads/Index', [
            'leads' => $leads,
            'filters' => ['q' => $q, 'scope' => $scope],
            'campaigns' => Campaign::query()->orderBy('name')->get(['id', 'name', 'status'])
                ->map(fn (Campaign $c) => ['id' => $c->id, 'name' => $c->name, 'active' => $c->status === CampaignStatus::Active]),
            'counts' => [
                'all' => Lead::query()->count(),
                'unassigned' => Lead::query()->whereNull('campaign_id')->count(),
            ],
            'timezone' => $settings->timezone(),
        ]);
    }

    /** Add one lead by hand into the shared list. */
    public function store(Request $request, RecipientHistory $history): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $email = EmailNormalizer::normalize($data['email']);
        if (! EmailNormalizer::isValid($email)) {
            throw ValidationException::withMessages(['email' => 'That email address is not valid.']);
        }
        if (Lead::query()->where('email_normalized', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This address is already in your leads.']);
        }
        if (Suppression::covers($email)) {
            throw ValidationException::withMessages(['email' => 'This address is on the do-not-contact list.']);
        }

        $lead = Lead::query()->create([
            'campaign_id' => null,
            'email' => trim($data['email']),
            'email_normalized' => $email,
            'first_name' => ($data['first_name'] ?? null) ?: null,
            'last_name' => ($data['last_name'] ?? null) ?: null,
            'company' => ($data['company'] ?? null) ?: null,
            'attributes' => filled($data['notes'] ?? null) ? ['notes' => $data['notes']] : null,
            'status' => LeadStatus::Pending,
            'previously_contacted_count' => $history->for($email)['count'],
        ]);

        return back()->with('success', "{$lead->email} added to your leads.");
    }

    /** Move selected unassigned leads into a campaign (they join its draft queue). */
    public function assign(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lead_ids' => ['required', 'array', 'min:1', 'max:500'],
            'lead_ids.*' => ['integer'],
            'campaign_id' => ['required', 'integer', 'exists:campaigns,id'],
        ]);

        $campaign = Campaign::query()->findOrFail($data['campaign_id']);
        $leads = Lead::query()->whereIn('id', $data['lead_ids'])->whereNull('campaign_id')->get();

        $taken = Lead::query()->where('campaign_id', $campaign->id)->pluck('email_normalized')->flip();
        $moved = 0;
        $skipped = 0;
        foreach ($leads as $lead) {
            if (isset($taken[$lead->email_normalized])) {
                $skipped++;

                continue;
            }
            $lead->forceFill(['campaign_id' => $campaign->id, 'status' => LeadStatus::Pending])->save();
            $moved++;
        }

        AuditLog::record('lead.assign', $request->user(), 'campaign', $campaign->id, null, ['moved' => $moved, 'skipped' => $skipped]);

        $message = "{$moved} lead".($moved === 1 ? '' : 's')." added to “{$campaign->name}”.";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped (already in that campaign).";
        }

        return back()->with($moved > 0 ? 'success' : 'warning', $message);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = (function () {
            foreach (Lead::query()->with('campaign:id,name')->orderBy('id')->lazy(500) as $lead) {
                yield [
                    $lead->email, $lead->first_name, $lead->last_name, $lead->company,
                    $lead->campaign?->name, $lead->status->value, $lead->previously_contacted_count,
                    json_encode($lead->attributes_data(), JSON_UNESCAPED_UNICODE),
                ];
            }
        })();

        return CsvExport::stream(
            'leads.csv',
            ['email', 'first_name', 'last_name', 'company', 'campaign', 'status', 'previously_contacted', 'extra_columns_json'],
            $rows,
            $request->user(),
            'leads.export',
        );
    }
}
