<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Suppression;
use App\Support\EmailNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Do-not-contact list: SendService refuses these, imports flag them, Compose blocks them. */
class SuppressionController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:200']]);
        $q = trim((string) ($validated['q'] ?? ''));

        $rows = Suppression::query()
            ->with('addedBy:id,name')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], strtolower($q)).'%';
                $query->where('email_normalized', 'like', $like);
            })
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Suppression $s) => [
                'id' => $s->id,
                'email' => $s->email_normalized,
                'reason' => $s->reason,
                'added_by' => $s->addedBy?->name,
                'created_at' => $s->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Suppressions', [
            'rows' => $rows,
            'filters' => ['q' => $q],
            'total' => Suppression::query()->count(),
        ]);
    }

    /** Accepts one or many addresses (newline / comma / semicolon separated, `Name <addr>` allowed). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'emails' => ['required', 'string', 'max:5000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $reason = trim((string) ($data['reason'] ?? '')) ?: null;

        $valid = [];
        $invalid = [];
        foreach (preg_split('/[\r\n,;]+/', $data['emails'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $email = EmailNormalizer::normalize($chunk);
            if ($email === '' || ! EmailNormalizer::isValid($email)) {
                $invalid[] = $chunk;

                continue;
            }

            $valid[$email] = true;
        }
        $valid = array_keys($valid);

        $existing = $valid === [] ? [] : Suppression::query()->whereIn('email_normalized', $valid)->pluck('email_normalized')->all();
        $new = array_values(array_diff($valid, $existing));

        if ($new !== []) {
            $now = now();
            Suppression::query()->insertOrIgnore(array_map(fn (string $email) => [
                'email_normalized' => $email,
                'reason' => $reason,
                'added_by' => $request->user()->id,
                'created_at' => $now,
                'updated_at' => $now,
            ], $new));

            AuditLog::record('suppression.add', $request->user(), 'suppression', null, null, ['emails' => $new, 'reason' => $reason]);
        }

        $parts = [count($new).' added'];
        if ($existing !== []) {
            $parts[] = count($existing).' already listed';
        }
        if ($invalid !== []) {
            $shown = array_slice($invalid, 0, 5);
            $parts[] = count($invalid).' invalid ('.implode(', ', $shown).(count($invalid) > 5 ? ', …' : '').')';
        }

        $level = $new !== [] ? 'success' : ($invalid !== [] ? 'warning' : 'info');

        return back()->with($level, implode(', ', $parts).'.');
    }

    public function destroy(Request $request, Suppression $suppression): RedirectResponse
    {
        $before = ['email' => $suppression->email_normalized, 'reason' => $suppression->reason];
        $suppression->delete();

        AuditLog::record('suppression.remove', $request->user(), 'suppression', $suppression->id, $before, null);

        return back()->with('success', "{$suppression->email_normalized} can be emailed again.");
    }
}
