<?php

namespace App\Providers;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\AppSetting;
use App\Models\Expense;
use App\Models\ExpenseClaim;
use App\Models\MaterialIssue;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\StockCount;
use App\Models\Store;
use App\Models\User;
use App\Services\ExpenseClaimService;
use App\Services\PurchasingService;
use App\Services\RequestWorkflow;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
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
        Route::model('pr', PurchaseRequisition::class);
        Route::model('po', PurchaseOrder::class);
        Route::model('quote', \App\Models\SupplierQuote::class);
        Route::model('invoice', PurchaseInvoice::class);
        Route::model('claim', ExpenseClaim::class);
        Route::model('boq', \App\Models\BoqItem::class);

        View::composer(['layouts.app', 'layouts.guest', 'settings', 'issues.show', 'reports.table', 'pos.show'], function ($view) {
            $view->with('companyName', rescue(fn () => AppSetting::get('company_name'), null, false) ?: config('app.name'));
            $view->with('appSubtitle', rescue(fn () => AppSetting::get('app_subtitle'), null, false) ?: 'Store & Projects');
            $view->with('logoPath', rescue(fn () => AppSetting::get('logo_path'), null, false));
            $view->with('faviconPath', rescue(fn () => AppSetting::get('favicon_path'), null, false));
            $view->with('themeCss', rescue(fn () => \App\Support\Theme::css(), '', false));
            $view->with('fontCssUrl', rescue(fn () => \App\Support\Theme::fontCssUrl(), null, false));
        });
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            $approvalCount = 0;
            if ($user && Gate::allows('approve-any')) {
                $approvalCount = MaterialRequest::with('approvals')->whereIn('status', [RequestStatus::Submitted->value, RequestStatus::PmApproved->value])
                    ->get()->filter(fn ($r) => Gate::allows('pm-decide', $r) || Gate::allows('overall-decide', $r))->count();
            }
            $issueQueueCount = $user && Gate::allows('issue-stock')
                ? MaterialRequest::whereIn('status', [RequestStatus::OverallApproved->value, RequestStatus::PartiallyIssued->value])->count() : 0;
            $transferInboxCount = $user && $user->hasRole(Role::Admin, Role::Storekeeper)
                ? \App\Models\StockTransfer::where('status', 'in_transit')->where('dispatched_by', '!=', $user->id)
                    ->whereIn('to_store_id', Store::operableBy($user)->select('id'))->count() : 0;
            $purchaseApprovalCount = 0;
            if ($user && $user->hasRole(Role::Admin, Role::OverallManager)) {
                $purchaseApprovalCount = PurchaseRequisition::where('status', 'submitted')->where('requested_by', '!=', $user->id)->count()
                    + PurchaseOrder::where('status', 'pending_approval')->get()->filter(fn ($po) => Gate::allows('decide-po', $po))->count();
            }
            $claimCount = $user && $user->hasRole(Role::Admin, Role::OverallManager, Role::ProjectManager)
                ? ExpenseClaim::with('project')->where('status', 'submitted')->get()->filter(fn ($c) => Gate::allows('decide-claim', $c))->count()
                : ($user && $user->hasRole(Role::Accountant) ? ExpenseClaim::where('status', 'approved')->count() : 0);
            $view->with(compact('approvalCount', 'issueQueueCount', 'transferInboxCount', 'purchaseApprovalCount', 'claimCount'));
        });
    }

    private function defineGates(): void
    {
        $is = fn (Role ...$roles) => fn (User $u) => $u->hasRole(...$roles);

        // Administration
        Gate::define('manage-users', $is(Role::Admin));
        Gate::define('manage-settings', $is(Role::Admin));
        Gate::define('view-activity', $is(Role::Admin, Role::OverallManager, Role::Auditor));

        // Projects
        Gate::define('manage-projects', $is(Role::Admin, Role::OverallManager));
        Gate::define('view-project', fn (User $u, Project $p) => Project::visibleTo($u)->whereKey($p->id)->exists());
        Gate::define('view-project-finance', fn (User $u, Project $p) => $u->hasRole(Role::Admin, Role::OverallManager, Role::Accountant, Role::Auditor)
            || ($u->role === Role::ProjectManager && $p->manager_id === $u->id));

        // Inventory
        Gate::define('manage-catalog', $is(Role::Admin, Role::Storekeeper));
        Gate::define('manage-stock', $is(Role::Admin, Role::Storekeeper));
        Gate::define('view-stock', fn (User $u) => true);
        Gate::define('view-stock-ledger', $is(Role::Admin, Role::OverallManager, Role::ProjectManager, Role::Storekeeper, Role::Accountant, Role::Auditor));
        Gate::define('view-stock-value', $is(Role::Admin, Role::OverallManager, Role::Storekeeper, Role::Accountant, Role::Auditor));
        // Store-level scoping (which store) is enforced again inside every stock service via Store::assertOperable.
        Gate::define('operate-stores', fn (User $u) => $u->hasRole(Role::Admin, Role::Storekeeper) && Store::operableBy($u)->exists());
        Gate::define('record-return', fn (User $u, MaterialIssue $i) => $u->hasRole(Role::Admin, Role::Storekeeper) && Store::operableBy($u)->exists()
            && $i->lines()->get()->contains(fn ($l) => bccomp((string) $l->qty, $l->returnedQty(), 3) > 0));
        Gate::define('view-returns', $is(Role::Admin, Role::OverallManager, Role::Storekeeper, Role::Accountant, Role::Auditor));
        Gate::define('view-counts', $is(Role::Admin, Role::OverallManager, Role::Storekeeper, Role::Accountant, Role::Auditor));
        Gate::define('start-count', fn (User $u) => $u->hasRole(Role::Admin, Role::Storekeeper) && Store::operableBy($u)->exists());
        Gate::define('edit-count', fn (User $u, StockCount $c) => $c->status === 'draft' && ($u->id === $c->counted_by || $u->isAdmin()));
        Gate::define('decide-count', fn (User $u, StockCount $c) => $c->status === 'submitted' && $u->id !== $c->counted_by
            && $u->hasRole(Role::Admin, Role::OverallManager));

        // Material requests
        Gate::define('create-request', fn (User $u) => $u->isAdmin()
            || ($u->hasRole(Role::Staff, Role::ProjectManager) && Project::requestableBy($u)->exists()));
        Gate::define('view-request', fn (User $u, MaterialRequest $r) => MaterialRequest::visibleTo($u)->whereKey($r->id)->exists());
        // The requester may edit until the final decision; editing a pending request sends it back to the PM (RequestWorkflow::editPending).
        Gate::define('edit-request', fn (User $u, MaterialRequest $r) => $u->id === $r->requester_id && in_array($r->status, \App\Services\RequestWorkflow::EDITABLE, true));
        Gate::define('pm-decide', fn (User $u, MaterialRequest $r) => $r->status === RequestStatus::Submitted && app(RequestWorkflow::class)->canActAsPm($u, $r));
        Gate::define('overall-decide', fn (User $u, MaterialRequest $r) => $r->status === RequestStatus::PmApproved && app(RequestWorkflow::class)->canActAsOverall($u, $r));
        Gate::define('approve-any', $is(Role::Admin, Role::OverallManager, Role::ProjectManager));

        // Issuance
        Gate::define('issue-stock', $is(Role::Storekeeper));
        Gate::define('issue-request', fn (User $u, MaterialRequest $r) => $u->role === Role::Storekeeper && $r->status->isIssuable());
        Gate::define('view-issue', fn (User $u, MaterialIssue $i) => $u->hasRole(Role::Admin, Role::OverallManager, Role::Storekeeper, Role::Accountant, Role::Auditor)
            || Gate::forUser($u)->allows('view-request', $i->request));
        Gate::define('acknowledge-issue', fn (User $u, MaterialIssue $i) => ($u->id === $i->request->requester_id || $u->role === Role::Storekeeper)
            && ! $i->acknowledgment()->exists());

        // Expenses
        Gate::define('record-expense', $is(Role::Admin, Role::Accountant));
        Gate::define('view-expenses', $is(Role::Admin, Role::OverallManager, Role::Accountant, Role::ProjectManager, Role::Auditor));
        Gate::define('view-expense', fn (User $u, Expense $e) => Expense::visibleTo($u)->whereKey($e->id)->exists());

        // Procurement (purchasing approval policy is separate from material issue approval; see PurchasingService)
        Gate::define('view-suppliers', $is(Role::Admin, Role::OverallManager, Role::Procurement, Role::Accountant, Role::Storekeeper, Role::Auditor));
        Gate::define('manage-suppliers', $is(Role::Admin, Role::Procurement));
        Gate::define('view-purchasing', $is(Role::Admin, Role::OverallManager, Role::Procurement, Role::Accountant, Role::Storekeeper, Role::ProjectManager, Role::Auditor));
        Gate::define('create-pr', $is(Role::Admin, Role::Procurement, Role::Storekeeper, Role::ProjectManager));
        Gate::define('view-pr', fn (User $u, PurchaseRequisition $pr) => PurchaseRequisition::visibleTo($u)->whereKey($pr->id)->exists());
        Gate::define('decide-pr', fn (User $u, PurchaseRequisition $pr) => $pr->status === 'submitted' && $u->id !== $pr->requested_by
            && $u->hasRole(Role::Admin, Role::OverallManager));
        Gate::define('buy', $is(Role::Admin, Role::Procurement));
        Gate::define('view-po', fn (User $u, PurchaseOrder $po) => PurchaseOrder::visibleTo($u)->whereKey($po->id)->exists());
        Gate::define('decide-po', fn (User $u, PurchaseOrder $po) => app(PurchasingService::class)->canDecideOrder($u, $po));
        Gate::define('receive-po', fn (User $u, PurchaseOrder $po) => $po->isReceivable() && $u->hasRole(Role::Admin, Role::Storekeeper)
            && Store::operableBy($u)->whereKey($po->store_id)->exists());
        Gate::define('view-invoices', $is(Role::Admin, Role::OverallManager, Role::Procurement, Role::Accountant, Role::Auditor));
        Gate::define('record-invoice', $is(Role::Admin, Role::Accountant));
        Gate::define('approve-invoice-exception', fn (User $u, PurchaseInvoice $i) => $i->match_status === 'exception'
            && $u->id !== $i->recorded_by && $u->hasRole(Role::Admin, Role::OverallManager));
        Gate::define('pay-invoice', fn (User $u, PurchaseInvoice $i) => $i->isPayable() && $u->hasRole(Role::Admin, Role::Accountant));

        // Project controls (Phase 4)
        Gate::define('view-cost-codes', $is(Role::Admin, Role::OverallManager, Role::Accountant, Role::ProjectManager, Role::Procurement, Role::Auditor));
        Gate::define('manage-cost-codes', $is(Role::Admin, Role::Accountant));
        Gate::define('manage-project-controls', fn (User $u, Project $p) => $u->hasRole(Role::Admin, Role::OverallManager)
            || ($u->role === Role::ProjectManager && $p->manager_id === $u->id));
        Gate::define('submit-claim', fn (User $u) => $u->hasRole(Role::Admin, Role::Accountant, Role::OverallManager)
            || ($u->hasRole(Role::Staff, Role::ProjectManager) && Project::requestableBy($u)->exists()));
        Gate::define('view-claim', fn (User $u, ExpenseClaim $c) => ExpenseClaim::visibleTo($u)->whereKey($c->id)->exists());
        Gate::define('decide-claim', fn (User $u, ExpenseClaim $c) => app(ExpenseClaimService::class)->canDecide($u, $c));
        Gate::define('pay-claim', fn (User $u, ExpenseClaim $c) => $c->status === 'approved' && $u->hasRole(Role::Admin, Role::Accountant));

        // Reports
        Gate::define('view-reports', $is(Role::Admin, Role::OverallManager, Role::Accountant, Role::ProjectManager, Role::Storekeeper, Role::Auditor));
        Gate::define('view-finance-reports', $is(Role::Admin, Role::OverallManager, Role::Accountant, Role::ProjectManager, Role::Auditor));
    }
}
