<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AccountCredentials;
use App\Support\ListPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        $query = User::query()->withCount('projects')
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->query('role')))
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name');

        return ListPage::make($request, $query, 'Users')
            ->store(fn ($q, $id) => $q->whereHas('stores', fn ($s) => $s->where('stores.id', $id)))
            ->search(['name', 'email', 'phone'], 'Name, e-mail or phone')
            ->columns([
                'Name' => fn ($u) => $u->name, 'E-mail' => fn ($u) => $u->email, 'Role' => fn ($u) => $u->role->label(),
                'Phone' => fn ($u) => $u->phone, 'Projects' => [fn ($u) => $u->projects_count, 'number'],
                'Status' => fn ($u) => $u->is_active ? 'Active' : 'Inactive',
            ])
            ->respond(fn ($users, $list) => view('users.index', ['users' => $users, 'list' => $list, 'stats' => $stats]));
    }

    public function create()
    {
        Gate::authorize('manage-users');

        return view('users.form', ['user' => new User(['is_active' => true, 'role' => Role::Staff]), 'projects' => $this->projectOptions(), 'assigned' => []]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-users');
        $data = $this->validated($request);
        $projectIds = $data['project_ids'];
        unset($data['project_ids']);
        $temporary = ($data['password'] ?? '') !== '' ? $data['password'] : self::temporaryPassword();
        $user = User::create(['password' => $temporary, 'must_change_password' => true] + $data);
        $user->projects()->sync($projectIds);
        $codes = Project::whereIn('id', $projectIds)->orderBy('code')->pluck('code')->implode(', ');
        ActivityLog::record('user.created', "User {$user->email} created as {$user->role->label()}".($codes ? ", assigned to {$codes}" : ''), $user);

        return $this->sendCredentials($user, $temporary, false, redirect()->route('users.index'), 'User created.');
    }

    public function edit(User $user)
    {
        Gate::authorize('manage-users');

        return view('users.form', ['user' => $user, 'projects' => $this->projectOptions($user), 'assigned' => $user->projects()->pluck('projects.id')->all()]);
    }

    public function update(Request $request, User $user)
    {
        Gate::authorize('manage-users');
        $data = $this->validated($request, $user);
        if ($user->id === $request->user()->id && (! $data['is_active'] || $data['role'] !== Role::Admin->value)) {
            return back()->withErrors(['role' => 'You cannot deactivate or demote your own admin account.']);
        }
        $temporary = null;
        if (! empty($data['password'])) {
            $temporary = $data['password'];
        } elseif ($request->boolean('reset_password')) {
            $temporary = self::temporaryPassword();
        }
        unset($data['password']);
        if ($temporary !== null) {
            $data['password'] = $temporary;
            $data['must_change_password'] = true;
        }
        $projectIds = $data['project_ids'];
        unset($data['project_ids']);
        $before = $user->only('role', 'is_active') + ['projects' => $user->projects()->pluck('projects.id')->sort()->values()->all()];
        $user->update($data);
        // Only projects offered on the form can change (open projects + closed ones the user is already on);
        // a project not offered can neither be added nor silently removed.
        $offered = $this->projectOptions($user)->pluck('id')->all();
        $kept = array_diff($before['projects'], $offered);
        $user->projects()->sync(array_values(array_unique(array_merge($kept, array_intersect($projectIds, $offered)))));
        $after = $user->only('role', 'is_active') + ['projects' => $user->projects()->pluck('projects.id')->sort()->values()->all()];
        ActivityLog::record('user.updated', "User {$user->email} updated", $user, ['before' => $before, 'after' => $after]);
        if ($temporary !== null) {
            return $this->sendCredentials($user, $temporary, true, redirect()->route('users.index'), 'User updated and password reset.');
        }

        return redirect()->route('users.index')->with('status', 'User updated.');
    }

    /** 12 characters with upper and lower case letters and digits; no symbols or look-alike characters, so it can be typed from an e-mail. */
    public static function temporaryPassword(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789'];
        $all = implode('', $sets);
        $chars = array_map(fn ($set) => $set[random_int(0, strlen($set) - 1)], $sets);
        while (count($chars) < 12) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /**
     * E-mail the sign-in details now. If mail cannot be sent (no mail server configured, server down), the account
     * still exists and the admin is shown the temporary password once, to hand over in person.
     */
    private function sendCredentials(User $user, string $temporary, bool $isReset, $redirect, string $done)
    {
        try {
            $user->notifyNow(new AccountCredentials($temporary, $isReset));
            ActivityLog::record($isReset ? 'user.password_reset_sent' : 'user.credentials_sent', "Sign-in details e-mailed to {$user->email}", $user);

            return $redirect->with('status', "{$done} The sign-in details were e-mailed to {$user->email}; they must change the temporary password at first sign-in.");
        } catch (\Throwable $e) {
            Log::warning('Could not e-mail sign-in details', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            ActivityLog::record('user.credentials_not_sent', "Sign-in details could not be e-mailed to {$user->email}", $user);

            return $redirect->with('status', $done)->with('credentials', ['email' => $user->email, 'password' => $temporary]);
        }
    }

    /** Projects that can be assigned: open ones, plus any closed project the user already belongs to. */
    private function projectOptions(?User $user = null)
    {
        return Project::with('manager:id,name')
            ->where(fn ($q) => $q->whereIn('status', ['planned', 'active', 'on_hold'])
                ->when($user?->exists, fn ($w) => $w->orWhereHas('members', fn ($m) => $m->whereKey($user->id))))
            ->orderBy('code')->get(['id', 'code', 'name', 'status', 'manager_id']);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'phone' => 'nullable|string|max:30',
            'role' => ['required', Rule::enum(Role::class)],
            'is_active' => 'sometimes|boolean',
            'password' => ['nullable', Password::min(8)->letters()->numbers()],
            'project_ids' => 'nullable|array',
            'project_ids.*' => ['integer', Rule::exists('projects', 'id')],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['project_ids'] = array_values(array_unique(array_map('intval', $data['project_ids'] ?? [])));
        // Staff can only see and request for projects they are assigned to: a new staff account needs at least one.
        if (! $user && $data['role'] === Role::Staff->value && ! $data['project_ids'] && $this->projectOptions()->isNotEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['project_ids' => 'Assign the new staff member to at least one project, otherwise they cannot see or request anything.']);
        }

        return $data;
    }
}
