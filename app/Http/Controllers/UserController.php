<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ListPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Admin-only user management. Users are deactivated, never deleted (audit trail). */
class UserController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('manage-users');

        $stats = [
            'total' => User::count(),
            'active' => User::where('is_active', true)->count(),
            'inactive' => User::where('is_active', false)->count(),
            'admins' => User::where('role', Role::Admin->value)->where('is_active', true)->count(),
        ];
        $query = User::query()
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->query('role')))
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name');

        return ListPage::make($request, $query, 'Users')
            ->search(['name', 'email', 'phone'], 'Name, e-mail or phone')
            ->columns([
                'Name' => fn ($u) => $u->name, 'E-mail' => fn ($u) => $u->email, 'Role' => fn ($u) => $u->role->label(),
                'Phone' => fn ($u) => $u->phone, 'Status' => fn ($u) => $u->is_active ? 'Active' : 'Inactive',
            ])
            ->respond(fn ($users, $list) => view('users.index', ['users' => $users, 'list' => $list, 'stats' => $stats]));
    }

    public function create()
    {
        Gate::authorize('manage-users');

        return view('users.form', ['user' => new User(['is_active' => true, 'role' => Role::Staff])]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-users');
        $data = $this->validated($request);
        $user = User::create($data + ['must_change_password' => true]);
        ActivityLog::record('user.created', "User {$user->email} created as {$user->role->label()}", $user);

        return redirect()->route('users.index')->with('status', 'User created. They must change the temporary password at first login.');
    }

    public function edit(User $user)
    {
        Gate::authorize('manage-users');

        return view('users.form', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        Gate::authorize('manage-users');
        $data = $this->validated($request, $user);
        if ($user->id === $request->user()->id && (! $data['is_active'] || $data['role'] !== Role::Admin->value)) {
            return back()->withErrors(['role' => 'You cannot deactivate or demote your own admin account.']);
        }
        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['must_change_password'] = true;
        }
        $before = $user->only('role', 'is_active');
        $user->update($data);
        ActivityLog::record('user.updated', "User {$user->email} updated", $user, ['before' => $before, 'after' => $user->only('role', 'is_active')]);

        return redirect()->route('users.index')->with('status', 'User updated.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'phone' => 'nullable|string|max:30',
            'role' => ['required', Rule::enum(Role::class)],
            'is_active' => 'sometimes|boolean',
            'password' => [$user ? 'nullable' : 'required', Password::min(8)->letters()->numbers()],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
