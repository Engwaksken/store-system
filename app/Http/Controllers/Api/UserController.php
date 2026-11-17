<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Models\CustomRole;
use App\Models\User;
use App\Services\UserAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Admin user management for the mobile app; same rules as the web Users pages (UserAccounts). */
class UserController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage-users');
        $query = User::query()->with('customRole')->withCount('projects')
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->query('role')))
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $this->like($request->query('q')))
                ->orWhere('email', 'like', $this->like($request->query('q')))
                ->orWhere('phone', 'like', $this->like($request->query('q')))))
            ->orderBy('name');

        return $this->paginated($request, $query, fn ($u) => Present::account($u));
    }

    /** Choices for the add / edit form. Pass user_id when editing so closed projects the user is on are offered too. */
    public function options(Request $request, UserAccounts $accounts): JsonResponse
    {
        Gate::authorize('manage-users');
        $user = $request->filled('user_id') ? User::find($request->integer('user_id')) : null;

        return response()->json([
            'roles' => collect(Role::cases())->map(fn ($r) => ['value' => $r->value, 'label' => $r->label()])->values(),
            'custom_roles' => CustomRole::orderBy('name')->get()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'permission_profile' => $c->permission_profile])->values(),
            'projects' => $accounts->projectOptions($user)->map(fn ($p) => Present::project($p) + ['status' => $p->status])->values(),
            'stats' => [
                'total' => User::count(),
                'active' => User::where('is_active', true)->count(),
                'inactive' => User::where('is_active', false)->count(),
            ],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        Gate::authorize('manage-users');

        return response()->json(['data' => Present::account($user->load('customRole'), true)]);
    }

    public function store(Request $request, UserAccounts $accounts): JsonResponse
    {
        Gate::authorize('manage-users');
        [$user, $temporary] = $accounts->create($accounts->validate($request));

        return $this->withCredentials($accounts, $user, $temporary, false, 201);
    }

    public function update(Request $request, User $user, UserAccounts $accounts): JsonResponse
    {
        Gate::authorize('manage-users');
        $temporary = $accounts->update($user, $accounts->validate($request, $user, $request->user()), $request->boolean('reset_password'));
        if ($temporary !== null) {
            return $this->withCredentials($accounts, $user, $temporary, true);
        }

        return response()->json(['data' => Present::account($user->fresh()->load('customRole'), true), 'credentials_emailed' => null]);
    }

    /** The temporary password is returned only when it could not be e-mailed, so the admin can hand it over once. */
    private function withCredentials(UserAccounts $accounts, User $user, string $temporary, bool $isReset, int $status = 200): JsonResponse
    {
        $mailed = $accounts->sendCredentials($user, $temporary, $isReset);

        return response()->json([
            'data' => Present::account($user->fresh()->load('customRole'), true),
            'credentials_emailed' => $mailed,
            'temporary_password' => $mailed ? null : $temporary,
        ], $status);
    }
}
