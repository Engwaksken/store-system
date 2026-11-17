<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\CustomRole;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AccountCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Creating and changing user accounts, shared by the web admin pages and the mobile API.
 * Users are deactivated, never deleted (audit trail).
 */
class UserAccounts
{
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

    /** Projects that can be assigned: open ones, plus any closed project the user already belongs to. */
    public function projectOptions(?User $user = null): Collection
    {
        return Project::with('manager:id,name')
            ->where(fn ($q) => $q->whereIn('status', ['planned', 'active', 'on_hold'])
                ->when($user?->exists, fn ($w) => $w->orWhereHas('members', fn ($m) => $m->whereKey($user->id))))
            ->orderBy('code')->get(['id', 'code', 'name', 'status', 'manager_id']);
    }

    /** Validated account fields plus `project_ids`. $actor is the admin making the change (self-demotion guard). */
    public function validate(Request $request, ?User $user = null, ?User $actor = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'phone' => 'nullable|string|max:30',
            'role' => ['required', Rule::enum(Role::class)],
            'custom_role_id' => ['nullable', 'integer', Rule::exists('custom_roles', 'id')],
            'is_active' => 'sometimes|boolean',
            'password' => ['nullable', Password::min(8)->letters()->numbers()],
            'project_ids' => 'nullable|array',
            'project_ids.*' => ['integer', Rule::exists('projects', 'id')],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['custom_role_id'] = $request->filled('custom_role_id') ? (int) $request->input('custom_role_id') : null;
        if ($data['custom_role_id']) {
            $profile = CustomRole::findOrFail($data['custom_role_id'])->permission_profile;
            if ($profile !== $data['role']) {
                throw ValidationException::withMessages(['custom_role_id' => 'The selected custom role uses a different permission profile.']);
            }
        }
        $data['project_ids'] = array_values(array_unique(array_map('intval', $data['project_ids'] ?? [])));
        if ($user && $actor && $user->id === $actor->id && (! $data['is_active'] || $data['role'] !== Role::Admin->value)) {
            throw ValidationException::withMessages(['role' => 'You cannot deactivate or demote your own admin account.']);
        }
        // Staff can only see and request for projects they are assigned to: a new staff account needs at least one.
        if (! $user && $data['role'] === Role::Staff->value && ! $data['project_ids'] && $this->projectOptions()->isNotEmpty()) {
            throw ValidationException::withMessages(['project_ids' => 'Assign the new staff member to at least one project, otherwise they cannot see or request anything.']);
        }

        return $data;
    }

    /** @return array{0: User, 1: string} the new user and the temporary password to hand over */
    public function create(array $data): array
    {
        $projectIds = $data['project_ids'] ?? [];
        unset($data['project_ids']);
        $temporary = ($data['password'] ?? '') !== '' ? $data['password'] : self::temporaryPassword();
        $user = User::create(['password' => $temporary, 'must_change_password' => true] + $data);
        $user->projects()->sync($projectIds);
        $codes = Project::whereIn('id', $projectIds)->orderBy('code')->pluck('code')->implode(', ');
        ActivityLog::record('user.created', "User {$user->email} created as {$user->roleLabel()}".($codes ? ", assigned to {$codes}" : ''), $user);

        return [$user, $temporary];
    }

    /** @return string|null the new temporary password when one was set or generated */
    public function update(User $user, array $data, bool $resetPassword = false): ?string
    {
        $temporary = null;
        if (! empty($data['password'])) {
            $temporary = $data['password'];
        } elseif ($resetPassword) {
            $temporary = self::temporaryPassword();
        }
        unset($data['password']);
        if ($temporary !== null) {
            $data['password'] = $temporary;
            $data['must_change_password'] = true;
        }
        $projectIds = $data['project_ids'] ?? [];
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

        return $temporary;
    }

    /**
     * E-mail the sign-in details now. Returns false if mail cannot be sent (no mail server configured, server down):
     * the account still exists and the admin must hand the temporary password over in person.
     */
    public function sendCredentials(User $user, string $temporary, bool $isReset): bool
    {
        try {
            $user->notifyNow(new AccountCredentials($temporary, $isReset));
            ActivityLog::record($isReset ? 'user.password_reset_sent' : 'user.credentials_sent', "Sign-in details e-mailed to {$user->email}", $user);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Could not e-mail sign-in details', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            ActivityLog::record('user.credentials_not_sent', "Sign-in details could not be e-mailed to {$user->email}", $user);

            return false;
        }
    }
}
