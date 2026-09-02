<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Services\Ai\GenerationRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Browser-driven generation loop: the page calls generate until remaining = 0. */
class CampaignGenerateController extends Controller
{
    public function generate(Request $request, Campaign $campaign, GenerationRunner $runner): JsonResponse
    {
        $data = $request->validate(['batch' => ['nullable', 'integer', 'min:1', 'max:5']]);

        // Each Claude call can take several seconds; keep the request comfortably inside PHP's limit.
        @set_time_limit(120);

        return response()->json($runner->run($campaign, $request->user(), $data['batch'] ?? null));
    }

    public function progress(Campaign $campaign, GenerationRunner $runner): JsonResponse
    {
        return response()->json($runner->progress($campaign));
    }
}
