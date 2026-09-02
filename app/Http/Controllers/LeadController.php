<?php

namespace App\Http\Controllers;

use App\Enums\DraftSource;
use App\Enums\DraftStatus;
use App\Enums\LeadStatus;
use App\Enums\SendStatus;
use App\Models\Attachment;
use App\Models\Campaign;
use App\Models\Draft;
use App\Models\Lead;
use App\Models\Suppression;
use App\Services\Ai\GenerationRunner;
use App\Services\Reports\ReportQueries;
use App\Services\Sending\RecipientHistory;
use App\Services\Sending\SendBlockedException;
use App\Services\Sending\SendRequest;
use App\Services\Sending\SendService;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The review queue: read a draft, tweak it, pick the mailbox, send. */
class LeadController extends Controller
{
    public function queue(Request $request, Campaign $campaign, ReportQueries $reports, Settings $settings, GenerationRunner $runner): Response
    {
        $user = $request->user();
        $accounts = $user->usableAccounts();

        $queue = $campaign->leads()
            ->where('status', LeadStatus::Ready->value)
            ->orderBy('id')
            ->get(['id', 'email', 'first_name', 'last_name', 'company', 'previously_contacted_count'])
            ->map(fn (Lead $l) => ['id' => $l->id, 'email' => $l->email, 'name' => $l->fullName(), 'company' => $l->company, 'previously_contacted_count' => $l->previously_contacted_count]);

        $currentId = (int) $request->query('lead', 0);
        $current = $currentId ? $campaign->leads()->whereKey($currentId)->first() : null;
        $current ??= $campaign->leads()->where('status', LeadStatus::Ready->value)->orderBy('id')->first();

        return Inertia::render('Campaigns/Queue', [
            'campaign' => CampaignController::campaignShape($campaign),
            'queue' => $queue,
            'current' => $current ? $this->detail($current) : null,
            'accounts' => $reports->accountUsage($accounts),
            'library' => Attachment::query()->where('is_library', true)->where('is_active', true)->orderBy('original_name')->get()->map->toSummary(),
            'progress' => $runner->progress($campaign),
            'settings' => [
                'min_gap_warning_seconds' => $settings->minGapWarningSeconds(),
                'max_attachment_mb' => (int) $settings->get('max_attachment_mb'),
                'timezone' => $settings->timezone(),
                'allowed_extensions' => config('outreach.attachments.allowed_extensions'),
            ],
        ]);
    }

    public function show(Lead $lead): JsonResponse
    {
        return response()->json($this->detail($lead));
    }

