<?php

namespace App\Http\Controllers;

use App\Services\Reports\ReportQueries;
use App\Support\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ReportQueries $reports, Settings $settings): Response
    {
        $user = $request->user();
        $today = $settings->today();

        return Inertia::render('Dashboard', [
            'today' => $today,
            'timezone' => $settings->timezone(),
            'accounts' => $reports->accountUsage($user->usableAccounts(), $today),
            'totals' => $reports->teamTotals($today),
            'members' => $reports->memberTotals($today),
            'series' => $reports->dailySeries(14, $today),
            'recent' => $reports->recent(10),
            'my_sent_today' => $user->sentEmails()->where('local_date', $today)->where('status', 'sent')->count(),
        ]);
    }
}
