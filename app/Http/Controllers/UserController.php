<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\CustomRole;
use App\Models\User;
use App\Services\UserAccounts;
use App\Support\ListPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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
        $query = User::query()->with('customRole')->withCount('projects')
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->query('role')))
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name');

        return ListPage::make($request, $query, 'Users')
            ->store(fn ($q, $id) => $q->whereHas('stores', fn ($s) => $s->where('stores.id', $id)))
            ->search(['name', 'email', 'phone'], 'Name, e-mail or phone')
            ->columns([
                'Name' => fn ($u) => $u->name, 'E-mail' => fn ($u) => $u->email, 'Role' => fn ($u) => $u->roleLabel(),
                'Phone' => fn ($u) => $u->phone, 'Projects' => [fn ($u) => $u->projects_count, 'number'],
                'Status' => fn ($u) => $u->is_active ? 'Active' : 'Inactive',
            ])
            ->respond(fn ($users, $list) => view('users.index', ['users' => $users, 'list' => $list, 'stats' => $stats]));
    }

    public function create(UserAccounts $accounts)
    {
        Gate::authorize('manage-users');

        return view('users.form', ['user' => new User(['is_active' => true, 'role' => Role::Staff]), 'projects' => $accounts->projectOptions(), 'assigned' => [], 'customRoles' => CustomRole::orderBy('name')->get()]);
    }

    public function store(Request $request, UserAccounts $accounts)
    {
        Gate::authorize('manage-users');
        [$user, $temporary] = $accounts->create($accounts->validate($request));

        return $this->credentialsRedirect($accounts, $user, $temporary, false, 'User created.');
    }

    public function edit(User $user, UserAccounts $accounts)
    {
        Gate::authorize('manage-users');

        return view('users.form', ['user' => $user, 'projects' => $accounts->projectOptions($user), 'assigned' => $user->projects()->pluck('projects.id')->all(), 'customRoles' => CustomRole::orderBy('name')->get()]);
    }

    public function update(Request $request, User $user, UserAccounts $accounts)
    {
        Gate::authorize('manage-users');
        $temporary = $accounts->update($user, $accounts->validate($request, $user, $request->user()), $request->boolean('reset_password'));
        if ($temporary !== null) {
            return $this->credentialsRedirect($accounts, $user, $temporary, true, 'User updated and password reset.');
        }

        return redirect()->route('users.index')->with('status', 'User updated.');
    }

    /** Kept for existing callers; see UserAccounts::temporaryPassword(). */
    public static function temporaryPassword(): string
    {
        return UserAccounts::temporaryPassword();
    }

    /** If the e-mail could not be sent, the admin is shown the temporary password once, to hand over in person. */
    private function credentialsRedirect(UserAccounts $accounts, User $user, string $temporary, bool $isReset, string $done)
    {
        $redirect = redirect()->route('users.index');
        if ($accounts->sendCredentials($user, $temporary, $isReset)) {
            return $redirect->with('status', "{$done} The sign-in details were e-mailed to {$user->email}; they must change the temporary password at first sign-in.");
        }

        return $redirect->with('status', $done)->with('credentials', ['email' => $user->email, 'password' => $temporary]);
    }

    /**
     * Owner/Admin: activate or deactivate the ticked accounts. Accounts are never deleted (their names stay on
     * approvals, vouchers and the audit log). You cannot deactivate yourself or the last active Owner/Admin.
     */
    public function bulk(\Illuminate\Http\Request $request)
    {
        \Illuminate\Support\Facades\Gate::authorize('manage-users');
        $action = $request->validate(['action' => 'required|in:activate,deactivate'])['action'];
        $users = User::whereIn('id', $this->bulkIds($request))->orderBy('name')->get();
        $me = $request->user();

        return $this->bulkApply($users, fn ($u) => $u->name, function (User $u) use ($action, $me) {
            $active = $action === 'activate';
            if ($u->is_active === $active) {
                throw new \App\Exceptions\WorkflowException($active ? 'already active' : 'already inactive');
            }
            if (! $active && $u->id === $me->id) {
                throw new \App\Exceptions\WorkflowException('you cannot deactivate your own account');
            }
            if (! $active && $u->isAdmin() && User::where('role', Role::Admin->value)->where('is_active', true)->count() <= 1) {
                throw new \App\Exceptions\WorkflowException('the last active Owner/Admin cannot be deactivated');
            }
            $u->forceFill(['is_active' => $active])->save();
            if (! $active) {
                $u->tokens()->delete(); // signs the mobile app out
            }
            \App\Models\ActivityLog::record($active ? 'user.activated' : 'user.deactivated', "{$u->name} ".($active ? 'activated' : 'deactivated').' (bulk)', $u);
        }, 'user', $action === 'activate' ? 'activated' : 'deactivated');
    }
}
