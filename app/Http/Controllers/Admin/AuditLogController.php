<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request, Settings $settings): Response
    {
        $action = trim((string) $request->query('action', ''));

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($action !== '', fn ($q) => $q->where('action', 'like', $action.'%'))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user?->name,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'before' => $log->before,
                'after' => $log->after,
                'ip' => $log->ip,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Audit', [
            'logs' => $logs,
            'filters' => ['action' => $action],
            'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
            'timezone' => $settings->timezone(),
        ]);
    }
}
