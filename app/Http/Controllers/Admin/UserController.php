<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Settings $settings): Response
    {
        $today = $settings->today();

        $users = User::query()
            ->withCount([
                'sentEmails as sent_today' => fn ($q) => $q->where('local_date', $today)->where('status', 'sent'),
            ])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $u->toSummary() + ['sent_today' => $u->sent_today]);

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'roles' => collect(Role::cases())->map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label()])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::enum(Role::class)],
            'daily_cap' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $password = Str::password(14, symbols: false);

        $user = User::query()->create($data + ['password' => $password, 'is_active' => true]);

        AuditLog::record('user.create', $request->user(), 'user', $user->id, null, [
            'name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'daily_cap' => $user->daily_cap,
        ]);

        return back()
            ->with('success', "{$user->name} was added.")
            ->with('credentials', ['name' => $user->name, 'email' => $user->email, 'password' => $password]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::enum(Role::class)],
            'daily_cap' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['required', 'boolean'],
        ]);

        $this->guardLastAdmin($user, Role::from($data['role']), (bool) $data['is_active']);

        $before = ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'daily_cap' => $user->daily_cap, 'is_active' => $user->is_active];
        $user->fill($data)->save();
        $after = ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'daily_cap' => $user->daily_cap, 'is_active' => $user->is_active];

        if ($before !== $after) {
            AuditLog::record('user.update', $request->user(), 'user', $user->id, $before, $after);
        }

        return back()->with('success', "{$user->name} was updated.");
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $password = Str::password(14, symbols: false);
        $user->forceFill(['password' => $password, 'remember_token' => null])->save();

        AuditLog::record('user.reset_password', $request->user(), 'user', $user->id);

        return back()
            ->with('success', "New password generated for {$user->name}.")
            ->with('credentials', ['name' => $user->name, 'email' => $user->email, 'password' => $password]);
    }

    /** The team must always keep at least one active admin. */
    private function guardLastAdmin(User $user, Role $newRole, bool $newActive): void
    {
        $staysAdmin = $newRole === Role::Admin && $newActive;
        if ($staysAdmin || ! $user->isAdmin() || ! $user->is_active) {
            return;
        }

        $otherAdmins = User::query()->where('role', Role::Admin->value)->where('is_active', true)->whereKeyNot($user->id)->count();
        if ($otherAdmins === 0) {
            throw ValidationException::withMessages(['role' => 'This is the only active admin — promote someone else first.']);
        }
    }
}
