<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\CustomRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomRoleController extends Controller
{
    public function index()
    {
        Gate::authorize('manage-users');
        return view('users.roles', ['roles' => CustomRole::withCount('users')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-users');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:custom_roles,name'],
            'permission_profile' => ['required', Rule::enum(Role::class)],
        ]);
        $role = CustomRole::create($data);
        ActivityLog::record('role.created', "Custom role {$role->name} created using {$role->permission_profile} permissions", $role);
        return redirect()->route('users.roles')->with('status', 'Custom role created.');
    }

    public function update(Request $request, CustomRole $customRole)
    {
        Gate::authorize('manage-users');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('custom_roles', 'name')->ignore($customRole->id)],
            'permission_profile' => ['required', Rule::enum(Role::class)],
        ]);
        if ($customRole->users()->whereKey($request->user()->id)->exists() && $data['permission_profile'] !== Role::Admin->value) {
            return back()->withErrors(['permission_profile' => 'You cannot remove your own admin access through a custom role.']);
        }
        $before = $customRole->only('name', 'permission_profile');
        DB::transaction(function () use ($customRole, $data) {
            $customRole->update($data);
            $customRole->users()->update(['role' => $data['permission_profile']]);
        });
        ActivityLog::record('role.updated', "Custom role {$customRole->name} updated", $customRole, ['before' => $before, 'after' => $data]);
        return redirect()->route('users.roles')->with('status', 'Custom role updated.');
    }

    public function destroy(CustomRole $customRole)
    {
        Gate::authorize('manage-users');
        if ($customRole->users()->exists()) {
            return back()->withErrors(['role' => 'This role is assigned to users. Reassign them before deleting it.']);
        }
        ActivityLog::record('role.deleted', "Custom role {$customRole->name} deleted", $customRole);
        $customRole->delete();
        return redirect()->route('users.roles')->with('status', 'Custom role deleted.');
    }
}
