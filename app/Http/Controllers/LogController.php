<?php

namespace App\Http\Controllers;

use App\Enums\SendStatus;
use App\Models\EmailAccount;
use App\Models\SentEmail;
use App\Models\User;
use App\Support\CsvExport;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogController extends Controller
{
    public function index(Request $request, Settings $settings): Response
    {
        $filters = $this->filters($request, $settings);

        $rows = $this->query($filters)
            ->with(['user:id,name', 'account' => fn ($q) => $q->select('id', 'label', 'from_email', 'deleted_at'), 'campaign:id,name'])
            ->withCount('attachments')
            ->orderByDesc('reserved_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (SentEmail $e) => self::row($e));

        return Inertia::render('Log/Index', [
            'rows' => $rows,
            'filters' => $filters,
            'accounts' => EmailAccount::withTrashed()->orderBy('label')->get(['id', 'label'])->map(fn ($a) => ['id' => $a->id, 'label' => $a->label]),
            'members' => User::query()->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'statuses' => collect(SendStatus::cases())->map(fn (SendStatus $s) => ['value' => $s->value, 'label' => $s->label()]),
            'timezone' => $settings->timezone(),
            'today' => $settings->today(),
        ]);
    }

    public function show(SentEmail $sentEmail): JsonResponse
    {
        $sentEmail->load(['user:id,name', 'account' => fn ($q) => $q->select('id', 'label', 'from_email', 'deleted_at'), 'attachments', 'campaign:id,name']);

        return response()->json(self::row($sentEmail) + [
            'body_html' => $sentEmail->body_html,
            'body_text' => $sentEmail->body_text,
            'body_md' => $sentEmail->body_md,
            'cc' => $sentEmail->cc,
            'bcc' => $sentEmail->bcc,
            'provider_message_id' => $sentEmail->provider_message_id,
            'message_id_header' => $sentEmail->message_id_header,
            'duration_ms' => $sentEmail->duration_ms,
            'attachments' => $sentEmail->attachments->map(fn ($a) => $a->toSummary() + ['filename_as_sent' => $a->pivot->filename_as_sent]),
        ]);
    }

    public function export(Request $request, Settings $settings): StreamedResponse
    {
        $filters = $this->filters($request, $settings);
        $tz = $settings->timezone();

        $rows = (function () use ($filters, $tz) {
            $query = $this->query($filters)
                ->with(['user:id,name', 'account' => fn ($q) => $q->select('id', 'label', 'from_email', 'deleted_at')])
                ->orderBy('reserved_at');

            foreach ($query->lazy(500) as $e) {
                yield [
                    $e->local_date?->format('Y-m-d'),
                    $e->sent_at?->setTimezone($tz)->format('Y-m-d H:i:s'),
                    $e->status->value,
                    $e->user?->name,
                    $e->account?->label,
                    $e->account?->from_email,
                    $e->to_email,
                    $e->to_name,
                    $e->subject,
                    $e->gap_seconds,
                    implode('; ', $e->cc ?? []),
                    implode('; ', $e->bcc ?? []),
                    $e->error,
                    $e->message_id_header,
                ];
            }
        })();

        return CsvExport::stream(
            'outreach-log-'.$filters['from'].'-to-'.$filters['to'].'.csv',
            ['Date', 'Sent at ('.$tz.')', 'Status', 'Member', 'Account', 'From', 'To', 'To name', 'Subject', 'Gap (s)', 'CC', 'BCC', 'Error', 'Message-ID'],
            $rows,
            $request->user(),
            'log.export',
            $filters,
        );
    }

    private function filters(Request $request, Settings $settings): array
    {
        $today = $settings->today();
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'account_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:pending,sent,failed,unknown'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        return [
            'from' => $validated['from'] ?? \Carbon\CarbonImmutable::parse($today)->subDays(29)->toDateString(),
            'to' => $validated['to'] ?? $today,
            'account_id' => isset($validated['account_id']) ? (int) $validated['account_id'] : null,
            'user_id' => isset($validated['user_id']) ? (int) $validated['user_id'] : null,
            'status' => $validated['status'] ?? null,
            'q' => trim((string) ($validated['q'] ?? '')),
        ];
    }

    private function query(array $filters): Builder
    {
        return SentEmail::query()
            ->whereBetween('local_date', [$filters['from'], $filters['to']])
            ->when($filters['account_id'], fn ($q, $id) => $q->where('email_account_id', $id))
            ->when($filters['user_id'], fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($filters['q'] !== '', fn ($q) => $q->where(function ($w) use ($filters) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%';
                $w->where('to_email', 'like', $like)->orWhere('subject', 'like', $like)->orWhere('to_name', 'like', $like);
            }));
    }

    public static function row(SentEmail $e): array
    {
        return [
            'id' => $e->id,
            'local_date' => $e->local_date?->format('Y-m-d'),
            'reserved_at' => $e->reserved_at?->toIso8601String(),
            'sent_at' => $e->sent_at?->toIso8601String(),
            'status' => $e->status->value,
            'status_label' => $e->status->label(),
            'user' => $e->user?->name,
            'user_id' => $e->user_id,
            'account' => $e->account?->label,
            'account_id' => $e->email_account_id,
            'from_email' => $e->account?->from_email,
            'to_email' => $e->to_email,
            'to_name' => $e->to_name,
            'subject' => $e->subject,
            'gap_seconds' => $e->gap_seconds,
            'error' => $e->error,
            'campaign' => $e->campaign?->name,
            'attachments_count' => $e->attachments_count ?? null,
        ];
    }
}
