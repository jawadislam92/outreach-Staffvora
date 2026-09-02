<?php

namespace App\Http\Controllers;

use App\Models\Suppression;
use App\Services\Sending\RecipientHistory;
use App\Support\EmailNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecipientCheckController extends Controller
{
    public function __invoke(Request $request, RecipientHistory $history): JsonResponse
    {
        $normalized = EmailNormalizer::normalize((string) $request->query('email', ''));

        if (! EmailNormalizer::isValid($normalized)) {
            return response()->json(['valid' => false, 'suppressed' => false, 'history' => null]);
        }

        return response()->json([
            'valid' => true,
            'email' => $normalized,
            'suppressed' => Suppression::covers($normalized),
            'history' => $history->for($normalized),
        ]);
    }
}
