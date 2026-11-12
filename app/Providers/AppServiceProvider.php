<?php

namespace App\Providers;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\AppSetting;
use App\Models\Expense;
use App\Models\MaterialIssue;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\RequestWorkflow;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Server-side authorization. Mirrors docs/ROLE_PERMISSION_MATRIX.md.
 * Hidden menus are cosmetic; every route checks one of these gates.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RequestWorkflow::class);
    }

    public function boot(): void
    {
        $this->defineGates();

        View::composer(['layouts.app', 'layouts.guest', 'issues.show', 'reports.table'], function ($view) {
            $view->with('companyName', rescue(fn () => AppSetting::get('company_name'), null, false) ?: config('app.name'));
        });
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            $approvalCount = 0;
            if ($user && Gate::allows('approve-any')) {
                $approvalCount = MaterialRequest::whereIn('status', [RequestStatus::Submitted->value, RequestStatus::PmApproved->value])
                    ->get()->filter(fn ($r) => Gate::allows('pm-decide', $r) || Gate::allows('overall-decide', $r))->count();
            }
            $issueQueueCount = $user && Gate::allows('issue-stock')
                ? MaterialRequest::whereIn('status', [RequestStatus::OverallApproved->value, RequestStatus::PartiallyIssued->value])->count() : 0;
            $view->with(compact('approvalCount', 'issueQueueCount'));
        });
    }

    private function defineGates(): void
    {
        $is = fn (Role ...$roles) => fn (User $u) => $u->hasRole(...$roles);

        // Administration
        Gate::define('manage-users', $is(Role::Admin));
        Gate::define('manage-settings', $is(Role::Admin));
        Gate::define('view-activity', $is(Role::Admin, Role::OverallManager));

        // Projects
        Gate::define('manage-projects', $is(Role::Admin, Role::OverallManager));
        Gate::define('view-project', fn (User $u, Project $p) => Project::visibleTo($u)->whereKey($p->id)->exists());
        Gate::define('view-project-finance', fn (User $u, Project $p) => $u->hasRole(Role::Admin, Role::OverallManager, Role::Accountant)
            || ($u->role === Role::ProjectManager && $p->manager_id === $u->id));

        // Inventory
        Gate::define('manage-catalog', $is(Role::Admin, Role::Storekeeper));
        Gate::define('manage-stock', $is(Role::Admin, Role::Storekeeper));
        Gate::define('view-stock', fn (User $u) => true);
        Gate::define('view-stock-ledger', $is(Role::Admin, Role::OverallManager, Role::ProjectManager, Role::Storekeeper, Role::Accountant));
        Gate::define('view-stock-value', $is(Role::Admin, Role::OverallManager, Role::Storekeeper, Role::Accountant));

        // Material requests
        Gate::define('create-request', fn (User $u) => $u->isAdmin()
            || ($u->hasRole(Role::Staff, Role::ProjectManager) && Project::requestableBy($u)->exists()));
        Gate::define('view-request', fn (User $u, MaterialRequest $r) => MaterialRequest::visibleTo($u)->whereKey($r->id)->exists());
        Gate::define('edit-request', fn (User $u, MaterialRequest $r) => $u->id === $r->requester_id && $r->status === RequestStatus::Draft);
        Gate::define('pm-decide', fn (User $u, MaterialRequest $r) => $r->status === RequestStatus::Submitted && app(RequestWorkflow::class)->canActAsPm($u, $r));
        Gate::define('overall-decide', fn (User $u, MaterialRequest $r) => $r->status === RequestStatus::PmApproved && app(RequestWorkflow::class)->canActAsOverall($u, $r));
        Gate::define('approve-any', $is(Role::Admin, Role::OverallManager, Role::ProjectManager));

        // Issuance
        Gate::define('issue-stock', $is(Role::Storekeeper));
        Gate::define('issue-request', fn (User $u, MaterialRequest $r) => $u->role === Role::Storekeeper && $r->status->isIssuable());
        Gate::define('view-issue', fn (User $u, MaterialIssue $i) => $u->hasRole(Role::Admin, Role::OverallManager, Role::Storekeeper, Role::Accountant)
            || Gate::forUser($u)->allows('view-request', $i->request));
        Gate::define('acknowledge-issue', fn (User $u, MaterialIssue $i) => ($u->id === $i->request->requester_id || $u->role === Role::Storekeeper)
            && ! $i->acknowledgment()->exists());

        // Expenses
        Gate::define('record-expense', $is(Role::Admin, Role::Accountant));
        Gate::define('view-expenses', $is(Role::Admin, Role::OverallManager, Role::Accountant, Role::ProjectManager));
        Gate::define('view-expense', fn (User $u, Expense $e) => Expense::visibleTo($u)->whereKey($e->id)->exists());

        // Reports
        Gate::define('view-reports', $is(Role::Admin, Role::OverallManager, Role::Accountant, Role::ProjectManager, Role::Storekeeper));
        Gate::define('view-finance-reports', $is(Role::Admin, Role::OverallManager, Role::Accountant, Role::ProjectManager));
    }
}