    public function saveDraft(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body_md' => ['required', 'string', 'max:20000'],
        ]);

        $draft = Draft::query()->firstOrNew(['lead_id' => $lead->id], ['campaign_id' => $lead->campaign_id, 'source' => DraftSource::Manual]);
        $draft->fill([
            'campaign_id' => $lead->campaign_id,
            'subject' => $data['subject'],
            'body_md' => $data['body_md'],
            'edited_by' => $request->user()->id,
        ]);
        if (! $draft->exists) {
            $draft->source = DraftSource::Manual;
        }
        $draft->save();

        if (in_array($lead->status, [LeadStatus::Pending, LeadStatus::Failed], true)) {
            $lead->forceFill(['status' => LeadStatus::Ready, 'generation_error' => null])->save();
        }

        return response()->json($this->detail($lead->fresh()));
    }

    public function send(Request $request, Lead $lead, SendService $sender): RedirectResponse
    {
        $data = $request->validate([
            'client_token' => ['required', 'string', 'max:64'],
            'email_account_id' => ['required', 'integer', 'exists:email_accounts,id'],
            'subject' => ['required', 'string', 'max:255'],
            'body_md' => ['required', 'string', 'max:20000'],
            'attachment_ids' => ['nullable', 'array', 'max:10'],
            'attachment_ids.*' => ['string', 'exists:attachments,id'],
            'ack_duplicate' => ['nullable', 'boolean'],
        ]);

        if ($lead->status === LeadStatus::Sent) {
            return back()->with('warning', "{$lead->email} was already sent from this campaign.");
        }

        // Persist edits first so the log and the draft agree even if sending fails.
        $draft = Draft::query()->firstOrNew(['lead_id' => $lead->id], ['campaign_id' => $lead->campaign_id, 'source' => DraftSource::Manual]);
        $draft->fill(['campaign_id' => $lead->campaign_id, 'subject' => $data['subject'], 'body_md' => $data['body_md'], 'edited_by' => $request->user()->id]);
        if (! $draft->exists) {
            $draft->source = DraftSource::Manual;
        }
        $draft->save();

        $account = \App\Models\EmailAccount::query()->findOrFail($data['email_account_id']);

        $sendRequest = new SendRequest(
            user: $request->user(),
            account: $account,
            to: $lead->email,
            toName: $lead->fullName(),
            subject: $data['subject'],
            bodyMd: $data['body_md'],
            clientToken: $data['client_token'],
            attachmentIds: array_values($data['attachment_ids'] ?? []),
            variables: $lead->variables(),
            ackDuplicate: (bool) ($data['ack_duplicate'] ?? false),
            campaignId: $lead->campaign_id,
            leadId: $lead->id,
            draftId: $draft->id,
        );

        try {
            $log = $sender->send($sendRequest);
        } catch (SendBlockedException $e) {
            return back()->withErrors([$e->field() => $e->getMessage()])->with('blocked', ['reason' => $e->reason, 'details' => $e->details]);
        }

        $next = $lead->campaign->leads()->where('status', LeadStatus::Ready->value)->whereKeyNot($lead->id)->orderBy('id')->value('id');
        $target = route('campaigns.queue', [$lead->campaign_id] + ($next ? ['lead' => $next] : []));

        return match ($log->status) {
            SendStatus::Sent => redirect($target)->with('success', "Sent to {$lead->email} from {$account->label}."),
            SendStatus::Unknown => redirect($target)->with('warning', "No confirmation for {$lead->email} — logged as unknown; check the mailbox before resending."),
            default => back()->with('error', "Sending to {$lead->email} failed: {$log->error}"),
        };
    }

    public function skip(Request $request, Lead $lead): RedirectResponse
    {
        $lead->forceFill(['status' => LeadStatus::Skipped])->save();
        Draft::query()->where('lead_id', $lead->id)->update(['status' => DraftStatus::Skipped->value]);

        $next = $lead->campaign->leads()->where('status', LeadStatus::Ready->value)->orderBy('id')->value('id');

        return redirect()->route('campaigns.queue', [$lead->campaign_id] + ($next ? ['lead' => $next] : []))->with('info', "{$lead->email} skipped.");
    }

    public function regenerate(Request $request, Lead $lead, GenerationRunner $runner): RedirectResponse
    {
        try {
            $runner->regenerate($lead, $request->user());
        } catch (\Throwable $e) {
            return back()->with('error', 'Regeneration failed: '.$e->getMessage());
        }

        return back()->with('success', 'New draft generated.');
    }

    public function destroy(Request $request, Lead $lead): RedirectResponse
    {
        abort_if($lead->status === LeadStatus::Sent, 422, 'Sent leads cannot be removed; the send history must stay intact.');

        $email = $lead->email;
        $lead->delete();

        return back()->with('success', "{$email} removed from the campaign.");
    }

    private function detail(Lead $lead): array
    {
        $lead->loadMissing('draft', 'campaign');
        $history = app(RecipientHistory::class)->for($lead->email_normalized);

        return CampaignController::leadRow($lead) + [
            'variables' => $lead->variables(),
            'attributes' => $lead->attributes_data(),
            'draft_full' => $lead->draft ? [
                'id' => $lead->draft->id,
                'subject' => $lead->draft->subject,
                'body_md' => $lead->draft->body_md,
                'source' => $lead->draft->source->value,
                'model' => $lead->draft->model,
                'generation_count' => $lead->draft->generation_count,
                'updated_at' => $lead->draft->updated_at?->toIso8601String(),
            ] : null,
            'history' => $history,
            'suppressed' => Suppression::covers($lead->email_normalized),
        ];
    }
}
