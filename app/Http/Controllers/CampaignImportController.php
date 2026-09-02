<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Services\Import\LeadCsvParser;
use App\Services\Import\LeadImporter;
use App\Services\Import\ParsedCsv;
use App\Support\CsvExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Two-step import: upload → parsed preview + column mapping held in the cache
 * for 30 minutes → confirm. Rejected rows are downloadable afterwards.
 */
class CampaignImportController extends Controller
{
    private const TTL_MINUTES = 30;

    /** With a campaign → campaign import; without ($campaign null) → shared lead list. */
    public function create(?Campaign $campaign = null): Response
    {
        return Inertia::render('Campaigns/Import', [
            'campaign' => $campaign ? CampaignController::campaignShape($campaign) : null,
            'fields' => LeadCsvParser::FIELDS,
            'max_rows' => (int) config('outreach.import.max_rows'),
            'endpoints' => [
                'preview' => $campaign ? route('campaigns.import.preview', $campaign) : route('leads.import.preview'),
                'store' => $campaign ? route('campaigns.import.store', $campaign) : route('leads.import.store'),
            ],
        ]);
    }

    public function preview(Request $request, ?Campaign $campaign = null): JsonResponse
    {
        $parser = app(LeadCsvParser::class);
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:4096'],
        ]);

        try {
            $parsed = $parser->parse((string) file_get_contents($request->file('file')->getRealPath()));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $token = (string) Str::ulid();
        Cache::put($this->key($token), [
            'user_id' => $request->user()->id,
            'campaign_id' => $campaign?->id,
            'parsed' => $parsed->toArray(),
        ], now()->addMinutes(self::TTL_MINUTES));

        return response()->json([
            'token' => $token,
            'headers' => $parsed->headers,
            'mapping' => $parsed->mapping,
            'sample' => $parsed->sample(20),
            'row_count' => $parsed->count(),
            'delimiter' => $parsed->delimiter === "\t" ? 'tab' : $parsed->delimiter,
            'encoding' => $parsed->encoding,
            'skipped_blank' => $parsed->skippedBlank,
        ]);
    }

    public function store(Request $request, ?Campaign $campaign = null): JsonResponse
    {
        $importer = app(LeadImporter::class);
        $data = $request->validate([
            'token' => ['required', 'string', 'max:40'],
            'mapping' => ['required', 'array'],
            'mapping.email' => ['required', 'string'],
            'mapping.*' => ['nullable', 'string', 'max:120'],
        ]);

        $cached = Cache::get($this->key($data['token']));
        if (! is_array($cached) || $cached['user_id'] !== $request->user()->id || $cached['campaign_id'] !== $campaign?->id) {
            throw ValidationException::withMessages(['token' => 'This upload expired — please upload the file again.']);
        }

        $parsed = ParsedCsv::fromArray($cached['parsed']);
        $keys = array_column($parsed->headers, 'key');
        $mapping = [];
        foreach (LeadCsvParser::FIELDS as $field) {
            $value = $data['mapping'][$field] ?? null;
            $mapping[$field] = ($value !== null && $value !== '' && in_array($value, $keys, true)) ? $value : null;
        }
        if (! $mapping['email']) {
            throw ValidationException::withMessages(['mapping.email' => 'Choose the column that holds the email address.']);
        }

        $result = $importer->import($campaign, $parsed->rows, $mapping, $request->user());
        Cache::forget($this->key($data['token']));

        if ($result->rejected !== []) {
            Cache::put($this->key($data['token']).':rejects', ['user_id' => $request->user()->id, 'rows' => $result->rejected], now()->addMinutes(self::TTL_MINUTES));
        }

        AuditLog::record(
            $campaign ? 'campaign.import' : 'leads.import',
            $request->user(),
            $campaign ? 'campaign' : 'leads',
            $campaign?->id,
            null,
            $result->toArray() + ['mapping' => $mapping],
        );

        return response()->json($result->toArray() + [
            'rejects_url' => $result->rejected !== [] ? route('campaigns.import.rejects', $data['token']) : null,
            'campaign_url' => $campaign ? route('campaigns.show', $campaign) : route('leads.index'),
        ]);
    }

    public function rejects(Request $request, string $token): StreamedResponse
    {
        $cached = Cache::get($this->key($token).':rejects');
        abort_unless(is_array($cached) && $cached['user_id'] === $request->user()->id, 404);

        return CsvExport::stream(
            'rejected-rows.csv',
            ['row', 'email', 'reason'],
            array_map(fn ($r) => [$r['row'], $r['email'], $r['reason']], $cached['rows']),
            $request->user(),
            'campaign.import.rejects',
        );
    }

    private function key(string $token): string
    {
        abort_unless(preg_match('/^[0-9A-Z]{26}$/i', $token), 404);

        return 'import:'.$token;
    }
}
